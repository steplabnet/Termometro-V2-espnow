<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Europe/Rome');

$file_path = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . "/icache.html";

// Simple cache check
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

/* 
   UPDATED QUERY: 
   We use CASE WHEN to check if temp > -50 before considering it for MIN/MAX.
   This prevents error values (like -127) from becoming the "Minimum" of the day.
*/
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

// Pad arrays
$temp = array_pad($temp, 4, null);
$tombra = array_pad($tombra, 4, null);
$hombra = array_pad($hombra, 4, null);
$power = array_pad($power, 4, null);
$dataora = array_pad($dataora, 4, null);
$tMobile = array_pad($tMobile, 4, null);
$portata = array_pad($portata, 4, null);

/* 
   UPDATED SAFE VALUES: 
   If value is NOT null AND > -50, use it. 
   Otherwise set to -100.0 (which hides the card in your JS).
*/
$safeTemp0 = ($temp[0] !== null && (float) $temp[0] > -50) ? (float) $temp[0] : -100.0;
$safeTombra0 = ($tombra[0] !== null && (float) $tombra[0] > -50) ? (float) $tombra[0] : -100.0;
$safeTMobile0 = ($tMobile[0] !== null && (float) $tMobile[0] > -50) ? (float) $tMobile[0] : -100.0;

$safeHombra0 = ($hombra[0] !== null) ? (string) $hombra[0] : '0';
$safePower0 = ($power[0] !== null) ? (float) $power[0] : 0.0;
$safeData0 = ($dataora[0] !== null) ? (int) $dataora[0] : time();
$safePortata0 = ($portata[0] !== null) ? (float) $portata[0] : 0.0;

/** ---------- 4. TREND CALCULATION (Last 30 mins) ---------- */
// 1800 seconds = 30 minutes
$time30mAgo = $now - 1800;

// Fetch the record closest to 30 mins ago
$queryTrend = "SELECT `temperatura`, `tombra`, `tMobile`, `portata`, `data` 
               FROM `dati_meteo` 
               WHERE `data` <= {$time30mAgo} 
               ORDER BY `data` DESC 
               LIMIT 1";

$resTrend = $link->query($queryTrend);
$trendData = ($resTrend && $resTrend->num_rows > 0) ? $resTrend->fetch_assoc() : null;

// Helper to calculate linear trend per hour
function calculateTrend($current, $old)
{
  /* UPDATED: Ignore trend calculation if current temp is invalid (< -50) */
  if ($current === null || $old === null || $current <= -50)
    return null;
  // Difference * 2 because the gap is 30 mins (0.5h)
  return ($current - (float) $old) * 2;
}

$trend_temp1 = calculateTrend($safeTemp0, $trendData['temperatura'] ?? null);
$trend_tombra = calculateTrend($safeTombra0, $trendData['tombra'] ?? null);
$trend_tMobile = calculateTrend($safeTMobile0, $trendData['tMobile'] ?? null);
$trend_piave = calculateTrend($safePortata0, $trendData['portata'] ?? null);

