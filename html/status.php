<?php
/* Warnings nicht ins .json leaken lassen */
ini_set('display_errors', '0');

$host = "host.docker.internal";
$port = 50001;
$timeout = 5;


date_default_timezone_set('Europe/Berlin');

/* =========================
   Persistent state directory
========================= */
function pick_state_dir() {
    $candidates = [
        "/state", // persistent mount (bevorzugt)
        __DIR__ . "/data",
        sys_get_temp_dir() . "/green-electrum-site"
    ];

    foreach ($candidates as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_dir($dir) && is_writable($dir)) return $dir;
    }
    return null;
}

$stateDir = pick_state_dir();
$cacheFile     = $stateDir ? $stateDir . "/status_cache.json" : null;
$lastSeenFile  = $stateDir ? $stateDir . "/last_seen.txt" : null;
$paceStateFile = $stateDir ? $stateDir . "/block_pace_state.json" : null;
$paceLockFile  = $stateDir ? $stateDir . "/block_pace.lock" : null;

$cacheTtl = 3; // seconds

$paceTtlHours   = 72;   // adjustable
$paceMaxSamples = 288;  // adjustable

/* =========================
   Helpers
========================= */
function fee_convert($val) {
    if (!is_numeric($val) || $val <= 0) return "-";
    return round($val * 100000000 / 1000, 1); // BTC/kB -> sat/vB
}

