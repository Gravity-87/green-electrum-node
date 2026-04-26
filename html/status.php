<?php
$host = "192.168.178.56";
$port = 50001;
$timeout = 3;

date_default_timezone_set('Europe/Berlin');

$cacheFile = __DIR__ . "/status_cache.json";
$cacheTtl  = 3; // Sekunden

function fee_convert($val) {
    if (!is_numeric($val) || $val <= 0) return "-";
    return round($val * 100000000 / 1000, 1); // BTC/kB -> sat/vB (1 decimal)
}

function electrum_request($host, $port, $payload) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 2);
    if (!$fp) return null;

    fwrite($fp, json_encode($payload) . "
");
    stream_set_timeout($fp, 2);

    $response = '';
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line === false) break;
        $response .= $line;
        if (strpos($line, "
") !== false) break;
    }

    fclose($fp);
    if (!$response) return null;

    return json_decode(trim($response), true);
}

/**
 * Extract block time from 80-byte header hex.
 * nTime is bytes 68..71 (little-endian)
 */
function header_time_from_hex($hex) {
    if (!is_string($hex) || strlen($hex) < 144) return null;

    $timeHexLE = substr($hex, 136, 8);
    if (!$timeHexLE || strlen($timeHexLE) !== 8) return null;

    $bytes = str_split($timeHexLE, 2);
    $bytes = array_reverse($bytes); // LE -> BE
    $timeHexBE = implode('', $bytes);

    $ts = hexdec($timeHexBE);
    if ($ts > 1231006505 && $ts < time() + 7200) return $ts;

    return null;
}


/**
 * Fee-Helperfunktionen
 */

function mempool_state_label($vbytes) {
    if ($vbytes < 300000) return "low";
    if ($vbytes < 1500000) return "normal";
    return "high";
}

function normalize_histogram($hist) {
    $rows = [];

    // 1) nur valide rows
    foreach ($hist as $r) {
        if (!is_array($r) || count($r) < 2) continue;
        $fee = floatval($r[0]);
        $sz  = floatval($r[1]);
        if ($fee <= 0 || $sz <= 0) continue;
        $rows[] = [$fee, $sz];
    }
    if (count($rows) === 0) return [];

    // 2) nach Fee absteigend sortieren (wichtig!)
    usort($rows, function($a, $b) {
        return $b[0] <=> $a[0];
    });

    // 3) erkennen, ob 2. Spalte kumulativ ist
    $isCumulative = true;
    for ($i = 1; $i < count($rows); $i++) {
        if ($rows[$i][1] < $rows[$i-1][1]) {
            $isCumulative = false;
            break;
        }
    }

    // 4) falls kumulativ -> in bucket sizes umrechnen
    if ($isCumulative) {
        $bucketed = [];
        $prev = 0.0;
        foreach ($rows as $r) {
            $cum = $r[1];
            $bucket = max(0.0, $cum - $prev);
            $prev = $cum;
            if ($bucket > 0) $bucketed[] = [$r[0], $bucket];
        }
        return $bucketed;
    }

    return $rows; // schon bucketed
}

function fee_from_histogram($hist, $targetVbytes) {
    $sum = 0.0;
    $lastFee = null;
    foreach ($hist as $row) {
        $fee = floatval($row[0]);
        $vbytes = floatval($row[1]);
        if ($fee <= 0 || $vbytes <= 0) continue;

        $lastFee = $fee;
        $sum += $vbytes;
        if ($sum >= $targetVbytes) return round($fee, 1);
    }
    return $lastFee !== null ? round($lastFee, 1) : null;
}


