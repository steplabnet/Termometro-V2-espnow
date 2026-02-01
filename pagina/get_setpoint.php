<?php
// get_setpoint.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

@ini_set('precision', 14);
@ini_set('serialize_precision', 10);

date_default_timezone_set('Europe/Rome');

// ---------- CONFIGURATION ----------

// 1. RAM STORAGE
$ramDir = '/dev/shm/thermo_data';

// 2. DISK BACKUPS
$stateBackup        = __DIR__ . '/state.json';
$scheduleBackup     = __DIR__ . '/schedule.json';
$historyBackup      = __DIR__ . '/temp_history.csv';
$humiHistoryBackup  = __DIR__ . '/humi_history.csv'; // Added
$presHistoryBackup  = __DIR__ . '/pres_history.csv'; // Added
$phoneHistoryBackup = __DIR__ . '/phone_history.csv';

// ---------- INIT & CHECKS ----------

if (!is_dir($ramDir)) {
    @mkdir($ramDir, 0777, true);
    @chmod($ramDir, 0777);
}

$stateFile        = $ramDir . '/state.json';
$scheduleFile     = $ramDir . '/schedule.json';
$historyFile      = $ramDir . '/temp_history.csv';
$humiHistoryFile  = $ramDir . '/humi_history.csv'; // Added
$presHistoryFile  = $ramDir . '/pres_history.csv'; // Added
$phoneHistoryFile = $ramDir . '/phone_history.csv';

// ---------- STARTUP SYNC (Disk -> RAM) ----------
$filesToSync = [
    $stateFile        => $stateBackup,
    $scheduleFile     => $scheduleBackup,
    $historyFile      => $historyBackup,
    $humiHistoryFile  => $humiHistoryBackup, // Added
    $presHistoryFile  => $presHistoryBackup, // Added
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

function read_json($file) {
    if (!is_readable($file)) return [];
    $raw = @file_get_contents($file);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function write_json_atomic($file, $data, $backupPath) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $json) !== false) {
        rename($tmp, $file);
        chmod($file, 0666);
        copy($file, $backupPath);
        return true;
    }
    return false;
}

function hmToMinutes($hm) {
    $parts = explode(':', $hm);
    return (int)($parts[0] ?? 0) * 60 + (int)($parts[1] ?? 0);
}

function computeAutoSetpoint($schedule, $manualSetpoint) {
    $now = new DateTime();
    $dayIndex = ((int)$now->format('w') + 6) % 7;
    if (empty($schedule[$dayIndex]['slots'])) return (float)$manualSetpoint;
    $slots = $schedule[$dayIndex]['slots'];
    usort($slots, fn($a, $b) => strcmp($a['time'] ?? '', $b['time'] ?? ''));
    $minutes = intval($now->format('G')) * 60 + intval($now->format('i'));
    $chosen = $slots[0];
    foreach ($slots as $s) {
        if (isset($s['time']) && hmToMinutes($s['time']) <= $minutes) $chosen = $s;
    }
    return (float)($chosen['setpoint'] ?? $manualSetpoint);
}

// Generic function to append history and keep backups in sync
function append_history_log($file, $backup, $val, $minDelta = 600) {
    $now = time();
    // Only log if the file hasn't been updated in the last 10 minutes (600s)
    if (file_exists($file) && filemtime($file) > $now - $minDelta) return;
    
    $line = "$now," . number_format($val, 2, '.', '') . "\n";
    file_put_contents($file, $line, FILE_APPEND);
    
    // Periodically sync the CSV to disk backup
    copy($file, $backup);
}

function phone_history_append($file, $backup, $val) {
    $now = time();
    if (file_exists($file) && filemtime($file) > $now - 60) return;
    file_put_contents($file, "$now,$val\n", FILE_APPEND);
    copy($file, $backup);
}

// ---------- LOGIC ----------

$state = read_json($stateFile);
$scheduleWrap = read_json($scheduleFile);
$schedule = $scheduleWrap['schedule'] ?? [];

$mode = $state['mode'] ?? 'AUTO';
$manualSetpoint = (float)($state['manualSetpoint'] ?? 20.0);

// Incoming Parameters
$tempParam  = $_GET['temp']  ?? $_POST['temp']  ?? null;
$humiParam  = $_GET['humi']  ?? $_POST['humi']  ?? null;
$presParam  = $_GET['pres']  ?? $_POST['pres']  ?? null;
$caldParam  = $_GET['cald']  ?? $_POST['cald']  ?? null;
$phoneParam = $_GET['phone'] ?? $_POST['phone'] ?? null;
$realParam  = $_GET['real']  ?? $_POST['real']  ?? null;

$updated = false;

// 1. Process Temperature
if ($tempParam !== null) {
    $val = round((float)$tempParam, 1);
    $state['actualTemp'] = $val;
    append_history_log($historyFile, $historyBackup, $val);
    $updated = true;
}

// 2. Process Humidity
if ($humiParam !== null) {
    $val = round((float)$humiParam, 1);
    $state['humi'] = $val;
    append_history_log($humiHistoryFile, $humiHistoryBackup, $val);
    $updated = true;
}

// 3. Process Pressure
if ($presParam !== null) {
    $val = round((float)$presParam, 1);
    $state['pres'] = $val;
    append_history_log($presHistoryFile, $presHistoryBackup, $val);
    $updated = true;
}

// 4. Process Heater
if ($caldParam !== null) {
    $state['cald'] = ((int)$caldParam === 1) ? 1 : 0;
    $updated = true;
}

// 5. Process Phone
if ($phoneParam !== null) {
    $state['phone'] = ((int)$phoneParam === 1) ? 1 : 0;
    phone_history_append($phoneHistoryFile, $phoneHistoryBackup, $state['phone']);
    $updated = true;
}

// 6. Process Real active setpoint
if ($realParam !== null) {
    $state['real'] = round((float)$realParam, 1);
    $updated = true;
}

if ($updated) {
    write_json_atomic($stateFile, $state, $stateBackup);
}

// Calculate target for ESP32
if ($mode === 'OFF') $targetSetpoint = 7.0;
elseif ($mode === 'ON') $targetSetpoint = $manualSetpoint;
else $targetSetpoint = computeAutoSetpoint($schedule, $manualSetpoint);

// ---------- RESPONSE ----------

$now = new DateTime();
echo json_encode([
    'ok'             => true,
    'mode'           => $mode,
    'setpoint'       => $targetSetpoint,
    'actualTemp'     => $state['actualTemp'] ?? null,
    'humi'           => $state['humi'] ?? null,
    'pres'           => $state['pres'] ?? null,
    'real'           => $state['real'] ?? null,
    'cald'           => $state['cald'] ?? 0,
    'phone'          => $state['phone'] ?? 0,
    'server_time'    => $now->format('H:i:s')
], JSON_UNESCAPED_SLASHES);