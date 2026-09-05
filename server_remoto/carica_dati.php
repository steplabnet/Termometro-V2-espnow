<?php
declare(strict_types=1);

/**
 * carica_dati.php — legacy HTTP GET entry point for one weather reading.
 *
 * The Raspberry now publishes over MQTT and its readings arrive through
 * ingest.php instead, but this endpoint is kept working for anything still
 * calling it (and as a way to push a reading by hand). Both doors share
 * store_lib.php, so the storage rules can only ever be defined once.
 *
 *   GET carica_dati.php?temp=12.3&tombra=11.8&hombra=64&power=430&battSoc=70&...
 */

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/connessione.php'; // defines $link
require_once __DIR__ . '/store_lib.php';

if (!$link instanceof mysqli) {
  http_response_code(503);
  exit('database unavailable');
}

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
  // Shelly Pro EM-50 clamp detail: voltage, current and power factor per
  // clamp plus the mains frequency. Live row only -- see EM_DETAIL_FIELDS.
  'emPvV', 'emPvA', 'emPvPf', 'emGridV', 'emGridA', 'emGridPf', 'emHz',
];

$reading = [];
foreach ($fields as $f) {
  // Absent parameters stay absent: store_lib.php leaves those columns alone
  // rather than overwriting a good value with an empty one.
  if (isset($_GET[$f]) && is_scalar($_GET[$f]) && $_GET[$f] !== '') {
    $reading[$f] = $_GET[$f];
  }
}

// `pres` has always been stored rounded to whole hPa.
if (isset($reading['pres']) && is_numeric($reading['pres'])) {
  $reading['pres'] = round((float) $reading['pres']);
}

if (!$reading) {
  http_response_code(400);
  exit('no usable parameters');
}

$result = meteo_store_reading($link, $reading);

header('Content-Type: text/plain; charset=utf-8');
echo $result['history'] ? "stored (history row written)\n" : "stored (live row only)\n";
echo "portata={$result['portata']} rev={$result['rev']}\n";
