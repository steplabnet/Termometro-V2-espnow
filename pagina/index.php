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

/** ---------- 3b. ATMOSPHERIC PRESSURE (from get_setpoint.php JSON) ---------- */
$stateFile = '/dev/shm/thermo_data/state.json';
$safePres0 = '--.-';
if (file_exists($stateFile)) {
    $stateJson = json_decode(file_get_contents($stateFile), true);
    if (isset($stateJson['pres'])) {
        $safePres0 = number_format((float)$stateJson['pres'], 1, '.', '');
    }
}

/** ---------- 4. TREND CALCULATION ---------- */
$time30mAgo = $now - 1800;
$queryTrend = "SELECT `temperatura`, `tombra`, `tMobile`, `portata`, `data` 
               FROM `dati_meteo` 
               WHERE `data` <= {$time30mAgo} 
               ORDER BY `data` DESC 
               LIMIT 1";
$resTrend = $link->query($queryTrend);
$trendData = ($resTrend && $resTrend->num_rows > 0) ? $resTrend->fetch_assoc() : null;

function calculateTrend($current, $old) {
  if ($current === null || $old === null || $current <= -50) return null;
  return ($current - (float) $old) * 2;
}

$trend_temp1 = calculateTrend($safeTemp0, $trendData['temperatura'] ?? null);
$trend_tombra = calculateTrend($safeTombra0, $trendData['tombra'] ?? null);
$trend_tMobile = calculateTrend($safeTMobile0, $trendData['tMobile'] ?? null);
$trend_piave = calculateTrend($safePortata0, $trendData['portata'] ?? null);

