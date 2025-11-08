<?php
// get_setpoint.php
// - Optional ?temp= updates state.json.actualTemp (rounded to 1 decimal)
// - Optional ?cald=0|1 updates state.json.cald (relay status)
// - Reads/Writes state.json from the same directory as this script
// - Also logs temperature to temp_history.csv if last modification > 10 minutes
// - Returns JSON: { ok, mode, setpoint, actualTemp, actualTemp_str, cald, date, time, timezone }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // allow microcontrollers / other origins

@ini_set('precision', 14);
@ini_set('serialize_precision', 10);

date_default_timezone_set('Europe/Rome');

// Disk paths (same folder as this file)
$stateFile = __DIR__ . '/state.json';
$scheduleFile = __DIR__ . '/schedule.json';
$historyFile = __DIR__ . '/temp_history.csv';   // <= CSV log here

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

/**
 * Append "unix_ts,temperature" to $historyFile if its last modification
 * was more than $minDelta seconds ago. Also prunes rows older than $keepSec.
 */
function history_append_if_due(string $historyFile, float $temp, int $minDelta = 600, int $keepSec = 172800): void
{
    $now = time();
    $due = true;
    $mtime = @filemtime($historyFile);
    if ($mtime !== false && ($now - $mtime) < $minDelta) {
        $due = false;
    }

    if ($due) {
        // Append new sample
        @file_put_contents($historyFile, $now . ',' . number_format($temp, 2, '.', '') . "\n", FILE_APPEND);

        // Prune > keepSec old
        $cutoff = $now - $keepSec;
        $rows = @file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($rows !== false) {
            $kept = [];
            foreach ($rows as $r) {
                $parts = explode(',', $r, 2);
                if (!count($parts))
                    continue;
                $ts = (int) $parts[0];
                if ($ts >= $cutoff)
                    $kept[] = $r;
            }
            // Atomic-ish rewrite
            $tmp = $historyFile . '.tmp';
            if (@file_put_contents($tmp, implode("\n", $kept) . (count($kept) ? "\n" : '')) !== false) {
                @rename($tmp, $historyFile);
            }
        }
    }
}

// ---------- load state & schedule ----------
$state = read_json($stateFile);
$scheduleWrap = read_json($scheduleFile);
$schedule = isset($scheduleWrap['schedule']) && is_array($scheduleWrap['schedule'])
    ? $scheduleWrap['schedule'] : [];

$mode = $state['mode'] ?? 'AUTO';
$manualSetpoint = isset($state['manualSetpoint']) ? (float) $state['manualSetpoint'] : 20.0;
$actualTemp = isset($state['actualTemp']) ? round((float) $state['actualTemp'], 1) : null;
$cald = isset($state['cald']) ? (int) $state['cald'] : 0;

// ---------- optional updates from parameters ----------
$tempParam = $_GET['temp'] ?? $_POST['temp'] ?? null;
$caldParam = $_GET['cald'] ?? $_POST['cald'] ?? null;

$updated = false;
if ($tempParam !== null) {
    $newTemp = round((float) $tempParam, 1); // store with 1 decimal
    $state['actualTemp'] = $newTemp;
    $actualTemp = $newTemp;
    $updated = true;

    // --- NEW: log to history only if last modification > 10 minutes ---
    history_append_if_due($historyFile, (float) $newTemp, 600, 172800);
}
if ($caldParam !== null) {
    $newCald = ((int) $caldParam === 1) ? 1 : 0;
    $state['cald'] = $newCald;
    $cald = $newCald;
    $updated = true;
}

// Save back if we updated anything
if ($updated) {
    $okDisk = write_json_atomic($stateFile, $state);
    if (!$okDisk) {
        echo json_encode(['ok' => false, 'error' => 'Failed to write state.json (permissions?)']);
        exit;
    }
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
$actualTemp_num = ($actualTemp !== null) ? (float) one_decimal_str($actualTemp) : null;
$actualTemp_str = ($actualTemp !== null) ? one_decimal_str($actualTemp) : null;

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
