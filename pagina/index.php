<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Europe/Rome');

$file_path = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . "/icache.html";

// Simple cache check (disabled)
if (is_file($file_path) && filemtime($file_path) > (time() - 60)) {
  // readfile($file_path);
  // exit;
}

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
$mm = [];

if ($resMinMax && $rowMM = $resMinMax->fetch_assoc()) {
  $mm = $rowMM;
}

// Format Helper
function fmt($val, $decimals = 1, $default = '--')
{
  return (isset($val) && $val !== null && $val !== '') ? round((float) $val, $decimals) : $default;
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


/** ---------- 2. ENERGY CALCULATION ---------- */
$energy = 0.0;
$a = 0;
$prev = null;

$query = "SELECT `power` FROM `dati_meteo` WHERE `data` < {$timeup} AND `data` > {$timedw}";
$result = $link->query($query);
if ($result instanceof mysqli_result) {
  while ($row = $result->fetch_assoc()) {
    $act = (float) ($row['power'] ?? 0);
    if ($a > 0 && $prev !== null) {
      $energy += ($act + $prev) / 2.0;
    }
    $prev = $act;
    $a++;
  }
  $result->free();
}
$energy = $energy / 6.0;

/** ---------- 3. LATEST DATA ---------- */
$temp = $tombra = $hombra = $power = $dataora = $tMobile = $portata = [];

$result = $link->query("SELECT `temperatura`,`data`,`tombra`,`hombra`,`power`,`tMobile`,`portata` FROM `dati_meteo` ORDER BY `id` DESC LIMIT 4");

if ($result instanceof mysqli_result) {
  while ($row = $result->fetch_assoc()) {
    $temp[] = isset($row['temperatura']) ? (float) $row['temperatura'] : null;
    $dataora[] = isset($row['data']) ? (int) $row['data'] : null;
    $tombra[] = isset($row['tombra']) ? (float) $row['tombra'] : null;
    $hombra[] = isset($row['hombra']) ? (int) $row['hombra'] : null;
    $power[] = isset($row['power']) ? (float) $row['power'] : null;
    $tMobile[] = isset($row['tMobile']) ? (float) $row['tMobile'] : null;
    $portata[] = isset($row['portata']) ? (float) $row['portata'] : null;
  }
  $result->free();
}

$temp = array_pad($temp, 4, null);
$tombra = array_pad($tombra, 4, null);
$hombra = array_pad($hombra, 4, null);
$power = array_pad($power, 4, null);
$dataora = array_pad($dataora, 4, null);
$tMobile = array_pad($tMobile, 4, null);
$portata = array_pad($portata, 4, null);

$safeTemp0 = ($temp[0] !== null && (float) $temp[0] > -50) ? (float) $temp[0] : -100.0;
$safeTombra0 = ($tombra[0] !== null && (float) $tombra[0] > -50) ? (float) $tombra[0] : -100.0;
$safeTMobile0 = ($tMobile[0] !== null && (float) $tMobile[0] > -50) ? (float) $tMobile[0] : -100.0;

$safeHombra0 = ($hombra[0] !== null) ? (string) $hombra[0] : '0';
$safePower0 = ($power[0] !== null) ? (float) $power[0] : 0.0;
$safeData0 = ($dataora[0] !== null) ? (int) $dataora[0] : time();
$safePortata0 = ($portata[0] !== null) ? (float) $portata[0] : 0.0;

/** ---------- 3b. PRESSURE & WEATHER FORECAST ---------- */
$stateFile = '/dev/shm/thermo_data/state.json';
$presHistoryFile = '/dev/shm/thermo_data/pres_history.csv';
$safePres0 = 1013.0;
$presTrend3h = 0.0;

if (file_exists($stateFile)) {
  $stateJson = json_decode((string) file_get_contents($stateFile), true);
  if (isset($stateJson['pres'])) $safePres0 = (float) $stateJson['pres'];
}

if (file_exists($presHistoryFile)) {
  $pRows = file($presHistoryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  $targetTs = time() - 10800; // 3 hours ago
  $oldP = null;
  foreach (array_reverse($pRows) as $pr) {
    $pParts = explode(',', $pr);
    if ((int) $pParts[0] <= $targetTs) {
      $oldP = (float) $pParts[1];
      break;
    }
  }
  if ($oldP !== null) $presTrend3h = $safePres0 - $oldP;
}

// Altitude Correction for 264m (+31.8 hPa)
$offsetPress = 2.5;
$mslp = $safePres0 + 31.8 -$offsetPress;

// Weather Logic (FIXED + TEXTS CORRECT)
$forecast = ['icon' => 'cloud', 'text' => 'Variabile', 'color' => 'var(--text-muted)'];
if ($presTrend3h <= -1.5) {
  $forecast = ['icon' => 'storm', 'text' => 'Temporale', 'color' => 'var(--accent-red)'];
} elseif ($presTrend3h <= -0.5) {
  $forecast = ['icon' => 'rain', 'text' => 'Pioggia', 'color' => 'var(--accent-blue)'];
} elseif ($mslp > 1022) {
  $forecast = ['icon' => 'sun', 'text' => 'Sereno', 'color' => 'var(--accent-orange)'];
} elseif ($mslp > 1016 && $presTrend3h > -0.2) {
  $forecast = ['icon' => 'partly_cloudy', 'text' => 'Poco Nuvoloso', 'color' => 'var(--accent-orange)'];
} elseif ($presTrend3h >= 0.5) {
  $forecast = ['icon' => 'partly_cloudy', 'text' => 'In Miglioramento', 'color' => 'var(--accent-orange)'];
} elseif ($mslp < 1008) {
  $forecast = ['icon' => 'rain', 'text' => 'Instabile', 'color' => 'var(--accent-blue)'];
}

/** ---------- 4. TREND CALCULATION (30 mins) ---------- */
$time30mAgo = $now - 1800;
$queryTrend = "SELECT `temperatura`, `tombra`, `tMobile`, `portata`, `data` 
               FROM `dati_meteo` 
               WHERE `data` <= {$time30mAgo} 
               ORDER BY `data` DESC 
               LIMIT 1";

$resTrend = $link->query($queryTrend);
$trendData = ($resTrend && $resTrend->num_rows > 0) ? $resTrend->fetch_assoc() : null;

function calculateTrend($current, $old)
{
  if ($current === null || $old === null || $current <= -50) return null;
  return ($current - (float) $old) * 2;
}

$trend_temp1 = calculateTrend($safeTemp0, $trendData['temperatura'] ?? null);
$trend_tombra = calculateTrend($safeTombra0, $trendData['tombra'] ?? null);
$trend_tMobile = calculateTrend($safeTMobile0, $trendData['tMobile'] ?? null);
$trend_piave = calculateTrend($safePortata0, $trendData['portata'] ?? null);

/** ---------- 4b. RIVER STATUS LOGIC ---------- */
$waterStatus = 'normal';

// Trigger "In Piena" if flow > 100 OR if trend is fast AND flow is > 100
if ($safePortata0 > 100.0) {
    $waterStatus = 'increasing';
} 
// Trigger "Secca" if flow <= 17 (keeping your original low-water logic)
elseif ($safePortata0 <= 17.0) {
    $waterStatus = 'dry';
} 
// Otherwise, it is "Normale"
else {
    $waterStatus = 'normal';
}

/** ---------- 5. FREEZING TIME PREDICTION ---------- */
function getFreezingTime($currentTemp, $trendPerHour)
{
  // Se uno dei due valori è null, non possiamo calcolare nulla
  if ($currentTemp === null || $trendPerHour === null) {
    return null;
  }

  // Procediamo solo se la temperatura è sopra zero e il trend è in calo
  if ($currentTemp > 0 && $trendPerHour < -0.1) {
    $hoursToZero = $currentTemp / abs((float)$trendPerHour);
    if ($hoursToZero < 24) {
      $secondsToZero = (int) ($hoursToZero * 3600);
      $targetTime = time() + $secondsToZero;
      return date('H:i', $targetTime);
    }
  }
  return null;
}

$iceTime_temp1 = getFreezingTime($safeTemp0, $trend_temp1);
$iceTime_tombra = getFreezingTime($safeTombra0, $trend_tombra);
$iceTime_tMobile = getFreezingTime($safeTMobile0, $trend_temp1);

function getTrendHtml($val, $unit = '°C/h', $icePrediction = null)
{
  if ($val === null) return '<span class="trend-neutral">--</span>';
  $rounded = round($val, 2);
  $sign = ($rounded > 0) ? '+' : '';
  $colorClass = ($rounded > 0) ? 'trend-up' : (($rounded < 0) ? 'trend-down' : 'trend-neutral');
  $arrow = ($rounded > 0) ? '&#8593;' : (($rounded < 0) ? '&#8595;' : '&nbsp;');
  $html = "<span class=\"{$colorClass}\">{$arrow} {$sign}{$rounded} <small>{$unit}</small></span>";
  if ($icePrediction) {
    $html .= "<div class=\"ice-prediction\">&#10052; 0°C alle {$icePrediction}</div>";
  }
  return $html;
}

$showTemp1 = ($safeTemp0 > -99) ? '' : 'style="display:none"';
$showTemp2 = ($safeTombra0 > -99) ? '' : 'style="display:none"';
$showTemp3 = ($safeTMobile0 > -99) ? '' : 'style="display:none"';

function getTempClass($val)
{
  return ($val < 1) ? 'freezing' : '';
}
function getPowerClass($val)
{
  return ($val <= 0) ? 'night-mode' : '';
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="refresh" content="600">
  <title>Dashboard Meteo Cesana</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --bg-color: #f0f2f5; --card-bg: #ffffff; --text-main: #1f2937; --text-muted: #6b7280;
      --accent-blue: #3b82f6; --accent-orange: #f59e0b; --accent-red: #ef4444;
      --accent-teal: #14b8a6; --accent-ice: #0ea5e9; --accent-purple: #8b5cf6;
      --accent-dry: #92400e;
      --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color); color: var(--text-main); padding: 20px; }
    a { text-decoration: none; color: inherit; display: block; height: 100%; }
    header { text-align: center; margin-bottom: 30px; }
    header h1 { font-weight: 800; font-size: 1.5rem; letter-spacing: -0.025em; }
    header .powered { font-size: 0.75rem; margin-top: 5px; opacity: 0.7; }

    .dashboard-grid {
      display: grid;
      gap: 20px;
      max-width: 1200px;
      margin: 0 auto;
      grid-template-columns: 1fr;
    }
    @media (min-width: 600px) { .dashboard-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (min-width: 900px) { .dashboard-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 1200px) { .dashboard-grid { grid-template-columns: repeat(4, 1fr); } }

    .card {
      background: var(--card-bg);
      border-radius: 16px;
      padding: 20px;
      box-shadow: var(--shadow);
      transition: transform 0.2s;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: space-between;
      text-align: center;
      min-height: 220px;
    }
    .card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
    .card-content { width: 100%; display: flex; flex-direction: column; align-items: center; margin-bottom: 15px; }
    .card-icon { width: 48px; height: 48px; margin-bottom: 10px; fill: currentColor; }
    
    .icon-temp { color: var(--accent-red); }
    .icon-power { color: var(--accent-orange); }
    .icon-humi { color: var(--accent-teal); }
    .icon-water { color: var(--accent-blue); }
    .icon-pres { color: var(--accent-purple); }
    .icon-water-dry { color: var(--accent-dry); }

    .icon-cold { display: none; }
    .card.freezing .icon-warm { display: none; }
    .card.freezing .icon-cold { display: block; color: var(--accent-ice); fill: none; stroke: var(--accent-ice); }

    .icon-night { display: none; }
    .card.night-mode .icon-day { display: none; }
    .card.night-mode .icon-night { display: block; }

    .card-label { font-size: 0.85rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 5px; }
    .card-value { font-size: 2.2rem; font-weight: 800; color: var(--text-main); line-height: 1; }
    .card-unit { font-size: 1.1rem; color: var(--text-muted); margin-left: 2px; }

    .minmax-row {
      display: flex;
      justify-content: space-between;
      width: 100%;
      padding-top: 15px;
      border-top: 1px solid #e5e7eb;
      margin-top: auto;
    }
    .minmax-item { display: flex; flex-direction: column; font-size: 0.8rem; flex: 1; }
    .minmax-label { color: #9ca3af; font-size: 0.7rem; text-transform: uppercase; }
    .minmax-val { font-weight: 700; }
    .val-min { color: var(--accent-blue); }
    .val-max { color: var(--accent-red); }
    .trend-up { color: var(--accent-red); font-weight: 700; }
    .trend-down { color: var(--accent-blue); font-weight: 700; }
    .forecast-tag { font-size: 0.75rem; font-weight: 800; margin-top: 5px; text-transform: uppercase; }

    .border-power { border-top: 5px solid var(--accent-orange); }
    .border-water { border-top: 5px solid var(--accent-blue); }
    .border-water.river-dry { border-top-color: var(--accent-dry); }
    .border-humi { border-top: 5px solid var(--accent-teal); }
    .border-temp { border-top: 5px solid var(--accent-red); }
    .border-pres { border-top: 5px solid var(--accent-purple); }
    footer { text-align: center; margin-top: 40px; font-size: 0.8rem; color: var(--text-muted); }

    /* Legend */
    .weather-legend {
      max-width: 1200px;
      margin: 40px auto 20px;
      background: var(--card-bg);
      border-radius: 16px;
      padding: 20px;
      box-shadow: var(--shadow);
    }
    .weather-legend h3 {
      text-align: center;
      margin-bottom: 15px;
      font-size: 1rem;
      font-weight: 800;
      color: var(--text-main);
    }
    .legend-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 15px;
    }
    .legend-item {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.8rem;
      color: var(--text-muted);
    }
    .legend-item strong { color: var(--text-main); }
    .legend-icon { width: 28px; height: 28px; fill: currentColor; }

    .legend-icon.sun { color: var(--accent-orange); }
    .legend-icon.partly,
    .legend-icon.improve { color: var(--accent-orange); }
    .legend-icon.rain { color: var(--accent-blue); }
    .legend-icon.storm { color: var(--accent-red); }
    .legend-icon.var { color: var(--text-muted); }
  </style>

  <script async src="https://www.googletagmanager.com/gtag/js?id=G-0TX9BGLRNC"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag() { dataLayer.push(arguments); }
    gtag('js', new Date());
    gtag('config', 'G-0TX9BGLRNC');
  </script>

  <script>
    function meteo_ajax() {
      var xhttp = new XMLHttpRequest();
      xhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
          var res = String(this.responseText).split("#");
          if (res[4]) document.getElementById("dataora").textContent = res[4];
          if (document.getElementById("temperatura")) document.getElementById("temperatura").textContent = res[0];
          if (document.getElementById("tombra")) document.getElementById("tombra").textContent = res[7];
          if (document.getElementById("tMobile")) document.getElementById("tMobile").textContent = res[14];
          if (document.getElementById("power")) document.getElementById("power").textContent = res[10];
          if (document.getElementById("hombra")) document.getElementById("hombra").textContent = res[8];
          if (document.getElementById("piave_portata")) document.getElementById("piave_portata").textContent = res[15];
        }
      };
      xhttp.open("GET", "dati_ajax.php?c=" + new Date().getTime(), true);
      xhttp.send();

      fetch('get_setpoint.php?' + new Date().getTime())
        .then(response => response.json())
        .then(data => {
            if (data.pres) document.getElementById("actualPres").textContent = parseFloat(data.pres).toFixed(1);
        }).catch(err => {});
    }
    setInterval(meteo_ajax, 10000);
    window.onload = meteo_ajax;
  </script>
