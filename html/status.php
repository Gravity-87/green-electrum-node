<?php
$host = "192.168.178.56";
$port = 50001;
$timeout = 3;

function electrum_request($host, $port, $payload) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 2);
    if (!$fp) return null;

    fwrite($fp, json_encode($payload) . "\n");
    stream_set_timeout($fp, 2);

    $response = '';
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line === false) break;

        $response .= $line;

        // Electrum antwortet meist mit einer JSON-Zeile → wir brechen früh ab
        if (strpos($line, "\n") !== false) break;
    }

    fclose($fp);

    if (!$response) return null;

    return json_decode(trim($response), true);
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
    @file_put_contents($lastSeenFile, time());
}

$lastSeen = @file_exists($lastSeenFile)
    ? intval(@file_get_contents($lastSeenFile))
    : 0;

// ===== DEFAULTS =====
$blockheight = null;
$server_version = null;
$protocol = null;

$fees = [
    "slow" => "-",
    "medium" => "-",
    "fast" => "-"
];

$uptime = "-";

// ===== ELECTRUM DATA =====
if ($status === "online") {

    // BLOCKHEIGHT
    $res = electrum_request($host, $port, [
        "id" => 1,
        "method" => "blockchain.headers.subscribe",
        "params" => []
    ]);

    if ($res && isset($res["result"]["height"])) {
        $blockheight = $res["result"]["height"];
    }

    // VERSION + PROTOCOL
    $res = electrum_request($host, $port, [
        "id" => 2,
        "method" => "server.version",
        "params" => ["green-client", "1.4"]
    ]);

    if ($res && isset($res["result"])) {
        $server_version = $res["result"][0] ?? null;
        $protocol = $res["result"][1] ?? null;
    }

    // FEES (einfacher Ansatz via estimatefee)
    $fast = electrum_request($host, $port, [
        "id" => 3,
        "method" => "blockchain.estimatefee",
        "params" => [1]
    ]);

    $medium = electrum_request($host, $port, [
        "id" => 4,
        "method" => "blockchain.estimatefee",
        "params" => [6]
    ]);

    $slow = electrum_request($host, $port, [
        "id" => 5,
        "method" => "blockchain.estimatefee",
        "params" => [12]
    ]);

    // BTC/kB → sat/vB grob umrechnen
	function fee_convert($val) {
	    if (!is_numeric($val) || $val <= 0) return "-";
	    return round($val * 100000000 / 1000);
	}

    $fees = [
        "fast" => isset($fast["result"]) ? fee_convert($fast["result"]) : "-",
        "medium" => isset($medium["result"]) ? fee_convert($medium["result"]) : "-",
        "slow" => isset($slow["result"]) ? fee_convert($slow["result"]) : "-"
    ];

    // UPTIME (optional einfach)
    $uptime = "running";
}

// ===== OUTPUT =====
header('Content-Type: application/json');

echo json_encode([
    "status" => $status,
    "latency" => $latency,
    "last_seen" => $lastSeen,
    "blockheight" => $blockheight,
    "server_version" => $server_version,
    "protocol" => $protocol,
    "fees" => $fees,
    "uptime" => $uptime
]);
