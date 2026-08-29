<?php
error_reporting(E_ALL);
ini_set('display_errors', true);
date_default_timezone_set('Europe/Rome');

// Include your existing files
include('funzioni.php');
include('connessione.php');

/* --- PHP LOGIC & HELPERS --- */

function dateComp($date1_, $date2_)
{
  // Logic to match previous day timestamps (approx -24h)
  if ($date2_ < $date1_ - 86338 && $date2_ > $date1_ - 86462) {
    return true;
  }
  return false;
}

// 1. Setup Parameters
$start = isset($_GET['start']) ? $_GET['start'] * 144 : 0;
$variable = $_GET['var'] ?? 'temperatura';

// Default settings
$titolo = "Dati Meteo";
$unit = "";

// Configure labels and units
switch ($variable) {
  case 'fanMode':
    $titolo = "Attiva";
    break;
  case 'temperatura':
  case 'chip':
  case 'tombra':
  case 'tMobile':
    $titolo = ($variable == 'chip') ? "Temp CPU" : "Temperatura";
    $unit = "°C";
    break;
  case 'humidity':
  case 'hombra':
    $titolo = "Umidità";
    $unit = "%";
    break;
  case 'wind':
    $titolo = "Vento";
    $unit = "m/s";
    break;
  case 'gust':
    $titolo = "Raffiche";
    $unit = "m/s";
    break;
  case 'rain':
    $titolo = "Pioggia";
    $unit = "mm";
    break;
  case 'press':
    $titolo = "Pressione";
    $unit = "hPa";
    break;
  case 'power':
    $titolo = "Potenza";
    $unit = "Kw";
    break;
  case 'portata':
    $titolo = "Portata";
    $unit = "m3/s";
    break;
  case 'pvPower':
    $titolo = "Produzione Fotovoltaico";
    $unit = "W";
    break;
  case 'gridPower':
    $titolo = "Scambio Rete";
    $unit = "W";
    break;
  case 'casa':
    $titolo = "Consumo Casa";
    $unit = "W";
    break;
  case 'prelievo':
    $titolo = "Prelievo Rete";
    $unit = "W";
    break;
}

// Shelly power channels legitimately go far below zero (grid export), so the
// "< -50 means dead sensor" rule that guards the temperature series must not be
// applied to them. 'casa' (pvPower + gridPower) and 'prelievo' (the imported
// half of gridPower) are derived, not DB columns.
$POWER_VARS = ['pvPower', 'gridPower', 'casa', 'prelievo'];
$skipSentinelFilter = in_array($variable, $POWER_VARS, true);

$now = time();
$ieri = $now - 86400;

// Catalog of variables selectable in the "multi" (compare) view.
//   key => [label, unit, color, source]   source: 'db' column or 'csv' (pressure)
$MULTI_CATALOG = [
  'temperatura' => ['label' => 'Temp Sole',    'unit' => '°C',   'color' => '#ef4444', 'src' => 'db'],
  'tMobile'     => ['label' => 'Temp Interno', 'unit' => '°C',   'color' => '#f59e0b', 'src' => 'db'],
  'tombra'      => ['label' => 'Temp Ombra',   'unit' => '°C',   'color' => '#14b8a6', 'src' => 'db'],
  'hombra'      => ['label' => 'Umidità',      'unit' => '%',    'color' => '#22c55e', 'src' => 'db'],
  'power'       => ['label' => 'Fotovoltaico', 'unit' => 'W',    'color' => '#eab308', 'src' => 'db'],
  'portata'     => ['label' => 'Piave',        'unit' => 'm³/s', 'color' => '#3b82f6', 'src' => 'db'],
  'press'       => ['label' => 'Pressione',    'unit' => 'hPa',  'color' => '#8b5cf6', 'src' => 'csv'],
  'pvPower'     => ['label' => 'Produzione FV', 'unit' => 'W',    'color' => '#f97316', 'src' => 'db',   'signed' => true],
  'gridPower'   => ['label' => 'Scambio Rete',  'unit' => 'W',    'color' => '#0ea5e9', 'src' => 'db',   'signed' => true],
  'casa'        => ['label' => 'Consumo Casa',  'unit' => 'W',    'color' => '#a855f7', 'src' => 'calc', 'signed' => true],
  'prelievo'    => ['label' => 'Prelievo Rete', 'unit' => 'W',    'color' => '#dc2626', 'src' => 'calc'],
];

