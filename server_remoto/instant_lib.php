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

  $safeCasa0 = ($safePv0 !== null && $safeGrid0 !== null) ? $safePv0 + $safeGrid0 : null;

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

  /** ---------- 8. RENDER-READY PAYLOAD ---------- */
  return [
    'ts' => $safeData0,
    // Seconds matter now that readings arrive on change rather than once a
    // minute: without them two consecutive updates look identically timed.
    'headerTime' => date('d-m-y H:i:s', $safeData0),
    'emAvailable' => $emAvailable,

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

    'casa' => [
      'val' => ($safeCasa0 === null ? '--' : (string) round($safeCasa0)),
      'shareShow' => ($safeCasa0 !== null && $safePv0 !== null && $safeCasa0 > 0),
      'share' => ($safeCasa0 !== null && $safePv0 !== null && $safeCasa0 > 0)
        ? round(min(100, ($safePv0 / $safeCasa0) * 100)) . '% da fotovoltaico'
        : '',
      'peak' => fmtW($em_max_casa),
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