</head>

<body>
  <header>
    <h1>Stazione Meteo Cesana</h1>
    <p id="dataora"><?php echo date("d-m-y  H:i", $safeData0); ?></p>
    <div class="powered">Powered by <a href="http://www.steplab.net" style="display:inline; color:#3b82f6;">Steplab</a></div>
  </header>

  <div class="dashboard-grid">
    <!-- 1. Temp Sensore (Sole) -->
    <div class="card border-temp <?php echo getTempClass($safeTemp0); ?>" id="temp1_card" <?php echo $showTemp1; ?>>
      <a href="grafico.php?var=temperatura" class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z"/></svg>
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-cold" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12h20M12 2v20M20 4l-8 8-8-8M4 20l8-8 8 8"/></svg>
        <div class="card-label">Temp (Sole)</div>
        <div><span class="card-value" id="temperatura"><?php echo $safeTemp0; ?></span><span class="card-unit">°C</span></div>
      </a>
      <div class="minmax-row">
        <div class="minmax-item">
          <span class="minmax-label">Min 24h</span>
          <span class="minmax-val val-min"><?php echo $mm_min_temp; ?>°</span>
        </div>
        <div class="minmax-item">
          <span class="minmax-label">Trend</span>
          <span class="minmax-val"><?php echo getTrendHtml($trend_temp1, '°C/h', $iceTime_temp1); ?></span>
        </div>
        <div class="minmax-item">
          <span class="minmax-label">Max 24h</span>
          <span class="minmax-val val-max"><?php echo $mm_max_temp; ?>°</span>
        </div>
      </div>
    </div>

    <!-- 2. Temp Mobile -->
    <div class="card border-temp <?php echo getTempClass($safeTMobile0); ?>" id="temp3_card" <?php echo $showTemp3; ?>>
      <a href="grafico.php?var=tMobile" class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z"/></svg>
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-cold" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12h20M12 2v20M20 4l-8 8-8-8M4 20l8-8 8 8"/></svg>
        <div class="card-label">Temp (Ombra)</div>
        <div><span class="card-value" id="tMobile"><?php echo $safeTMobile0; ?></span><span class="card-unit">°C</span></div>
      </a>
      <div class="minmax-row">
        <div class="minmax-item"><span class="minmax-label">Min 24h</span><span class="minmax-val val-min"><?php echo $mm_min_tMobile; ?>°</span></div>
        <div class="minmax-item"><span class="minmax-label">Max 24h</span><span class="minmax-val val-max"><?php echo $mm_max_tMobile; ?>°</span></div>
      </div>
    </div>

    <!-- 3. Pressione & Previsioni -->
    <div class="card border-pres" id="pres_card">
      <div class="card-content">
        <?php if ($forecast['icon'] == 'sun'): ?>
          <!-- Sun -->
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-orange)" fill="currentColor">
            <circle cx="12" cy="12" r="5"/>
            <path d="M12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"
                  stroke="currentColor" stroke-width="2" fill="none"/>
          </svg>

        <?php elseif ($forecast['icon'] == 'partly_cloudy'): ?>
          <!-- Sun + Cloud -->
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-orange)" fill="currentColor">
            <circle cx="17" cy="7" r="3"/>
            <path d="M17 1v2M17 11v2M11 7h2M21 7h2
                     M12.5 2.5l1.4 1.4
                     M19.1 9.1l1.4 1.4
                     M12.5 11.5l1.4-1.4
                     M19.1 4.9l1.4-1.4"
                  stroke="currentColor" stroke-width="1.5" fill="none"/>
            <path d="M6.5 19
                     C4 19 2 17.2 2 14.9
                     c0-2 1.4-3.7 3.3-4.1
                     C5.3 7.8 7.8 6 10.7 6
                     c2.8 0 5.2 1.7 6 4.2
                     h.3
                     c2.3 0 4.2 1.8 4.2 4
                     s-1.9 4-4.2 4
                     H6.5z"
                  style="color:var(--text-muted)"/>
          </svg>

        <?php elseif ($forecast['icon'] == 'rain'): ?>
          <!-- Rain -->
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-blue)" fill="currentColor">
            <path d="M17.5 19c-3.037 0-5.5-2.463-5.5-5.5 0-3.037 2.463-5.5 5.5-5.5.38 0 .75.039 1.107.111C17.706 5.826 15.081 4 12 4 8.134 4 5 7.134 5 11c0 .138.004.276.012.412C3.289 12.288 2 13.992 2 16c0 2.761 2.239 5 5 5h10.5c2.485 0 4.5-2.015 4.5-4.5S19.985 12 17.5 12z"/>
            <path d="M9 13v3M12 13v3M15 13v3" stroke="white" stroke-width="2" fill="none"/>
          </svg>

        <?php elseif ($forecast['icon'] == 'storm'): ?>
          <!-- Storm (distinct from rain) -->
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--accent-red)" fill="currentColor">
            <path d="M17.5 19c-3.037 0-5.5-2.463-5.5-5.5 0-3.037 2.463-5.5 5.5-5.5.38 0 .75.039 1.107.111C17.706 5.826 15.081 4 12 4 8.134 4 5 7.134 5 11c0 .138.004.276.012.412C3.289 12.288 2 13.992 2 16c0 2.761 2.239 5 5 5h10.5c2.485 0 4.5-2.015 4.5-4.5S19.985 12 17.5 12z"/>
            <path d="M9 13v3M12 13v3" stroke="white" stroke-width="2" fill="none"/>
            <path d="M14 12l-2 4h3l-2 4" stroke="white" stroke-width="2" fill="none"/>
          </svg>

        <?php else: ?>
          <!-- Cloud -->
          <svg viewBox="0 0 24 24" class="card-icon" style="color:var(--text-muted)" fill="currentColor">
            <path d="M17.5 19c-3.037 0-5.5-2.463-5.5-5.5 0-3.037 2.463-5.5 5.5-5.5.38 0 .75.039 1.107.111C17.706 5.826 15.081 4 12 4 8.134 4 5 7.134 5 11c0 .138.004.276.012.412C3.289 12.288 2 13.992 2 16c0 2.761 2.239 5 5 5h10.5c2.485 0 4.5-2.015 4.5-4.5S19.985 12 17.5 12z"/>
          </svg>
        <?php endif; ?>

        <div class="card-label">Pressione</div>
        <div><span class="card-value" id="actualPres"><?php echo number_format($safePres0, 1); ?></span><span class="card-unit">hPa</span></div>
        <div class="forecast-tag" style="color: <?php echo $forecast['color']; ?>"><?php echo $forecast['text']; ?></div>
      </div>
      <div class="minmax-row">
        <div class="minmax-item"><span class="minmax-label">Sea Lvl</span><span class="minmax-val"><?php echo number_format($mslp, 1); ?></span></div>
        <div class="minmax-item"><span class="minmax-label">Tend (3h)</span><span class="minmax-val <?php echo ($presTrend3h < 0 ? 'trend-down' : 'trend-up'); ?>"><?php echo ($presTrend3h > 0 ? '+': '') . number_format($presTrend3h, 1); ?></span></div>
      </div>
    </div>

    <!-- 4. Potenza -->
    <div class="card border-power <?php echo getPowerClass($safePower0); ?>" id="power_card">
      <a href="grafico.php?var=power" class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-power" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.25v2.25M12 18.75V21M18.75 12h2.25M3 12h2.25M12 7.5a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9Z"/></svg>
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-night" viewBox="0 0 24 24" fill="currentColor"><path d="M9.528 1.718a.75.75 0 01.162.819A8.97 8.97 0 009 6a9 9 0 009 9 8.97 8.97 0 003.463-.69.75.75 0 01.981.98 10.503 10.503 0 01-9.694 6.46c-5.799 0-10.5-4.701-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 01.818.162z"/></svg>
        <div class="card-label">Potenza</div>
        <div><span class="card-value" id="power"><?php echo $safePower0; ?></span><span class="card-unit">W</span></div>
      </a>
      <div class="minmax-row"><div class="minmax-item"><span class="minmax-label">Peak 24h</span><span class="minmax-val"><?php echo $mm_max_power; ?>W</span></div></div>
    </div>

    <!-- 5. Umidità -->
    <div class="card border-humi">
      <a href="grafico.php?var=hombra" class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-humi" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.25c0 7.039 4.343 11.233 4.343 14.5a5.093 5.093 0 0 1-10.186 0c0-3.267 4.343-7.461 4.343-14.5a.75.75 0 0 1 .75-.75Z"/></svg>
        <div class="card-label">Umidità</div>
        <div><span class="card-value" id="hombra"><?php echo $safeHombra0; ?></span><span class="card-unit">%</span></div>
      </a>
      <div class="minmax-row"><div class="minmax-item"><span class="minmax-label">Range 24h</span><span class="minmax-val"><?php echo $mm_min_humi; ?>-<?php echo $mm_max_humi; ?>%</span></div></div>
    </div>

   
