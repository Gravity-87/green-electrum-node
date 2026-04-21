<?php
$host = "192.168.178.56";
$port = 50001;
$timeout = 2;

$fp = @fsockopen($host, $port, $errno, $errstr, $timeout);

if (!$fp) {
    header('Content-Type: application/json');
    echo json_encode([
        "status" => "offline"
    ]);
    exit;
}

function rpc($fp, $method, $params = []) {
    static $id = 0;
    $id++;

    $req = json_encode([
        "id" => $id,
        "method" => $method,
        "params" => $params
    ]) . "\n";

    fwrite($fp, $req);
    $res = fgets($fp, 4096);

    return json_decode($res, true);
}

// 🧠 Version
$ver = rpc($fp, "server.version", ["web", "1.4"]);
$version = $ver["result"][0] ?? "unknown";
$protocol = $ver["result"][1] ?? "unknown";

// ⛓ Blockheight
$hdr = rpc($fp, "blockchain.headers.subscribe");
$height = $hdr["result"]["height"] ?? null;

// 💸 Fees (Histogram)
$fees = rpc($fp, "mempool.get_fee_histogram");

$fee_fast = null;
$fee_medium = null;
$fee_slow = null;

if (isset($fees["result"]) && count($fees["result"]) > 0) {
    $hist = $fees["result"];

    $fee_fast = $hist[0][0] ?? null;

    $mid = floor(count($hist) / 2);
    $fee_medium = $hist[$mid][0] ?? null;

    $fee_slow = $hist[count($hist)-1][0] ?? null;
}

// 👉 ERST HIER schließen
fclose($fp);

// 👉 UND DANN JSON
header('Content-Type: application/json');

echo json_encode([
    "status" => "ok",
    "version" => $version,
    "protocol" => $protocol,
    "blockheight" => $height,
    "fee_fast" => $fee_fast,
    "fee_medium" => $fee_medium,
    "fee_slow" => $fee_slow
]);
