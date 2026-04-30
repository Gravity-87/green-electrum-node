<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

$logFile = '/state/uptime-events.ndjson';
$now = time();
$windowSec = 24 * 3600;
$start = $now - $windowSec;

$probeInterval = 15;   // seconds
$gapThreshold  = 45;   // >45s ohne sample => unknown-Lücke

function load_samples($file, $minTs, $maxTs) {
    $out = [];
    if (!is_readable($file)) return $out;

    $fh = @fopen($file, 'r');
    if (!$fh) return $out;

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;

        $j = json_decode($line, true);
        if (!is_array($j)) continue;

        $ts = isset($j['ts']) && is_numeric($j['ts']) ? intval($j['ts']) : null;
        $st = isset($j['status']) ? strtolower(trim((string)$j['status'])) : null;
        if ($ts === null) continue;
        if ($ts < $minTs || $ts > $maxTs) continue;
        if ($st !== 'online' && $st !== 'offline') continue;

        $out[] = ['ts' => $ts, 'status' => $st];
    }

    fclose($fh);

    usort($out, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $out;
}

$samples = load_samples($logFile, $start - $gapThreshold, $now);

$knownSec = 0.0;
$onlineSec = 0.0;
$offlineSec = 0.0;
$incidents24h = 0;

// transitions online -> offline (nur bei plausibler Lücke)
for ($i = 1; $i < count($samples); $i++) {
    $prev = $samples[$i - 1];
    $cur  = $samples[$i];
    $gap = $cur['ts'] - $prev['ts'];
    if ($gap <= ($gapThreshold * 2) && $prev['status'] === 'online' && $cur['status'] === 'offline' && $cur['ts'] >= $start) {
        $incidents24h++;
    }
}

// Zeitanteile berechnen
if (count($samples) > 0) {
    for ($i = 0; $i < count($samples); $i++) {
        $cur = $samples[$i];
        $nextTs = ($i + 1 < count($samples)) ? $samples[$i + 1]['ts'] : $now;

        $segStart = max($cur['ts'], $start);
        $segEnd   = min($nextTs, $now);
        if ($segEnd <= $segStart) continue;

        $dt = $segEnd - $segStart;
        $knownDt = min($dt, $gapThreshold); // nur bis gapThreshold als sicher gemessen
        $knownSec += $knownDt;

        if ($cur['status'] === 'online') $onlineSec += $knownDt;
        else $offlineSec += $knownDt;
    }
}

$knownSec = max(0.0, min($windowSec, $knownSec));
$unknownSec = max(0.0, $windowSec - $knownSec);

$uptimePct = $knownSec > 0 ? round(($onlineSec / $knownSec) * 100, 2) : null;
$coveragePct = round(($knownSec / $windowSec) * 100, 2);

// 72 Stunden-Buckets (online/offline/unknown)
// Buckets helper
function build_buckets($samples, $start, $now, $bucketSec) {
    $count = intval(floor(($now - $start) / $bucketSec));
    $out = [];

    for ($i = 0; $i < $count; $i++) {
        $bStart = $start + ($i * $bucketSec);
        $bEnd   = $bStart + $bucketSec;

        $on = 0; $off = 0; $tot = 0;

        foreach ($samples as $s) {
            if ($s['ts'] >= $bStart && $s['ts'] < $bEnd) {
                $tot++;
                if ($s['status'] === 'online') $on++;
                else $off++;
            }
        }

        if ($tot === 0) $state = 'unknown';
        else if ($on > 0 && $off === 0) $state = 'online';
        else if ($off > 0 && $on === 0) $state = 'offline';
        else $state = ($off > $on) ? 'offline' : 'online'; // mixed => majority

        $out[] = [
            'idx' => $i,
            'state' => $state,
            'samples' => $tot
        ];
    }

    return $out;
}

// 24h hourly (compat)
$buckets24 = build_buckets($samples, $start, $now, 3600);

// 24h in 20-minute bins (72 bars)
$buckets72 = build_buckets($samples, $start, $now, 1200);

$out = [
    'uptime_24h_pct' => $uptimePct,               // bezogen auf bekannte Messzeit
    'coverage_24h_pct' => $coveragePct,           // wie viel der 24h überhaupt gemessen wurden
    'offline_minutes_24h' => round($offlineSec / 60, 1),
    'incidents_24h' => $incidents24h,
    'bars_24h' => $buckets24,
    'bar_states_24h' => array_map(fn($b) => $b['state'], $buckets24),
    'bars_24h_20m' => $buckets72,
    'bar_states_24h_20m' => array_map(fn($b) => $b['state'], $buckets72),
    'note' => 'unknown means no monitoring data, not necessarily downtime',
    'generated_at' => $now
];

echo json_encode($out, JSON_UNESCAPED_SLASHES);
