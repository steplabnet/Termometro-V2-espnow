<?php
// get_setpoint.php
//
// INPUTS (GET or POST):
// - ?temp=FLOAT   : Updates current temp. 
//                   Logs to 'temp_history.csv' (Throttled: max 1 write/10mins, Keeps 48h).
// - ?cald=0|1     : Updates boiler relay status.
// - ?phone=0|1    : Updates phone state. 
//                   Logs to 'phone_history.csv' (Immediate: logs every request, Keeps 24h).
//
// OPERATIONS:
// - Reads/Writes 'state.json' (current status).
// - Reads 'schedule.json' (weekly program) to calculate Auto Setpoint.
//
// RETURNS (JSON):
// { 
//   "ok": true, 
//   "mode": "AUTO|ON|OFF", 
//   "setpoint": 20.0, 
//   "actualTemp": 19.5, 
//   "cald": 1, 
//   "phone": 0,
//   "date": "YYYY-MM-DD", 
//   "time": "HH:MM:SS", ... 
// }


// ... rest of script
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

@ini_set('precision', 14);
@ini_set('serialize_precision', 10);

date_default_timezone_set('Europe/Rome');

// Disk paths
$stateFile = __DIR__ . '/state.json';
$scheduleFile = __DIR__ . '/schedule.json';
$historyFile = __DIR__ . '/temp_history.csv';
$phoneHistoryFile = __DIR__ . '/phone_history.csv'; // <--- NEW LOG FILE

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
    $dayIndex = ((int) $now->format('w') + 6) % 7;
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
 * Temp History: Prunes older than $keepSec (default 48h)
 * Throttles writes ($minDelta)
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
        @file_put_contents($historyFile, $now . ',' . number_format($temp, 2, '.', '') . "\n", FILE_APPEND);

        // Prune
        $cutoff = $now - $keepSec;
        $rows = @file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($rows !== false) {
            $kept = [];
            foreach ($rows as $r) {
                $parts = explode(',', $r, 2);
                if (!count($parts))
                    continue;
                if ((int) $parts[0] >= $cutoff)
                    $kept[] = $r;
            }
            $tmp = $historyFile . '.tmp';
            if (@file_put_contents($tmp, implode("\n", $kept) . (count($kept) ? "\n" : '')) !== false) {
                @rename($tmp, $historyFile);
            }
        }
    }
}

/**
 * Phone History: Log NOW, prune older than 24h (86400 sec)
 */
/**
 * Phone History: Log ONCE PER MINUTE, prune older than 24h (86400 sec)
 */
function phone_history_append(string $file, int $val): void
{
    $now = time();
    $keepSec = 86400; // 24 Hours
    $minDelta = 60;   // 60 Seconds throttle

    // 1. Check Throttling (Don't write if written recently)
    $mtime = @filemtime($file);
    if ($mtime !== false && ($now - $mtime) < $minDelta) {
        return; // Exit function, do not save
    }

    // 2. Append new value
    @file_put_contents($file, $now . ',' . $val . "\n", FILE_APPEND);

    // 3. Prune old values
    $cutoff = $now - $keepSec;
    $rows = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // Only rewrite if we actually have data to check
    if ($rows !== false && count($rows) > 0) {
        $kept = [];
        $rewriteNeeded = false;

        foreach ($rows as $r) {
            $parts = explode(',', $r, 2);
            if (!count($parts))
                continue;

            $ts = (int) $parts[0];
            if ($ts >= $cutoff) {
                $kept[] = $r;
            } else {
                $rewriteNeeded = true; // Found an old row, so we need to save the cleaned list
            }
        }

        // Optimization: only write to disk if we actually removed something
        if ($rewriteNeeded) {
            $tmp = $file . '.tmp';
            $content = implode("\n", $kept) . (count($kept) ? "\n" : '');
            if (@file_put_contents($tmp, $content) !== false) {
                @rename($tmp, $file);
            }
        }
    }
}

// ---------- load state & schedule ----------
$state = read_json($stateFile);
$scheduleWrap = read_json($scheduleFile);
$schedule = isset($scheduleWrap['schedule']) && is_array($scheduleWrap['schedule']) ? $scheduleWrap['schedule'] : [];

$mode = $state['mode'] ?? 'AUTO';
$manualSetpoint = isset($state['manualSetpoint']) ? (float) $state['manualSetpoint'] : 20.0;
$actualTemp = isset($state['actualTemp']) ? round((float) $state['actualTemp'], 1) : null;
$cald = isset($state['cald']) ? (int) $state['cald'] : 0;
// Load phone state (default to 0 if missing)
$phone = isset($state['phone']) ? (int) $state['phone'] : 0;

// ---------- optional updates ----------
$tempParam = $_GET['temp'] ?? $_POST['temp'] ?? null;
$caldParam = $_GET['cald'] ?? $_POST['cald'] ?? null;
$phoneParam = $_GET['phone'] ?? $_POST['phone'] ?? null; // <--- Check Param

$updated = false;

// 1. Handle Temp
if ($tempParam !== null) {
    $newTemp = round((float) $tempParam, 1);
    $state['actualTemp'] = $newTemp;
    $actualTemp = $newTemp;
    $updated = true;
    // Log temp (throttled 10 mins, keep 48h)
    history_append_if_due($historyFile, (float) $newTemp, 600, 172800);
}

// 2. Handle Cald (Relay)
if ($caldParam !== null) {
    $newCald = ((int) $caldParam === 1) ? 1 : 0;
    $state['cald'] = $newCald;
    $cald = $newCald;
    $updated = true;
}

// 3. Handle Phone (NEW)
if ($phoneParam !== null) {
    $newPhone = ((int) $phoneParam === 1) ? 1 : 0;
    $state['phone'] = $newPhone;
    $phone = $newPhone;
    $updated = true;

    // Log phone (Log now, keep 24h)
    phone_history_append($phoneHistoryFile, $newPhone);
}

// Save state.json
if ($updated) {
    $okDisk = write_json_atomic($stateFile, $state);
    if (!$okDisk) {
        echo json_encode(['ok' => false, 'error' => 'Failed to write state.json']);
        exit;
    }
}

// ---------- compute setpoint ----------
if ($mode === 'OFF') {
    $setpoint = null;
} elseif ($mode === 'ON') {
    $setpoint = $manualSetpoint;
} else {
    $setpoint = computeAutoSetpoint($schedule, $manualSetpoint);
}

// ---------- normalize numbers ----------
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
    'phone' => $phone, // <--- Return current phone state
    'date' => $now->format('Y-m-d'),
    'time' => $now->format('H:i:s'),
    'timezone' => $now->getTimezone()->getName()
], JSON_UNESCAPED_SLASHES);
?>