<?php

$file = "uptime.log";
$max_entries = 50;

// Status check
$host = "192.168.178.56"; // deine funktionierende IP!
$port = 50001;
$timeout = 2;

$connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
$status = $connection ? "1" : "0";

if ($connection) fclose($connection);

// Log schreiben
$entry = time() . "|" . $status . "\n";

@file_put_contents($file, $entry, FILE_APPEND);

// Datei kürzen
$lines = file_exists($file) ? file($file) : [];
if (count($lines) > $max_entries) {
    $lines = array_slice($lines, -$max_entries);
    file_put_contents($file, implode("", $lines));
}

// Ausgabe
$data = [];

foreach ($lines as $line) {
    list($ts, $st) = explode("|", trim($line));
    $data[] = ["t" => (int)$ts, "s" => (int)$st];
}

header('Content-Type: application/json');
echo json_encode($data);