/** PV production under this many watts is noise, not production. */
const PV_ZERO_THRESHOLD = 10.0;

/** Apply the PV deadband to a raw pvPower reading. */
function pv_clean($val)
{
  if ($val === null || !is_numeric($val)) {
    return null;
  }
  $val = (float) $val;
  return (abs($val) < PV_ZERO_THRESHOLD) ? 0.0 : $val;
}

/** House load: what the panels make plus what the grid supplies (export is negative). */
function casa_power(array $row)
{
  $pv = pv_clean($row['pvPower'] ?? null);
  $grid = $row['gridPower'] ?? null;
  if (!is_numeric($pv) || !is_numeric($grid)) {
    return null;
  }
  return (float) $pv + (float) $grid;
}

/**
 * Prelievo: what we actually buy from the grid. Zero while the PV covers the
 * whole load (grid flow <= 0, i.e. balance or export), the imported side of the
 * exchange otherwise -- the same rule the dashboard card uses.
 */
function prelievo_power(array $row)
{
  $grid = $row['gridPower'] ?? null;
  if (!is_numeric($grid)) {
    return null;
  }
  return max(0.0, (float) $grid);
}

/** The derived ('calc') series, by variable name. */
function calc_power(string $variable, array $row)
{
  return ($variable === 'prelievo') ? prelievo_power($row) : casa_power($row);
}

// ---- MULTI (compare) MODE ---------------------------------------------------
// grafico.php?var=multi&v[]=temperatura&v[]=power  (or &sel=temperatura,power)
// Overlays several variables on one chart, each on a Y axis grouped by unit.
$isMulti = ($variable === 'multi');
$multiSelected = [];
$multiSeries   = [];
if ($isMulti) {
  $titolo = 'Confronto';

  // Selection: v[] array (from the toolbar) or a comma-separated sel= list.
  $raw = [];
  if (isset($_GET['v']) && is_array($_GET['v'])) $raw = $_GET['v'];
  elseif (isset($_GET['sel']))                   $raw = explode(',', (string) $_GET['sel']);
  foreach ($raw as $k) {
    $k = trim((string) $k);
    if (isset($MULTI_CATALOG[$k]) && !in_array($k, $multiSelected, true)) $multiSelected[] = $k;
  }
  if (!$multiSelected) $multiSelected = ['temperatura', 'tombra']; // sensible default: Sole + Ombra

  // Shared timeline: the last 144 DB samples (chronological). All DB variables
  // share these rows; pressure is matched onto the same timestamps below.
  $rowsAsc = [];
  $resM = $link->query("SELECT * FROM dati_meteo ORDER BY id DESC LIMIT 144");
  if ($resM instanceof mysqli_result) {
    while ($rw = $resM->fetch_assoc()) $rowsAsc[] = $rw;
    $rowsAsc = array_reverse($rowsAsc);
  }
  $labels = [];
  $times  = [];
  foreach ($rowsAsc as $rw) {
    $times[]  = (int) $rw['data'];
    $labels[] = date('H:i', (int) $rw['data']);
  }

  // Load the pressure CSV once, only if a pressure series was requested.
  $presPairs = [];
  $needCsv = false;
  foreach ($multiSelected as $k) if ($MULTI_CATALOG[$k]['src'] === 'csv') $needCsv = true;
  if ($needCsv) {
    $f = '/dev/shm/thermo_data/pres_history.csv';
    if (is_readable($f)) {
      foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $r) {
        $p = explode(',', $r);
        if (count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) {
          $presPairs[] = [(int) $p[0], (float) $p[1]];
        }
      }
    }
  }
  // Nearest pressure sample within +/-15 min of a timestamp, else null.
  $presAt = function (int $ts) use ($presPairs) {
    $best = null; $bestDiff = 901;
    foreach ($presPairs as $pr) {
      $d = abs($pr[0] - $ts);
      if ($d <= 900 && $d < $bestDiff) { $bestDiff = $d; $best = $pr[1]; }
    }
    return $best;
  };

  // One Y axis id per distinct unit (y0, y1, ...).
  $unitAxis = [];
  foreach ($multiSelected as $k) {
    $u = $MULTI_CATALOG[$k]['unit'];
    if (!isset($unitAxis[$u])) $unitAxis[$u] = 'y' . count($unitAxis);
  }

  foreach ($multiSelected as $k) {
    $meta = $MULTI_CATALOG[$k];
    $data = [];
    if ($meta['src'] === 'csv') {
      foreach ($times as $ts) $data[] = $presAt($ts);
    } elseif ($meta['src'] === 'calc') {
      foreach ($rowsAsc as $rw) $data[] = calc_power($k, $rw);
    } else {
      // 'signed' series (the Shelly power channels) keep their negative values;
      // everything else treats < -50 as a dead-sensor sentinel.
      $signed = !empty($meta['signed']);
      foreach ($rowsAsc as $rw) {
        $v = ($k === 'pvPower') ? pv_clean($rw[$k] ?? null) : ($rw[$k] ?? null);
        $ok = is_numeric($v) && ($signed || (float) $v > -50);
        $data[] = $ok ? (float) $v : null;
      }
    }
    $multiSeries[] = [
      'label' => $meta['label'],
      'unit'  => $meta['unit'],
      'color' => $meta['color'],
      'axis'  => $unitAxis[$meta['unit']],
      'data'  => $data,
    ];
  }
}

