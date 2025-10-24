<?php
// get_setpoint.php
// - Optional ?temp= updates state.json.actualTemp (rounded to 1 decimal)
// - Optional ?cald=0|1 updates state.json.cald (relay status)
// - Reads state from /dev/shm/state.json when available (fallback to script dir)
// - Writes to disk state.json and mirrors to /dev/shm/state.json best-effort
// - Returns JSON: { ok, mode, setpoint, actualTemp, actualTemp_str, cald, date, time, timezone }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // allow microcontrollers / other origins

// Keep floats sane when json_encode() prints them
@ini_set('precision', 14);
@ini_set('serialize_precision', 10);

// ---------- timezone ----------
date_default_timezone_set('Europe/Rome');

// Disk + SHM paths
$stateFileDisk = __DIR__ . '/state.json';
$stateFileShm = '/dev/shm/state.json';
// Prefer SHM for reads if available; always keep disk as the source of truth for errors
$stateFileRead = is_readable($stateFileShm) ? $stateFileShm : $stateFileDisk;

$scheduleFile = __DIR__ . '/schedule.json';

// ---------- helpers ----------
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
    if ($json === false)
        return false;

    $fp = @fopen($tmp, 'wb');
    if (!$fp)
        return false;

    @flock($fp, LOCK_EX);
    $ok = fwrite($fp, $json) !== false;
    @flock($fp, LOCK_UN);
    @fclose($fp);

    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    return @rename($tmp, $file);
}

function hmToMinutes($hm)
{
    $parts = explode(':', $hm);
    $h = isset($parts[0]) ? intval($parts[0]) : 0;
    $m = isset($parts[1]) ? intval($parts[1]) : 0;
    return $h * 60 + $m;
}

function computeAutoSetpoint($schedule, $manualSetpoint)
{
    $now = new DateTime();
    $dayIndex = ((int) $now->format('w') + 6) % 7; // Monday=0
    if (!isset($schedule[$dayIndex]['slots']) || !is_array($schedule[$dayIndex]['slots']) || !count($schedule[$dayIndex]['slots'])) {
        return (float) $manualSetpoint;
    }
    $slots = $schedule[$dayIndex]['slots'];
    usort($slots, fn($a, $b) => strcmp($a['time'] ?? '', $b['time'] ?? ''));
    $minutes = intval($now->format('G')) * 60 + intval($now->format('i'));
    $chosen = $slots[0];
    foreach ($slots as $s) {
        if (isset($s['time']) && hmToMinutes($s['time']) <= $minutes)
            $chosen = $s;
    }
    return isset($chosen['setpoint']) ? (float) $chosen['setpoint'] : (float) $manualSetpoint;
}

function one_decimal_str($n)
{
    return number_format((float) $n, 1, '.', '');
}

// ---------- load state & schedule ----------
$state = read_json($stateFileRead);
$scheduleWrap = read_json($scheduleFile);
$schedule = isset($scheduleWrap['schedule']) && is_array($scheduleWrap['schedule'])
    ? $scheduleWrap['schedule'] : [];

$mode = isset($state['mode']) ? $state['mode'] : 'AUTO';
$manualSetpoint = isset($state['manualSetpoint']) ? (float) $state['manualSetpoint'] : 20.0;
$actualTemp = isset($state['actualTemp']) ? round((float) $state['actualTemp'], 1) : null;
$cald = isset($state['cald']) ? (int) $state['cald'] : 0;

// ---------- optional updates from parameters ----------
$tempParam = $_GET['temp'] ?? $_POST['temp'] ?? null;
if ($tempParam !== null) {
    $newTemp = round((float) $tempParam, 1); // store with 1 decimal
    $state['actualTemp'] = $newTemp;
    $actualTemp = $newTemp;
}

$caldParam = $_GET['cald'] ?? $_POST['cald'] ?? null;
if ($caldParam !== null) {
    $newCald = ((int) $caldParam === 1) ? 1 : 0; // force 0 or 1
    $state['cald'] = $newCald;
    $cald = $newCald;
}

// Save back if we updated anything
if ($tempParam !== null || $caldParam !== null) {
    // Write to disk first (source of truth)
    $okDisk = write_json_atomic($stateFileDisk, $state);
    if (!$okDisk) {
        echo json_encode(['ok' => false, 'error' => 'Failed to write state.json on disk (permissions?)']);
        exit;
    }
    // Mirror to SHM (best-effort; do not fail the request if it can’t be written)
    @write_json_atomic($stateFileShm, $state);
}

// ---------- compute current setpoint ----------
if ($mode === 'OFF') {
    $setpoint = null;
} elseif ($mode === 'ON') {
    $setpoint = $manualSetpoint;
} else { // AUTO
    $setpoint = computeAutoSetpoint($schedule, $manualSetpoint);
}

// ---------- normalize numbers for output ----------
$actualTemp_num = ($actualTemp !== null) ? (float) one_decimal_str($actualTemp) : null; // numeric 1-decimal
$actualTemp_str = ($actualTemp !== null) ? one_decimal_str($actualTemp) : null;         // string  1-decimal

// ---------- respond ----------
$now = new DateTime();

echo json_encode([
    'ok' => true,
    'mode' => $mode,
    'setpoint' => $setpoint,
    'actualTemp' => $actualTemp_num,
    'actualTemp_str' => $actualTemp_str,
    'cald' => $cald,
    'date' => $now->format('Y-m-d'),
    'time' => $now->format('H:i:s'),
    'timezone' => $now->getTimezone()->getName()
], JSON_UNESCAPED_SLASHES);