/** ---------- 5. FREEZING TIME PREDICTION ---------- */
function getFreezingTime($currentTemp, $trendPerHour)
{
  // Only predict if temp is positive and trend is negative (getting colder)
  // Use a small threshold (-0.1) to avoid division by near-zero or noise
  if ($currentTemp > 0 && $trendPerHour < -0.1) {
    // formula: Distance to 0 / Speed
    $hoursToZero = $currentTemp / abs($trendPerHour);

    // Only show if it will freeze within 24 hours
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
$iceTime_tMobile = getFreezingTime($safeTMobile0, $trend_tMobile);


// Helper for formatting trend HTML with optional Ice Time
function getTrendHtml($val, $unit = '°C/h', $icePrediction = null)
{
  if ($val === null)
    return '<span class="trend-neutral">--</span>';

  $rounded = round($val, 2);
  $sign = ($rounded > 0) ? '+' : '';
  $colorClass = ($rounded > 0) ? 'trend-up' : (($rounded < 0) ? 'trend-down' : 'trend-neutral');
  $arrow = ($rounded > 0) ? '&#8593;' : (($rounded < 0) ? '&#8595;' : '&nbsp;');

  $html = "<span class=\"{$colorClass}\">{$arrow} {$sign}{$rounded} <small>{$unit}</small></span>";

  // Add Ice Prediction if available
  if ($icePrediction) {
    $html .= "<div class=\"ice-prediction\">&#10052; 0°C alle {$icePrediction}</div>";
  }

  return $html;
}

// Determine visibility (Cards hide if value is -100)
$showTemp1 = ($safeTemp0 > -99) ? '' : 'style="display:none"';
$showTemp2 = ($safeTombra0 > -99) ? '' : 'style="display:none"';
$showTemp3 = ($safeTMobile0 > -99) ? '' : 'style="display:none"';

// Helper for Temp Ice
function getTempClass($val)
{
  return ($val < 1) ? 'freezing' : '';
}

// Helper for Power Night
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
      --bg-color: #f0f2f5;
      --card-bg: #ffffff;
      --text-main: #1f2937;
      --text-muted: #6b7280;

      /* Accent Colors */
      --accent-blue: #3b82f6;
      --accent-orange: #f59e0b;
      --accent-red: #ef4444;
      --accent-teal: #14b8a6;
      --accent-ice: #0ea5e9;
      --accent-black: #111827;

      --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', sans-serif;
      background-color: var(--bg-color);
      color: var(--text-main);
      padding: 20px;
    }

    a {
      text-decoration: none;
      color: inherit;
      display: block;
      height: 100%;
    }

    header {
      text-align: center;
      margin-bottom: 30px;
    }

    header h1 {
      font-weight: 800;
      font-size: 1.5rem;
      letter-spacing: -0.025em;
    }

    header p {
      color: var(--text-muted);
      font-size: 0.9rem;
      margin-top: 5px;
    }

    header .powered {
      font-size: 0.75rem;
      margin-top: 5px;
      opacity: 0.7;
    }

    /* --- GRID SYSTEM --- */
    .dashboard-grid {
      display: grid;
      gap: 20px;
      max-width: 1000px;
      margin: 0 auto;
      grid-template-columns: 1fr;
    }

    @media (min-width: 600px) {
      .dashboard-grid {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (min-width: 900px) {
      .dashboard-grid {
        grid-template-columns: repeat(3, 1fr);
      }
    }

    /* --- CARD --- */
    .card {
      background: var(--card-bg);
      border-radius: 16px;
      padding: 20px;
      box-shadow: var(--shadow);
      transition: transform 0.2s, box-shadow 0.2s;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: space-between;
      text-align: center;
      position: relative;
      overflow: hidden;
      min-height: 200px;
    }

    .card:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .card-content {
      width: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      margin-bottom: 15px;
    }

    /* Modern SVG Icons */
    .card-icon {
      width: 48px;
      height: 48px;
      margin-bottom: 10px;
      fill: currentColor;
      transition: color 0.3s ease;
    }

    /* Standard Colors */
    .icon-temp {
      color: var(--accent-red);
    }

    .icon-power {
      color: var(--accent-orange);
    }

    .icon-humi {
      color: var(--accent-teal);
    }

    .icon-water {
      color: var(--accent-blue);
    }

    /* Freezing Logic (Temp) */
    .icon-cold {
      display: none;
    }

    .card.freezing .icon-warm {
      display: none;
    }

    .card.freezing .icon-cold {
      display: block;
      color: var(--accent-ice);
      fill: none;
      stroke: var(--accent-ice);
    }

    /* Night Mode Logic */
    .icon-night {
      display: none;
    }

    .card.night-mode .icon-day {
      display: none;
    }

    .card.night-mode .icon-night {
      display: block;
      color: var(--text-main);
    }

    .card.night-mode.border-power {
      border-top-color: var(--accent-black);
    }

    .card-label {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 5px;
    }

    .card-value {
      font-size: 2.2rem;
      font-weight: 800;
      color: var(--text-main);
      line-height: 1;
    }

    .card-unit {
      font-size: 1.1rem;
      font-weight: 400;
      color: var(--text-muted);
      margin-left: 2px;
    }

    /* --- MIN/MAX & TREND FOOTER --- */
    .minmax-row {
      display: flex;
      justify-content: space-between;
      width: 100%;
      padding-top: 15px;
      border-top: 1px solid #e5e7eb;
      margin-top: auto;
      align-items: flex-start;
      /* Align top in case trend has 2 lines */
    }

    .minmax-item {
      display: flex;
      flex-direction: column;
      font-size: 0.8rem;
      flex: 1;
    }

    .minmax-label {
      color: #9ca3af;
      font-size: 0.7rem;
      text-transform: uppercase;
      margin-bottom: 2px;
    }

    .minmax-val {
      font-weight: 700;
    }

    .val-min {
      color: var(--accent-blue);
    }

    .val-max {
      color: var(--accent-red);
    }

    /* Trend Colors */
    .trend-up {
      color: var(--accent-red);
      font-weight: 700;
    }

    .trend-down {
      color: var(--accent-blue);
      font-weight: 700;
    }

    .trend-neutral {
      color: var(--text-muted);
    }

    .ice-prediction {
      color: var(--accent-ice);
      font-size: 0.75em;
      font-weight: 700;
      margin-top: 2px;
      white-space: nowrap;
    }

    small {
      font-size: 0.65em;
      font-weight: 400;
      opacity: 0.8;
    }

    /* Borders */
    .border-power {
      border-top: 5px solid var(--accent-orange);
    }

    .border-water {
      border-top: 5px solid var(--accent-blue);
    }

    .border-humi {
      border-top: 5px solid var(--accent-teal);
    }

    .border-temp {
      border-top: 5px solid var(--accent-red);
    }

    footer {
      text-align: center;
      margin-top: 40px;
      font-size: 0.8rem;
      color: var(--text-muted);
    }
  </style>

  <script async src="https://www.googletagmanager.com/gtag/js?id=G-0TX9BGLRNC"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag() { dataLayer.push(arguments); }
    gtag('js', new Date());
    gtag('config', 'G-0TX9BGLRNC');
  </script>

  <script>
    function updateTempCard(cardId, valId, newVal) {
      var elVal = document.getElementById(valId);
      if (elVal) elVal.textContent = newVal;

      var card = document.getElementById(cardId);
      if (card) {
        var numVal = parseFloat(newVal);
        // Freezing if < 1
        if (!isNaN(numVal) && numVal < 1) {
          card.classList.add('freezing');
        } else {
          card.classList.remove('freezing');
        }
      }
    }

    function updatePowerCard(cardId, valId, newVal) {
      var elVal = document.getElementById(valId);
      if (elVal) elVal.textContent = newVal;

      var card = document.getElementById(cardId);
      if (card) {
        var numVal = parseFloat(newVal);
        // Night mode if <= 0
        if (!isNaN(numVal) && numVal <= 0) {
          card.classList.add('night-mode');
        } else {
          card.classList.remove('night-mode');
        }
      }
    }

    function meteo_ajax() {
      var xhttp = new XMLHttpRequest();
      xhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
          if (this.responseText === 'noajax') return;
          var res = String(this.responseText).split("#");

          if (res[4]) document.getElementById("dataora").textContent = res[4];

          // Temp Sensore (temp1)
          var t1 = document.getElementById("temp1_card");
          if (res[0] === '-100') {
            t1.style.display = "none";
          } else {
            t1.style.display = "flex";
            updateTempCard("temp1_card", "temperatura", res[0]);
          }

          // Temp Ombra (temp2)
          var t2 = document.getElementById("temp2_card");
          if (res[7] === '-100') {
            t2.style.display = "none";
          } else {
            t2.style.display = "flex";
            updateTempCard("temp2_card", "tombra", res[7]);
          }

          // Temp Mobile (temp3)
          var t3 = document.getElementById("temp3_card");
          if (res[14] === '-100' || !res[14]) {
            t3.style.display = "none";
          } else {
            t3.style.display = "flex";
            updateTempCard("temp3_card", "tMobile", res[14]);
          }

          // Power Logic (update icon/border)
          updatePowerCard("power_card", "power", (res[10] || '0'));

          document.getElementById("hombra").textContent = (res[8] || '0');
          document.getElementById("piave_portata").textContent = (res[15] || '0');
        }
      };
      xhttp.open("GET", "dati_ajax.php?c=<?php echo time(); ?>", true);
      xhttp.send();
    }

    setInterval(meteo_ajax, 3000);
    window.onload = function () { meteo_ajax(); };
  </script>
</head>

<body>

  <header>
    <h1>Stazione Meteo Cesana</h1>
    <p id="dataora"><?php echo htmlspecialchars(date("d-m-y  H:i", $safeData0)); ?></p>
    <div class="powered">
      Powered by <a href="http://www.steplab.net" style="display:inline; color:#3b82f6;">Steplab</a>
    </div>
  </header>

  <div class="dashboard-grid">

    <!-- 1. Temp Sensore (Secondary) -->
    <div class="card border-temp <?php echo getTempClass($safeTemp0); ?>" id="temp1_card" <?php echo $showTemp1; ?>>
      <a href="grafico.php?var=temperatura" class="card-content">
        <!-- Warm Icon -->
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24"
          fill="currentColor">
          <path
            d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z" />
          <path d="M12 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z" fill="white" />
        </svg>
        <!-- Cold Icon -->
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-cold" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M2 12h20M12 2v20M20 4l-8 8-8-8M4 20l8-8 8 8" />
        </svg>
        <div class="card-label">Temp (Sole)</div>
        <div>
          <span class="card-value" id="temperatura"><?php echo htmlspecialchars((string) $safeTemp0); ?></span>
          <span class="card-unit">°C</span>
        </div>
      </a>
      <div class="minmax-row">
        <div class="minmax-item">
          <span class="minmax-label">Min 24h</span>
          <span class="minmax-val val-min"><?php echo $mm_min_temp; ?>°</span>
        </div>
        <!-- TREND -->
        <div class="minmax-item">
          <span class="minmax-label">Trend (30m)</span>
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
        <!-- Warm Icon -->
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24"
          fill="currentColor">
          <path
            d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z" />
          <path d="M12 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z" fill="white" />
        </svg>
        <!-- Cold Icon -->
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-cold" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M2 12h20M12 2v20M20 4l-8 8-8-8M4 20l8-8 8 8" />
        </svg>
        <div class="card-label">Temp (Ombra)</div>
        <div>
          <span class="card-value"
            id="tMobile"><?php echo ($safeTMobile0 > -99) ? htmlspecialchars((string) $safeTMobile0) : '--'; ?></span>
          <span class="card-unit">°C</span>
        </div>
      </a>
      <div class="minmax-row">
        <div class="minmax-item">
          <span class="minmax-label">Min 24h</span>
          <span class="minmax-val val-min"><?php echo $mm_min_tMobile; ?>°</span>
        </div>
        <!-- TREND -->
        <div class="minmax-item">
          <span class="minmax-label">Trend (30m)</span>
          <span class="minmax-val"><?php echo getTrendHtml($trend_tMobile, '°C/h', $iceTime_tMobile); ?></span>
        </div>
        <div class="minmax-item">
          <span class="minmax-label">Max 24h</span>
          <span class="minmax-val val-max"><?php echo $mm_max_tMobile; ?>°</span>
        </div>
      </div>
    </div>

    <!-- 3. Potenza -->
    <div class="card border-power <?php echo getPowerClass($safePower0); ?>" id="power_card">
      <a href="grafico.php?var=power" class="card-content">
        <!-- Day Icon -->
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-power icon-day" viewBox="0 0 24 24"
          fill="currentColor">
          <path
            d="M12 2.25a.75.75 0 0 1 .75.75v2.25a.75.75 0 0 1-1.5 0V3a.75.75 0 0 1 .75-.75ZM7.5 12a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM18.894 6.166a.75.75 0 0 0-1.06-1.06l-1.591 1.59a.75.75 0 1 0 1.06 1.061l1.591-1.59ZM21.75 12a.75.75 0 0 1-.75.75h-2.25a.75.75 0 0 1 0-1.5H21a.75.75 0 0 1 .75.75ZM17.834 18.894a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 1 0-1.061 1.06l1.59 1.591ZM12 18a.75.75 0 0 1 .75.75V21a.75.75 0 0 1-1.5 0v-2.25A.75.75 0 0 1 12 18ZM7.758 17.303a.75.75 0 0 0-1.061-1.06l-1.591 1.59a.75.75 0 0 0 1.06 1.061l1.591-1.59ZM6 12a.75.75 0 0 1-.75.75H3a.75.75 0 0 1 0-1.5h2.25A.75.75 0 0 1 6 12ZM6.697 7.757a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 0 0-1.061 1.06l1.59 1.591Z" />
          <path d="M12 14c-1.5 0-4.5 1-4.5 3v3h9v-3c0-2-3-3-4.5-3Z" opacity="0.4" />
          <path d="M4 19h16v2H4z" />
        </svg>
        <!-- Night Icon -->
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-night" viewBox="0 0 24 24" fill="currentColor">
          <path fill-rule="evenodd"
            d="M9.528 1.718a.75.75 0 01.162.819A8.97 8.97 0 009 6a9 9 0 009 9 8.97 8.97 0 003.463-.69.75.75 0 01.981.98 10.503 10.503 0 01-9.694 6.46c-5.799 0-10.5-4.701-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 01.818.162z"
            clip-rule="evenodd" />
        </svg>

        <div class="card-label">Potenza</div>
        <div>
          <span class="card-value" id="power"><?php echo htmlspecialchars((string) $safePower0); ?></span>
          <span class="card-unit">W</span>
        </div>
      </a>
      <div class="minmax-row">
        <div class="minmax-item">
          <span class="minmax-label">Peak 24h</span>
          <span class="minmax-val val-max"><?php echo $mm_max_power; ?>W</span>
        </div>
      </div>
    </div>

    <!-- 4. Umidità -->
    <div class="card border-humi">
      <a href="grafico.php?var=hombra" class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-humi" viewBox="0 0 24 24" fill="currentColor">
          <path
            d="M12 2.25a.75.75 0 0 1 .75.75c0 7.039 4.343 11.233 4.343 14.5a5.093 5.093 0 0 1-10.186 0c0-3.267 4.343-7.461 4.343-14.5a.75.75 0 0 1 .75-.75Z" />
        </svg>
        <div class="card-label">Umidità</div>
        <div>
          <span class="card-value" id="hombra"><?php echo htmlspecialchars((string) $safeHombra0); ?></span>
          <span class="card-unit">%</span>
        </div>
      </a>
      <div class="minmax-row" style="visibility:hidden">
        <div class="minmax-item"><span class="minmax-label">.</span></div>
      </div>
    </div>

    <!-- 5. Piave -->
    <div class="card border-water">
      <a href="grafico.php?var=portata" class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-water" viewBox="0 0 24 24" fill="currentColor">
          <path
            d="M3.75 6c0-.414.336-.75.75-.75h15a.75.75 0 0 1 0 1.5h-15a.75.75 0 0 1-.75-.75Zm0 6c0-.414.336-.75.75-.75h15a.75.75 0 0 1 0 1.5h-15a.75.75 0 0 1-.75-.75Zm.75 5.25a.75.75 0 0 0 0 1.5h15a.75.75 0 0 0 0-1.5h-15Z" />
          <path d="M12 3a9 9 0 0 0-9 9 9 9 0 0 0 12.3 8.37 1 1 0 1 0-.74-1.86A7 7 0 1 1 12 5a1 1 0 0 0 0-2Z"
            opacity="0.1" />
        </svg>
        <div class="card-label">Piave</div>
        <div>
          <span class="card-value" id="piave_portata"><?php echo htmlspecialchars((string) $safePortata0); ?></span>
          <span class="card-unit">m³/s</span>
        </div>
      </a>
      <div class="minmax-row">
        <div class="minmax-item">
          <span class="minmax-label">Min 24h</span>
          <span class="minmax-val val-min"><?php echo $mm_min_portata; ?></span>
        </div>
        <!-- TREND -->
        <div class="minmax-item">
          <span class="minmax-label">Trend (30m)</span>
          <span class="minmax-val"><?php echo getTrendHtml($trend_piave, 'm³/s/h'); ?></span>
        </div>
        <div class="minmax-item">
          <span class="minmax-label">Max 24h</span>
          <span class="minmax-val val-max"><?php echo $mm_max_portata; ?></span>
        </div>
      </div>
    </div>

    <!-- 6. Temp Ombra (Primary - Last) -->
    <div class="card border-temp <?php echo getTempClass($safeTombra0); ?>" id="temp2_card" <?php echo $showTemp2; ?>>
      <a href="grafico.php?var=tombra" class="card-content">
        <!-- Warm Icon -->
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24"
          fill="currentColor">
          <path
            d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z" />
          <path d="M12 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z" fill="white" />
        </svg>
        <!-- Cold Icon -->
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-cold" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M2 12h20M12 2v20M20 4l-8 8-8-8M4 20l8-8 8 8" />
        </svg>
        <div class="card-label">Temp (Interno)</div>
        <div>
          <span class="card-value" id="tombra"><?php echo htmlspecialchars((string) $safeTombra0); ?></span>
          <span class="card-unit">°C</span>
        </div>
      </a>
      <div class="minmax-row">
        <div class="minmax-item">
          <span class="minmax-label">Min 24h</span>
          <span class="minmax-val val-min"><?php echo $mm_min_tombra; ?>°</span>
        </div>
        <!-- TREND -->
        <div class="minmax-item">
          <span class="minmax-label">Trend (30m)</span>
          <span class="minmax-val"><?php echo getTrendHtml($trend_tombra, '°C/h', $iceTime_tombra); ?></span>
        </div>
        <div class="minmax-item">
          <span class="minmax-label">Max 24h</span>
          <span class="minmax-val val-max"><?php echo $mm_max_tombra; ?>°</span>
        </div>
      </div>
    </div>

  </div>

  <footer>
    &copy; <?php echo date("Y"); ?> Cesana Beach
  </footer>

</body>

</html>
<?php
$output = ob_get_contents();
ob_end_flush();

if ($output !== false) {
  $tmp = $file_path . '.tmp';
  if (@file_put_contents($tmp, $output) !== false) {
    @rename($tmp, $file_path);
  } else {
    @file_put_contents($file_path, $output);
  }
}
?>