// Pressure is NOT stored in dati_meteo: the board logs it to a RAM-disk CSV
// ("timestamp,hPa") via get_setpoint.php. Build its series straight from that
// file. Every other variable still comes from the DB below.
$pressZoneStart = null; // index where the 3h trend window starts (press mode)
$usingCsv = ($variable === 'press');

if ($usingCsv) {
  $presHistoryFile = '/dev/shm/thermo_data/pres_history.csv';
  $bucket = 600; // 10-min buckets keep the point count sane (~33h of history)
  $seen = [];
  if (is_readable($presHistoryFile)) {
    foreach (file($presHistoryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $r) {
      $p = explode(',', $r);
      if (count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1]) && (float) $p[1] > -50) {
        $b = intdiv((int) $p[0], $bucket) * $bucket;
        $seen[$b] = (float) $p[1]; // last sample in each bucket wins
      }
    }
    ksort($seen);
  }
  // Stored newest-first so the array_reverse() in the JSON step (below) yields
  // a chronological series, matching how the DB branch feeds the chart.
  $labels = [];
  $dataSeries1 = [];
  foreach ($seen as $ts => $val) {
    $labels[] = date('H:i', $ts);
    $dataSeries1[] = $val;
  }

  // Mark where the last 3h (the window feeding the trend) begins, as an index
  // into the chronological series. $seen keys are already sorted ascending.
  $cutoff = $now - 10800; // 3h
  $i = 0;
  foreach (array_keys($seen) as $ts) {
    if ($ts >= $cutoff) { $pressZoneStart = $i; break; }
    $i++;
  }

  $labels = array_reverse($labels);
  $dataSeries1 = array_reverse($dataSeries1);
  $alignedSeries2 = []; // no "yesterday" overlay: the CSV only spans ~33h
}

if (!$usingCsv && !$isMulti) {

// 2. Fetch Main Data (Series 1 - Today)
$queryMain = "SELECT * FROM dati_meteo WHERE 1 ORDER BY id DESC LIMIT 144";
if (isset($_GET['ieri'])) {
  $queryMain = "SELECT * FROM dati_meteo WHERE data <= $ieri ORDER BY id DESC LIMIT 144";
}

$result = $link->query($queryMain);

$dataSeries1 = [];
$rawDate1 = [];
$labels = [];

while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
  if ($variable == 'press') {
    $val = round(press_qff($row['press'], $row['chip']), 1);
  } elseif ($variable == 'casa' || $variable == 'prelievo') {
    $val = calc_power($variable, $row);
    if ($val === null) {
      continue;
    }
  } else {
    $val = ($variable === 'pvPower') ? pv_clean($row[$variable] ?? null) : $row[$variable];
  }

  // --- FILTER: Discard data < -50 (not for signed power channels) ---
  if (!$skipSentinelFilter && $val < -50) {
    continue;
  }
  // ----------------------------------

  $dataSeries1[] = $val;
  $labels[] = date("H:i", $row['data']);
  $rawDate1[] = $row['data'];
}

