<?php
declare(strict_types=1);

/**
 * ingest.php — the MQTT bridge's way into the database.
 *
 * mqtt_ingest.py holds the subscription to casa/stazionemeteo and POSTs each
 * message here as JSON, authenticated with the shared secret in
 * ingest_secret.php. All the actual storage rules (live row every reading,
 * history row every 10 minutes, RAM snapshot, cached Piave flow) live in
 * store_lib.php, which carica_dati.php uses too.
 *
 *   POST /ingest.php
 *   X-Ingest-Token: <secret>
 *   Content-Type: application/json
 *   {"temp": 12.3, "tombra": 11.8, ...}
 */

date_default_timezone_set('Europe/Rome');

// The bridge gives up on a slow response and retries with a newer reading;
// finish the store anyway rather than leaving it half applied.
ignore_user_abort(true);

require_once __DIR__ . '/connessione.php'; // defines $link
require_once __DIR__ . '/store_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Answer and stop. */
function ingest_fail(int $status, string $message): void
{
  http_response_code($status);
  echo json_encode(['ok' => false, 'error' => $message]);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  ingest_fail(405, 'POST only');
}

$expected = require __DIR__ . '/ingest_secret.php';
$token = $_SERVER['HTTP_X_INGEST_TOKEN'] ?? '';
// hash_equals: constant time, so a wrong token cannot be found byte by byte.
if (!is_string($expected) || $expected === '' || !hash_equals($expected, (string) $token)) {
  ingest_fail(403, 'bad token');
}

$raw = (string) file_get_contents('php://input');
$msg = json_decode($raw, true);
if (!is_array($msg)) {
  ingest_fail(400, 'body is not a JSON object');
}

if (!$link instanceof mysqli) {
  ingest_fail(503, 'database unavailable');
}

// The station's MQTT payload already uses the field names the storage layer
// expects; only the ones below are actually persisted. Anything else in the
// message (adc_raw, station_id, timestamp, ...) is diagnostic and ignored here.
$fields = [
  'temp', 'humi', 'wind', 'rain', 'pres', 'chip', 'gust',
  'tombra', 'hombra', 'tMobile', 'tempCpu', 'fan', 'power',
  'pvPower', 'gridPower',
  // Marstek Venus E: power (+ charging), state of charge, hottest cell, and
  // the moment the battery itself was read -- battTs is what lets the
  // dashboard tell a live reading from one left behind by a bridge that
  // stopped.
  'battPower', 'battSoc', 'battTemp', 'battTs',
  // Diagnostics: every temperature, voltage and current the pack reports.
  // Live row only -- see BATTERY_LIVE_FIELDS in store_lib.php.
  'battTempMin', 'battTempInt', 'battTempMos1', 'battTempMos2',
  'battVolt', 'battCurr', 'battCellVMax', 'battCellVMin',
  'battAcV', 'battAcHz', 'battAcW',
];
$reading = [];
foreach ($fields as $f) {
  if (array_key_exists($f, $msg) && $msg[$f] !== null) {
    $reading[$f] = $msg[$f];
  }
}

if (!$reading) {
  ingest_fail(400, 'no usable fields');
}

try {
  $result = meteo_store_reading($link, $reading);
} catch (Throwable $e) {
  error_log('ingest failed: ' . $e->getMessage());
  ingest_fail(500, 'store failed');
}

echo json_encode([
  'ok' => true,
  'history' => $result['history'],   // true when a 10-minute row was written
  'rev' => $result['rev'],           // snapshot revision the browsers will see
]);
