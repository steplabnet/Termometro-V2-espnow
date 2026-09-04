<?php
declare(strict_types=1);

/**
 * Builds the dashboard payload: every number, label, colour and icon shown on
 * index.php, computed once and rendered-ready.
 *
 * This used to live inline at the top of index.php. It moved here so the exact
 * same values feed three consumers:
 *   - index.php       -> first paint
 *   - carica_dati.php -> writes the snapshot to /dev/shm on every Pi upload
 *   - live.php        -> long-poll endpoint the browser patches the DOM from
 * One builder means the AJAX refresh can never drift from the server render.
 */

require_once __DIR__ . '/instant_store.php';

/** PV production under this many watts is noise, not production. */
// define() rather than const: grafico.php declares the same constant, and the
// two files must stay includable together.
defined('PV_ZERO_THRESHOLD') || define('PV_ZERO_THRESHOLD', 10.0);

/** Battery power under this many watts is the pack feeding its own
 * electronics, not charging or discharging. Same deadband as IDLE_W in the
 * Pi's batteria.php, so the two dashboards never disagree about "in attesa". */
defined('BATT_IDLE_W') || define('BATT_IDLE_W', 15.0);

/** A battery reading older than this is not used: see battTs in store_lib.php. */
defined('BATT_MAX_AGE') || define('BATT_MAX_AGE', 300);

/**
 * Battery diagnostics: three temperatures, four voltages, a current, plus the
 * AC power the derived AC current is computed from.
 *
 * These started as live-row-only fields -- read by one card, charted by
 * nothing. They are now written to `dati_meteo` as well, because every one of
 * them is plottable from the diagnostics card and a chart needs a history to
 * draw. Rows logged before that are simply NULL here and show as gaps.
 *
 * Defined here rather than in store_lib.php because both files need it and
 * only this one is included by index.php.
 */
defined('BATTERY_DIAG_FIELDS') || define('BATTERY_DIAG_FIELDS', [
  'battTempMin', 'battTempInt', 'battTempMos1', 'battTempMos2',
  'battVolt', 'battCurr', 'battCellVMax', 'battCellVMin',
  'battAcV', 'battAcHz', 'battAcW',
]);

/** Usable capacity of the pack, kWh — only to turn SoC into a "residuo".
 * 5.12 kWh is what the Venus E 3.0 reports as its rated capacity. */
defined('BATT_CAPACITY_KWH') || define('BATT_CAPACITY_KWH', 5.12);

/** ---------- FORMATTING HELPERS ---------- */

function fmt($val, $decimals = 1, $default = '--')
{
  return (isset($val) && $val !== null && $val !== '' && (float) $val > -99) ? round((float) $val, $decimals) : $default;
}

/** Format a wattage as W below 1 kW and kW above, so cards stay readable. */
function fmtW($val, $default = '--')
{
  if ($val === null) {
    return $default;
  }
  $val = (float) $val;
  return (abs($val) >= 1000) ? number_format($val / 1000, 2) . ' kW' : round($val) . ' W';
}

function calculateTrend($current, $old)
{
  if ($current === null || $old === null || $current <= -50)
    return null;
  $val = ($current - (float) $old) * 2;
  return (abs($val) > 20) ? null : $val;
}

function getFreezingTime($currentTemp, $trendPerHour)
{
  if ($currentTemp === null || $trendPerHour === null || $currentTemp <= -50)
    return null;
  if ($currentTemp > 0 && $trendPerHour < -0.1) {
    $hoursToZero = $currentTemp / abs((float) $trendPerHour);
    $targetTime = time() + (int) ($hoursToZero * 3600);
    $limitTime = strtotime('tomorrow 06:00');
    if ($targetTime < $limitTime)
      return date('H:i', $targetTime);
  }
  return null;
}

function getTrendHtml($val, $unit = '°C/h', $icePrediction = null)
{
  if ($val === null)
    return '<span class="trend-neutral">--</span>';
  $rounded = round($val, 2);
  $sign = ($rounded > 0) ? '+' : '';
  $colorClass = ($rounded > 0) ? 'trend-up' : (($rounded < 0) ? 'trend-down' : 'trend-neutral');
  $arrow = ($rounded > 0) ? '&#8593;' : (($rounded < 0) ? '&#8595;' : '&nbsp;');

  $html = "<span class=\"{$colorClass}\" style=\"display:block; font-weight:700;\">{$arrow} {$sign}{$rounded} <small>{$unit}</small></span>";
  if ($icePrediction) {
    $html .= "<div class=\"ice-prediction\">&#10052; 0°C alle {$icePrediction}</div>";
  }
  return $html;
}

/**
 * Estimate WHEN the forecast change is expected, from the rate of the
 * 3h pressure trend. A steeper drop/rise means the system is closer.
 * Returns '' for stable conditions (nothing to time).
 */
function getForecastTiming(array $forecast, float $presTrend3h): string
{
  $worsening = ['snow', 'snow_storm', 'storm', 'sleet', 'rain'];

  if (in_array($forecast['icon'], $worsening, true)) {
    $drop = abs($presTrend3h);
    if ($drop >= 1.5) {
      $lo = 1;
      $hi = 2;
    }   // very rapid -> imminent
    elseif ($drop >= 0.8) {
      $lo = 2;
      $hi = 4;
    } elseif ($drop >= 0.5) {
      $lo = 4;
      $hi = 8;
    } else {
      $lo = 8;
      $hi = 14;
    }   // slow drift
    $eta = date('H:i', time() + (int) ((($lo + $hi) / 2) * 3600));
    return "tra ~{$lo}-{$hi} h (verso le {$eta})";
  }

  // Clouds building on a falling barometer -> time the expected worsening.
  if ($forecast['text'] === 'Nuvoloso' && $presTrend3h <= -0.3) {
    $drop = abs($presTrend3h);
    if ($drop >= 1.5) {
      $lo = 1;
      $hi = 2;
    } elseif ($drop >= 0.8) {
      $lo = 2;
      $hi = 4;
    } elseif ($drop >= 0.5) {
      $lo = 4;
      $hi = 8;
    } else {
      $lo = 6;
      $hi = 12;
    }
    $eta = date('H:i', time() + (int) ((($lo + $hi) / 2) * 3600));
    return "peggioramento tra ~{$lo}-{$hi} h (verso le {$eta})";
  }

  if ($forecast['text'] === 'In Miglioramento') {
    $rise = abs($presTrend3h);
    if ($rise >= 1.0) {
      $lo = 2;
      $hi = 4;
    } else {
      $lo = 4;
      $hi = 8;
    }
    $eta = date('H:i', time() + (int) ((($lo + $hi) / 2) * 3600));
    return "schiarite tra ~{$lo}-{$hi} h (verso le {$eta})";
  }

  return '';
}

