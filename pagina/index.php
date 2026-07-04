<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Europe/Rome');

$file_path = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . "/icache.html";

ob_start();

require_once 'funzioni.php';
require_once 'connessione.php'; // Defines $link

if (!isset($timeCorrect) || !is_numeric($timeCorrect)) {
  $timeCorrect = 0;
}

$now = time();
$timeup = $now + (int) $timeCorrect;
$timedw = mktime(0, 0, 0, (int) date('n'), (int) date('j'), (int) date('Y')) + (int) $timeCorrect;

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

function fmt($val, $decimals = 1, $default = '--') {
  return (isset($val) && $val !== null && $val !== '' && (float)$val > -99) ? round((float) $val, $decimals) : $default;
}

$mm_min_temp = fmt($mm['min_temp'] ?? null);
$mm_max_temp = fmt($mm['max_temp'] ?? null);
$mm_min_tombra = fmt($mm['min_tombra'] ?? null);
$mm_max_tombra = fmt($mm['max_tombra'] ?? null);
$mm_min_tMobile = fmt($mm['min_tMobile'] ?? null);
$mm_max_tMobile = fmt($mm['max_tMobile'] ?? null);
$mm_min_humi = fmt($mm['min_humi'] ?? null, 0);
$mm_max_humi = fmt($mm['max_humi'] ?? null, 0);
$mm_max_power = fmt($mm['max_power'] ?? null, 0);
$mm_min_portata = fmt($mm['min_portata'] ?? null, 2);
$mm_max_portata = fmt($mm['max_portata'] ?? null, 2);

/** ---------- 2. LATEST DATA ---------- */
$result = $link->query("SELECT `temperatura`,`data`,`tombra`,`hombra`,`power`,`tMobile`,`portata` FROM `dati_meteo` ORDER BY `id` DESC LIMIT 1");
$row = ($result instanceof mysqli_result) ? $result->fetch_assoc() : null;

$safeTemp0 = (isset($row['temperatura']) && $row['temperatura'] !== null && (float) $row['temperatura'] > -50) ? (float) $row['temperatura'] : -100.0;
$safeTombra0 = (isset($row['tombra']) && $row['tombra'] !== null && (float) $row['tombra'] > -50) ? (float) $row['tombra'] : -100.0;
$safeTMobile0 = (isset($row['tMobile']) && $row['tMobile'] !== null && (float) $row['tMobile'] > -50) ? (float) $row['tMobile'] : -100.0;
$safeHombra0 = $row['hombra'] ?? '0';
$safePower0 = (float)($row['power'] ?? 0);
$safeData0 = (int)($row['data'] ?? time());
$safePortata0 = (float)($row['portata'] ?? 0);

/** ---------- 3. PRESSURE & FORECAST (WITH SNOW; USING TEMPERATURA SOLE) ---------- */
$stateFile = '/dev/shm/thermo_data/state.json';
$presHistoryFile = '/dev/shm/thermo_data/pres_history.csv';
$safePres0 = 1013.0;
$presTrend3h = 0.0;
$presTrendValid = false;   // true only when both endpoints are trustworthy

// Displayed "current" pressure (from the live state file).
if (file_exists($stateFile)) {
  $stateJson = json_decode((string) file_get_contents($stateFile), true);
  if (isset($stateJson['pres'])) $safePres0 = (float) $stateJson['pres'];
}

// --- Robust 3h trend ---------------------------------------------------------
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
  foreach ($pHist as $row) {
    if (abs($row[0] - $centerTs) <= $halfWin) $vals[] = $row[1];
  }
  if (!$vals) return null;
  sort($vals);
  $n = count($vals);
  return ($n % 2) ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2.0;
};

if ($pHist) {
  $nowTs = time();
  // Both endpoints come from the SAME series (shared calibration).
  // Logger cadence is ~10 min, so windows are sized to hold a few samples and
  // tolerate one missed tick:
  //   "now": median of the last ~20 min -> also rejects a stale/dead logger.
  //   "3h ago": median within +/- 30 min of the 3h mark, else we don't trust it.
  $pNow = $presMedianAround($nowTs, 1200);
  $pOld = $presMedianAround($nowTs - 10800, 1800);
  if ($pNow !== null && $pOld !== null) {
    $presTrend3h = $pNow - $pOld;
    $presTrendValid = true;
  }
}