// ===== Cache fast path =====
if (@file_exists($cacheFile)) {
    $raw = @file_get_contents($cacheFile);
    $cached = $raw ? json_decode($raw, true) : null;

    if (is_array($cached) && isset($cached["generated_at"])) {
        if ((time() - intval($cached["generated_at"])) <= $cacheTtl) {
            header('Content-Type: application/json');
            echo json_encode($cached, JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

// ===== STATUS + LATENCY =====
$start = microtime(true);
$fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
$latency = round((microtime(true) - $start) * 1000);

$status = $fp ? "online" : "offline";
if ($fp) fclose($fp);

// ===== LAST SEEN =====
$lastSeenFile = __DIR__ . "/last_seen.txt";


if ($status === "online") {
    $ok = @file_put_contents($lastSeenFile, (string)time(), LOCK_EX);
    if ($ok === false) error_log("Cannot write last_seen file: $lastSeenFile");
}
$lastSeen = @file_exists($lastSeenFile) ? intval(@file_get_contents($lastSeenFile)) : 0;

// ===== DEFAULTS =====
$blockheight = null;
$blockTime = null;
$blockAgeSec = null;

$server_version = null;
$protocol = null;

$fees = [
    "fast" => "-",
    "medium" => "-",
    "slow" => "-"
];
$feesEqual = false;
$feesNote = null;

$feeRangeMin = null;
$feeRangeMax = null;


$uptime = "-";

$mempoolVbytes = null;
$mempoolState = null;

$feePeak = null;
$feeP90 = null; // optional: "typisch hoher Bereich"


// ===== ELECTRUM DATA =====
if ($status === "online") {
    // BLOCKHEIGHT + HEADER TIME
    $resHeader = electrum_request($host, $port, [
        "id" => 1,
        "method" => "blockchain.headers.subscribe",
        "params" => []
    ]);

    if ($resHeader && isset($resHeader["result"]["height"])) {
        $blockheight = $resHeader["result"]["height"];
    }

    if ($resHeader && isset($resHeader["result"]["hex"])) {
        $blockTime = header_time_from_hex($resHeader["result"]["hex"]);
        if ($blockTime) $blockAgeSec = max(0, time() - $blockTime);
    }

    // VERSION + PROTOCOL (robust parse)
    $resVersion = electrum_request($host, $port, [
        "id" => 2,
        "method" => "server.version",
        "params" => ["green-client", "1.4"]
    ]);

    if ($resVersion && array_key_exists("result", $resVersion)) {
        if (is_array($resVersion["result"])) {
            $server_version = $resVersion["result"][0] ?? null;
            $protocol = $resVersion["result"][1] ?? null;
        } else {
            $server_version = (string)$resVersion["result"];
        }
    }

    // FEES: primär über mempool histogram, fallback estimatefee
    $usedHistogram = false;

    $histRes = electrum_request($host, $port, [
        "id" => 30,
        "method" => "mempool.get_fee_histogram",
        "params" => []
    ]);

    if ($histRes && isset($histRes["result"]) && is_array($histRes["result"]) && count($histRes["result"]) > 0) {
        $hist = $histRes["result"];

        // mempool size (vbytes) + state
	$hist = normalize_histogram($histRes["result"]);

	$total = 0.0;
	foreach ($hist as $r) $total += floatval($r[1]);

	$mempoolVbytes = intval($total);
	$mempoolState = mempool_state_label($mempoolVbytes);

	    if (count($hist) > 0) {
	        $feePeak = round(floatval($hist[0][0]), 1); // höchster Fee-Bucket
	    }
	    if ($total > 0) {
	        $feeP90 = fee_from_histogram($hist, $total * 0.10); // obere 10%
	    }

	$fastTarget   = $total * 0.15;
	$mediumTarget = $total * 0.50;
	$slowTarget   = $total * 0.90;

	$fastH   = fee_from_histogram($hist, $fastTarget);
	$mediumH = fee_from_histogram($hist, $mediumTarget);
	$slowH   = fee_from_histogram($hist, $slowTarget);

        if ($fastH !== null || $mediumH !== null || $slowH !== null) {
            $fees = [
                "fast" => $fastH ?? "-",
                "medium" => $mediumH ?? "-",
                "slow" => $slowH ?? "-"
            ];
            $usedHistogram = true;
        }
    }

    // Fallback auf estimatefee
    if (!$usedHistogram) {
        $fastRes = electrum_request($host, $port, [
            "id" => 3,
            "method" => "blockchain.estimatefee",
            "params" => [2]
        ]);

        $mediumRes = electrum_request($host, $port, [
            "id" => 4,
            "method" => "blockchain.estimatefee",
            "params" => [6]
        ]);

        $slowRes = electrum_request($host, $port, [
            "id" => 5,
            "method" => "blockchain.estimatefee",
            "params" => [12]
        ]);

        $fastFee   = isset($fastRes["result"])   ? fee_convert($fastRes["result"])   : "-";
        $mediumFee = isset($mediumRes["result"]) ? fee_convert($mediumRes["result"]) : "-";
        $slowFee   = isset($slowRes["result"])   ? fee_convert($slowRes["result"])   : "-";

        if ($fastFee === "-" && $mediumFee !== "-") $fastFee = $mediumFee;

        $fees = [
            "fast" => $fastFee,
            "medium" => $mediumFee,
            "slow" => $slowFee
        ];
    }

    // fees_equal / fees_note
    $f = is_numeric($fees["fast"])   ? floatval($fees["fast"])   : null;
    $m = is_numeric($fees["medium"]) ? floatval($fees["medium"]) : null;
    $s = is_numeric($fees["slow"])   ? floatval($fees["slow"])   : null;

    if ($f !== null && $m !== null && $s !== null) {
        if (abs($f - $m) < 0.0001 && abs($m - $s) < 0.0001) {
            $feesEqual = true;
            $feesNote = "All fee tiers currently equal";
        }
    }

    $uptime = "running";
}


// fee range from final fees array (robust, always last)
$feeRangeMin = null;
$feeRangeMax = null;

$vals = [];
foreach (["slow", "medium", "fast"] as $k) {
    if (isset($fees[$k]) && is_numeric($fees[$k])) {
        $vals[] = floatval($fees[$k]);
    }
}
if (count($vals) > 0) {
    $feeRangeMin = min($vals);
    $feeRangeMax = max($vals);
}



// ===== OUTPUT =====
$out = [
    "status" => $status,
    "latency" => $latency,
    "last_seen" => $lastSeen,

    "blockheight" => $blockheight,
    "block_time" => $blockTime,
    "block_age_sec" => $blockAgeSec,

    "server_version" => $server_version,
    "protocol" => $protocol,

    "fees" => $fees,
    "fees_equal" => $feesEqual,
    "fees_note" => $feesNote,
    "fee_range_min" => $feeRangeMin,
    "fee_range_max" => $feeRangeMax,
    "fee_peak" => $feePeak,
    "fee_p90" => $feeP90,

    "mempool_vbytes" => $mempoolVbytes,
    "mempool_state" => $mempoolState,

    "uptime" => $uptime,
    "generated_at" => time()
];

// Cache write (best effort)
@file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_SLASHES), LOCK_EX);

header('Content-Type: application/json');
echo json_encode($out, JSON_UNESCAPED_SLASHES);