// 3. Fetch Comparison Data (Series 2 - Yesterday)
$queryComparison = "SELECT * FROM dati_meteo WHERE data <= $ieri ORDER BY id DESC LIMIT 144";
$result2 = $link->query($queryComparison);

$dataSeries2_Raw = [];
$rawDate2 = [];

while ($row = $result2->fetch_array(MYSQLI_ASSOC)) {
  if ($variable == 'press') {
    $val = round(press_qff($row['press'], $row['chip']), 1);
  } else if ($variable == 'chip') {
    $val = round($row['cpuTemp'], 1);
  } elseif ($variable == 'casa' || $variable == 'prelievo') {
    $val = calc_power($variable, $row);
    if ($val === null) {
      continue;
    }
  } else {
    $val = ($variable === 'pvPower') ? pv_clean($row[$variable] ?? null) : $row[$variable];
  }

  // --- FILTER: Discard data < -50 (not for signed power channels) ---
  if (!$skipSentinelFilter && $val < -50) {
    continue;
  }
  // ----------------------------------

  $dataSeries2_Raw[] = $val;
  $rawDate2[] = $row['data'];
}

// 4. Align Data Series
$alignedSeries2 = [];

for ($i = 0; $i < count($rawDate1); $i++) {
  $found = false;
  $todayTime = $rawDate1[$i];

  for ($j = 0; $j < count($rawDate2); $j++) {
    if (dateComp($todayTime, $rawDate2[$j])) {
      $alignedSeries2[] = $dataSeries2_Raw[$j];
      $found = true;
      break;
    }
  }

  if (!$found) {
    $alignedSeries2[] = null;
  }
}

} // end if (!$usingCsv && !$isMulti)

// 5. JSON Encode
if ($isMulti) {
  // $labels is already chronological for multi mode; series carry their own data.
  $jsonLabels = json_encode($labels);
  $jsonMulti  = json_encode($multiSeries);
  $jsonData1  = '[]';
  $jsonData2  = '[]';
} else {
  $jsonLabels = json_encode(array_reverse($labels));
  $jsonData1  = json_encode(array_reverse($dataSeries1));
  $jsonData2  = json_encode(array_reverse($alignedSeries2));
  $jsonMulti  = '[]';
}

?>