$mslp = $safePres0 + 31.8 - 2.5;

// >>> USE TEMPERATURA OMBRA (shade air temp) as reference; fall back to sun sensor <<<
// The shade sensor is the true air temperature; the sun-exposed probe over-reads.
$outTemp = ($safeTMobile0 > -99) ? $safeTMobile0 : $safeTemp0;

// humidity numeric
$humi = is_numeric($safeHombra0) ? (float)$safeHombra0 : 0.0;
$humiValid = ($humi > 0);

// Humidity gating: precipitation needs moisture in the air.
// If the sensor is invalid we don't block (treat as "moist enough").
$moistAir = (!$humiValid || $humi >= 70);
$dryAir   = ($humiValid && $humi < 55);

// default
$forecast = ['icon' => 'cloud', 'text' => 'Variabile', 'color' => 'var(--text-muted)'];

// snow heuristics (shade temp + falling pressure + high humidity)
$snowLikely = false;
if ($outTemp > -99) {
  if ($outTemp <= 1.5 && $presTrend3h <= -0.3 && $humi >= 75) $snowLikely = true;
  if ($outTemp <= 0.0 && $presTrend3h <= -0.2 && $humi >= 65) $snowLikely = true;
}
// A storm needs both a sharp pressure drop AND moisture.
$stormLikely = ($presTrend3h <= -1.5 && $moistAir);