function electrum_request($host, $port, $payload) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 5);
    if (!$fp) return null;

    fwrite($fp, json_encode($payload) . "
");
    stream_set_timeout($fp, 5);

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
 * Extract nTime from block header hex (80-byte header)
 * nTime bytes are at offset 68..71 (little-endian)
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

function mempool_state_label($vbytes) {
    if ($vbytes < 300000) return "low";
    if ($vbytes < 1500000) return "normal";
    return "high";
}

function fee_pressure_label($fast, $p90 = null) {
    $f = is_numeric($fast) ? floatval($fast) : null;
    $p = is_numeric($p90) ? floatval($p90) : null;

    // robust: prefer fast, fallback p90
    $x = $f ?? $p;
    if ($x === null) return "unknown";

    // more realistic public-facing thresholds
    if ($x <= 5)   return "low";
    if ($x <= 15)  return "normal";
    if ($x <= 40)  return "high";
    return "very_high";
}


function normalize_histogram($hist) {
    $rows = [];

    foreach ($hist as $r) {
        if (!is_array($r) || count($r) < 2) continue;
        $fee = floatval($r[0]);
        $sz  = floatval($r[1]);
        if ($fee <= 0 || $sz <= 0) continue;
        $rows[] = [$fee, $sz];
    }
    if (count($rows) === 0) return [];

    // descending by fee
    usort($rows, function($a, $b) {
        return $b[0] <=> $a[0];
    });

    // detect cumulative second column
    $isCumulative = true;
    for ($i = 1; $i < count($rows); $i++) {
        if ($rows[$i][1] < $rows[$i-1][1]) {
            $isCumulative = false;
            break;
        }
    }

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

    return $rows;
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

/* ===== Block pace persistence ===== */
function load_pace_state($file) {
    $empty = ["last_height" => null, "last_time" => null, "samples" => []];
    if (!$file || !file_exists($file)) return $empty;

    $raw = @file_get_contents($file);
    $obj = $raw ? json_decode($raw, true) : null;
    if (!is_array($obj)) return $empty;

    return [
        "last_height" => (isset($obj["last_height"]) && is_numeric($obj["last_height"])) ? intval($obj["last_height"]) : null,
        "last_time"   => (isset($obj["last_time"]) && is_numeric($obj["last_time"])) ? intval($obj["last_time"]) : null,
        "samples"     => (isset($obj["samples"]) && is_array($obj["samples"])) ? $obj["samples"] : [],
    ];
}

function save_pace_state($file, $state) {
    if (!$file) return false;
    $tmp = $file . ".tmp";
    $json = json_encode($state, JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $file); // atomar
}

function prune_pace_samples($samples, $ttlHours, $maxSamples) {
    $now = time();
    $ttl = $ttlHours * 3600;
    $out = [];

    foreach ($samples as $s) {
        $sec = isset($s["sec"]) ? floatval($s["sec"]) : null;
        $ts  = isset($s["ts"]) ? intval($s["ts"]) : null;
        if ($sec === null || $ts === null) continue;
        if ($sec < 60 || $sec > 7200) continue;
        if (($now - $ts) > $ttl) continue;
        $out[] = ["sec" => $sec, "ts" => $ts];
    }

    if (count($out) > $maxSamples) {
        $out = array_slice($out, -$maxSamples);
    }

    return $out;
}

function pace_trend_from_samples($samples) {
    $n = count($samples);
    if ($n < 10) return ["trend" => "stable", "arrow" => "→"];

    $vals = array_map(function($x){ return floatval($x["sec"]); }, $samples);

    $shortN = min(6, count($vals));
    $longN  = min(24, count($vals));

    $short = array_slice($vals, -$shortN);
    $long  = array_slice($vals, -$longN);

    $sAvg = array_sum($short) / count($short);
    $lAvg = array_sum($long) / count($long);

    $threshold = 30;
    if ($sAvg > $lAvg + $threshold) return ["trend" => "slower", "arrow" => "↑"];
    if ($sAvg < $lAvg - $threshold) return ["trend" => "faster", "arrow" => "↓"];
    return ["trend" => "stable", "arrow" => "→"];
}

/* =========================
   Cache fast path
========================= */
if ($cacheFile && file_exists($cacheFile)) {
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

/* =========================
   STATUS + LATENCY
========================= */
$start = microtime(true);
$fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
$latency = round((microtime(true) - $start) * 1000);

$status = $fp ? "online" : "offline";
if ($fp) fclose($fp);

/* =========================
   LAST SEEN
========================= */
if ($status === "online" && $lastSeenFile) {
    @file_put_contents($lastSeenFile, (string)time(), LOCK_EX);
}
$lastSeen = ($lastSeenFile && file_exists($lastSeenFile)) ? intval(@file_get_contents($lastSeenFile)) : 0;

/* =========================
   Defaults
========================= */
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
$feePeak = null;
$feeP90 = null;

$mempoolVbytes = null;
$mempoolState = null;

$blockPaceAvgSec = null;
$blockPaceTrend = "stable";
$blockPaceArrow = "→";
$blockPaceSamples = 0;

$blockPaceRecentSec = [];

$uptime = "-";


// Preload last known block pace for offline display
$paceSnapshot = load_pace_state($paceStateFile);
$paceSnapshot["samples"] = prune_pace_samples($paceSnapshot["samples"], $paceTtlHours, $paceMaxSamples);

$blockPaceSamples = count($paceSnapshot["samples"]);
if ($blockPaceSamples > 0) {
    $sum = 0.0;
    foreach ($paceSnapshot["samples"] as $s) $sum += floatval($s["sec"]);
    $blockPaceAvgSec = round($sum / $blockPaceSamples, 1);

    $tr = pace_trend_from_samples($paceSnapshot["samples"]);
    $blockPaceTrend = $tr["trend"];
    $blockPaceArrow = $tr["arrow"];
}



/* =========================
   ELECTRUM DATA
========================= */
if ($status === "online") {
    // blockheight + latest header time
    $resHeader = electrum_request($host, $port, [
        "id" => 1,
        "method" => "blockchain.headers.subscribe",
        "params" => []
    ]);

    if ($resHeader && isset($resHeader["result"]["height"])) {
        $blockheight = intval($resHeader["result"]["height"]);
    }

    if ($resHeader && isset($resHeader["result"]["hex"])) {
        $blockTime = header_time_from_hex($resHeader["result"]["hex"]);
        if ($blockTime) $blockAgeSec = max(0, time() - $blockTime);
    }

// block pace (server persistent, locked update)
if ($paceStateFile && $paceLockFile) {
    $lockFp = @fopen($paceLockFile, "c");

    if ($lockFp && @flock($lockFp, LOCK_EX)) {
        $pace = load_pace_state($paceStateFile);

        if (is_numeric($blockheight) && is_numeric($blockTime)) {
            $lastH = $pace["last_height"];
            $lastT = $pace["last_time"];

            if (is_numeric($lastH) && is_numeric($lastT)) {
                $dH = intval($blockheight) - intval($lastH);
                $dT = intval($blockTime) - intval($lastT);

                if ($dH > 0 && $dT > 0) {
                    $secPerBlock = $dT / $dH;
                    if ($secPerBlock >= 60 && $secPerBlock <= 7200) {
                        $pace["samples"][] = [
                            "sec" => round($secPerBlock, 2),
                            "ts"  => time()
                        ];
                    }
                }
            }

            $pace["last_height"] = intval($blockheight);
            $pace["last_time"]   = intval($blockTime);
        }

        $pace["samples"] = prune_pace_samples($pace["samples"], $paceTtlHours, $paceMaxSamples);
        save_pace_state($paceStateFile, $pace);

        $blockPaceSamples = count($pace["samples"]);
        if ($blockPaceSamples > 0) {
            $sum = 0.0;
            foreach ($pace["samples"] as $s) $sum += floatval($s["sec"]);
            $blockPaceAvgSec = round($sum / $blockPaceSamples, 1);

            $tr = pace_trend_from_samples($pace["samples"]);
            $blockPaceTrend = $tr["trend"];
            $blockPaceArrow = $tr["arrow"];
        }

        // recent series for sparkline (last 24)
        $recent = array_slice($pace["samples"], -24);
        $blockPaceRecentSec = [];
        foreach ($recent as $r) {
            $v = isset($r["sec"]) ? floatval($r["sec"]) : null;
            if (is_numeric($v) && $v > 0) $blockPaceRecentSec[] = $v;
        }

        @flock($lockFp, LOCK_UN);
    }

    if ($lockFp) @fclose($lockFp);
}


    // server.version
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

    // FEES: histogram first, estimatefee fallback
    $usedHistogram = false;

    $histRes = electrum_request($host, $port, [
        "id" => 30,
        "method" => "mempool.get_fee_histogram",
        "params" => []
    ]);

    if ($histRes && isset($histRes["result"]) && is_array($histRes["result"]) && count($histRes["result"]) > 0) {
        $hist = normalize_histogram($histRes["result"]);

        $total = 0.0;
        foreach ($hist as $r) $total += floatval($r[1]);

        $mempoolVbytes = intval($total);
        $mempoolState = mempool_state_label($mempoolVbytes);

        if (count($hist) > 0) {
            $feePeak = round(floatval($hist[0][0]), 1); // raw peak
        }
        if ($total > 0) {
            $feeP90 = fee_from_histogram($hist, $total * 0.10); // high-tier reference
        }

        $fastTarget   = $total * 0.15;
        $mediumTarget = $total * 0.50;
        $slowTarget   = $total * 0.90;

        $fastH   = fee_from_histogram($hist, $fastTarget);
        $mediumH = fee_from_histogram($hist, $mediumTarget);
        $slowH   = fee_from_histogram($hist, $slowTarget);

        if ($fastH !== null || $mediumH !== null || $slowH !== null) {
            $fees = [
                "fast"   => $fastH ?? "-",
                "medium" => $mediumH ?? "-",
                "slow"   => $slowH ?? "-"
            ];
            $usedHistogram = true;
        }
    }

    // fallback estimatefee
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
            "fast"   => $fastFee,
            "medium" => $mediumFee,
            "slow"   => $slowFee
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

/* =========================
   Fee range from final fees
========================= */
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


$feePressureState = fee_pressure_label($fees["fast"] ?? null, $feeP90 ?? null);

$backlogState = $mempoolState ?? "unknown";
$feePressureState = fee_pressure_label($fees["fast"] ?? null, $feeP90 ?? null);



/* =========================
   OUTPUT
========================= */
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

    "mempool_backlog_state" => $backlogState,
    "fee_pressure_state"    => $feePressureState,

    "block_pace_avg_sec" => $blockPaceAvgSec,
    "block_pace_trend" => $blockPaceTrend,
    "block_pace_arrow" => $blockPaceArrow,
    "block_pace_samples" => $blockPaceSamples,

    "block_pace_recent_sec" => $blockPaceRecentSec,

    "uptime" => $uptime,
    "generated_at" => time()
];

// cache write best effort
if ($cacheFile) {
    @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

header('Content-Type: application/json');
echo json_encode($out, JSON_UNESCAPED_SLASHES);
