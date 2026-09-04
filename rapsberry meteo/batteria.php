<?php
// ── Configuration ────────────────────────────────────────────────────────────
// Dashboard for the Marstek Venus E 3.0 home battery. Reads the same SQLite
// file as index.php: mqtt_receiver.py logs one `batteria` row per minute and
// rewrites a tmpfs snapshot on every battery message, exactly as it already
// does for the Shelly meter.
define('DB_PATH', '/dev/shm/meteo.db');
define('BATTERY_LIMIT', 1440);   // default history points (~24 h at 1/min)

// Live snapshots, rewritten by mqtt_receiver.py on EVERY message. The tables
// stay on their one-minute cadence; the instant cards read these so they
// follow the device instead of the history table.
define('BATTERY_LATEST_PATH', '/dev/shm/battery_latest.json');
define('BATTERY_LATEST_MAX_AGE', 150);  // seconds; matches mqtt_receiver.py
define('ENERGY_LATEST_PATH', '/dev/shm/energy_latest.json');
define('ENERGY_LATEST_MAX_AGE', 150);

// Capacity of the pack, kWh — only used to turn SoC into a "residuo" figure.
// 5.12 kWh is what this battery reports as rated_capacity (5120 Wh) over its
// local API, and Bat.GetStatus's bat_capacity tracks SoC against exactly that.
define('BATTERY_CAPACITY_KWH', 5.12);

// ── API mode ─────────────────────────────────────────────────────────────────
$api = $_GET['api'] ?? '';

