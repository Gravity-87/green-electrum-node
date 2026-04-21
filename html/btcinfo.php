<?php
header('Content-Type: application/json; charset=utf-8');

$rpcUrl = 'http://host.docker.internal:8332/';
$cookiePath = '/run/bitcoin/.cookie';

$cookie = @trim(file_get_contents($cookiePath));
if (!$cookie || strpos($cookie, ':') === false) {
    echo json_encode(["error" => "rpc cookie missing/unreadable"]);
    exit;
}
[$rpcUser, $rpcPass] = explode(':', $cookie, 2);

$payload = json_encode([
    ["jsonrpc"=>"1.0","id"=>"net","method"=>"getnetworkinfo","params"=>[]],
    ["jsonrpc"=>"1.0","id"=>"chain","method"=>"getblockchaininfo","params"=>[]]
]);

$ch = curl_init($rpcUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_USERPWD => $rpcUser . ':' . $rpcPass,
    CURLOPT_TIMEOUT => 4
]);

$raw = curl_exec($ch);
if ($raw === false) {
    echo json_encode(["error"=>"rpc connection failed"]);
    curl_close($ch);
    exit;
}
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http !== 200) {
    echo json_encode(["error"=>"rpc http error", "http"=>$http]);
    exit;
}

$data = json_decode($raw, true);
$net = $data[0]['result'] ?? null;
$chain = $data[1]['result'] ?? null;

if (!$net || !$chain) {
    echo json_encode(["error"=>"invalid rpc response"]);
    exit;
}

echo json_encode([
    "version" => $net["subversion"] ?? "unknown",
    "blocks"  => $chain["blocks"] ?? null,
    "sync"    => (!($chain["initialblockdownload"] ?? true)) ? "synced" : "syncing"
]);
