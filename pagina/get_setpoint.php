<?php
// get_setpoint.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

@ini_set('precision', 14);
@ini_set('serialize_precision', 10);

date_default_timezone_set('Europe/Rome');

// ---------- CONFIGURATION ----------

// 1. RAM STORAGE (Using a new folder name to avoid previous permission locks)
$ramDir = '/dev/shm/thermo_data';

// 2. DISK BACKUPS (Local folder)
$stateBackup = __DIR__ . '/state.json';
$scheduleBackup = __DIR__ . '/schedule.json';
$historyBackup = __DIR__ . '/temp_history.csv';
$phoneHistoryBackup = __DIR__ . '/phone_history.csv';

// ---------- INIT & CHECKS ----------

// Check open_basedir restrictions
$basedir = ini_get('open_basedir');
if ($basedir && !str_contains($basedir, '/dev/shm') && !str_contains($basedir, '/dev/')) {
    echo json_encode(['ok' => false, 'error' => "PHP Configuration 'open_basedir' prevents access to /dev/shm. Please edit php.ini."]);
    exit;
}

// Create RAM directory if missing
if (!is_dir($ramDir)) {
    if (!@mkdir($ramDir, 0777, true)) {
        $e = error_get_last();
        echo json_encode(['ok' => false, 'error' => "Failed to create RAM folder ($ramDir). Permission denied.", 'details' => $e['message'] ?? '']);
        exit;
    }
    @chmod($ramDir, 0777);
}

// Check write permissions
if (!is_writable($ramDir)) {
    echo json_encode(['ok' => false, 'error' => "RAM folder ($ramDir) is not writable. Check permissions."]);
    exit;
}

// Define paths
$stateFile = $ramDir . '/state.json';
$scheduleFile = $ramDir . '/schedule.json';
$historyFile = $ramDir . '/temp_history.csv';
$phoneHistoryFile = $ramDir . '/phone_history.csv';

// ---------- STARTUP SYNC (Disk -> RAM) ----------
$filesToSync = [
    $stateFile => $stateBackup,
    $scheduleFile => $scheduleBackup,
    $historyFile => $historyBackup,
    $phoneHistoryFile => $phoneHistoryBackup
];

foreach ($filesToSync as $ramPath => $diskPath) {
    if (!file_exists($ramPath) && file_exists($diskPath)) {
        if (@copy($diskPath, $ramPath)) {
            @chmod($ramPath, 0666);
        }
    }
}

// ---------- HELPERS ----------
function read_json($file)
{
    if (!is_readable($file))
        return [];
    $raw = @file_get_contents($file);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function write_json_atomic($file, $data)
{
    $tmp = $file . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $fp = @fopen($tmp, 'wb');
    if (!$fp)
        return ['error' => "Cannot open $tmp"];

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return ['error' => "Cannot lock $tmp"];
    }

    $res = fwrite($fp, $json);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($res === false) {
        @unlink($tmp);
        return ['error' => "Write failed"];
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return ['error' => "Rename failed (Permissions?)"];
    }

    @chmod($file, 0666);
    return true;
}

function hmToMinutes($hm)
{
    $parts = explode(':', $hm);
    return (int) ($parts[0] ?? 0) * 60 + (int) ($parts[1] ?? 0);
}

function computeAutoSetpoint($schedule, $manualSetpoint)
{
    $now = new DateTime();
    $dayIndex = ((int) $now->format('w') + 6) % 7;
    if (empty($schedule[$dayIndex]['slots']))
        return (float) $manualSetpoint;

    $slots = $schedule[$dayIndex]['slots'];
    usort($slots, fn($a, $b) => strcmp($a['time'] ?? '', $b['time'] ?? ''));

    $minutes = intval($now->format('G')) * 60 + intval($now->format('i'));
    $chosen = $slots[0];
    foreach ($slots as $s) {
        if (isset($s['time']) && hmToMinutes($s['time']) <= $minutes)
            $chosen = $s;
    }
    return (float) ($chosen['setpoint'] ?? $manualSetpoint);
}