if ($api !== '') {
  header('Content-Type: application/json');

  if (!file_exists(DB_PATH)) {
    echo json_encode(['error' => 'Database not found']);
    exit;
  }

  if (!in_array('sqlite', PDO::getAvailableDrivers())) {
    echo json_encode(['error' => 'PDO SQLite driver not available. Run: sudo apt install php-sqlite3']);
    exit;
  }

  try {
    $db = new PDO('sqlite:' . DB_PATH, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_TIMEOUT => 2,
    ]);
  } catch (Exception $e) {
    echo json_encode(['error' => 'Cannot open DB: ' . $e->getMessage()]);
    exit;
  }

  function db_rows(PDO $db, string $sql): array
  {
    try {
      return $db->query($sql)->fetchAll();
    } catch (Exception $e) {
      // Table missing (battery not connected yet) — an empty history is the
      // honest answer, and the page then shows "in attesa di dati".
      return [];
    }
  }

  /** Content of $path when it is younger than $maxAge seconds, else null. */
  function snapshot(string $path, int $maxAge)
  {
    if (!is_readable($path)) return null;
    $j = json_decode((string) @file_get_contents($path), true);
    if (!is_array($j) || !isset($j['timestamp'])) return null;
    $age = time() - (int) strtotime($j['timestamp']);
    return ($age >= 0 && $age <= $maxAge) ? $j : null;
  }

  /**
   * Freshest battery reading: the tmpfs snapshot when it is recent, otherwise
   * the newest `batteria` row. The snapshot is up to a minute ahead of the
   * table — it is rewritten on every message, not once per stored row.
   */
  function battery_live(PDO $db)
  {
    $snap = snapshot(BATTERY_LATEST_PATH, BATTERY_LATEST_MAX_AGE);
    if ($snap !== null) return $snap;
    $rows = db_rows($db, "SELECT * FROM batteria ORDER BY id DESC LIMIT 1");
    return $rows[0] ?? null;
  }

  /** Same idea for the Shelly meter, so the flow panel can show PV/rete/casa. */
  function energy_live(PDO $db)
  {
    $snap = snapshot(ENERGY_LATEST_PATH, ENERGY_LATEST_MAX_AGE);
    if ($snap === null) {
      $rows = db_rows($db, "SELECT * FROM energia ORDER BY id DESC LIMIT 1");
      $snap = $rows[0] ?? null;
    }
    // Same PV deadband index.php applies: production under 10 W is noise.
    if (is_array($snap) && isset($snap['pv_power']) && is_numeric($snap['pv_power'])
        && abs((float) $snap['pv_power']) < 10.0) {
      $snap['pv_power'] = 0.0;
      if (isset($snap['grid_power']) && is_numeric($snap['grid_power'])) {
        $snap['casa_power'] = (float) $snap['grid_power'];
      }
    }
    return $snap;
  }

  /**
   * Charge / discharge energy over the given rows, kWh.
   *
   * The Venus reports instantaneous power and its lifetime counters are not
   * guaranteed to be published, so the daily figures are integrated here:
   * trapezoid over consecutive samples, positive power counted as charge and
   * negative as discharge. Gaps longer than 5 min are skipped rather than
   * bridged — a receiver that was down must not invent energy.
   */
  function integrate_kwh(array $rows): array
  {
    $charge = 0.0;
    $discharge = 0.0;
    $prevT = null;
    $prevP = null;
    foreach ($rows as $r) {
      $t = isset($r['timestamp']) ? strtotime((string) $r['timestamp']) : false;
      $p = isset($r['battery_power']) && is_numeric($r['battery_power']) ? (float) $r['battery_power'] : null;
      if ($t === false || $p === null) { $prevT = null; $prevP = null; continue; }
      if ($prevT !== null) {
        $dt = $t - $prevT;
        if ($dt > 0 && $dt <= 300) {
          $wh = (($p + $prevP) / 2.0) * $dt / 3600.0;
          if ($wh > 0) $charge += $wh; else $discharge += -$wh;
        }
      }
      $prevT = $t;
      $prevP = $p;
    }
    return ['charge_kwh' => $charge / 1000.0, 'discharge_kwh' => $discharge / 1000.0];
  }

  try {
    switch ($api) {
      case 'batteria':
        $limit = min((int) ($_GET['limit'] ?? BATTERY_LIMIT), 2880);
        $rows = db_rows($db, "SELECT * FROM batteria ORDER BY id DESC LIMIT $limit");
        echo json_encode(array_reverse($rows));
        break;

      // Everything the live cards need in one small request: the fast poll
      // hits this and nothing else.
      case 'instant':
        echo json_encode([
          'batteria' => battery_live($db),
          'energia'  => energy_live($db),
          'now'      => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        break;

      // Today (local midnight → now) and the whole of yesterday, so each of
      // today's totals can carry the day-before figure beside it.
      case 'daily':
        $todayStart = gmdate('Y-m-d\TH:i:s\Z', strtotime('today midnight'));
        $ydayStart  = gmdate('Y-m-d\TH:i:s\Z', strtotime('yesterday midnight'));
        $rows = [];
        try {
          $st = $db->prepare("SELECT timestamp, battery_power, soc FROM batteria
                              WHERE timestamp >= :from ORDER BY id ASC");
          $st->execute([':from' => $ydayStart]);
          $rows = $st->fetchAll();
        } catch (Exception $e) { /* table absent — empty totals */ }

        $today = [];
        $yday = [];
        foreach ($rows as $r) {
          if ((string) $r['timestamp'] >= $todayStart) $today[] = $r; else $yday[] = $r;
        }

        $socOf = function (array $rs) {
          $min = null; $max = null;
          foreach ($rs as $r) {
            if (!isset($r['soc']) || !is_numeric($r['soc'])) continue;
            $v = (float) $r['soc'];
            $min = ($min === null) ? $v : min($min, $v);
            $max = ($max === null) ? $v : max($max, $v);
          }
          return ['soc_min' => $min, 'soc_max' => $max];
        };

        echo json_encode([
          'today' => integrate_kwh($today) + $socOf($today),
          'yday'  => integrate_kwh($yday) + $socOf($yday),
        ]);
        break;

      default:
        echo json_encode(['error' => 'Unknown API endpoint']);
    }
  } catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Batteria Marstek Venus E — Dashboard</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: system-ui, sans-serif;
      background: #f1f5f9;
      color: #1e293b;
      min-height: 100vh;
      padding: 1.5rem;
    }

    header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.5rem;
    }

    header h1 {
      font-size: 1.4rem;
      font-weight: 600;
      letter-spacing: .02em;
    }

    header h1 svg {
      width: 1.5rem;
      height: 1.5rem;
      vertical-align: -.25rem;
      margin-right: .4rem;
      color: #16a34a;
    }

    #last-update {
      font-size: .8rem;
      color: #64748b;
    }

    .header-meta {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .header-link {
      font-size: .85rem;
      color: #0284c7;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: .3rem;
    }

    .header-link svg { width: 1rem; height: 1rem; }
    .header-link:hover { text-decoration: underline; }

    /* ── Cards ── */
    .cards {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
      gap: .75rem;
      margin-bottom: 1.5rem;
    }

    .card {
      background: #ffffff;
      border-radius: .75rem;
      padding: .9rem 1rem;
      display: flex;
      flex-direction: column;
      gap: .25rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
    }

    .card .label {
      font-size: .7rem;
      text-transform: uppercase;
      color: #94a3b8;
      letter-spacing: .05em;
    }

    .card .value {
      font-size: 1.5rem;
      font-weight: 700;
    }

    .card .unit {
      font-size: .75rem;
      color: #64748b;
    }

    .card .sub {
      font-size: .72rem;
      color: #94a3b8;
    }

    /* value colour helpers */
    .c-blue { color: #0284c7; }
    .c-green { color: #16a34a; }
    .c-yellow { color: #ca8a04; }
    .c-orange { color: #ea580c; }
    .c-red { color: #dc2626; }
    .c-purple { color: #9333ea; }
    .c-teal { color: #0d9488; }
    .c-gray { color: #475569; }

    /* ── Grouped panels ── */
    .groups {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(480px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .group {
      background: #ffffff;
      border-radius: .75rem;
      padding: 1rem 1.2rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
    }

    .group h2 {
      font-size: .85rem;
      color: #64748b;
      margin-bottom: .75rem;
      text-transform: uppercase;
      letter-spacing: .05em;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: .5rem;
    }

    .group h2 svg {
      width: 1.1rem;
      height: 1.1rem;
      flex-shrink: 0;
    }

    .group canvas { width: 100% !important; }

    .icon-batt { color: #16a34a; }
    .icon-bolt { color: #f59e0b; }
    .icon-plug { color: #f97316; }
    .icon-flow { color: #0284c7; }

    .group-stats {
      display: flex;
      gap: 2rem;
      flex-wrap: wrap;
      margin-bottom: .75rem;
    }

    /* Sotto-titolo dentro un gruppo, per separare le tre famiglie di misure. */
    .diag-title {
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #94a3b8;
      font-weight: 600;
      margin: .9rem 0 .4rem;
    }

    .diag-title:first-of-type {
      margin-top: .2rem;
    }

    .stat {
      display: flex;
      flex-direction: column;
      gap: .15rem;
    }

    .stat .label {
      font-size: .7rem;
      text-transform: uppercase;
      color: #94a3b8;
      letter-spacing: .05em;
    }

    .stat .value {
      font-size: 1.75rem;
      font-weight: 700;
    }

    .stat .unit {
      font-size: .75rem;
      color: #64748b;
    }

    /* ── State-of-charge gauge ── */
    .soc-wrap {
      display: flex;
      align-items: center;
      gap: 1.2rem;
      margin-bottom: .9rem;
    }

    .soc-figure {
      display: flex;
      align-items: baseline;
      gap: .3rem;
    }

    .soc-figure .value {
      font-size: 2.6rem;
      font-weight: 700;
      line-height: 1;
    }

    .soc-figure .unit {
      font-size: 1rem;
      color: #64748b;
    }

    .soc-bar {
      flex: 1;
      min-width: 120px;
      height: 1.5rem;
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      border-radius: 999px;
      overflow: hidden;
    }

    .soc-fill {
      height: 100%;
      width: 0;
      border-radius: 999px;
      background: #16a34a;
      transition: width .4s ease, background-color .4s ease;
    }

    .soc-fill.low { background: #dc2626; }
    .soc-fill.mid { background: #ca8a04; }

    /* ── Badges ── */
    .badge {
      padding: .15rem .5rem;
      border-radius: 999px;
      font-size: .72rem;
      white-space: nowrap;
    }

    .badge-charge { background: #dcfce7; color: #16a34a; }
    .badge-discharge { background: #ffedd5; color: #ea580c; }
    .badge-idle { background: #e2e8f0; color: #475569; }
    .badge-off { background: #fee2e2; color: #dc2626; }

    .state-badge {
      margin-left: auto;
      font-weight: 600;
      letter-spacing: 0;
      text-transform: none;
    }

    /* ── Energy flow ── */
    .flow {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: .75rem;
    }

    .flow-item {
      border: 1px solid #e2e8f0;
      border-radius: .6rem;
      padding: .7rem .8rem;
      display: flex;
      flex-direction: column;
      gap: .2rem;
    }

    .flow-item .label {
      font-size: .7rem;
      text-transform: uppercase;
      color: #94a3b8;
      letter-spacing: .05em;
    }

    .flow-item .value {
      font-size: 1.35rem;
      font-weight: 700;
    }

    .flow-item .dir {
      font-size: .7rem;
      color: #64748b;
    }

    /* ── Tables ── */
    .section-title {
      font-size: .85rem;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: .05em;
      margin-bottom: .6rem;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      background: #ffffff;
      border-radius: .75rem;
      overflow: hidden;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
    }

    th,
    td {
      padding: .55rem .9rem;
      text-align: left;
      font-size: .82rem;
    }

    th {
      background: #f8fafc;
      color: #94a3b8;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .05em;
      border-bottom: 1px solid #e2e8f0;
    }

    tr:not(:last-child) td {
      border-bottom: 1px solid #f1f5f9;
    }

    td.num,
    th.num {
      text-align: right;
      font-variant-numeric: tabular-nums;
    }

    td.metric {
      font-weight: 600;
      color: #334155;
    }

    #db-error,
    #no-data {
      border-radius: .5rem;
      padding: .75rem 1rem;
      margin-bottom: 1rem;
      display: none;
    }

    #db-error {
      background: #fee2e2;
      color: #dc2626;
    }

    #no-data {
      background: #fef9c3;
      color: #854d0e;
      font-size: .85rem;
    }

    #no-data code {
      background: #fef3c7;
      border-radius: .25rem;
      padding: 0 .25rem;
    }
  </style>
</head>

<body>

  <header>
    <h1>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
        stroke-linejoin="round" aria-hidden="true">
        <rect x="2" y="7" width="16" height="10" rx="2" />
        <line x1="22" y1="11" x2="22" y2="13" />
        <line x1="6" y1="11" x2="6" y2="13" />
        <line x1="10" y1="11" x2="10" y2="13" />
      </svg>
      Batteria — Marstek Venus E 3.0
    </h1>
    <div class="header-meta">
      <a class="header-link" href="index.php" title="Dashboard stazione meteo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round" aria-hidden="true">
          <path d="M17.5 19a4.5 4.5 0 1 0 0-9h-1.8A7 7 0 1 0 4 15.5" />
        </svg>
        Stazione Meteo
      </a>
      <a class="header-link" href="alarms.php" title="Configura allarmi">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round" aria-hidden="true">
          <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
          <line x1="12" y1="9" x2="12" y2="13" />
          <line x1="12" y1="17" x2="12.01" y2="17" />
        </svg>
        Allarmi
      </a>
      <span id="last-update">Loading…</span>
    </div>
  </header>

  <div id="db-error"></div>
  <div id="no-data">
    Nessun dato dalla batteria: il ricevitore MQTT non ha ancora visto un messaggio sui topic
    <code>casa/batteria/data</code> / <code>marstek/venus/+</code>.
  </div>

  <!-- ── Live cards ── -->
  <div class="cards">
    <div class="card">
      <span class="label">Potenza batteria</span>
      <span class="value c-green" id="c-power">—</span>
      <span class="unit" id="c-power-dir">W</span>
    </div>
    <div class="card">
      <span class="label">Energia residua</span>
      <span class="value c-teal" id="c-residual">—</span>
      <span class="unit">kWh stimati</span>
    </div>
    <div class="card">
      <span class="label">Caricata oggi</span>
      <span class="value c-green" id="c-charge-today">—</span>
      <span class="unit">kWh</span>
      <span class="sub">ieri <span id="c-charge-yday">—</span> kWh</span>
    </div>
    <div class="card">
      <span class="label">Scaricata oggi</span>
      <span class="value c-orange" id="c-discharge-today">—</span>
      <span class="unit">kWh</span>
      <span class="sub">ieri <span id="c-discharge-yday">—</span> kWh</span>
    </div>
    <div class="card">
      <span class="label">Temperatura celle</span>
      <span class="value c-purple" id="c-temp">—</span>
      <span class="unit">°C</span>
      <!-- L'elettronica gira parecchio piu' calda del pacco: tenerla qui sotto
           evita di confonderla con la temperatura su cui lavora il BMS. -->
      <span class="sub">min <span id="c-temp-min">—</span>° · &Delta; <span id="c-temp-spread">—</span>° · elettronica <span id="c-temp-mos">—</span>°</span>
    </div>
    <div class="card">
      <span class="label">SoC min/max oggi</span>
      <span class="value c-gray" id="c-soc-range">—</span>
      <span class="unit">%</span>
    </div>
  </div>

  <div class="groups">
    <!-- ── State of charge ── -->
    <div class="group">
      <h2>
        <svg class="icon-batt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="2" y="7" width="16" height="10" rx="2" />
          <line x1="22" y1="11" x2="22" y2="13" />
        </svg>
        Stato di carica
        <span class="badge badge-idle state-badge" id="c-state">—</span>
      </h2>
      <div class="soc-wrap">
        <div class="soc-figure">
          <span class="value c-green" id="c-soc">—</span><span class="unit">%</span>
        </div>
        <div class="soc-bar">
          <div class="soc-fill" id="soc-fill"></div>
        </div>
      </div>
      <canvas id="chart-soc" height="140"></canvas>
    </div>

    <!-- ── Charge / discharge power ── -->
    <div class="group">
      <h2>
        <svg class="icon-bolt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
        </svg>
        Potenza (carica &gt; 0 / scarica &lt; 0)
        <span class="badge badge-idle state-badge" id="c-mode">—</span>
      </h2>
      <div class="group-stats">
        <div class="stat"><span class="label">Batteria</span><span class="value c-green" id="s-power">—</span><span
            class="unit">W</span></div>
        <div class="stat"><span class="label">Uscita AC</span><span class="value c-blue" id="s-ac">—</span><span
            class="unit">W</span></div>
        <div class="stat"><span class="label">SoC</span><span class="value c-teal" id="s-soc">—</span><span
            class="unit">%</span></div>
      </div>
      <canvas id="chart-power" height="140"></canvas>
    </div>

    <!-- ── Everything the pack reports about itself ──
         Tre temperature, quattro tensioni, due correnti: separate per fascia
         perche' rispondono a domande diverse. Le celle dicono se il pacco sta
         bene, l'elettronica se il raffreddamento tiene, gli scarti (Δ) se il
         pacco e' bilanciato. -->
    <div class="group">
      <h2>
        <svg class="icon-bolt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M14 4v10.5a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0z" />
          <line x1="14" y1="8" x2="17" y2="8" />
          <line x1="14" y1="12" x2="17" y2="12" />
        </svg>
        Temperature, tensioni e correnti
      </h2>

      <h3 class="diag-title">Temperature</h3>
      <div class="group-stats">
        <div class="stat"><span class="label">Cella max</span><span class="value" id="d-cell-max">—</span><span
            class="unit">°C</span></div>
        <div class="stat"><span class="label">Cella min</span><span class="value" id="d-cell-min">—</span><span
            class="unit">°C</span></div>
        <div class="stat"><span class="label">&Delta; celle</span><span class="value" id="d-cell-spread">—</span><span
            class="unit">°C · sopra 5 sbilanciato</span></div>
        <div class="stat"><span class="label">Interna</span><span class="value c-purple" id="d-t-int">—</span><span
            class="unit">°C</span></div>
        <div class="stat"><span class="label">MOS 1</span><span class="value c-purple" id="d-t-mos1">—</span><span
            class="unit">°C</span></div>
        <div class="stat"><span class="label">MOS 2</span><span class="value c-purple" id="d-t-mos2">—</span><span
            class="unit">°C</span></div>
      </div>

      <h3 class="diag-title">Tensioni</h3>
      <div class="group-stats">
        <div class="stat"><span class="label">Pacco</span><span class="value c-teal" id="d-v-pack">—</span><span
            class="unit">V</span></div>
        <div class="stat"><span class="label">Cella max</span><span class="value c-teal" id="d-v-cmax">—</span><span
            class="unit">V</span></div>
        <div class="stat"><span class="label">Cella min</span><span class="value c-teal" id="d-v-cmin">—</span><span
            class="unit">V</span></div>
        <div class="stat"><span class="label">&Delta; celle</span><span class="value" id="d-v-spread">—</span><span
            class="unit">mV · sopra 50 sbilanciato</span></div>
        <div class="stat"><span class="label">Rete AC</span><span class="value c-blue" id="d-v-ac">—</span><span
            class="unit">V · <span id="d-hz">—</span> Hz</span></div>
      </div>

      <h3 class="diag-title">Correnti</h3>
      <div class="group-stats">
        <div class="stat"><span class="label">Pacco</span><span class="value c-green" id="d-i-pack">—</span><span
            class="unit">A</span></div>
        <!-- Il registro 37004 della mappa restituisce la potenza, non la
             corrente: questa e' ricavata da |W| / V, e lo dice. -->
        <div class="stat"><span class="label">Rete AC</span><span class="value c-blue" id="d-i-ac">—</span><span
            class="unit">A stimata da W/V</span></div>
      </div>
    </div>

    <!-- ── Flow against the Shelly meter ── -->
    <div class="group">
      <h2>
        <svg class="icon-flow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="10" />
          <path d="M4 12h6M14 12h6" />
          <path d="M10 8l4 4-4 4" />
        </svg>
        Flusso energetico (adesso)
      </h2>
      <div class="flow">
        <div class="flow-item">
          <span class="label">Fotovoltaico</span>
          <span class="value c-orange" id="f-pv">—</span>
          <span class="dir">W prodotti</span>
        </div>
        <div class="flow-item">
          <span class="label">Rete</span>
          <span class="value c-blue" id="f-grid">—</span>
          <span class="dir" id="f-grid-dir">W</span>
        </div>
        <div class="flow-item">
          <span class="label">Batteria</span>
          <span class="value c-green" id="f-batt">—</span>
          <span class="dir" id="f-batt-dir">W</span>
        </div>
        <div class="flow-item">
          <span class="label">Casa</span>
          <span class="value c-purple" id="f-casa">—</span>
          <span class="dir">W consumati</span>
        </div>
      </div>
    </div>

    <!-- ── Battery vs house/PV over 24 h ── -->
    <div class="group">
      <h2>
        <svg class="icon-plug" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M9 2v6M15 2v6" />
          <path d="M6 8h12v3a6 6 0 0 1-12 0V8z" />
          <path d="M12 17v5" />
        </svg>
        Batteria e impianto (24 h)
      </h2>
      <canvas id="chart-mix" height="140"></canvas>
    </div>
  </div>

  <!-- ── Today vs yesterday ── -->
  <div class="section-title">Riepilogo giornaliero</div>
  <table id="daily-table" style="margin-bottom:1.5rem">
    <thead>
      <tr>
        <th>Grandezza</th>
        <th class="num">Oggi</th>
        <th class="num">Ieri</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td colspan="3" style="color:#64748b">Loading…</td>
      </tr>
    </tbody>
  </table>

  <!-- ── Raw readings ── -->
  <div class="section-title">Ultime letture</div>
  <table id="readings-table">
    <thead>
      <tr>
        <th>Ora</th>
        <th class="num">SoC (%)</th>
        <th class="num">Potenza (W)</th>
        <th class="num">Temp. (°C)</th>
        <th>Stato</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td colspan="5" style="color:#64748b">Loading…</td>
      </tr>
    </tbody>
  </table>

  <script>
    // ── Chart factory ─────────────────────────────────────────────────────────────
    const GRID = 'rgba(0,0,0,.06)';
    const FONT = '#64748b';
    const CAPACITY_KWH = <?= json_encode(BATTERY_CAPACITY_KWH) ?>;

    function makeChart(id, datasets, yLabel = '') {
      return new Chart(document.getElementById(id), {
        type: 'line',
        data: { labels: [], datasets },
        options: {
          animation: false,
          responsive: true,
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: { labels: { color: FONT, boxWidth: 12, font: { size: 11 } } } },
          scales: {
            x: { ticks: { color: FONT, maxTicksLimit: 8, font: { size: 10 } }, grid: { color: GRID } },
            y: { ticks: { color: FONT, font: { size: 10 } }, grid: { color: GRID }, title: { display: !!yLabel, text: yLabel, color: FONT, font: { size: 10 } } }
          }
        }
      });
    }

    function ds(label, color, fill = false) {
      return {
        label, data: [], borderColor: color,
        backgroundColor: fill ? color.replace(')', ',.15)').replace('rgb', 'rgba') : 'transparent',
        borderWidth: 1.5, pointRadius: 0, tension: .3, fill
      };
    }

    const charts = {
      // SoC is a percentage, so its axis is pinned to 0–100 instead of being
      // rescaled to the day's range — a 62→68 % swing must not look dramatic.
      soc: (() => {
        const c = makeChart('chart-soc', [ds('SoC', 'rgb(22,163,74)', true)], '%');
        c.options.scales.y.min = 0;
        c.options.scales.y.max = 100;
        c.update('none');
        return c;
      })(),
      power: makeChart('chart-power', [
        ds('Potenza batteria', 'rgb(22,163,74)', true),
        ds('Uscita AC', 'rgb(2,132,199)'),
      ], 'W'),
      mix: makeChart('chart-mix', [
        ds('Produzione FV', 'rgb(249,115,22)'),
        ds('Consumo Casa', 'rgb(168,85,247)'),
        ds('Batteria', 'rgb(22,163,74)'),
      ], 'W'),
    };

    // ── Helpers ───────────────────────────────────────────────────────────────────
    // Signed numeric coercion: keeps negatives (discharge, export) but rejects junk.
    function num(v) {
      if (v === null || v === undefined || v === '') return null;
      const n = Number(v);
      return Number.isFinite(n) ? n : null;
    }

    function fmt(v, decimals = 1) {
      const n = num(v);
      return n === null ? '—' : n.toFixed(decimals);
    }

    function setCard(id, v, decimals = 1) {
      document.getElementById(id).textContent = fmt(v, decimals);
    }

    function updateChartData(chart, labels, ...series) {
      chart.data.labels = labels;
      series.forEach((s, i) => { chart.data.datasets[i].data = s; });
      chart.update('none');
    }

    const ROME_TZ = 'Europe/Rome';

    function romeHHMM(ts) {
      const d = new Date(ts);
      if (Number.isNaN(d.getTime())) return null;
      return d.toLocaleTimeString('en-GB', { timeZone: ROME_TZ, hour: '2-digit', minute: '2-digit', hour12: false });
    }

    function romeFullDateTime(ts) {
      const d = new Date(ts);
      if (Number.isNaN(d.getTime())) return ts;
      return d.toLocaleString('it-IT', { timeZone: ROME_TZ, hour12: false });
    }

    // Charging / discharging / idle, from the signed battery power. Under 15 W
    // the pack is only feeding its own electronics, so it reads as "in attesa"
    // instead of flickering between the two directions.
    const IDLE_W = 15;

    function powerState(p) {
      if (p === null) return { key: 'off', label: 'Nessun dato', cls: 'badge-off', color: 'c-gray' };
      if (p > IDLE_W) return { key: 'charge', label: 'In carica', cls: 'badge-charge', color: 'c-green' };
      if (p < -IDLE_W) return { key: 'discharge', label: 'In scarica', cls: 'badge-discharge', color: 'c-orange' };
      return { key: 'idle', label: 'In attesa', cls: 'badge-idle', color: 'c-gray' };
    }

    // Pack-temperature thresholds. LiFePO4 cells are happy from 10 to 35 °C;
    // past 40 °C they are warm enough to watch and past 45 °C the BMS starts
    // derating, so those two are coloured.
    const CELL_WARM_C = 40;
    const CELL_HOT_C = 45;

    function cellTempClass(t) {
      if (t === null) return '';
      if (t >= CELL_HOT_C) return 'c-red';
      if (t >= CELL_WARM_C) return 'c-orange';
      return '';
    }

    /** Every temperature, voltage and current the pack reports. */
    function renderDiagnostics(b) {
      const set = (id, v, dec) => {
        document.getElementById(id).textContent = fmt(v, dec);
      };
      const cellMax = num(b.cell_temp_max), cellMin = num(b.cell_temp_min);
      set('d-cell-max', cellMax, 1);
      set('d-cell-min', cellMin, 1);
      set('d-t-int', num(b.temperature), 1);
      set('d-t-mos1', num(b.temp_mos1), 1);
      set('d-t-mos2', num(b.temp_mos2), 1);

      const tSpread = (cellMax !== null && cellMin !== null) ? cellMax - cellMin : null;
      const tSpreadEl = document.getElementById('d-cell-spread');
      tSpreadEl.textContent = fmt(tSpread, 1);
      tSpreadEl.className = 'value ' + (tSpread !== null && tSpread > 5 ? 'c-orange' : 'c-gray');

      // Cell colour follows the BMS thresholds, same as the card above.
      document.getElementById('d-cell-max').className = 'value ' + (cellTempClass(cellMax) || 'c-teal');
      document.getElementById('d-cell-min').className = 'value ' +
        (cellMin !== null && cellMin < 0 ? 'c-blue' : 'c-teal');

      const vMax = num(b.cell_voltage_max), vMin = num(b.cell_voltage_min);
      set('d-v-pack', num(b.battery_voltage), 2);
      set('d-v-cmax', vMax, 3);
      set('d-v-cmin', vMin, 3);
      set('d-v-ac', num(b.ac_voltage), 1);
      set('d-hz', num(b.ac_frequency), 2);

      // In mV: the interesting numbers here are tens of millivolts, and three
      // decimals of a volt hide exactly the digit that matters.
      const vSpread = (vMax !== null && vMin !== null) ? (vMax - vMin) * 1000 : null;
      const vSpreadEl = document.getElementById('d-v-spread');
      vSpreadEl.textContent = fmt(vSpread, 0);
      vSpreadEl.className = 'value ' + (vSpread !== null && vSpread > 50 ? 'c-orange' : 'c-gray');

      set('d-i-pack', num(b.battery_current), 1);

      // Derived, not read: register 37004 ("ac_current" in the community map)
      // returns the AC power on this firmware, so it is not published at all.
      const acW = num(b.ac_power), acV = num(b.ac_voltage);
      const acA = (acW !== null && acV !== null && acV > 50) ? Math.abs(acW) / acV : null;
      set('d-i-ac', acA, 1);
    }

    function setBadge(id, label, cls) {
      const el = document.getElementById(id);
      el.textContent = label;
      el.className = 'badge state-badge ' + cls;
    }

    // ── Live cards ────────────────────────────────────────────────────────────────
    function renderBattery(b) {
      document.getElementById('no-data').style.display = b ? 'none' : 'block';
      if (!b) return;

      const soc = num(b.soc);
      const power = num(b.battery_power);
      const st = powerState(power);

      document.getElementById('c-soc').textContent = fmt(soc, 0);
      document.getElementById('s-soc').textContent = fmt(soc, 0);
      const fill = document.getElementById('soc-fill');
      fill.style.width = (soc === null ? 0 : Math.max(0, Math.min(100, soc))) + '%';
      fill.className = 'soc-fill' + (soc === null ? '' : soc < 20 ? ' low' : soc < 50 ? ' mid' : '');

      // Power is shown unsigned with the direction spelled out beside it; the
      // chart and the readings table keep the sign.
      const powerEl = document.getElementById('c-power');
      powerEl.textContent = power === null ? '—' : Math.abs(power).toFixed(0);
      powerEl.className = 'value ' + st.color;
      document.getElementById('c-power-dir').textContent =
        power === null ? 'W' : st.key === 'idle' ? 'W — in attesa'
          : 'W — ' + (st.key === 'charge' ? 'in carica' : 'in scarica');
      document.getElementById('s-power').textContent = power === null ? '—' : power.toFixed(0);
      document.getElementById('s-ac').textContent = fmt(b.ac_power, 0);

      // The headline temperature is the HOTTEST CELL, which is what the BMS
      // limits work from. `temperature` (register 35000) is the electronics /
      // MOS area and runs a good 6 °C above the pack -- colouring that one
      // with the cell thresholds below would raise a warning about a battery
      // that is perfectly happy. Over the local API the pack was the only
      // reading there was, hence the old behaviour; Modbus publishes both.
      // meteo.py picks the same figure for the remote dashboard, so the two
      // agree: see read_latest_battery().
      const cellMax = num(b.cell_temp_max);
      const cellMin = num(b.cell_temp_min);
      const mos = num(b.temperature);
      const temp = cellMax !== null ? cellMax : mos;
      const tempEl = document.getElementById('c-temp');
      tempEl.textContent = fmt(temp, 1);
      tempEl.className = 'value ' + (cellTempClass(temp) || 'c-purple');
      document.getElementById('c-temp-min').textContent = fmt(cellMin, 1);
      document.getElementById('c-temp-mos').textContent = fmt(mos, 1);
      // The spread matters as much as the absolute value: above ~5 °C it
      // points at a weak or badly balanced cell even while both ends look
      // fine, which is why BATTERY_VENUS.md asks for it on this card.
      const spread = (cellMax !== null && cellMin !== null) ? cellMax - cellMin : null;
      const spreadEl = document.getElementById('c-temp-spread');
      spreadEl.textContent = fmt(spread, 1);
      spreadEl.className = (spread !== null && spread > 5) ? 'c-orange' : '';

      renderDiagnostics(b);

      // Residual energy is an estimate: the Venus publishes no kWh gauge, so
      // it is usable capacity × SoC, and the card says "stimati".
      document.getElementById('c-residual').textContent =
        soc === null ? '—' : (CAPACITY_KWH * soc / 100).toFixed(2);

      setBadge('c-state', b.state ? String(b.state) : st.label, st.cls);
      setBadge('c-mode', b.mode ? String(b.mode) : '—', 'badge-idle');

      document.getElementById('f-batt').textContent = power === null ? '—' : Math.abs(power).toFixed(0);
      document.getElementById('f-batt-dir').textContent =
        power === null ? 'W' : st.key === 'charge' ? 'W in carica'
          : st.key === 'discharge' ? 'W in scarica' : 'W — in attesa';

      document.getElementById('last-update').textContent =
        'Aggiornato: ' + romeFullDateTime(b.timestamp || new Date().toISOString()) + ' (Roma)';
    }

    function renderEnergy(e) {
      if (!e) return;
      const grid = num(e.grid_power);
      document.getElementById('f-pv').textContent = fmt(e.pv_power, 0);
      document.getElementById('f-grid').textContent = grid === null ? '—' : Math.abs(grid).toFixed(0);
      document.getElementById('f-grid-dir').textContent =
        grid === null ? 'W' : grid >= 0 ? 'W prelevati' : 'W immessi';
      document.getElementById('f-casa').textContent = fmt(e.casa_power, 0);
    }

    // ── History ───────────────────────────────────────────────────────────────────
    async function loadHistory() {
      const res = await fetch('?api=batteria&limit=1440');
      if (!res.ok) throw new Error(`HTTP ${res.status} on batteria`);
      const rows = await res.json();
      if (rows.error) { showError(rows.error); return; }
      if (!Array.isArray(rows)) { showError('Unexpected response from batteria API'); return; }
      hideError();

      const labels = rows.map(r => r.timestamp ? (romeHHMM(r.timestamp) ?? '') : '');
      updateChartData(charts.soc, labels, rows.map(r => num(r.soc)));
      updateChartData(charts.power, labels,
        rows.map(r => num(r.battery_power)),
        rows.map(r => num(r.ac_power)),
      );
      updateChartData(charts.mix, labels,
        rows.map(r => num(r.pv_power)),
        rows.map(r => num(r.casa_power)),
        rows.map(r => num(r.battery_power)),
      );

      renderReadingsTable(rows.slice(-20).reverse());
    }

    function renderReadingsTable(rows) {
      const tbody = document.querySelector('#readings-table tbody');
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="5" style="color:#64748b">Nessuna lettura</td></tr>';
        return;
      }
      tbody.innerHTML = rows.map(r => {
        const p = num(r.battery_power);
        const st = powerState(p);
        return `<tr>
          <td>${romeHHMM(r.timestamp) ?? '—'}</td>
          <td class="num">${fmt(r.soc, 0)}</td>
          <td class="num">${p === null ? '—' : p.toFixed(0)}</td>
          <td class="num ${cellTempClass(num(r.temperature))}">${fmt(r.temperature, 1)}</td>
          <td><span class="badge ${st.cls}">${r.state ? String(r.state) : st.label}</span></td>
        </tr>`;
      }).join('');
    }

    async function loadDaily() {
      const res = await fetch('?api=daily');
      if (!res.ok) throw new Error(`HTTP ${res.status} on daily`);
      const d = await res.json();
      if (!d || d.error) return;

      const t = d.today || {}, y = d.yday || {};
      setCard('c-charge-today', t.charge_kwh, 2);
      setCard('c-discharge-today', t.discharge_kwh, 2);
      document.getElementById('c-charge-yday').textContent = fmt(y.charge_kwh, 2);
      document.getElementById('c-discharge-yday').textContent = fmt(y.discharge_kwh, 2);

      document.getElementById('c-soc-range').textContent =
        (num(t.soc_min) === null || num(t.soc_max) === null)
          ? '—' : `${Number(t.soc_min).toFixed(0)}–${Number(t.soc_max).toFixed(0)}`;

      // Round-trip yield only means something once both directions have run.
      const eff = (r) => (num(r.charge_kwh) > 0.05 && num(r.discharge_kwh) > 0)
        ? (r.discharge_kwh / r.charge_kwh * 100).toFixed(0) + ' %' : '—';

      document.querySelector('#daily-table tbody').innerHTML = `
        <tr><td class="metric">Energia caricata</td><td class="num">${fmt(t.charge_kwh, 2)} kWh</td><td class="num">${fmt(y.charge_kwh, 2)} kWh</td></tr>
        <tr><td class="metric">Energia scaricata</td><td class="num">${fmt(t.discharge_kwh, 2)} kWh</td><td class="num">${fmt(y.discharge_kwh, 2)} kWh</td></tr>
        <tr><td class="metric">SoC minimo</td><td class="num">${fmt(t.soc_min, 0)} %</td><td class="num">${fmt(y.soc_min, 0)} %</td></tr>
        <tr><td class="metric">SoC massimo</td><td class="num">${fmt(t.soc_max, 0)} %</td><td class="num">${fmt(y.soc_max, 0)} %</td></tr>
        <tr><td class="metric">Resa ciclo</td><td class="num">${eff(t)}</td><td class="num">${eff(y)}</td></tr>`;
    }

    // ── Error banner ──────────────────────────────────────────────────────────────
    function showError(msg) {
      const el = document.getElementById('db-error');
      el.textContent = 'DB Error: ' + msg;
      el.style.display = 'block';
    }
    function hideError() { document.getElementById('db-error').style.display = 'none'; }

    // ── Refresh loops ─────────────────────────────────────────────────────────────
    // Same two cadences as index.php: the instant poll repaints the live cards
    // from the tmpfs snapshots (rewritten on every MQTT message), the slow one
    // redraws the 1440-point charts and the daily totals.
    const INSTANT_INTERVAL = 3_000;
    const FULL_INTERVAL = 30_000;

    async function refreshInstant() {
      let data;
      try {
        const res = await fetch('?api=instant');
        if (!res.ok) return;
        data = await res.json();
      } catch (e) {
        return;   // transient failure: leave the last painted values alone
      }
      if (!data || data.error) return;
      renderBattery(data.batteria);
      renderEnergy(data.energia);
    }

    async function refresh() {
      try {
        await Promise.all([loadHistory(), loadDaily()]);
      } catch (e) { showError(e.message); }
    }

    refresh();
    setInterval(refresh, FULL_INTERVAL);

    // A hidden tab paints nothing, so polling it only costs the Pi requests.
    setInterval(() => { if (!document.hidden) refreshInstant(); }, INSTANT_INTERVAL);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshInstant(); });
    refreshInstant();
  </script>
</body>

</html>
