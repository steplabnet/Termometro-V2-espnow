<?php
// get_setpoint.php
// - Reads setPoint from state.json: { "setPoint": 20, "actualTemp": 20, "cald": 1 }
// - Optional ?temp= updates state.json.actualTemp (rounded to 1 decimal)
// - Optional ?cald=0|1 updates state.json.cald
// - Returns JSON: { ok, setpoint, actualTemp, actualTemp_str, cald, date, time, timezone }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // allow microcontrollers / other origins

@ini_set('precision', 14);
@ini_set('serialize_precision', 10);

date_default_timezone_set('Europe/Rome');

$stateFile = __DIR__ . '/state.json';

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

function one_decimal_str($n)
{
    return number_format((float) $n, 1, '.', '');
}

// ---------- load state ----------
$state = read_json($stateFile);

// Defaults if file missing/invalid
$setPoint = isset($state['setPoint']) ? (float) $state['setPoint'] : 20.0;
$actualTemp = isset($state['actualTemp']) ? (float) $state['actualTemp'] : null;
$cald = isset($state['cald']) ? (int) $state['cald'] : 0;

// ---------- optional updates from parameters ----------
$updated = false;

$tempParam = $_GET['temp'] ?? $_POST['temp'] ?? null;
if ($tempParam !== null) {
    $actualTemp = round((float) $tempParam, 1);
    $state['actualTemp'] = $actualTemp;
    $updated = true;
}

$caldParam = $_GET['cald'] ?? $_POST['cald'] ?? null;
if ($caldParam !== null) {
    $cald = ((int) $caldParam === 1) ? 1 : 0; // force 0 or 1
    $state['cald'] = $cald;
    $updated = true;
}

// Ensure setPoint key exists in the file (initialize once if needed)
if (!isset($state['setPoint'])) {
    $state['setPoint'] = $setPoint;
    $updated = true;
}

// Save back if anything changed
if ($updated) {
    $okDisk = write_json_atomic($stateFile, $state);
    if (!$okDisk) {
        echo json_encode(['ok' => false, 'error' => 'Failed to write state.json (permissions?)']);
        exit;
    }
}

// ---------- normalize numbers for output ----------
$actualTemp_num = ($actualTemp !== null) ? (float) one_decimal_str($actualTemp) : null;
$actualTemp_str = ($actualTemp !== null) ? one_decimal_str($actualTemp) : null;

// ---------- respond ----------
$now = new DateTime();

echo json_encode([
    'ok' => true,
    'setpoint' => $setPoint,      // read directly from state.json (key: setPoint)
    'actualTemp' => $actualTemp_num,
    'actualTemp_str' => $actualTemp_str,
    'cald' => $cald,
    'date' => $now->format('Y-m-d'),
    'time' => $now->format('H:i:s'),
    'timezone' => $now->getTimezone()->getName()
], JSON_UNESCAPED_SLASHES);