<!doctype html>
<html lang="it" data-bs-theme="dark">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Chart - <?php echo $titolo; ?></title>

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- FontAwesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <style>
    body {
      background-color: #1a1d20;
      font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      color: #e0e0e0;
      margin: 0;
      padding: 10px;
      height: 100vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .chart-container {
      position: relative;
      flex-grow: 1;
      width: 100%;
      min-height: 0;
      background-color: #212529;
      border-radius: 12px;
      padding: 15px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
      border: 1px solid #373b3e;
    }

    /* Home Button Style */
    .home-btn {
      position: fixed;
      top: 20px;
      right: 25px;
      z-index: 1000;
      background-color: rgba(44, 48, 52, 0.85);
      backdrop-filter: blur(4px);
      border: 1px solid #495057;
      color: #dee2e6;
      border-radius: 50px;
      /* Pill shape */
      padding: 8px 16px;
      font-size: 0.9rem;
      text-decoration: none;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
      transition: all 0.3s ease;
    }

    .home-btn:hover {
      background-color: #0d6efd;
      border-color: #0d6efd;
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 6px 8px rgba(0, 0, 0, 0.3);
    }

    /* Stats Row */
    .stats-row {
      margin-top: 15px;
      display: flex;
      justify-content: space-around;
      gap: 10px;
    }

    .mini-stat {
      background-color: #2c3034;
      border: 1px solid #373b3e;
      border-radius: 8px;
      padding: 10px;
      text-align: center;
      flex: 1;
    }

    .mini-stat .label {
      font-size: 0.75rem;
      color: #adb5bd;
      text-transform: uppercase;
    }

    .mini-stat .value {
      font-size: 1.1rem;
      font-weight: bold;
      color: #fff;
    }

    /* Multi (compare) selection toolbar */
    .multi-toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
      margin-bottom: 10px;
      flex: 0 0 auto;
    }
    .multi-toolbar .chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background-color: #2c3034;
      border: 1px solid #373b3e;
      border-radius: 20px;
      padding: 6px 12px;
      font-size: 0.85rem;
      cursor: pointer;
      user-select: none;
    }
    .multi-toolbar .chip input { accent-color: #0d6efd; margin: 0; }
  </style>
</head>

<body>

  <!-- Home Button -->
  <a href="index.php" class="home-btn">
    <i class="fas fa-home me-2"></i>Home
  </a>

  <?php if ($isMulti): ?>
    <!-- Variable picker for the compare view -->
    <form method="get" class="multi-toolbar">
      <input type="hidden" name="var" value="multi">
      <?php foreach ($MULTI_CATALOG as $k => $meta): ?>
        <label class="chip">
          <input type="checkbox" name="v[]" value="<?php echo $k; ?>" <?php echo in_array($k, $multiSelected, true) ? 'checked' : ''; ?>>
          <span style="color:<?php echo $meta['color']; ?>; font-size:1.1rem; line-height:0;">&#9679;</span>
          <?php echo $meta['label']; ?>
        </label>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-sm btn-primary">Aggiorna</button>
    </form>
  <?php endif; ?>

  <!-- Main Chart Area -->
  <div class="chart-container">
    <canvas id="myChart"></canvas>
  </div>

  <?php
  // ENERGY SECTION (Shelly power channels): true kWh, integrated over the real
  // sample timestamps instead of assuming a fixed cadence.
  if (in_array($variable, ['pvPower', 'gridPower', 'casa', 'prelievo'], true) && !$isMulti):

    /**
     * Integrate a power channel between two timestamps.
     * Returns ['kwh' => total, 'pos' => imported kWh, 'neg' => exported kWh].
     * Gaps longer than 1h are not bridged, so a logger outage does not invent energy.
     */
    function energia_potenza(int $timedw, int $timeup, string $variable): array
    {
      global $link;
      $empty = ['kwh' => 0.0, 'pos' => 0.0, 'neg' => 0.0];
      try {
        // mysqli throws when the columns are not there yet (PHP 8.1+).
        $res = $link->query("SELECT data, pvPower, gridPower FROM dati_meteo WHERE data > $timedw AND data < $timeup ORDER BY data ASC");
      } catch (Throwable $e) {
        return $empty;
      }
      if (!$res instanceof mysqli_result) {
        return $empty;
      }
      $tot = 0.0;
      $pos = 0.0;
      $neg = 0.0;
      $prevT = null;
      $prevV = null;
      while ($row = $res->fetch_assoc()) {
        $v = ($variable === 'casa' || $variable === 'prelievo') ? calc_power($variable, $row)
          : (($variable === 'pvPower') ? pv_clean($row[$variable] ?? null) : ($row[$variable] ?? null));
        if ($v === null || !is_numeric($v)) {
          continue;
        }
        $v = (float) $v;
        $t = (int) $row['data'];
        if ($prevT !== null && $t - $prevT <= 3600) {
          $wh = (($v + $prevV) / 2.0) * (($t - $prevT) / 3600.0);
          $tot += $wh;
          if ($wh > 0) $pos += $wh; else $neg += $wh;
        }
        $prevT = $t;
        $prevV = $v;
      }
      return ['kwh' => $tot / 1000.0, 'pos' => $pos / 1000.0, 'neg' => $neg / 1000.0];
    }

    $midnight = mktime(0, 0, 0, (int) date('n'), (int) date('j'), (int) date('Y'));
    $eOggi = energia_potenza($midnight, time(), $variable);
    $eIeri = energia_potenza($midnight - 86400, $midnight, $variable);
    ?>
    <div class="stats-row">
      <?php if ($variable === 'gridPower'): ?>
        <div class="mini-stat">
          <div class="label">Prelevato oggi</div>
          <div class="value"><?php echo number_format($eOggi['pos'], 2); ?> kWh</div>
        </div>
        <div class="mini-stat">
          <div class="label">Immesso oggi</div>
          <div class="value"><?php echo number_format(abs($eOggi['neg']), 2); ?> kWh</div>
        </div>
        <div class="mini-stat">
          <div class="label">Saldo ieri</div>
          <div class="value"><?php echo number_format($eIeri['kwh'], 2); ?> kWh</div>
        </div>
      <?php else: ?>
        <div class="mini-stat">
          <div class="label">Oggi</div>
          <div class="value"><?php echo number_format($eOggi['kwh'], 2); ?> kWh</div>
        </div>
        <div class="mini-stat">
          <div class="label">Ieri</div>
          <div class="value"><?php echo number_format($eIeri['kwh'], 2); ?> kWh</div>
        </div>
        <div class="mini-stat">
          <div class="label">Totale (2 giorni)</div>
          <div class="value"><?php echo number_format($eOggi['kwh'] + $eIeri['kwh'], 2); ?> kWh</div>
        </div>
      <?php endif; ?>
    </div>
  <?php
  // ENERGY SECTION (legacy, other variables)
  elseif ($variable !== 'press' && !$isMulti):

    function energia($timeup, $timedw)
    {
      global $link;
      $timeCorrect = 0;
      $timeup = $timeup + $timeCorrect;
      $timedw = $timedw + $timeCorrect;

      $query = "SELECT * FROM dati_meteo WHERE data < $timeup and data > $timedw";
      $result = $link->query($query);

      $a = 0;
      $energy = 0;
      $prev = 0;
      while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
        // Simple sanity check here too just in case (optional)
        if ($row['press'] < -50)
          continue;

        $act = $row['press'];
        if ($a > 0) {
          $energy = ($act + $prev) / 2 + $energy;
        }
        $prev = $row['press'];
        $a++;
      }
      return round($energy / 6000, 2);
    }
    ?>
    <div class="stats-row">
      <div class="mini-stat">
        <div class="label">Today</div>
        <div class="value"><?php echo energia(time(), mktime(0, 0, 0, date('n'), date('j'), date("Y"))); ?> kWh</div>
      </div>
      <div class="mini-stat">
        <div class="label">Yesterday</div>
        <div class="value">
          <?php echo energia(time() - 86400, mktime(0, 0, 0, date('n'), date('j'), date("Y")) - 86400); ?> kWh
        </div>
      </div>
      <div class="mini-stat">
        <div class="label">Total (2 Days)</div>
        <div class="value">
          <?php echo energia(mktime(0, 0, 0, date('n'), date('j'), date("Y")), mktime(0, 0, 0, date('n'), date('j'), date("Y")) - 86400); ?>
          kWh
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- Google Analytics -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=UA-47884594-8"></script>
  <script>
    setTimeout(() => { window.location.reload(); }, 300000);

    const ctx = document.getElementById("myChart").getContext('2d');

    // Gradient
    let gradientFill = ctx.createLinearGradient(0, 0, 0, 400);
    gradientFill.addColorStop(0, 'rgba(54, 162, 235, 0.4)');
    gradientFill.addColorStop(1, 'rgba(54, 162, 235, 0.0)');

    const MULTI = <?php echo $isMulti ? 'true' : 'false'; ?>;
    const MULTI_SERIES = <?php echo $jsonMulti; ?>;
    const LABELS = <?php echo $jsonLabels; ?>;

    // Start index of the last-3h window (pressure view only), else null.
    const TREND_ZONE_START = <?php echo ($variable === 'press' && $pressZoneStart !== null) ? (int) $pressZoneStart : 'null'; ?>;

    // Shades the last 3h of the pressure chart — the window feeding the trend.
    const trendZonePlugin = {
      id: 'trendZone',
      beforeDatasetsDraw(chart) {
        if (TREND_ZONE_START === null) return;
        const { ctx, chartArea } = chart;
        const xs = chart.scales.x;
        const startPx = Math.max(xs.getPixelForValue(TREND_ZONE_START), chartArea.left);
        ctx.save();
        ctx.fillStyle = 'rgba(139, 92, 246, 0.13)'; // purple tint
        ctx.fillRect(startPx, chartArea.top, chartArea.right - startPx, chartArea.bottom - chartArea.top);
        ctx.strokeStyle = 'rgba(139, 92, 246, 0.7)';
        ctx.setLineDash([4, 4]);
        ctx.beginPath();
        ctx.moveTo(startPx, chartArea.top);
        ctx.lineTo(startPx, chartArea.bottom);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = 'rgba(196, 181, 253, 0.95)';
        ctx.font = '12px sans-serif';
        ctx.fillText('Trend 3h', startPx + 6, chartArea.top + 14);
        ctx.restore();
      }
    };

    // Shared X axis: thin out the H:i labels to ~8 ticks, always keep the last.
    const xScale = {
      grid: { color: '#373b3e', drawBorder: false },
      ticks: {
        color: '#adb5bd',
        autoSkip: false,
        maxRotation: 0,
        callback: function (val, index) {
          const total = this.chart.data.labels.length;
          const label = this.getLabelForValue(val);
          if (index === total - 1) return label;
          const targetTicks = 8;
          const step = Math.ceil(total / targetTicks);
          if (index % step === 0 && total - 1 - index > step * 0.7) return label;
          return null;
        }
      }
    };

    let datasets, scales;

    if (MULTI) {
      // One Y axis per distinct unit; alternate left/right, grid only on the first.
      const axisByUnit = {};
      MULTI_SERIES.forEach(s => { axisByUnit[s.unit] = s.axis; });
      scales = { x: xScale };
      Object.keys(axisByUnit).forEach((unit, i) => {
        scales[axisByUnit[unit]] = {
          type: 'linear',
          position: (i % 2 === 0) ? 'left' : 'right',
          grid: { color: '#373b3e', drawBorder: false, drawOnChartArea: (i === 0) },
          ticks: { color: '#adb5bd', callback: v => v + ' ' + unit },
          beginAtZero: false
        };
      });
      datasets = MULTI_SERIES.map(s => ({
        label: s.label + ' (' + s.unit + ')',
        data: s.data,
        borderColor: s.color,
        backgroundColor: 'transparent',
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 5,
        tension: 0.3,
        spanGaps: true,
        yAxisID: s.axis
      }));
    } else {
      datasets = [
        {
          label: '<?php echo $titolo ?> Today',
          data: <?php echo $jsonData1; ?>,
          borderColor: '#36a2eb',
          backgroundColor: gradientFill,
          borderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 6,
          fill: true,
          tension: 0.3
        },
        {
          label: '<?php echo $variable == 'chip' ? 'Temp CPU' : 'Ieri'; ?>',
          data: <?php echo $jsonData2; ?>,
          borderColor: 'rgba(255, 255, 255, 0.9)',
          backgroundColor: 'transparent',
          borderWidth: 2,
          borderDash: [5, 5],
          pointRadius: 0,
          hidden: <?php echo ($variable === 'press') ? 'true' : 'false'; ?>,
          tension: 0.3
        }
      ];
      scales = {
        x: xScale,
        y: {
          grid: { color: '#373b3e', drawBorder: false },
          ticks: {
            color: '#adb5bd',
            callback: function (value) { return value + ' <?php echo $unit; ?>'; }
          },
          beginAtZero: false
        }
      };
    }

    const myChart = new Chart(ctx, {
      type: 'line',
      plugins: [trendZonePlugin],
      data: { labels: LABELS, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            labels: { color: '#adb5bd' },
            position: 'top',
            align: 'start'
          },
          tooltip: {
            mode: 'index',
            intersect: false,
            backgroundColor: 'rgba(0,0,0,0.8)',
            titleColor: '#fff',
            bodyColor: '#fff',
            borderColor: 'rgba(255,255,255,0.1)',
            borderWidth: 1
          }
        },
        scales
      }
    });

    // Hover the highlighted 3h band -> zoom so it fills the right half of the
    // chart; leaving the band restores the full view. (Pressure view only.)
    if (TREND_ZONE_START !== null) {
      const canvas = document.getElementById('myChart');
      const N = LABELS.length;
      const L = N - TREND_ZONE_START;             // zone length, in points
      const zoomMin = Math.max(0, N - 2 * L);     // window so the zone = right half
      let zoomed = false;

      const setZoom = (on) => {
        if (on === zoomed) return;
        zoomed = on;
        myChart.options.scales.x.min = on ? zoomMin : undefined;
        myChart.options.scales.x.max = on ? (N - 1) : undefined;
        myChart.update();
      };

      canvas.addEventListener('mousemove', (e) => {
        const area = myChart.chartArea;
        const rect = canvas.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        const startPx = myChart.scales.x.getPixelForValue(TREND_ZONE_START);
        const inZone = x >= startPx && x <= area.right && y >= area.top && y <= area.bottom;
        setZoom(inZone);
      });
      canvas.addEventListener('mouseleave', () => setZoom(false));
    }
  </script>
</body>

</html>