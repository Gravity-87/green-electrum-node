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

// logging config
$maxEntries = 2000;
$sampleEverySec = 60;

// target check (dein Node)
$host = "192.168.178.56";
$port = 50001;
$timeout = 2;

$conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
$currentStatus = $conn ? 1 : 0;
if ($conn) fclose($conn);

$now = time();
$lines = [];

// lock for read-modify-write
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
        $shouldAppend = true; // status change immediately
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

// parse all valid points
$allPoints = [];
foreach ($lines as $line) {
    $p = parse_line($line);
    if ($p) $allPoints[] = ["t" => (int)$p[0], "s" => (int)$p[1]];
}
if (empty($allPoints)) {
    $allPoints[] = ["t" => $now, "s" => $currentStatus];
}

// ---- 24h aggregation ----
$windowSec = 86400;       // 24h
$binCount = 72;           // 20-min bins
$binSec = (int)($windowSec / $binCount);
$cutoff = $now - $windowSec;

// points in window
$points = [];
foreach ($allPoints as $pt) {
    if ($pt["t"] >= $cutoff) $points[] = $pt;
}
if (empty($points)) {
    $points[] = ["t" => $now, "s" => $currentStatus];
}

// bin majority
$bins = array_fill(0, $binCount, null); // null=no data, 1=up, 0=down
$upCount = array_fill(0, $binCount, 0);
$totalCount = array_fill(0, $binCount, 0);

foreach ($points as $pt) {
    $idx = (int)floor(($pt["t"] - $cutoff) / $binSec);
    if ($idx < 0) $idx = 0;
    if ($idx >= $binCount) $idx = $binCount - 1;

    $totalCount[$idx] += 1;
    if ((int)$pt["s"] === 1) $upCount[$idx] += 1;
}

for ($i = 0; $i < $binCount; $i++) {
    if ($totalCount[$i] === 0) {
        $bins[$i] = null;
    } else {
        $bins[$i] = ($upCount[$i] / $totalCount[$i] >= 0.5) ? 1 : 0;
    }
}

// uptime % + coverage
$known = 0;
$up = 0;
foreach ($bins as $b) {
    if ($b === null) continue;
    $known++;
    if ($b === 1) $up++;
}
$uptimePct = $known > 0 ? round(($up / $known) * 100, 1) : null;
$coveragePct = round(($known / $binCount) * 100, 1);

// incidents + longest outage (from transitions in 24h points)
usort($points, function($a, $b) { return $a["t"] <=> $b["t"]; });

$incidents = 0;
$longestOutageSec = 0;
$outageStart = null;
$prev = null;

foreach ($points as $pt) {
    if ($prev !== null) {
        if ((int)$prev["s"] === 1 && (int)$pt["s"] === 0) {
            $incidents++;
            $outageStart = $pt["t"];
        } elseif ((int)$prev["s"] === 0 && (int)$pt["s"] === 1 && $outageStart !== null) {
            $dur = $pt["t"] - $outageStart;
            if ($dur > $longestOutageSec) $longestOutageSec = $dur;
            $outageStart = null;
        }
    }
    $prev = $pt;
}
if ($outageStart !== null) {
    $dur = $now - $outageStart;
    if ($dur > $longestOutageSec) $longestOutageSec = $dur;
}

echo json_encode([
    "uptime_24h_pct" => $uptimePct,
    "coverage_24h_pct" => $coveragePct,
    "bins" => $bins,
    "incidents_24h" => $incidents,
    "longest_outage_sec" => $longestOutageSec
], JSON_UNESCAPED_SLASHES);
