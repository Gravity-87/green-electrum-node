<?php
header('Content-Type: application/json; charset=utf-8');

$file = '/state/btcinfo-host.json';
if (!is_file($file) || !is_readable($file)) {
    echo json_encode(["error" => "btcinfo cache missing"]);
    exit;
}

$raw = @file_get_contents($file);
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(["error" => "btcinfo cache invalid"]);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_SLASHES);
