<?php
$host = "192.168.178.56";
$port = 50001;

$start = microtime(true);
$fp = @fsockopen($host, $port, $errno, $errstr, 1);
$latency = round((microtime(true) - $start) * 1000);

$status = $fp ? "online" : "offline";

if ($fp) fclose($fp);

// Fake blockheight (optional später echt via electrs API)
$blockheight = rand(820000, 830000);

header('Content-Type: application/json');
echo json_encode([
    "status" => $status,
    "latency" => $latency,
    "blockheight" => $blockheight
]);