function getTempClass($val)
{
  return ($val < 1 && $val > -99) ? 'freezing' : '';
}

function getPowerClass($val)
{
  return ($val <= 0) ? 'night-mode' : '';
}

/** Open a connection on demand (live.php must not hold one while long-polling). */
function meteo_db(): ?mysqli
{
  require __DIR__ . '/connessione.php'; // defines $link
  return ($link instanceof mysqli) ? $link : null;
}

/**
 * ---------- THE BUILDER ----------
 * Runs every query the dashboard needs and returns a render-ready tree.
 * Everything the browser has to patch on refresh is already a string in here.
 */
function meteo_build_payload(mysqli $link): array
{
  $now = time();

  /** ---------- 1. CALCULATE MIN / MAX (Last 24H) ---------- */
  $time24hAgo = $now - 86400;

  $queryMinMax = "SELECT
    MAX(CASE WHEN (temperatura + 0) > -50 THEN (temperatura + 0) END) as max_temp,
    MIN(CASE WHEN (temperatura + 0) > -50 THEN (temperatura + 0) END) as min_temp,
    MAX(CASE WHEN (tombra + 0) > -50 THEN (tombra + 0) END) as max_tombra,
    MIN(CASE WHEN (tombra + 0) > -50 THEN (tombra + 0) END) as min_tombra,
    MAX(CASE WHEN (tMobile + 0) > -50 THEN (tMobile + 0) END) as max_tMobile,
    MIN(CASE WHEN (tMobile + 0) > -50 THEN (tMobile + 0) END) as min_tMobile,
    MAX(hombra + 0) as max_humi,
    MIN(hombra + 0) as min_humi,
    MAX(power + 0) as max_power,
    MAX(portata + 0) as max_portata,
    MIN(portata + 0) as min_portata
    FROM dati_meteo
    WHERE data >= {$time24hAgo}";

  $resMinMax = $link->query($queryMinMax);
  $mm = ($resMinMax && $rowMM = $resMinMax->fetch_assoc()) ? $rowMM : [];

  $mm_min_temp = fmt($mm['min_temp'] ?? null);
  $mm_max_temp = fmt($mm['max_temp'] ?? null);
  $mm_min_tombra = fmt($mm['min_tombra'] ?? null);
  $mm_max_tombra = fmt($mm['max_tombra'] ?? null);
  $mm_min_tMobile = fmt($mm['min_tMobile'] ?? null);
  $mm_max_tMobile = fmt($mm['max_tMobile'] ?? null);
  $mm_min_humi = fmt($mm['min_humi'] ?? null, 0);
  $mm_max_humi = fmt($mm['max_humi'] ?? null, 0);
  $mm_max_power = fmt($mm['max_power'] ?? null, 0);

  /** ---------- 2. LATEST DATA ---------- */
  $result = $link->query("SELECT `temperatura`,`data`,`tombra`,`hombra`,`power`,`tMobile`,`portata` FROM `dati_instant` WHERE `id` = 1");
  $row = ($result instanceof mysqli_result) ? $result->fetch_assoc() : null;

  $safeTemp0 = (isset($row['temperatura']) && $row['temperatura'] !== null && (float) $row['temperatura'] > -50) ? (float) $row['temperatura'] : -100.0;
  $safeTombra0 = (isset($row['tombra']) && $row['tombra'] !== null && (float) $row['tombra'] > -50) ? (float) $row['tombra'] : -100.0;
  $safeTMobile0 = (isset($row['tMobile']) && $row['tMobile'] !== null && (float) $row['tMobile'] > -50) ? (float) $row['tMobile'] : -100.0;
  $safeHombra0 = $row['hombra'] ?? '0';
  $safePower0 = (float) ($row['power'] ?? 0);
  $safeData0 = (int) ($row['data'] ?? $now);
  $safePortata0 = (float) ($row['portata'] ?? 0);

  /** ---------- 2b. SHELLY PRO EM-50 (PV PRODUCTION + GRID EXCHANGE) ----------
   * Queried apart from the main SELECTs so the dashboard still renders on a DB
   * where carica_dati.php has not yet added the two columns.
   *   pvPower   = photovoltaic production, W
   *   gridPower = grid exchange, W  (> 0 prelievo / import, < 0 immissione / export)
   *   casaPower = house load = pvPower + gridPower
   */
  $safePv0 = null;
  $safeGrid0 = null;

  // mysqli throws on an unknown column (PHP 8.1+ reports errors as exceptions),
  // so this has to be caught, not silenced with @.
  try {
    $resEm = $link->query("SELECT `pvPower`,`gridPower` FROM `dati_instant` WHERE `id` = 1");
    if ($resEm instanceof mysqli_result && $rowEm = $resEm->fetch_assoc()) {
      $safePv0 = is_numeric($rowEm['pvPower']) ? (float) $rowEm['pvPower'] : null;
      // Under 10 W the panels are not producing; show a clean 0 W.
      if ($safePv0 !== null && abs($safePv0) < PV_ZERO_THRESHOLD) {
        $safePv0 = 0.0;
      }
      $safeGrid0 = is_numeric($rowEm['gridPower']) ? (float) $rowEm['gridPower'] : null;
    }
  } catch (Throwable $e) {
    // Columns not created yet (carica_dati.php adds them on its first run).
    $safePv0 = null;
    $safeGrid0 = null;
  }

  // The meter cards are shown only when a real reading is stored. The columns
  // existing is not enough: they are NULL until meteo.py forwards the first
  // pvPower/gridPower, and empty cards look broken.
  $emAvailable = ($safePv0 !== null || $safeGrid0 !== null);

  /** ---------- 2c. MARSTEK VENUS E (HOME BATTERY) ----------
   * battPower is signed the house way: > 0 charging, < 0 discharging.
   * battTs is when the BATTERY was read, which is not when this page is being
   * built: meteo.py keeps sending the rest of the reading while the battery
   * bridge is down, and the stored figures would otherwise stay in the house
   * load for ever. Past BATT_MAX_AGE the battery is simply treated as absent.
   */
  $safeBattPower0 = null;
  $safeBattSoc0 = null;
  $safeBattTemp0 = null;
  // Diagnostics: field => value, only the ones actually stored. Kept as an
  // array so a column the bridge does not publish yet simply does not appear,
  // and the card shows a dash for it instead of a zero.
  $battDiag = [];
  $battAge = null;
  try {
    $resB = $link->query("SELECT `battPower`,`battSoc`,`battTemp`,`battTs` FROM `dati_instant` WHERE `id` = 1");
    if ($resB instanceof mysqli_result && $rowB = $resB->fetch_assoc()) {
      $battTs = is_numeric($rowB['battTs']) ? (int) $rowB['battTs'] : null;
      $battAge = ($battTs !== null) ? $now - $battTs : null;
      if ($battAge !== null && $battAge <= BATT_MAX_AGE) {
        $safeBattPower0 = is_numeric($rowB['battPower']) ? (float) $rowB['battPower'] : null;
        $safeBattSoc0 = is_numeric($rowB['battSoc']) ? (float) $rowB['battSoc'] : null;
        // Hottest cell (the pack), not the electronics -- meteo.py picks it.
        $safeBattTemp0 = is_numeric($rowB['battTemp']) ? (float) $rowB['battTemp'] : null;

        // A second query, its own try/catch: these columns arrive with the
        // diagnostics card and must not be able to take the three above down
        // with them on a database that predates them.
        try {
          $cols = implode(',', array_map(
            static fn($c) => "`$c`", BATTERY_DIAG_FIELDS));
          $resD = $link->query("SELECT $cols FROM `dati_instant` WHERE `id` = 1");
          if ($resD instanceof mysqli_result && $rowD = $resD->fetch_assoc()) {
            foreach ($rowD as $k => $v) {
              if (is_numeric($v)) {
                $battDiag[$k] = (float) $v;
              }
            }
          }
        } catch (Throwable $e) {
          // Diagnostics columns not created yet: the card shows dashes.
        }
      }
    }
  } catch (Throwable $e) {
    // Columns not created yet (store_lib.php adds them on its first history row).
  }

  $battAvailable = ($safeBattPower0 !== null || $safeBattSoc0 !== null
    || $safeBattTemp0 !== null);

  /**
   * House load, net of the battery.
   *
   * The Venus sits behind the grid meter, on the house side, so the Shelly
   * cannot tell it apart from a dishwasher: pvPower + gridPower counts a pack
   * charging at 800 W as 800 W of consumption, and hides the same amount while
   * it discharges. Subtracting battPower leaves what the house is actually
   * using. Checked against the meters at 09:57 on 2026-09-04: PV 1871 W,
   * export 877 W, battery charging 770 W -> 224 W of real load, and
   * 224 + 770 + 877 = 1871 balances exactly.
   *
   * With no fresh battery reading this falls back to the old sum, which is the
   * right answer for a house that has no battery and the best available one
   * for a house whose bridge is down.
   */
  $safeCasa0 = ($safePv0 !== null && $safeGrid0 !== null) ? $safePv0 + $safeGrid0 : null;
  if ($safeCasa0 !== null && $safeBattPower0 !== null) {
    $safeCasa0 -= $safeBattPower0;
  }

  // 24h extremes for the same two channels (again isolated from the main query).
  $em_max_pv = null;
  $em_max_grid = null;
  $em_min_grid = null;
  $em_max_casa = null;
  if ($emAvailable) {
    try {
      $resEmMM = $link->query(
        "SELECT MAX(pvPower + 0) AS max_pv,
                MAX(gridPower + 0) AS max_grid,
                MIN(gridPower + 0) AS min_grid,
                MAX(pvPower + gridPower) AS max_casa
         FROM dati_meteo
         WHERE data >= {$time24hAgo} AND pvPower IS NOT NULL"
      );
      if ($resEmMM instanceof mysqli_result && $rowEmMM = $resEmMM->fetch_assoc()) {
        $em_max_pv = is_numeric($rowEmMM['max_pv']) ? (float) $rowEmMM['max_pv'] : null;
        $em_max_grid = is_numeric($rowEmMM['max_grid']) ? (float) $rowEmMM['max_grid'] : null;
        $em_min_grid = is_numeric($rowEmMM['min_grid']) ? (float) $rowEmMM['min_grid'] : null;
        $em_max_casa = is_numeric($rowEmMM['max_casa']) ? (float) $rowEmMM['max_casa'] : null;
      }
    } catch (Throwable $e) {
      // dati_instant has the columns but dati_meteo does not (yet): no extremes.
    }

    // The house peak again, this time net of the battery. Deliberately a
    // SECOND query rather than one more column in the one above: battPower
    // reaches dati_meteo only when the ten-minute path adds it, and while it
    // is missing mysqli throws -- which, from inside the same try, would take
    // the PV and grid extremes down with it. They are unrelated to the
    // battery and must not depend on it.
    //
    // COALESCE, not a bare subtraction: a row logged before the battery
    // existed has battPower NULL, and NULL would swallow the whole expression
    // and lose that row's peak.
    $em_max_casa_batt = null;
    try {
      // Two peaks in one pass: the house alone, and the house plus whatever
      // the battery was drawing. LEAST(...,0) is the algebra for "count the
      // battery only while it charges" -- casa + max(0,b) is the same as
      // pv + grid - min(b,0), and this way it stays one SQL expression.
      $resCasaMM = $link->query(
        "SELECT MAX(pvPower + gridPower - COALESCE(battPower, 0)) AS max_casa,
                MAX(pvPower + gridPower - LEAST(COALESCE(battPower, 0), 0)) AS max_casa_batt
         FROM dati_meteo
         WHERE data >= {$time24hAgo} AND pvPower IS NOT NULL"
      );
      if ($resCasaMM instanceof mysqli_result && $rowCasaMM = $resCasaMM->fetch_assoc()) {
        if (is_numeric($rowCasaMM['max_casa'])) {
          $em_max_casa = (float) $rowCasaMM['max_casa'];
        }
        if (is_numeric($rowCasaMM['max_casa_batt'])) {
          $em_max_casa_batt = (float) $rowCasaMM['max_casa_batt'];
        }
      }
    } catch (Throwable $e) {
      // No battPower column yet: keep the gross peak from the query above.
    }
  }

  // 24h extremes for the battery itself: how full it got, how empty, and the
  // hardest it pushed each way.
  $batt_min_soc = null;
  $batt_max_soc = null;
  $batt_max_charge = null;
  $batt_max_discharge = null;
  $batt_min_temp = null;
  $batt_max_temp = null;
  if ($battAvailable) {
    try {
      $resBMM = $link->query(
        "SELECT MIN(battSoc + 0) AS min_soc,
                MAX(battSoc + 0) AS max_soc,
                MAX(battPower + 0) AS max_charge,
                MIN(battPower + 0) AS min_charge,
                MIN(battTemp + 0) AS min_temp,
                MAX(battTemp + 0) AS max_temp
         FROM dati_meteo
         WHERE data >= {$time24hAgo} AND battSoc IS NOT NULL"
      );
      if ($resBMM instanceof mysqli_result && $rowBMM = $resBMM->fetch_assoc()) {
        $batt_min_soc = is_numeric($rowBMM['min_soc']) ? (float) $rowBMM['min_soc'] : null;
        $batt_max_soc = is_numeric($rowBMM['max_soc']) ? (float) $rowBMM['max_soc'] : null;
        $batt_max_charge = is_numeric($rowBMM['max_charge']) ? (float) $rowBMM['max_charge'] : null;
        $batt_max_discharge = is_numeric($rowBMM['min_charge']) ? (float) $rowBMM['min_charge'] : null;
        $batt_min_temp = is_numeric($rowBMM['min_temp']) ? (float) $rowBMM['min_temp'] : null;
        $batt_max_temp = is_numeric($rowBMM['max_temp']) ? (float) $rowBMM['max_temp'] : null;
      }
    } catch (Throwable $e) {
      // No battery history yet: the card shows its live figures only.
    }
  }

  /** ---------- 3. PRESSURE & FORECAST (WITH SNOW; USING TEMPERATURA SOLE) ---------- */
  $stateFile = INSTANT_STATE_FILE;
  $presHistoryFile = INSTANT_PRES_HISTORY_FILE;
  $safePres0 = 1013.0;
  $presTrend3h = 0.0;
  $presTrendValid = false;   // true only when both endpoints are trustworthy

  // Displayed "current" pressure (from the live state file).
  if (file_exists($stateFile)) {
    $stateJson = json_decode((string) file_get_contents($stateFile), true);
    if (isset($stateJson['pres']))
      $safePres0 = (float) $stateJson['pres'];
  }

  // --- Robust 3h trend -------------------------------------------------------
  // Parse the history CSV ("timestamp,pressure") into a clean list.
  $pHist = [];
  if (file_exists($presHistoryFile)) {
    foreach (file($presHistoryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $pr) {
      $pParts = explode(',', $pr);
      if (count($pParts) >= 2 && is_numeric($pParts[0]) && is_numeric($pParts[1])) {
        $pHist[] = [(int) $pParts[0], (float) $pParts[1]];
      }
    }
  }

  // Median pressure of all samples within +/- $halfWin seconds of $centerTs.
  // Median (not mean) rejects single spikes; the window averages out sensor jitter.
  $presMedianAround = function (int $centerTs, int $halfWin) use ($pHist) {
    $vals = [];
    foreach ($pHist as $r) {
      if (abs($r[0] - $centerTs) <= $halfWin)
        $vals[] = $r[1];
    }
    if (!$vals)
      return null;
    sort($vals);
    $n = count($vals);
    return ($n % 2) ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2.0;
  };

  if ($pHist) {
    // Both endpoints come from the SAME series (shared calibration).
    // Logger cadence is ~10 min, so windows are sized to hold a few samples and
    // tolerate one missed tick:
    //   "now": median of the last ~20 min -> also rejects a stale/dead logger.
    //   "3h ago": median within +/- 30 min of the 3h mark, else we don't trust it.
    $pNow = $presMedianAround($now, 1200);
    $pOld = $presMedianAround($now - 10800, 1800);
    if ($pNow !== null && $pOld !== null) {
      $presTrend3h = $pNow - $pOld;
      $presTrendValid = true;
    }
  }

  $mslp = $safePres0 + 31.8 - 2.5;

  // >>> USE TEMPERATURA OMBRA (shade air temp) as reference; fall back to sun sensor <<<
  // The shade sensor is the true air temperature; the sun-exposed probe over-reads.
  // Shade air temp lives in the `tombra` column ($safeTombra0); `tMobile` is the
  // indoor sensor.
  $outTemp = ($safeTombra0 > -99) ? $safeTombra0 : $safeTemp0;

  // humidity numeric
  $humi = is_numeric($safeHombra0) ? (float) $safeHombra0 : 0.0;
  $humiValid = ($humi > 0);

  // Humidity gating: precipitation needs moisture in the air.
  // If the sensor is invalid we don't block (treat as "moist enough").
  $moistAir = (!$humiValid || $humi >= 70);
  $dryAir = ($humiValid && $humi < 55);

  // default
  $forecast = ['icon' => 'cloud', 'text' => 'Variabile', 'color' => 'var(--text-muted)'];

  // snow heuristics (shade temp + falling pressure + high humidity)
  $snowLikely = false;
  if ($outTemp > -99) {
    if ($outTemp <= 1.5 && $presTrend3h <= -0.3 && $humi >= 75)
      $snowLikely = true;
    if ($outTemp <= 0.0 && $presTrend3h <= -0.2 && $humi >= 65)
      $snowLikely = true;
  }
  // A storm needs both a sharp pressure drop AND moisture.
  $stormLikely = ($presTrend3h <= -1.5 && $moistAir);

  if ($snowLikely) {
    $forecast = ['icon' => 'snow', 'text' => 'Neve', 'color' => 'var(--accent-ice)'];
  } elseif ($stormLikely) {
    if ($outTemp <= 1.0) {
      $forecast = ['icon' => 'snow_storm', 'text' => 'Bufera', 'color' => 'var(--accent-ice)'];
    } else {
      $forecast = ['icon' => 'storm', 'text' => 'Temporale', 'color' => 'var(--accent-red)'];
    }
  } elseif ($presTrend3h <= -0.5 && $moistAir) {
    if ($outTemp <= 1.0) {
      $forecast = ['icon' => 'sleet', 'text' => 'Nevischio', 'color' => 'var(--accent-ice)'];
    } else {
      $forecast = ['icon' => 'rain', 'text' => 'Pioggia', 'color' => 'var(--accent-blue)'];
    }
  } elseif ($presTrend3h <= -0.5 && $dryAir) {
    // Pressure dropping but the air is dry -> clouds building, not rain yet.
    $forecast = ['icon' => 'cloud', 'text' => 'Nuvoloso', 'color' => 'var(--text-muted)'];
  } elseif ($humiValid && $humi >= 95 && abs($presTrend3h) < 0.4 && $mslp <= 1022 && $outTemp <= 8.0) {
    // Saturated, calm and cool -> fog / mist.
    $forecast = ['icon' => 'cloud', 'text' => 'Nebbia', 'color' => 'var(--text-muted)'];
  } elseif ($mslp > 1022 && $dryAir) {
    // High pressure + dry air -> confidently clear.
    $forecast = ['icon' => 'sun', 'text' => 'Sereno', 'color' => 'var(--accent-orange)'];
  } elseif ($mslp > 1022) {
    // High pressure but humid -> hazy / veiled sun.
    $forecast = ['icon' => 'partly_cloudy', 'text' => 'Velato', 'color' => 'var(--accent-orange)'];
  } elseif ($mslp > 1016 && $presTrend3h > -0.2) {
    $forecast = ['icon' => 'partly_cloudy', 'text' => 'Poco Nuvoloso', 'color' => 'var(--accent-orange)'];
  } elseif ($presTrend3h >= 0.5) {
    $forecast = ['icon' => 'partly_cloudy', 'text' => 'In Miglioramento', 'color' => 'var(--accent-orange)'];
  } elseif ($mslp < 1008) {
    if (!$moistAir) {
      // Low pressure but dry -> unsettled/cloudy rather than wet.
      $forecast = ['icon' => 'cloud', 'text' => 'Nuvoloso', 'color' => 'var(--text-muted)'];
    } elseif ($outTemp <= 1.0) {
      $forecast = ['icon' => 'sleet', 'text' => 'Instabile (freddo)', 'color' => 'var(--accent-ice)'];
    } else {
      $forecast = ['icon' => 'rain', 'text' => 'Instabile', 'color' => 'var(--accent-blue)'];
    }
  }

  /** ---------- PV AS SOLAR-IRRADIANCE / SKY-NOW PROXY ---------- */
  // Photovoltaic output is a direct read of how much sun is hitting the panel.
  // To judge cloud cover we compare it against the CLEAR-SKY output expected for
  // THIS time of day, estimated from the best output seen at the same clock hour
  // over the past 7 days. This normalises for sun elevation, so a clear morning
  // or evening is no longer mistaken for cloud, and night falls out for free
  // (no historical output at this hour -> no reference -> no classification).
  $pvNow = $safePower0;

  $hourWindows = [];
  for ($k = 1; $k <= 7; $k++) {
    $lo = $now - ($k * 86400) - 1800;   // same clock time k days ago, +/- 30 min
    $hi = $now - ($k * 86400) + 1800;
    $hourWindows[] = "(data BETWEEN {$lo} AND {$hi})";
  }
  $qHourPeak = "SELECT MAX(power + 0) AS hour_peak FROM dati_meteo WHERE (" . implode(' OR ', $hourWindows) . ")";
  $resHP = $link->query($qHourPeak);
  $hourPeak = ($resHP && $rHP = $resHP->fetch_assoc()) ? (float) ($rHP['hour_peak'] ?? 0) : 0.0;

  // Fraction of the clear-sky reference we are actually producing right now.
  // Need a meaningful reference (>20 W) or we can't tell (deep night / no data).
  $sunFrac = ($hourPeak > 20 && $pvNow >= 0) ? min(1.0, $pvNow / $hourPeak) : null;
  $sunPct = ($sunFrac !== null) ? (int) round($sunFrac * 100) : null;

  $skyNow = null; // ['text' => ..., 'color' => ...]
  if ($sunFrac !== null) {
    if ($sunFrac >= 0.70) {
      $skyNow = ['text' => 'Sereno', 'color' => 'var(--accent-orange)'];
    } elseif ($sunFrac >= 0.40) {
      $skyNow = ['text' => 'Poco Nuvoloso', 'color' => 'var(--accent-orange)'];
    } elseif ($sunFrac >= 0.15) {
      $skyNow = ['text' => 'Nuvoloso', 'color' => 'var(--text-muted)'];
    } else {
      $skyNow = ['text' => 'Coperto', 'color' => 'var(--text-muted)'];
    }
  }

  $iceWarning = ($outTemp > -99 && $outTemp <= 0.0);
  $forecastTiming = getForecastTiming($forecast, $presTrend3h);

  /** ---------- 4. TRENDS & FILTERS ---------- */
  $time30mAgo = $now - 1800;
  $queryTrend = "SELECT `temperatura`, `tombra`, `tMobile`, `portata` FROM `dati_meteo` WHERE `data` <= {$time30mAgo} ORDER BY `data` DESC LIMIT 1";
  $resTrend = $link->query($queryTrend);
  $trendData = ($resTrend && $resTrend->num_rows > 0) ? $resTrend->fetch_assoc() : null;

  $trend_temp1 = calculateTrend($safeTemp0, $trendData['temperatura'] ?? null);
  $trend_tombra = calculateTrend($safeTombra0, $trendData['tombra'] ?? null);
  $trend_tMobile = calculateTrend($safeTMobile0, $trendData['tMobile'] ?? null);
  $trend_piave = calculateTrend($safePortata0, $trendData['portata'] ?? null);

  $iceTime_temp1 = getFreezingTime($safeTemp0, $trend_temp1);
  $iceTime_tombra = getFreezingTime($safeTombra0, $trend_tombra);
  $iceTime_tMobile = getFreezingTime($safeTMobile0, $trend_tMobile);

  /** ---------- 5. RIVER STATUS ---------- */
  $waterStatus = 'normal';
  if ($safePortata0 > 100.0)
    $waterStatus = 'increasing';
  elseif ($safePortata0 <= 17.0)
    $waterStatus = 'dry';

  /** ---------- 6. PV CARD (metered, with ADC fallback) ---------- */
  // Prefer the metered production; fall back to the ADC estimate while the
  // meter (or its DB columns) is not available, so the card is never empty.
  $pvMetered = ($safePv0 !== null);
  $pvValue = $pvMetered ? round($safePv0) : $safePower0;
  // Below 10 W there is no real production (clamp/inverter noise) -> show 0.
  // mqtt_receiver.py already stores it that way; this also covers rows logged
  // before that rule existed and the ADC fallback above.
  if ((float) $pvValue < PV_ZERO_THRESHOLD) {
    $pvValue = 0;
  }
  $pvPeak = ($em_max_pv !== null) ? fmtW($em_max_pv) : ($mm_max_power . ' W');
  $pvLink = $pvMetered ? 'grafico.php?var=pvPower' : 'grafico.php?var=power';

  /** ---------- 7. GRID DIRECTION ---------- */
  $exporting = ($safeGrid0 !== null && $safeGrid0 < 0);
  if ($safeGrid0 === null) {
    $gridFlow = '--';
  } elseif ($exporting) {
    $gridFlow = '&#8593; Immissione';
  } elseif ($safeGrid0 > 5) {
    $gridFlow = '&#8595; Prelievo';
  } else {
    $gridFlow = 'Equilibrio';
  }

  /** ---------- 7b. BATTERY STATE ----------
   * Derived from the power, not read: `state` on the Venus says what it has
   * been TOLD to do, not what it is doing. BATT_IDLE_W keeps a pack running
   * its own electronics from reading as a discharge.
   */
  $battCharging = ($safeBattPower0 !== null && $safeBattPower0 > BATT_IDLE_W);
  $battDischarging = ($safeBattPower0 !== null && $safeBattPower0 < -BATT_IDLE_W);
  if ($safeBattPower0 === null) {
    $battFlow = '--';
    $battColor = 'var(--text-muted)';
  } elseif ($battCharging) {
    $battFlow = '&#8595; In carica';
    $battColor = 'var(--accent-green)';
  } elseif ($battDischarging) {
    $battFlow = '&#8593; In scarica';
    $battColor = 'var(--accent-orange)';
  } else {
    $battFlow = 'In attesa';
    $battColor = 'var(--text-muted)';
  }

  /* Cell temperature, coloured by what it means for the pack rather than by
   * how warm it sounds. Below 0 °C the BMS refuses to CHARGE (discharge is
   * fine down to -20), which is normal on a winter night and worth flagging
   * as a state, not an alarm; above 45 °C it starts derating. */
  $battTempColor = 'var(--text-muted)';
  $battTempNote = '';
  if ($safeBattTemp0 !== null) {
    if ($safeBattTemp0 < 0) {
      $battTempColor = 'var(--accent-ice)';
      $battTempNote = ' carica bloccata dal BMS';
    } elseif ($safeBattTemp0 >= 45) {
      $battTempColor = 'var(--accent-red)';
      $battTempNote = ' in derating';
    } elseif ($safeBattTemp0 >= 35) {
      $battTempColor = 'var(--accent-orange)';
    } else {
      $battTempColor = 'var(--accent-teal)';
    }
  }

  /* ---------- 7c. PACK DIAGNOSTICS ----------
   * One card, three families: temperatures, voltages, currents. The two
   * spreads are the interesting derived numbers -- a pack whose cells sit
   * within a few mV and a couple of degrees of each other is balanced, and
   * both go wrong long before either extreme looks alarming on its own.
   */
  $d = static fn(string $k) => $battDiag[$k] ?? null;
  $fmtNum = static fn($v, int $dec, string $unit)
    => ($v === null) ? '--' : number_format((float) $v, $dec) . ' ' . $unit;

  $cellTempSpread = ($safeBattTemp0 !== null && $d('battTempMin') !== null)
    ? $safeBattTemp0 - $d('battTempMin') : null;
  $cellVoltSpread = ($d('battCellVMax') !== null && $d('battCellVMin') !== null)
    ? ($d('battCellVMax') - $d('battCellVMin')) * 1000 : null;   // in mV

  /* AC current is DERIVED, not read. Register 37004 is `ac_current` in the
   * community register map, but on this firmware it returns the AC power --
   * the same word as 30006, checked over three samples. |W| / V is the honest
   * substitute, and the card says so rather than implying a measurement. */
  $acCurrent = ($d('battAcW') !== null && $d('battAcV') !== null && $d('battAcV') > 50)
    ? abs($d('battAcW')) / $d('battAcV') : null;

  /* Casa + batteria: what the house and the pack are drawing together.
   *
   * Only a CHARGING battery is added. A discharging one is not consumption --
   * it is where part of the consumption is coming from, and adding it with its
   * own sign would subtract, making the total smaller than the house alone.
   */
  $battDraw = ($safeBattPower0 !== null) ? max(0.0, $safeBattPower0) : null;
  $safeCasaBatt0 = ($safeCasa0 !== null)
    ? $safeCasa0 + ($battDraw ?? 0.0)
    : null;

  // How much of the load is NOT bought from the grid -- covered by the panels
  // directly or by the battery giving back. Computed from the import rather
  // than from pvPower, because with a battery in the middle the PV figure on
  // its own no longer says what the house consumed.
  $prelievo0 = ($safeGrid0 === null) ? null : max(0.0, $safeGrid0);
  $selfShare = null;
  if ($safeCasa0 !== null && $prelievo0 !== null && $safeCasa0 > 0) {
    $selfShare = (int) round(min(100, max(0, ($safeCasa0 - $prelievo0) / $safeCasa0 * 100)));
  }
  $selfLabel = $battAvailable ? '% da FV e batteria' : '% da fotovoltaico';

  /** ---------- 8. RENDER-READY PAYLOAD ---------- */
  return [
    'ts' => $safeData0,
    // Seconds matter now that readings arrive on change rather than once a
    // minute: without them two consecutive updates look identically timed.
    'headerTime' => date('d-m-y H:i:s', $safeData0),
    'emAvailable' => $emAvailable,
    // Like emAvailable: the card only exists in the markup while the battery
    // is reporting, so the browser reloads rather than patching when it flips.
    'battAvailable' => $battAvailable,

    'tempSole' => [
      'val' => ($safeTemp0 <= -99 ? '--' : (string) $safeTemp0),
      'freezing' => (getTempClass($safeTemp0) !== ''),
      'min' => (string) $mm_min_temp,
      'max' => (string) $mm_max_temp,
      'trend' => getTrendHtml($trend_temp1, '°C/h', $iceTime_temp1),
    ],
    'tempOmbra' => [
      'val' => ($safeTombra0 <= -99 ? '--' : (string) $safeTombra0),
      'freezing' => (getTempClass($safeTombra0) !== ''),
      'min' => (string) $mm_min_tombra,
      'max' => (string) $mm_max_tombra,
      'trend' => getTrendHtml($trend_tombra, '°C/h', $iceTime_tombra),
    ],
    'tempInterno' => [
      'val' => ($safeTMobile0 <= -99 ? '--' : (string) $safeTMobile0),
      'freezing' => (getTempClass($safeTMobile0) !== ''),
      'min' => (string) $mm_min_tMobile,
      'max' => (string) $mm_max_tMobile,
      'trend' => getTrendHtml($trend_tMobile, '°C/h', $iceTime_tMobile),
    ],

    'pres' => [
      'val' => number_format($safePres0, 1),
      'icon' => $forecast['icon'],
      'text' => $forecast['text'],
      'color' => $forecast['color'],
      'timing' => $forecastTiming,
      'ice' => $iceWarning,
      'mslp' => number_format($mslp, 1),
      'trendText' => $presTrendValid ? (($presTrend3h > 0 ? '+' : '') . number_format($presTrend3h, 1)) : 'n/d',
      'trendClass' => $presTrendValid ? ($presTrend3h < 0 ? 'trend-down' : 'trend-up') : '',
    ],

    'pv' => [
      'val' => (string) $pvValue,
      'night' => (getPowerClass((float) $pvValue) !== ''),
      'skyText' => ($skyNow !== null) ? $skyNow['text'] : '',
      'skyColor' => ($skyNow !== null) ? $skyNow['color'] : 'var(--text-muted)',
      'peak' => (string) $pvPeak,
      'sunPct' => ($sunPct !== null ? $sunPct . '%' : '--'),
      'link' => $pvLink,
    ],

    'humi' => [
      'val' => (string) $safeHombra0,
      'range' => $mm_min_humi . '% - ' . $mm_max_humi . '%',
    ],

    'piave' => [
      'val' => number_format($safePortata0, 2),
      'status' => $waterStatus,
      'statusText' => ($waterStatus == 'dry' ? 'Secca' : ($waterStatus == 'increasing' ? 'In Piena' : 'Normale')),
      'statusColor' => ($waterStatus == 'dry' ? 'var(--accent-dry)' : ($waterStatus == 'increasing' ? 'var(--accent-red)' : 'var(--accent-blue)')),
      'rising' => ($waterStatus == 'increasing' && $trend_piave !== null && $trend_piave > 1.5),
      'trend' => getTrendHtml($trend_piave, 'm³/s/h'),
    ],

    'grid' => [
      'val' => ($safeGrid0 === null ? '--' : (string) round(abs($safeGrid0))),
      'exporting' => $exporting,
      'flow' => $gridFlow,
      'flowColor' => $exporting ? 'var(--accent-green)' : 'var(--accent-blue)',
      'maxIn' => ($em_max_grid === null ? '--' : fmtW(max(0, $em_max_grid))),
      'maxOut' => ($em_min_grid === null ? '--' : fmtW(abs(min(0, $em_min_grid)))),
    ],

    // Prelievo: what the house actually buys from the grid. Zero whenever the
    // PV covers the whole consumption (grid flow <= 0, i.e. balance or export);
    // otherwise it is simply the imported side of the exchange.
    'prelievo' => [
      'val' => ($safeGrid0 === null ? '--' : (string) round(max(0, $safeGrid0))),
      'drawing' => ($safeGrid0 !== null && $safeGrid0 > 5),
      'stateText' => ($safeGrid0 === null ? '--'
        : (($safeGrid0 > 5) ? '&#8595; Dalla rete' : '100% da fotovoltaico')),
      'stateColor' => ($safeGrid0 !== null && $safeGrid0 > 5)
        ? 'var(--accent-red)' : 'var(--accent-green)',
      'shareShow' => ($safeGrid0 !== null && $safeCasa0 !== null && $safeCasa0 > 0),
      'share' => ($safeGrid0 !== null && $safeCasa0 !== null && $safeCasa0 > 0)
        ? round(min(100, (max(0, $safeGrid0) / $safeCasa0) * 100)) . '% da rete'
        : '',
      'max' => ($em_max_grid === null ? '--' : fmtW(max(0, $em_max_grid))),
    ],

    // Consumo casa is net of the battery (see the derivation above), so it is
    // what the appliances are drawing rather than what the meters see.
    'casa' => [
      'val' => ($safeCasa0 === null ? '--' : (string) round($safeCasa0)),
      'shareShow' => ($selfShare !== null),
      'share' => ($selfShare !== null) ? $selfShare . $selfLabel : '',
      'peak' => fmtW($em_max_casa),
    ],

    /* Casa + batteria. Deliberately its own card rather than a second figure
     * on the one above: the two answer different questions -- what the house
     * is using, and what the whole installation is pulling while it also
     * fills the battery. */
    'casaBatt' => [
      'val' => ($safeCasaBatt0 === null ? '--' : (string) round($safeCasaBatt0)),
      'charging' => ($battDraw !== null && $battDraw > BATT_IDLE_W),
      // The split, so the number is never a mystery: house plus battery. The
      // "solo casa" branch is what the card would say while the battery is
      // idle -- index.php hides the whole card in that case, and the string is
      // kept because live.php serves this payload to other consumers too.
      'breakdown' => ($safeCasa0 === null) ? '--'
        : (($battDraw !== null && $battDraw > BATT_IDLE_W)
          ? fmtW($safeCasa0) . ' casa + ' . fmtW($battDraw) . ' batteria'
          : 'solo casa'),
      'breakdownColor' => ($battDraw !== null && $battDraw > BATT_IDLE_W)
        ? 'var(--accent-green)' : 'var(--text-muted)',
      'peak' => fmtW($em_max_casa_batt ?? $em_max_casa),
    ],

    /* Everything the pack reports about itself. Rendered as plain strings
     * with their units: nothing here is charted or compared, it is read. */
    'battDiag' => [
      'show' => ($battDiag !== []),
      'tCellMax'  => $fmtNum($safeBattTemp0, 1, '°C'),
      'tCellMin'  => $fmtNum($d('battTempMin'), 1, '°C'),
      'tSpread'   => $fmtNum($cellTempSpread, 1, '°C'),
      'tSpreadWarn' => ($cellTempSpread !== null && $cellTempSpread > 5),
      'tInt'      => $fmtNum($d('battTempInt'), 1, '°C'),
      'tMos1'     => $fmtNum($d('battTempMos1'), 1, '°C'),
      'tMos2'     => $fmtNum($d('battTempMos2'), 1, '°C'),
      'vPack'     => $fmtNum($d('battVolt'), 2, 'V'),
      'vCellMax'  => $fmtNum($d('battCellVMax'), 3, 'V'),
      'vCellMin'  => $fmtNum($d('battCellVMin'), 3, 'V'),
      // In mV: the digit that matters here is the one three decimals of a
      // volt hide.
      'vSpread'   => ($cellVoltSpread === null) ? '--'
        : round($cellVoltSpread) . ' mV',
      'vSpreadWarn' => ($cellVoltSpread !== null && $cellVoltSpread > 50),
      'vAc'       => $fmtNum($d('battAcV'), 1, 'V'),
      'hz'        => $fmtNum($d('battAcHz'), 2, 'Hz'),
      'iPack'     => $fmtNum($d('battCurr'), 1, 'A'),
      'iAc'       => $fmtNum($acCurrent, 1, 'A'),
    ],

    'batteria' => [
      'val' => ($safeBattSoc0 === null ? '--' : (string) round($safeBattSoc0)),
      // Width of the fill bar, as a CSS length the poller can drop straight in.
      'fill' => ($safeBattSoc0 === null ? '0%'
        : max(0, min(100, round($safeBattSoc0))) . '%'),
      'fillColor' => ($safeBattSoc0 === null ? 'var(--text-muted)'
        : ($safeBattSoc0 <= 15 ? 'var(--accent-red)'
          : ($safeBattSoc0 <= 35 ? 'var(--accent-orange)' : 'var(--accent-green)'))),
      'charging' => $battCharging,
      'discharging' => $battDischarging,
      'flow' => $battFlow,
      'flowColor' => $battColor,
      // Signed watts as the battery reports them, so the card says how hard it
      // is working as well as which way.
      'power' => ($safeBattPower0 === null ? '--' : fmtW(abs($safeBattPower0))),
      'residuo' => ($safeBattSoc0 === null ? '--'
        : number_format($safeBattSoc0 / 100 * BATT_CAPACITY_KWH, 2) . ' kWh'),
      'range' => ($batt_min_soc === null || $batt_max_soc === null) ? '--'
        : round($batt_min_soc) . '% - ' . round($batt_max_soc) . '%',
      // Cella piu' calda: e' quella su cui lavorano i limiti del BMS, mentre
      // `temperature` sulla batteria e' l'elettronica e corre 6 gradi sopra.
      'temp' => ($safeBattTemp0 === null ? '--' : number_format($safeBattTemp0, 1) . ' °C'),
      'tempShow' => ($safeBattTemp0 !== null),
      'tempColor' => $battTempColor,
      'tempNote' => $battTempNote,
      'tempRange' => ($batt_min_temp === null || $batt_max_temp === null) ? '--'
        : round($batt_min_temp) . '° - ' . round($batt_max_temp) . '°',
      'maxCharge' => ($batt_max_charge === null) ? '--' : fmtW(max(0, $batt_max_charge)),
      'maxDischarge' => ($batt_max_discharge === null) ? '--' : fmtW(abs(min(0, $batt_max_discharge))),
    ],
  ];
}

/**
 * Build the payload and park it in /dev/shm. Returns the stored version
 * (i.e. with `rev` and `built_at` filled in), or null if the DB is unreachable.
 */
function meteo_refresh_payload(?mysqli $link = null): ?array
{
  $link = $link ?? meteo_db();
  if (!$link instanceof mysqli) {
    return null;
  }
  return instant_write(meteo_build_payload($link));
}

/**
 * The snapshot every reader should use: the RAM copy while it is fresh, a
 * rebuild (which refreshes the RAM copy) otherwise. Falls back to a stale
 * snapshot rather than nothing when the DB is down.
 */
function meteo_payload_get(?mysqli $link = null): ?array
{
  $cached = instant_read();
  if (instant_is_fresh($cached)) {
    return $cached;
  }
  $fresh = meteo_refresh_payload($link);
  return $fresh ?? $cached;
}