/** ---------- 5. FREEZING TIME PREDICTION ---------- */
function getFreezingTime($currentTemp, $trendPerHour) {
  if ($currentTemp > 0 && $trendPerHour < -0.1) {
    $hoursToZero = $currentTemp / abs($trendPerHour);
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

function getTrendHtml($val, $unit = '°C/h', $icePrediction = null) {
  if ($val === null) return '<span class="trend-neutral">--</span>';
  $rounded = round($val, 2);
  $sign = ($rounded > 0) ? '+' : '';
  $colorClass = ($rounded > 0) ? 'trend-up' : (($rounded < 0) ? 'trend-down' : 'trend-neutral');
  $arrow = ($rounded > 0) ? '&#8593;' : (($rounded < 0) ? '&#8595;' : '&nbsp;');
  $html = "<span class=\"{$colorClass}\">{$arrow} {$sign}{$rounded} <small>{$unit}</small></span>";
  if ($icePrediction) $html .= "<div class=\"ice-prediction\">&#10052; 0°C alle {$icePrediction}</div>";
  return $html;
}

$showTemp1 = ($safeTemp0 > -99) ? '' : 'style="display:none"';
$showTemp2 = ($safeTombra0 > -99) ? '' : 'style="display:none"';
$showTemp3 = ($safeTMobile0 > -99) ? '' : 'style="display:none"';

function getTempClass($val) { return ($val < 1) ? 'freezing' : ''; }
function getPowerClass($val) { return ($val <= 0) ? 'night-mode' : ''; }
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
      --accent-blue: #3b82f6;
      --accent-orange: #f59e0b;
      --accent-red: #ef4444;
      --accent-teal: #14b8a6;
      --accent-ice: #0ea5e9;
      --accent-purple: #8b5cf6;
      --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background-color: var(--bg-color); color: var(--text-main); padding: 20px; }
    a { text-decoration: none; color: inherit; display: block; height: 100%; }
    header { text-align: center; margin-bottom: 30px; }
    header h1 { font-weight: 800; font-size: 1.5rem; letter-spacing: -0.025em; }
    header p { color: var(--text-muted); font-size: 0.9rem; margin-top: 5px; }
    header .powered { font-size: 0.75rem; margin-top: 5px; opacity: 0.7; }
    .dashboard-grid { display: grid; gap: 20px; max-width: 1200px; margin: 0 auto; grid-template-columns: 1fr; }
    @media (min-width: 600px) { .dashboard-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (min-width: 900px) { .dashboard-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 1200px) { .dashboard-grid { grid-template-columns: repeat(4, 1fr); } }
    .card { background: var(--card-bg); border-radius: 16px; padding: 20px; box-shadow: var(--shadow); transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column; align-items: center; text-align: center; min-height: 220px; }
    .card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
    .card-content { width: 100%; display: flex; flex-direction: column; align-items: center; margin-bottom: 15px; height:100%; }
    .card-icon { width: 48px; height: 48px; margin-bottom: 10px; fill: currentColor; }
    .icon-temp { color: var(--accent-red); }
    .icon-power { color: var(--accent-orange); }
    .icon-humi { color: var(--accent-teal); }
    .icon-water { color: var(--accent-blue); }
    .icon-pres { color: var(--accent-purple); }
    .icon-cold { display: none; }
    .card.freezing .icon-warm { display: none; }
    .card.freezing .icon-cold { display: block; color: var(--accent-ice); fill: none; stroke: var(--accent-ice); }
    .icon-night { display: none; }
    .card.night-mode .icon-day { display: none; }
    .card.night-mode .icon-night { display: block; }
    .card-label { font-size: 0.85rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 5px; }
    .card-value { font-size: 2.2rem; font-weight: 800; color: var(--text-main); line-height: 1; }
    .card-unit { font-size: 1.1rem; color: var(--text-muted); margin-left: 2px; }
    .minmax-row { display: flex; justify-content: space-between; width: 100%; padding-top: 15px; border-top: 1px solid #e5e7eb; margin-top: auto; }
    .minmax-item { display: flex; flex-direction: column; font-size: 0.8rem; flex: 1; }
    .minmax-label { color: #9ca3af; font-size: 0.7rem; text-transform: uppercase; }
    .minmax-val { font-weight: 700; }
    .val-min { color: var(--accent-blue); }
    .val-max { color: var(--accent-red); }
    .trend-up { color: var(--accent-red); font-weight: 700; }
    .trend-down { color: var(--accent-blue); font-weight: 700; }
    .ice-prediction { color: var(--accent-ice); font-size: 0.75em; font-weight: 700; margin-top: 2px; }
    .border-power { border-top: 5px solid var(--accent-orange); }
    .border-water { border-top: 5px solid var(--accent-blue); }
    .border-humi { border-top: 5px solid var(--accent-teal); }
    .border-temp { border-top: 5px solid var(--accent-red); }
    .border-pres { border-top: 5px solid var(--accent-purple); }
    footer { text-align: center; margin-top: 40px; font-size: 0.8rem; color: var(--text-muted); }
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
        <div class="card-label">Temp (Sole)</div>
        <div><span class="card-value" id="temperatura"><?php echo $safeTemp0; ?></span><span class="card-unit">°C</span></div>
      </a>
      <div class="minmax-row">
        <div class="minmax-item"><span class="minmax-label">Trend</span><span class="minmax-val"><?php echo getTrendHtml($trend_temp1, '°C/h', $iceTime_temp1); ?></span></div>
      </div>
    </div>

    <!-- 2. Temp Mobile -->
    <div class="card border-temp <?php echo getTempClass($safeTMobile0); ?>" id="temp3_card" <?php echo $showTemp3; ?>>
      <a href="grafico.php?var=tMobile" class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z"/></svg>
        <div class="card-label">Temp (Ombra)</div>
        <div><span class="card-value" id="tMobile"><?php echo $safeTMobile0; ?></span><span class="card-unit">°C</span></div>
      </a>
      <div class="minmax-row">
        <div class="minmax-item"><span class="minmax-label">Min 24h</span><span class="minmax-val val-min"><?php echo $mm_min_tMobile; ?>°</span></div>
        <div class="minmax-item"><span class="minmax-label">Max 24h</span><span class="minmax-val val-max"><?php echo $mm_max_tMobile; ?>°</span></div>
      </div>
    </div>

    <!-- 3. Pressione (from BME280) -->
    <div class="card border-pres" id="pres_card">
      <div class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-pres" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 7v5l3 3"></path></svg>
        <div class="card-label">Pressione</div>
        <div><span class="card-value" id="actualPres"><?php echo $safePres0; ?></span><span class="card-unit">hPa</span></div>
      </div>
      <div class="minmax-row" style="visibility:hidden"><div class="minmax-item">.</div></div>
    </div>

    <!-- 4. Potenza -->
    <div class="card border-power <?php echo getPowerClass($safePower0); ?>" id="power_card">
      <a href="grafico.php?var=power" class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-power" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.25v2.25M12 18.75V21M18.75 12h2.25M3 12h2.25M12 7.5a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9Z"/></svg>
        <div class="card-label">Potenza</div>
        <div><span class="card-value" id="power"><?php echo $safePower0; ?></span><span class="card-unit">W</span></div>
      </a>
      <div class="minmax-row"><div class="minmax-item"><span class="minmax-label">Peak</span><span class="minmax-val"><?php echo $mm_max_power; ?>W</span></div></div>
    </div>

    <!-- 5. Umidità -->
    <div class="card border-humi">
      <a href="grafico.php?var=hombra" class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-humi" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.25c0 7.039 4.343 11.233 4.343 14.5a5.093 5.093 0 0 1-10.186 0c0-3.267 4.343-7.461 4.343-14.5a.75.75 0 0 1 .75-.75Z"/></svg>
        <div class="card-label">Umidità</div>
        <div><span class="card-value" id="hombra"><?php echo $safeHombra0; ?></span><span class="card-unit">%</span></div>
      </a>
      <div class="minmax-row"><div class="minmax-item"><span class="minmax-label">Range</span><span class="minmax-val"><?php echo $mm_min_humi; ?>-<?php echo $mm_max_humi; ?>%</span></div></div>
    </div>

    <!-- 6. Piave -->
    <div class="card border-water">
      <a href="grafico.php?var=portata" class="card-content">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-water" viewBox="0 0 24 24" fill="currentColor"><path d="M3.75 6h15M3.75 12h15M3.75 18h15"/></svg>
        <div class="card-label">Piave</div>
        <div><span class="card-value" id="piave_portata"><?php echo $safePortata0; ?></span><span class="card-unit">m³/s</span></div>
      </a>
      <div class="minmax-row"><div class="minmax-item"><span class="minmax-label">Trend</span><span class="minmax-val"><?php echo getTrendHtml($trend_piave, 'm³/s/h'); ?></span></div></div>
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

  <footer>&copy; <?php echo date("Y"); ?> Cesana Beach</footer>
</body>
</html>
<?php
$output = ob_get_contents();
ob_end_flush();
if ($output !== false) {
  $tmp = $file_path . '.tmp';
  if (@file_put_contents($tmp, $output) !== false) @rename($tmp, $file_path);
  else @file_put_contents($file_path, $output);
}
?>