if ($snowLikely) {
  $forecast = ['icon' => 'snow', 'text' => 'Neve', 'color' => 'var(--accent-ice)'];
}
elseif ($stormLikely) {
  if ($outTemp <= 1.0) {
    $forecast = ['icon' => 'snow_storm', 'text' => 'Bufera', 'color' => 'var(--accent-ice)'];
  } else {
    $forecast = ['icon' => 'storm', 'text' => 'Temporale', 'color' => 'var(--accent-red)'];
  }
}
elseif ($presTrend3h <= -0.5 && $moistAir) {
  if ($outTemp <= 1.0) {
    $forecast = ['icon' => 'sleet', 'text' => 'Nevischio', 'color' => 'var(--accent-ice)'];
  } else {
    $forecast = ['icon' => 'rain', 'text' => 'Pioggia', 'color' => 'var(--accent-blue)'];
  }
}
elseif ($presTrend3h <= -0.5 && $dryAir) {
  // Pressure dropping but the air is dry -> clouds building, not rain yet.
  $forecast = ['icon' => 'cloud', 'text' => 'Nuvoloso', 'color' => 'var(--text-muted)'];
}
elseif ($humiValid && $humi >= 95 && abs($presTrend3h) < 0.4 && $mslp <= 1022 && $outTemp <= 8.0) {
  // Saturated, calm and cool -> fog / mist.
  $forecast = ['icon' => 'cloud', 'text' => 'Nebbia', 'color' => 'var(--text-muted)'];
}
elseif ($mslp > 1022 && $dryAir) {
  // High pressure + dry air -> confidently clear.
  $forecast = ['icon' => 'sun', 'text' => 'Sereno', 'color' => 'var(--accent-orange)'];
}
elseif ($mslp > 1022) {
  // High pressure but humid -> hazy / veiled sun.
  $forecast = ['icon' => 'partly_cloudy', 'text' => 'Velato', 'color' => 'var(--accent-orange)'];
}
elseif ($mslp > 1016 && $presTrend3h > -0.2) {
  $forecast = ['icon' => 'partly_cloudy', 'text' => 'Poco Nuvoloso', 'color' => 'var(--accent-orange)'];
}
elseif ($presTrend3h >= 0.5) {
  $forecast = ['icon' => 'partly_cloudy', 'text' => 'In Miglioramento', 'color' => 'var(--accent-orange)'];
}
elseif ($mslp < 1008) {
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
$sunPct  = ($sunFrac !== null) ? (int) round($sunFrac * 100) : null;

$skyNow = null; // ['text' => ..., 'color' => ...]
if ($sunFrac !== null) {
  if ($sunFrac >= 0.70) {
    $skyNow = ['text' => 'Sereno',        'color' => 'var(--accent-orange)'];
  } elseif ($sunFrac >= 0.40) {
    $skyNow = ['text' => 'Poco Nuvoloso', 'color' => 'var(--accent-orange)'];
  } elseif ($sunFrac >= 0.15) {
    $skyNow = ['text' => 'Nuvoloso',      'color' => 'var(--text-muted)'];
  } else {
    $skyNow = ['text' => 'Coperto',       'color' => 'var(--text-muted)'];
  }
}

$iceWarning = ($outTemp > -99 && $outTemp <= 0.0);

/**
 * Estimate WHEN the forecast change is expected, from the rate of the
 * 3h pressure trend. A steeper drop/rise means the system is closer.
 * Returns '' for stable conditions (nothing to time).
 */
function getForecastTiming(array $forecast, float $presTrend3h): string {
  $worsening = ['snow', 'snow_storm', 'storm', 'sleet', 'rain'];

  if (in_array($forecast['icon'], $worsening, true)) {
    $drop = abs($presTrend3h);
    if      ($drop >= 1.5) { $lo = 1; $hi = 2;  }   // very rapid -> imminent
    elseif  ($drop >= 0.8) { $lo = 2; $hi = 4;  }
    elseif  ($drop >= 0.5) { $lo = 4; $hi = 8;  }
    else                   { $lo = 8; $hi = 14; }   // slow drift
    $eta = date('H:i', time() + (int)((($lo + $hi) / 2) * 3600));
    return "tra ~{$lo}-{$hi} h (verso le {$eta})";
  }

  // Clouds building on a falling barometer -> time the expected worsening.
  if ($forecast['text'] === 'Nuvoloso' && $presTrend3h <= -0.3) {
    $drop = abs($presTrend3h);
    if      ($drop >= 1.5) { $lo = 1; $hi = 2;  }
    elseif  ($drop >= 0.8) { $lo = 2; $hi = 4;  }
    elseif  ($drop >= 0.5) { $lo = 4; $hi = 8;  }
    else                   { $lo = 6; $hi = 12; }
    $eta = date('H:i', time() + (int)((($lo + $hi) / 2) * 3600));
    return "peggioramento tra ~{$lo}-{$hi} h (verso le {$eta})";
  }

  if ($forecast['text'] === 'In Miglioramento') {
    $rise = abs($presTrend3h);
    if ($rise >= 1.0) { $lo = 2; $hi = 4; } else { $lo = 4; $hi = 8; }
    $eta = date('H:i', time() + (int)((($lo + $hi) / 2) * 3600));
    return "schiarite tra ~{$lo}-{$hi} h (verso le {$eta})";
  }

  return '';
}

$forecastTiming = getForecastTiming($forecast, $presTrend3h);

/** ---------- 4. TRENDS & FILTERS ---------- */
$time30mAgo = $now - 1800;
$queryTrend = "SELECT `temperatura`, `tombra`, `tMobile`, `portata` FROM `dati_meteo` WHERE `data` <= {$time30mAgo} ORDER BY `data` DESC LIMIT 1";
$resTrend = $link->query($queryTrend);
$trendData = ($resTrend && $resTrend->num_rows > 0) ? $resTrend->fetch_assoc() : null;

function calculateTrend($current, $old) {
  if ($current === null || $old === null || $current <= -50) return null;
  $val = ($current - (float) $old) * 2;
  return (abs($val) > 20) ? null : $val;
}

function getFreezingTime($currentTemp, $trendPerHour) {
  if ($currentTemp === null || $trendPerHour === null || $currentTemp <= -50) return null;
  if ($currentTemp > 0 && $trendPerHour < -0.1) {
    $hoursToZero = $currentTemp / abs((float)$trendPerHour);
    $targetTime = time() + (int)($hoursToZero * 3600);
    $limitTime = strtotime('tomorrow 06:00');
    if ($targetTime < $limitTime) return date('H:i', $targetTime);
  }
  return null;
}

function getTrendHtml($val, $unit = '°C/h', $icePrediction = null) {
  if ($val === null) return '<span class="trend-neutral">--</span>';
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

$trend_temp1 = calculateTrend($safeTemp0, $trendData['temperatura'] ?? null);
$trend_tombra = calculateTrend($safeTombra0, $trendData['tombra'] ?? null);
$trend_tMobile = calculateTrend($safeTMobile0, $trendData['tMobile'] ?? null);
$trend_piave = calculateTrend($safePortata0, $trendData['portata'] ?? null);

$iceTime_temp1 = getFreezingTime($safeTemp0, $trend_temp1);
$iceTime_tombra = getFreezingTime($safeTombra0, $trend_tombra);
$iceTime_tMobile = getFreezingTime($safeTMobile0, $trend_tMobile);

/** ---------- 5. RIVER & POWER STATUS ---------- */
$waterStatus = 'normal';
if ($safePortata0 > 100.0) $waterStatus = 'increasing';
elseif ($safePortata0 <= 17.0) $waterStatus = 'dry';

function getTempClass($val) { return ($val < 1 && $val > -99) ? 'freezing' : ''; }
function getPowerClass($val) { return ($val <= 0) ? 'night-mode' : ''; }
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="refresh" content="60">
  <title>Dashboard Meteo Cesana</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg-color: #f0f2f5; --card-bg: #ffffff; --text-main: #1f2937; --text-muted: #6b7280;
      --accent-blue: #3b82f6; --accent-orange: #f59e0b; --accent-red: #ef4444;
      --accent-teal: #14b8a6; --accent-ice: #0ea5e9; --accent-purple: #8b5cf6;
      --accent-dry: #92400e; --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color); color: var(--text-main); padding: 20px; }
    a { text-decoration: none !important; color: inherit; display: flex; flex-direction: column; align-items: center; width: 100%; height: 100%; }
    header { text-align: center; margin-bottom: 30px; }
    header h1 { font-weight: 800; font-size: 1.5rem; }
    .dashboard-grid { display: grid; gap: 20px; max-width: 1200px; margin: 0 auto; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
    .card { background: var(--card-bg); border-radius: 16px; padding: 20px; box-shadow: var(--shadow); border-top: 5px solid transparent; transition: transform 0.2s; min-height: 250px; }
    .card:hover { transform: translateY(-3px); }
    .card-icon { width: 48px; height: 48px; margin-bottom: 10px; fill: currentColor; }
    .icon-temp { color: var(--accent-red); }
    .icon-power { color: var(--accent-orange); }
    .icon-humi { color: var(--accent-teal); }
    .icon-water { color: var(--accent-blue); }
    .icon-cold { display: none; }
    .card.freezing .icon-warm { display: none; }
    .card.freezing .icon-cold { display: block; color: var(--accent-ice); fill: none; stroke: var(--accent-ice); stroke-width: 2; }
    .icon-night { display: none; }
    .card.night-mode .icon-day { display: none; }
    .card.night-mode .icon-night { display: block; }
    .card-label { font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 5px; }
    .card-value { font-size: 2.2rem; font-weight: 800; line-height: 1; }
    .card-unit { font-size: 1rem; color: var(--text-muted); margin-left: 3px; }
    .minmax-row {
      display: flex;
      justify-content: center;
      width: 100%;
      padding-top: 15px;
      border-top: 1px solid #eee;
      margin-top: auto;
    }
    .minmax-item {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      font-size: 0.75rem;
    }
    .minmax-label {
      display: block;
      color: #9ca3af;
      font-size: 0.65rem;
      text-transform: uppercase;
      margin-bottom: 2px;
    }
    .ice-prediction {
      font-size: 0.65rem;
      color: var(--accent-ice);
      margin-top: 2px;
      text-align: center;
      width: 100%;
    }
    .minmax-val { font-weight: 700; }
    .val-min { color: var(--accent-blue); }
    .val-max { color: var(--accent-red); }
    .trend-up { color: var(--accent-red); }
    .trend-down { color: var(--accent-blue); }
    .border-temp { border-top-color: var(--accent-red); }
    .border-pres { border-top-color: var(--accent-purple); }
    .border-power { border-top-color: var(--accent-orange); }
    .border-humi { border-top-color: var(--accent-teal); }
    .border-water { border-top-color: var(--accent-blue); }
    .river-dry { border-top-color: var(--accent-dry); }
    .border-feedback { border-top-color: var(--accent-purple); }
    .icon-feedback { color: var(--accent-purple); }
    .border-multi { border-top-color: var(--accent-teal); }
    .icon-multi { color: var(--accent-teal); }
  </style>
</head>
<body>
  <header>
    <h1>Stazione Meteo Cesana</h1>
    <p><?php echo date("d-m-y H:i", $safeData0); ?></p>
  </header>

  <div class="dashboard-grid">
    <!-- 1. Temp Sole -->
    <div class="card border-temp <?php echo getTempClass($safeTemp0); ?>">
      <a href="grafico.php?var=temperatura">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24"><path d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z"/></svg>
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-cold" viewBox="0 0 24 24"><path d="M2 12h20M12 2v20M20 4l-8 8-8-8M4 20l8-8 8 8"/></svg>
        <div class="card-label">Temperatura Sole</div>
        <div class="card-value"><?php echo ($safeTemp0 <= -99 ? '--' : $safeTemp0); ?><span class="card-unit">°C</span></div>
        <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Min</span><span class="minmax-val val-min"><?php echo $mm_min_temp; ?>°</span></div>
            <div class="minmax-item"><span class="minmax-label">Trend</span><?php echo getTrendHtml($trend_temp1, '°C/h', $iceTime_temp1); ?></div>
            <div class="minmax-item"><span class="minmax-label">Max</span><span class="minmax-val val-max"><?php echo $mm_max_temp; ?>°</span></div>
        </div>
      </a>
    </div>

    <!-- 2. Temp Ombra -->
    <div class="card border-temp <?php echo getTempClass($safeTMobile0); ?>">
      <a href="grafico.php?var=tMobile">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24"><path d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z"/></svg>
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-cold" viewBox="0 0 24 24"><path d="M2 12h20M12 2v20M20 4l-8 8-8-8M4 20l8-8 8 8"/></svg>
        <div class="card-label">Temperatura Ombra</div>
        <div class="card-value"><?php echo ($safeTMobile0 <= -99 ? '--' : $safeTMobile0); ?><span class="card-unit">°C</span></div>
        <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Min</span><span class="minmax-val val-min"><?php echo $mm_min_tMobile; ?>°</span></div>
            <div class="minmax-item"><span class="minmax-label">Trend</span><?php echo getTrendHtml($trend_tMobile, '°C/h', $iceTime_tMobile); ?></div>
            <div class="minmax-item"><span class="minmax-label">Max</span><span class="minmax-val val-max"><?php echo $mm_max_tMobile; ?>°</span></div>
        </div>
      </a>
    </div>

    <!-- 3. Pressione / Forecast -->
    <div class="card border-pres">
      <a href="grafico.php?var=press">

        <?php if ($forecast['icon'] == 'sun'): ?>
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-orange)" fill="currentColor"><circle cx="12" cy="12" r="5"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></g></svg>

        <?php elseif ($forecast['icon'] == 'partly_cloudy'): ?>
          <svg viewBox="0 0 24 24" class="card-icon" fill="currentColor"><g style="color:var(--accent-orange)"><circle cx="16" cy="8" r="4"/><g stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><line x1="16" y1="1" x2="16" y2="2.5"/><line x1="16" y1="13.5" x2="16" y2="15"/><line x1="21" y1="3" x2="22" y2="2"/><line x1="10" y1="13" x2="11" y2="14"/><line x1="23" y1="8" x2="21.5" y2="8"/><line x1="10.5" y1="8" x2="9" y2="8"/><line x1="21" y1="13" x2="22" y2="14"/><line x1="10" y1="3" x2="11" y2="2"/></g></g><path d="M16.5 19c-3 0-5.5-2.5-5.5-5.5 0-3 2.5-5.5 5.5-5.5.4 0 .7 0 1.1.1C16.7 5.8 14 4 11 4 7.1 4 4 7.1 4 11c0 .1 0 .3 0 .4C2.3 12.3 1 14 1 16c0 2.8 2.2 5 5 5h10.5c2.5 0 4.5-2 4.5-4.5s-2-4.5-4.5-4.5c0 0 0 0 0 0" style="color:var(--text-muted)"/></svg>

        <?php elseif ($forecast['icon'] == 'snow'): ?>
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-ice)" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M12 2v20M2 12h20M4 4l16 16M20 4L4 20"/>
          </svg>

        <?php elseif ($forecast['icon'] == 'sleet'): ?>
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-ice)" fill="currentColor">
            <path d="M17.5 18c-3 0-5.5-2.5-5.5-5.5S14.5 7 17.5 7c.4 0 .7 0 1.1.1C17.7 4.8 15 3 12 3 8.1 3 5 6.1 5 10c0 .1 0 .3 0 .4C3.3 11.3 2 13 2 15c0 2.8 2.2 5 5 5h10.5c2.5 0 4.5-2 4.5-4.5S20 11 17.5 11"/>
            <g stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none">
              <line x1="9" y1="21" x2="9" y2="23"/>
              <line x1="15" y1="21" x2="15" y2="23"/>
            </g>
          </svg>

        <?php elseif ($forecast['icon'] == 'snow_storm'): ?>
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-ice)" fill="currentColor">
            <path d="M17.5 18c-3 0-5.5-2.5-5.5-5.5 0-3 2.5-5.5 5.5-5.5.4 0 .7 0 1.1.1C17.7 4.8 15 3 12 3 8.1 3 5 6.1 5 10c0 .1 0 .3 0 .4C3.3 11.3 2 13 2 15c0 2.8 2.2 5 5 5h10.5c2.4 0 4.5-2 4.5-4.5s-2-4.5-4.5-4.5"/>
            <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M9 20h6"/>
              <path d="M12 18v6"/>
              <path d="M10 19l4 4"/>
              <path d="M14 19l-4 4"/>
            </g>
          </svg>

        <?php elseif ($forecast['icon'] == 'rain'): ?>
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-blue)" fill="currentColor"><path d="M17.5 18c-3 0-5.5-2.5-5.5-5.5 0-3 2.5-5.5 5.5-5.5.4 0 .7 0 1.1.1C17.7 4.8 15 3 12 3 8.1 3 5 6.1 5 10c0 .1 0 .3 0 .4C3.3 11.3 2 13 2 15c0 2.8 2.2 5 5 5h10.5c2.5 0 4.5-2 4.5-4.5s-2-4.5-4.5-4.5"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="21" x2="8" y2="23"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="16" y1="21" x2="16" y2="23"/></g></svg>

        <?php elseif ($forecast['icon'] == 'storm'): ?>
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-red)" fill="currentColor"><path d="M17.5 18c-3 0-5.5-2.5-5.5-5.5 0-3 2.5-5.5 5.5-5.5.4 0 .7 0 1.1.1C17.7 4.8 15 3 12 3 8.1 3 5 6.1 5 10c0 .1 0 .3 0 .4C3.3 11.3 2 13 2 15c0 2.8 2.2 5 5 5h10.5c2.4 0 4.5-2 4.5-4.5s-2-4.5-4.5-4.5"/><path d="M13 20l-2 3h3l-2 3" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>

        <?php else: ?>
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--text-muted)" fill="currentColor"><path d="M17.5 19c-3 0-5.5-2.5-5.5-5.5 0-3 2.5-5.5 5.5-5.5.4 0 .7 0 1.1.1C17.7 5.8 15 4 12 4 8.1 4 5 7.1 5 11c0 .1 0 .3 0 .4C3.3 12.3 2 14 2 16c0 2.8 2.2 5 5 5h10.5c2.5 0 4.5-2 4.5-4.5s-2-4.5-4.5-4.5"/></svg>
        <?php endif; ?>

        <div class="card-label">Pressione</div>
        <div class="card-value"><?php echo number_format($safePres0, 1); ?><span class="card-unit">hPa</span></div>

        <div style="font-weight:800; font-size: 0.75rem; color:<?php echo $forecast['color']; ?>; margin-top:5px; text-transform:uppercase;">
          <?php echo $forecast['text']; ?>
        </div>

        <?php if (!empty($forecastTiming)): ?>
          <div style="margin-top:3px; font-size:0.68rem; color:var(--text-muted);">
            &#128337; <?php echo $forecastTiming; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($iceWarning)): ?>
          <div style="margin-top:4px; font-size:0.7rem; font-weight:700; color:var(--accent-ice);">
            &#10052; Rischio gelo
          </div>
        <?php endif; ?>

        <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Liv. Mare</span><span class="minmax-val"><?php echo number_format($mslp, 1); ?></span></div>
            <div class="minmax-item"><span class="minmax-label">Trend 3h</span><span class="minmax-val <?php echo ($presTrendValid ? ($presTrend3h < 0 ? 'trend-down' : 'trend-up') : ''); ?>"><?php echo $presTrendValid ? (($presTrend3h > 0 ? '+' : '') . number_format($presTrend3h, 1)) : 'n/d'; ?></span></div>
        </div>
      </a>
    </div>

 <!-- 4. Potenza Fotovoltaico (istantanea) -->
