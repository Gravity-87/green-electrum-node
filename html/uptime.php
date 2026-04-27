<?php
ini_set('display_errors', '0');
header('Content-Type: application/json');

function pick_state_dir() {
    $candidates = [
        "/state",
        __DIR__ . "/data",
        sys_get_temp_dir() . "/green-electrum-site"
    ];
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_dir($dir) && is_writable($dir)) return $dir;
    }
    return __DIR__;
}

function parse_line($line) {
    $line = trim($line);
    if ($line === '') return null;
    $parts = explode('|', $line);
    if (count($parts) !== 2) return null;

    $ts = (int)$parts[0];
    $st = (int)$parts[1];
    if ($ts <= 0) return null;
    if ($st !== 0 && $st !== 1) return null;

    return [$ts, $st];
}

$stateDir = pick_state_dir();
$file = $stateDir . "/uptime.log";
$lockFile = $stateDir . "/uptime.lock";

$maxEntries = 288;
$sampleEverySec = 10;

// Ziel prüfen
$host = "192.168.178.56";
$port = 50001;
$timeout = 2;

$conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
$currentStatus = $conn ? 1 : 0;
if ($conn) fclose($conn);

$now = time();
$lines = [];

$lockFp = @fopen($lockFile, "c");
if ($lockFp && @flock($lockFp, LOCK_EX)) {
    if (file_exists($file)) {
        $raw = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($raw)) $lines = $raw;
    }

    $lastTs = null;
    $lastSt = null;

    if (!empty($lines)) {
        $lastParsed = parse_line($lines[count($lines) - 1]);
        if ($lastParsed) {
            $lastTs = $lastParsed[0];
            $lastSt = $lastParsed[1];
        }
    }

    $shouldAppend = false;

    if ($lastTs === null) {
        $shouldAppend = true;
    } elseif (($now - $lastTs) >= $sampleEverySec) {
        $shouldAppend = true;
    } elseif ($lastSt !== null && ((int)$lastSt !== (int)$currentStatus)) {
        $shouldAppend = true; // Statuswechsel sofort speichern
    }

    if ($shouldAppend) {
        $lines[] = $now . "|" . $currentStatus;
        if (count($lines) > $maxEntries) {
            $lines = array_slice($lines, -$maxEntries);
        }
        @file_put_contents($file, implode("
", $lines) . "
", LOCK_EX);
    }

    @flock($lockFp, LOCK_UN);
}
if ($lockFp) @fclose($lockFp);

// Ausgabe
$data = [];
foreach ($lines as $line) {
    $p = parse_line($line);
    if ($p) {
        $data[] = ["t" => (int)$p[0], "s" => (int)$p[1]];
    }
}

// Falls noch leer, wenigstens aktuellen Punkt liefern
if (empty($data)) {
    $data[] = ["t" => $now, "s" => $currentStatus];
}

echo json_encode($data, JSON_UNESCAPED_SLASHES);