function history_append_if_due($file, $temp, $minDelta = 600, $keep = 172800)
{
    $now = time();
    if (@filemtime($file) > $now - $minDelta)
        return;

    @file_put_contents($file, "$now," . number_format($temp, 2, '.', '') . "\n", FILE_APPEND);

    // Prune
    $rows = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($rows) {
        $cutoff = $now - $keep;
        $kept = [];
        foreach ($rows as $r) {
            if (((int) explode(',', $r)[0]) >= $cutoff)
                $kept[] = $r;
        }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, implode("\n", $kept) . "\n")) {
            @rename($tmp, $file);
            @chmod($file, 0666);
        }
    }
}

function phone_history_append($file, $val)
{
    $now = time();
    if (@filemtime($file) > $now - 60)
        return;
    @file_put_contents($file, "$now,$val\n", FILE_APPEND);

    // Prune (Keep 24h)
    $rows = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($rows) {
        $cutoff = $now - 86400;
        $kept = [];
        foreach ($rows as $r) {
            if (((int) explode(',', $r)[0]) >= $cutoff)
                $kept[] = $r;
        }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, implode("\n", $kept) . "\n")) {
            @rename($tmp, $file);
            @chmod($file, 0666);
        }
    }
}

// ---------- LOGIC ----------

$state = read_json($stateFile);
$scheduleWrap = read_json($scheduleFile);
$schedule = $scheduleWrap['schedule'] ?? [];

$mode = $state['mode'] ?? 'AUTO';
$manualSetpoint = (float) ($state['manualSetpoint'] ?? 20.0);
$actualTemp = isset($state['actualTemp']) ? round((float) $state['actualTemp'], 1) : null;
$cald = (int) ($state['cald'] ?? 0);
$phone = (int) ($state['phone'] ?? 0);
$real = isset($state['real']) ? round((float) $state['real'], 1) : null;

// Handle Updates
$tempParam = $_GET['temp'] ?? $_POST['temp'] ?? null;
$caldParam = $_GET['cald'] ?? $_POST['cald'] ?? null;
$phoneParam = $_GET['phone'] ?? $_POST['phone'] ?? null;
$realParam = $_GET['real'] ?? $_POST['real'] ?? null;

$updated = false;

if ($tempParam !== null) {
    $actualTemp = round((float) $tempParam, 1);
    $state['actualTemp'] = $actualTemp;
    history_append_if_due($historyFile, $actualTemp);
    $updated = true;
}
if ($caldParam !== null) {
    $cald = ((int) $caldParam === 1) ? 1 : 0;
    $state['cald'] = $cald;
    $updated = true;
}
if ($phoneParam !== null) {
    $phone = ((int) $phoneParam === 1) ? 1 : 0;
    $state['phone'] = $phone;
    phone_history_append($phoneHistoryFile, $phone);
    $updated = true;
}
if ($realParam !== null) {
    $real = round((float) $realParam, 1);
    $state['real'] = $real;
    $updated = true;
}

if ($updated) {
    $res = write_json_atomic($stateFile, $state);
    if ($res !== true) {
        echo json_encode(['ok' => false, 'error' => $res['error']]);
        exit;
    }
}

// Calculate Setpoint
if ($mode === 'OFF')
    $setpoint = null;
elseif ($mode === 'ON')
    $setpoint = $manualSetpoint;
else
    $setpoint = computeAutoSetpoint($schedule, $manualSetpoint);

// Response
$now = new DateTime();
echo json_encode([
    'ok' => true,
    'debug_storage' => $ramDir, // <--- Verify path here
    'mode' => $mode,
    'setpoint' => $setpoint,
    'actualTemp' => $actualTemp,
    'actualTemp_str' => ($actualTemp !== null) ? number_format($actualTemp, 1, '.', '') : null,
    'real' => $real,
    'cald' => $cald,
    'phone' => $phone,
    'date' => $now->format('Y-m-d'),
    'time' => $now->format('H:i:s'),
    'timezone' => $now->getTimezone()->getName()
], JSON_UNESCAPED_SLASHES);
?>