<!-- 6. Piave -->
    <div class="card border-water <?php echo ($waterStatus == 'dry' ? 'river-dry' : ''); ?>">
      <a href="grafico.php?var=portata" class="card-content">
        <?php if ($waterStatus == 'dry'): ?>
          <!-- Icona Secca (Marrone) -->
          <svg viewBox="0 0 24 24" class="card-icon icon-water-dry" fill="currentColor"><path d="M2 13h20v2H2v-2zm2-4h16v2H4V9zm4-4h8v2H8V5z" opacity="0.3"/><path d="M12 22a9 9 0 0 1-9-9c0-1.5.5-3 1.5-4l1.5 1.5c-.6.7-1 1.6-1 2.5 0 3.9 3.1 7 7 7s7-3.1 7-7c0-.9-.4-1.8-1-2.5l1.5-1.5c1 1 1.5 2.5 1.5 4a9 9 0 0 1-9 9z"/><path d="M7 12h10v1H7z"/></svg>
        <?php elseif ($waterStatus == 'increasing'): ?>
          <!-- Icona In Piena (Rossa con freccia se il trend è forte) -->
          <svg viewBox="0 0 24 24" class="card-icon icon-water" fill="currentColor">
            <path d="M3 14c2 0 3-1 3-3s1-3 3-3 3 1 3 3 1 3 3 3 3-1 3-3 1-3 3-3 3 1 3 3-1 3-3 3H3z"/>
            <?php if ($trend_piave > 1.5): ?>
              <path d="M12 2l-4 4h8l-4-4z" fill="var(--accent-red)"/> <!-- Freccia allerta trend -->
            <?php endif; ?>
            <path d="M3.5 18c0-1.5 1-2.5 2.5-2.5s2.5 1 2.5 2.5-1 2.5-2.5 2.5-2.5-1-2.5-2.5zM15.5 18c0-1.5 1-2.5 2.5-2.5s2.5 1 2.5 2.5-1 2.5-2.5 2.5-2.5-1-2.5-2.5z" opacity="0.5"/>
          </svg>
        <?php else: ?>
          <!-- Icona Normale (Blu) -->
          <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-water" viewBox="0 0 24 24" fill="currentColor"><path d="M3.75 6h15M3.75 12h15M3.75 18h15"/></svg>
        <?php endif; ?>

        <div class="card-label">Fiume Piave</div>
        <div><span class="card-value" id="piave_portata"><?php echo number_format($safePortata0, 2); ?></span><span class="card-unit">m³/s</span></div>
        
        <div class="forecast-tag" style="color: <?php 
          if ($waterStatus == 'dry') echo 'var(--accent-dry)';
          elseif ($waterStatus == 'increasing') echo 'var(--accent-red)';
          else echo 'var(--accent-blue)';
        ?>">
          <?php 
            if ($waterStatus == 'dry') echo 'Secca';
            elseif ($waterStatus == 'increasing') echo 'In Piena';
            else echo 'Normale';
          ?>
        </div>
      </a>
      <div class="minmax-row">
        <div class="minmax-item">
          <span class="minmax-label">Trend Orario</span>
          <span class="minmax-val"><?php echo getTrendHtml($trend_piave, 'm³/s/h'); ?></span>
        </div>
      </div>
    </div>

    <!-- 7. Temp Ombra (Interno) -->
    <div class="card border-temp <?php echo getTempClass($safeTombra0); ?>" id="temp2_card" <?php echo $showTemp2; ?>>
      <a href="grafico.php?var=tombra" class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z"/></svg>
        <div class="card-label">Temp (Interno)</div>
        <div><span class="card-value" id="tombra"><?php echo $safeTombra0; ?></span><span class="card-unit">°C</span></div>
      </a>
      <div class="minmax-row">
        <div class="minmax-item"><span class="minmax-label">Min 24h</span><span class="minmax-val val-min"><?php echo $mm_min_tombra; ?>°</span></div>
        <div class="minmax-item"><span class="minmax-label">Max 24h</span><span class="minmax-val val-max"><?php echo $mm_max_tombra; ?>°</span></div>
      </div>
    </div>
  </div>

  <!-- Legend -->
  <div class="weather-legend">
    <h3>Legenda Meteo</h3>

    <div class="legend-grid">
      <div class="legend-item">
        <!-- Sereno -->
        <svg viewBox="0 0 24 24" class="legend-icon sun" fill="currentColor">
          <circle cx="12" cy="12" r="5"/>
          <path d="M12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"
                stroke="currentColor" stroke-width="2" fill="none"/>
        </svg>
        <span><strong>Sereno</strong><br>Alta pressione stabile</span>
      </div>

      <div class="legend-item">
        <!-- Poco nuvoloso -->
        <svg viewBox="0 0 24 24" class="legend-icon partly" fill="currentColor">
          <circle cx="17" cy="7" r="3"/>
          <path d="M17 1v2M17 11v2M11 7h2M21 7h2
                   M12.5 2.5l1.4 1.4
                   M19.1 9.1l1.4 1.4
                   M12.5 11.5l1.4-1.4
                   M19.1 4.9l1.4-1.4"
                stroke="currentColor" stroke-width="1.5" fill="none"/>
          <path d="M6.5 19
                   C4 19 2 17.2 2 14.9
                   c0-2 1.4-3.7 3.3-4.1
                   C5.3 7.8 7.8 6 10.7 6
                   c2.8 0 5.2 1.7 6 4.2
                   h.3
                   c2.3 0 4.2 1.8 4.2 4
                   s-1.9 4-4.2 4
                   H6.5z"/>
        </svg>
        <span><strong>Poco nuvoloso</strong><br>Tempo stabile</span>
      </div>

      <div class="legend-item">
        <!-- In miglioramento -->
        <svg viewBox="0 0 24 24" class="legend-icon improve" fill="currentColor">
          <circle cx="17" cy="7" r="3"/>
          <path d="M17 1v2M17 11v2M11 7h2M21 7h2
                   M12.5 2.5l1.4 1.4
                   M19.1 9.1l1.4 1.4
                   M12.5 11.5l1.4-1.4
                   M19.1 4.9l1.4-1.4"
                stroke="currentColor" stroke-width="1.5" fill="none"/>
          <path d="M6.5 19
                   C4 19 2 17.2 2 14.9
                   c0-2 1.4-3.7 3.3-4.1
                   C5.3 7.8 7.8 6 10.7 6
                   c2.8 0 5.2 1.7 6 4.2
                   h.3
                   c2.3 0 4.2 1.8 4.2 4
                   s-1.9 4-4.2 4
                   H6.5z"/>
        </svg>
        <span><strong>In miglioramento</strong><br>Pressione in aumento</span>
      </div>

      <div class="legend-item">
        <!-- Pioggia -->
        <svg viewBox="0 0 24 24" class="legend-icon rain" fill="currentColor">
          <path d="M17.5 19c-3.037 0-5.5-2.463-5.5-5.5 0-3.037 2.463-5.5 5.5-5.5.38 0 .75.039 1.107.111C17.706 5.826 15.081 4 12 4 8.134 4 5 7.134 5 11c0 .138.004.276.012.412C3.289 12.288 2 13.992 2 16c0 2.761 2.239 5 5 5h10.5c2.485 0 4.5-2.015 4.5-4.5S19.985 12 17.5 12z"/>
          <path d="M9 13v3M12 13v3M15 13v3" stroke="white" stroke-width="2" fill="none"/>
        </svg>
        <span><strong>Pioggia</strong><br>Pressione in calo</span>
      </div>

      <div class="legend-item">
        <!-- Temporale -->
        <svg viewBox="0 0 24 24" class="legend-icon storm" fill="currentColor">
          <path d="M17.5 19c-3.037 0-5.5-2.463-5.5-5.5 0-3.037 2.463-5.5 5.5-5.5.38 0 .75.039 1.107.111C17.706 5.826 15.081 4 12 4 8.134 4 5 7.134 5 11c0 .138.004.276.012.412C3.289 12.288 2 13.992 2 16c0 2.761 2.239 5 5 5h10.5c2.485 0 4.5-2.015 4.5-4.5S19.985 12 17.5 12z"/>
          <path d="M9 13v3M12 13v3" stroke="white" stroke-width="2" fill="none"/>
          <path d="M14 12l-2 4h3l-2 4" stroke="white" stroke-width="2" fill="none"/>
        </svg>
        <span><strong>Temporale</strong><br>Forte instabilità</span>
      </div>

      <div class="legend-item">
        <!-- Variabile -->
        <svg viewBox="0 0 24 24" class="legend-icon var" fill="currentColor">
          <path d="M17.5 19c-3.037 0-5.5-2.463-5.5-5.5 0-3.037 2.463-5.5 5.5-5.5.38 0 .75.039 1.107.111C17.706 5.826 15.081 4 12 4 8.134 4 5 7.134 5 11c0 .138.004.276.012.412C3.289 12.288 2 13.992 2 16c0 2.761 2.239 5 5 5h10.5c2.485 0 4.5-2.015 4.5-4.5S19.985 12 17.5 12z"/>
        </svg>
        <span><strong>Variabile</strong><br>Condizioni miste</span>
      </div>
    </div>
  </div>

  <footer style="margin-top:20px; text-align:center;">&copy; <?php echo date("Y"); ?> Cesana Beach | Alt: 264m slm</footer>
</body>
</html>
<?php
$output = ob_get_contents();
ob_end_flush();
if ($output !== false) {
  file_put_contents($file_path, $output);
}
?>