<div class="card border-power <?php echo getPowerClass($safePower0); ?>">
  <a href="grafico.php?var=power">

    <!-- Day icon: solar panel + lightning -->
    <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-power icon-day" viewBox="0 0 24 24" fill="currentColor">
      <!-- panel -->
      <path d="M3 11h18l-1 8H4l-1-8zm2 2 .5 4h13L19 13H5z" opacity="0.9"/>
      <path d="M4 9h16v2H4V9z"/>
      <!-- panel grid -->
      <path d="M8 11.5v7M12 11.5v7M16 11.5v7" opacity="0.35"/>
      <path d="M5.8 14.5h12.4" opacity="0.35"/>
      <!-- lightning -->
      <path d="M13 2 8 12h4l-1 10 5-10h-4l1-10z"/>
    </svg>

    <!-- Night icon: solar panel + moon -->
    <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-night" viewBox="0 0 24 24" fill="currentColor">
      <!-- moon -->
      <path d="M18.5 2.5c-3.6.6-6.3 3.7-6.3 7.5 0 4.2 3.4 7.6 7.6 7.6 1.1 0 2.1-.2 3-.6-1.2 2.8-4 4.8-7.2 4.8-4.3 0-7.8-3.5-7.8-7.8 0-3.8 2.7-6.9 6.3-7.5-.6-.2-1.1-.3-1.6-.3z" opacity="0.9"/>
      <!-- panel -->
      <path d="M3 11h18l-1 8H4l-1-8zm2 2 .5 4h13L19 13H5z" opacity="0.55"/>
      <path d="M4 9h16v2H4V9z" opacity="0.55"/>
      <!-- panel grid -->
      <path d="M8 11.5v7M12 11.5v7M16 11.5v7" opacity="0.25"/>
      <path d="M5.8 14.5h12.4" opacity="0.25"/>
    </svg>

    <div class="card-label">Potenza Fotovoltaico</div>
    <div class="card-value"><?php echo $safePower0; ?><span class="card-unit">W</span></div>

    <?php if ($skyNow !== null): ?>
      <div style="font-weight:800; font-size:0.75rem; color:<?php echo $skyNow['color']; ?>; margin-top:5px; text-transform:uppercase;">
        &#9728; <?php echo $skyNow['text']; ?>
      </div>
    <?php endif; ?>

    <div class="minmax-row">
      <div class="minmax-item">
        <span class="minmax-label">Picco 24h</span>
        <span class="minmax-val"><?php echo $mm_max_power; ?> W</span>
      </div>
      <div class="minmax-item">
        <span class="minmax-label">Irraggiamento</span>
        <span class="minmax-val"><?php echo ($sunPct !== null ? $sunPct . '%' : '--'); ?></span>
      </div>
    </div>
  </a>
</div>

    <!-- 5. Umidità -->
    <div class="card border-humi">
      <a href="grafico.php?var=hombra">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-humi" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.25c0 7.039 4.343 11.233 4.343 14.5a5.093 5.093 0 0 1-10.186 0c0-3.267 4.343-7.461 4.343-14.5a.75.75 0 0 1 .75-.75Z"/></svg>
        <div class="card-label">Umidità</div>
        <div class="card-value"><?php echo $safeHombra0; ?><span class="card-unit">%</span></div>
        <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Range 24h</span><span class="minmax-val"><?php echo $mm_min_humi; ?>% - <?php echo $mm_max_humi; ?>%</span></div>
        </div>
      </a>
    </div>

    <!-- 6. Piave -->
    <div class="card border-water <?php echo ($waterStatus == 'dry' ? 'river-dry' : ''); ?>">
      <a href="grafico.php?var=portata">
        <?php if ($waterStatus == 'dry'): ?>
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-dry)" fill="currentColor"><path d="M2 13h20v2H2v-2zm2-4h16v2H4V9zm4-4h8v2H8V5z" opacity="0.3"/><path d="M12 22a9 9 0 0 1-9-9c0-1.5.5-3 1.5-4l1.5 1.5c-.6.7-1 1.6-1 2.5 0 3.9 3.1 7 7 7s7-3.1 7-7c0-.9-.4-1.8-1-2.5l1.5-1.5c1 1 1.5 2.5 1.5 4a9 9 0 0 1-9 9z"/></svg>
        <?php else: ?>
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-blue)" fill="currentColor"><path d="M3 14c2 0 3-1 3-3s1-3 3-3 3 1 3 3 1 3 3 3 3-1 3-3 1-3 3-3 3 1 3 3-1 3-3 3H3z"/><path d="M3 19c2 0 3-1 3-3s1-3 3-3 3 1 3 3 1 3 3 3 3-1 3-3 1-3 3-3 3 1 3 3-1 3-3 3H3z" opacity="0.4"/><?php if ($waterStatus == 'increasing' && $trend_piave > 1.5): ?><path d="M12 2l-4 4h8l-4-4z" style="color:var(--accent-red)"/><?php endif; ?></svg>
        <?php endif; ?>
        <div class="card-label">Fiume Piave</div>
        <div class="card-value"><?php echo number_format($safePortata0, 2); ?><span class="card-unit">m³/s</span></div>
        <div style="font-weight:800; font-size:0.8rem; margin-top:5px; text-transform:uppercase; color:<?php echo ($waterStatus == 'dry' ? 'var(--accent-dry)' : ($waterStatus == 'increasing' ? 'var(--accent-red)' : 'var(--accent-blue)')); ?>;">
            <?php echo ($waterStatus == 'dry' ? 'Secca' : ($waterStatus == 'increasing' ? 'In Piena' : 'Normale')); ?>
        </div>
        <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Trend Orario</span><?php echo getTrendHtml($trend_piave, 'm³/s/h'); ?></div>
        </div>
      </a>
    </div>

    <!-- 7. Temp Interno -->
    <div class="card border-temp <?php echo getTempClass($safeTombra0); ?>">
      <a href="grafico.php?var=tombra">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24"><path d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z"/></svg>
        <div class="card-label">Temperatura Interno</div>
        <div class="card-value"><?php echo ($safeTombra0 <= -99 ? '--' : $safeTombra0); ?><span class="card-unit">°C</span></div>
        <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Min</span><span class="minmax-val val-min"><?php echo $mm_min_tombra; ?>°</span></div>
            <div class="minmax-item"><span class="minmax-label">Trend</span><?php echo getTrendHtml($trend_tombra, '°C/h', $iceTime_tombra); ?></div>
            <div class="minmax-item"><span class="minmax-label">Max</span><span class="minmax-val val-max"><?php echo $mm_max_tombra; ?>°</span></div>
        </div>
      </a>
    </div>

    <!-- 8. Feedback Previsioni -->
    <div class="card border-feedback">
      <a href="feedback.php">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-feedback" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zM9.5 12.5l-2.5-3 1.4-1.1 1 1.3 3.2-3.9 1.4 1.2-4.5 5.5z"/></svg>
        <div class="card-label">Feedback Previsioni</div>
        <div class="card-value" style="font-size:1.3rem; margin-top:6px;">Com'è il cielo?</div>
        <div style="font-weight:600; font-size:0.78rem; color:var(--text-muted); margin-top:8px; text-align:center;">
          Segnala il meteo reale<br>per tarare l'algoritmo
        </div>
        <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Previsto ora</span><span class="minmax-val" style="color:<?php echo $forecast['color']; ?>"><?php echo $forecast['text']; ?></span></div>
        </div>
      </a>
    </div>

    <!-- 9. Multi Plot -->
    <div class="card border-multi">
      <a href="grafico.php?var=multi">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-multi" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l3-4 3 3 4-6"/></svg>
        <div class="card-label">Multi Plot</div>
        <div class="card-value" style="font-size:1.3rem; margin-top:6px;">Confronta dati</div>
        <div style="font-weight:600; font-size:0.78rem; color:var(--text-muted); margin-top:8px; text-align:center;">
          Sovrapponi più variabili<br>sullo stesso grafico
        </div>
        <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Grafico</span><span class="minmax-val" style="color:var(--accent-teal)">Comparativo</span></div>
        </div>
      </a>
    </div>
  </div>

  <footer style="margin-top:40px; text-align:center; font-size:0.8rem; color:var(--text-muted);">
    &copy; <?php echo date("Y"); ?> Cesana Beach | Alt: 264m slm | Powered by Steplab
  </footer>
</body>
</html>
<?php
$output = ob_get_contents();
ob_end_flush();
if ($output !== false) file_put_contents($file_path, $output);
?>