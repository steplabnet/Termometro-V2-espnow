<?php
// ── Configuration ────────────────────────────────────────────────────────────
define('DB_PATH', '/dev/shm/meteo.db');
define('METEO_LIMIT', 1440);   // default history points (~2 h at 1/min)
define('SENSORS_LIMIT', 50);

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
      return [];
    }
  }

  try {
    switch ($api) {
      case 'meteo':
        $limit = min((int) ($_GET['limit'] ?? METEO_LIMIT), 2880);
        $rows = db_rows($db, "SELECT * FROM meteo ORDER BY id DESC LIMIT $limit");
        echo json_encode(array_reverse($rows));
        break;

      case 'meteo_latest':
        $rows = db_rows($db, "SELECT * FROM meteo ORDER BY id DESC LIMIT 1");
        echo json_encode($rows[0] ?? (object) []);
        break;

      case 'ufficio':
        $limit = min((int) ($_GET['limit'] ?? METEO_LIMIT), 2880);
        $rows = db_rows($db, "SELECT * FROM ufficio ORDER BY id DESC LIMIT $limit");
        echo json_encode(array_reverse($rows));
        break;

      case 'ufficio_latest':
        $rows = db_rows($db, "SELECT * FROM ufficio ORDER BY id DESC LIMIT 1");
        echo json_encode($rows[0] ?? (object) []);
        break;

      case 'sensors':
        $limit = min((int) ($_GET['limit'] ?? SENSORS_LIMIT), 500);
        $rows = db_rows($db, "SELECT * FROM sensors ORDER BY id DESC LIMIT $limit");
        echo json_encode($rows);
        break;

      case 'sensors_latest':
        $rows = db_rows($db, "
                    SELECT s.* FROM sensors s
                    INNER JOIN (
                        SELECT sensoreId, MAX(id) AS max_id FROM sensors GROUP BY sensoreId
                    ) g ON s.id = g.max_id
                    ORDER BY s.sensoreId
                ");
        echo json_encode($rows);
        break;

      default:
        echo json_encode(['error' => 'Unknown api: ' . htmlspecialchars($api)]);
    }
  } catch (Exception $e) {
    echo json_encode(['error' => 'Query error: ' . $e->getMessage()]);
  }

  $db = null;
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stazione Meteo — Dashboard</title>
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

    /* value colour helpers */
    .c-blue {
      color: #0284c7;
    }

    .c-green {
      color: #16a34a;
    }

    .c-yellow {
      color: #ca8a04;
    }

    .c-orange {
      color: #ea580c;
    }

    .c-red {
      color: #dc2626;
    }

    .c-purple {
      color: #9333ea;
    }

    .c-teal {
      color: #0d9488;
    }

    .c-gray {
      color: #475569;
    }

    /* ── Charts ── */
    .charts {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .chart-box {
      background: #ffffff;
      border-radius: .75rem;
      padding: 1rem 1.2rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
    }

    .chart-box h2 {
      font-size: .85rem;
      color: #64748b;
      margin-bottom: .75rem;
      text-transform: uppercase;
      letter-spacing: .05em;
    }

    .chart-box canvas {
      width: 100% !important;
    }

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

    .icon-sun {
      color: #f59e0b;
    }

    .icon-leaf {
      color: #0d9488;
    }

    .icon-cloud {
      color: #16a34a;
    }

    .icon-bolt {
      color: #ea580c;
    }

    .icon-server {
      color: #dc2626;
    }

    .icon-thermo {
      color: #9333ea;
    }

    header h1 svg {
      width: 1.5rem;
      height: 1.5rem;
      vertical-align: -.25rem;
      margin-right: .4rem;
      color: #0284c7;
    }

    .group-stats {
      display: flex;
      gap: 2rem;
      margin-bottom: .75rem;
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

    .group canvas {
      width: 100% !important;
    }

    /* ── Sensor table ── */
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

    .badge-on {
      background: #dcfce7;
      color: #16a34a;
      padding: .15rem .5rem;
      border-radius: 999px;
      font-size: .72rem;
    }

    .badge-off {
      background: #fee2e2;
      color: #dc2626;
      padding: .15rem .5rem;
      border-radius: 999px;
      font-size: .72rem;
    }

    .badge-na {
      background: #dbeafe;
      color: #2563eb;
      padding: .15rem .5rem;
      border-radius: 999px;
      font-size: .72rem;
    }

    #db-error {
      background: #fee2e2;
      color: #dc2626;
      border-radius: .5rem;
      padding: .75rem 1rem;
      margin-bottom: 1rem;
      display: none;
    }

    /* ── Clickable groups + comparison modal ── */
    .group[data-compare] {
      cursor: pointer;
      transition: transform .12s ease, box-shadow .12s ease;
    }

    .group[data-compare]:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, .12);
    }

    .modal[hidden] { display: none; }

    .modal {
      position: fixed;
      inset: 0;
      z-index: 50;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .modal-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(15, 23, 42, .55);
    }

    .modal-content {
      position: relative;
      background: #f1f5f9;
      border-radius: .75rem;
      width: min(1000px, 95vw);
      max-height: 95vh;
      overflow: auto;
      padding: 1.25rem 1.5rem;
      box-shadow: 0 10px 30px rgba(0, 0, 0, .25);
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
    }

    .modal-header h2 {
      font-size: 1.05rem;
      font-weight: 600;
      color: #1e293b;
    }

    .modal-close {
      background: transparent;
      border: none;
      font-size: 1.75rem;
      cursor: pointer;
      color: #64748b;
      padding: 0 .5rem;
      line-height: 1;
    }

    .modal-close:hover { color: #1e293b; }

    .modal-body {
      display: grid;
      gap: 1rem;
    }

    .modal-body .chart-box { margin: 0; }

    .compare-avg {
      display: flex;
      gap: 1.5rem;
      flex-wrap: wrap;
      margin-bottom: .75rem;
      font-size: .85rem;
      color: #475569;
    }

    .compare-avg .pill {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
    }

    .compare-avg .swatch {
      display: inline-block;
      width: .75rem;
      height: .25rem;
      border-radius: 2px;
    }

    .compare-avg strong {
      color: #1e293b;
      font-weight: 700;
      font-size: 1rem;
    }
  </style>
</head>

<body>

  <header>
    <h1>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
        stroke-linejoin="round" aria-hidden="true">
        <path d="M17.5 19a4.5 4.5 0 1 0 0-9h-1.8A7 7 0 1 0 4 15.5" />
        <path d="M8 19v3M12 21v3M16 19v3" />
      </svg>
      Stazione Meteo
    </h1>
    <div class="header-meta">
      <a class="header-link" href="cronotermostato.php" title="Cronotermostato ufficio">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round" aria-hidden="true">
          <path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z" />
        </svg>
        Cronotermostato
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

  <!-- ── Grouped panels (temp + humidity + combined chart) ── -->
  <div class="groups">
    <div class="group" data-compare="fullsun" title="Clicca per confronto con ieri">
      <h2>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="4" />
          <path
            d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
        </svg>
        Full Sun
      </h2>
      <div class="group-stats">
        <div class="stat"><span class="label">Temperatura</span><span class="value c-blue" id="c-temp">—</span><span
            class="unit">°C</span></div>
        <div class="stat"><span class="label">Umidità</span><span class="value c-blue" id="c-humi">—</span><span
            class="unit">%</span></div>
        <div class="stat"><span class="label">Umidità Media 24h</span><span class="value c-blue"
            id="c-humi-avg">—</span><span class="unit">%</span></div>
      </div>
      <canvas id="chart-fullsun" height="140"></canvas>
    </div>
    <div class="group" data-compare="serra" title="Clicca per confronto con ieri">
      <h2>
        <svg class="icon-leaf" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19.2 2.96c1 2.88.07 14.82-7.2 17.02A7 7 0 0 1 11 20" />
          <path d="M2 21c0-3 1.85-5.36 5.08-6" />
        </svg>
        Parametri Serra
      </h2>
      <div class="group-stats">
        <div class="stat"><span class="label">Temperatura</span><span class="value c-teal" id="c-tombra">—</span><span
            class="unit">°C</span></div>
        <div class="stat"><span class="label">Umidità</span><span class="value c-teal" id="c-hombra">—</span><span
            class="unit">%</span></div>
        <div class="stat"><span class="label">Umidità Media 24h</span><span class="value c-teal"
            id="c-hombra-avg">—</span><span class="unit">%</span></div>
      </div>
      <canvas id="chart-serra" height="140"></canvas>
    </div>
    <div class="group" data-compare="ombra" title="Clicca per confronto con ieri">
      <h2>
        <svg class="icon-cloud" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M17.5 19a4.5 4.5 0 1 0 0-9h-1.8A7 7 0 1 0 4 15.5" />
        </svg>
        Ombra
      </h2>
      <div class="group-stats">
        <div class="stat"><span class="label">Temperatura</span><span class="value c-green" id="c-tmobile">—</span><span
            class="unit">°C</span></div>
        <div class="stat"><span class="label">Umidità</span><span class="value c-green" id="c-hmobile">—</span><span
            class="unit">%</span></div>
        <div class="stat"><span class="label">Umidità Media 24h</span><span class="value c-green"
            id="c-hmobile-avg">—</span><span class="unit">%</span></div>
      </div>
      <canvas id="chart-ombra" height="140"></canvas>
    </div>
    <div class="group">
      <h2>
        <svg class="icon-bolt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" />
        </svg>
        Fotovoltaico
      </h2>
      <div class="group-stats">
        <div class="stat"><span class="label">Potenza</span><span class="value c-orange" id="c-power">—</span><span
            class="unit">W</span></div>
        <div class="stat"><span class="label">Picco 24h</span><span class="value c-orange"
            id="c-power-peak">—</span><span class="unit">W</span></div>
      </div>
      <canvas id="chart-power" height="140"></canvas>
    </div>
    <div class="group">
      <h2>
        <svg class="icon-server" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="4" y="4" width="16" height="16" rx="2" />
          <rect x="9" y="9" width="6" height="6" />
          <path d="M9 2v2M15 2v2M9 20v2M15 20v2M20 9h2M20 15h2M2 9h2M2 15h2" />
        </svg>
        Server Health
      </h2>
      <div class="group-stats">
        <div class="stat"><span class="label">CPU Temp</span><span class="value c-red" id="c-cpu">—</span><span
            class="unit">°C</span></div>
        <div class="stat"><span class="label">Ventola</span><span class="value" id="c-fan">—</span><span
            class="unit"></span></div>
      </div>
      <canvas id="chart-cpu" height="140"></canvas>
    </div>
    <div class="group">
      <h2>
        <svg class="icon-thermo" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z" />
        </svg>
        Ufficio
        <a id="uff-link" href="cronotermostato.php" title="Programma cronotermostato"
          style="margin-left:auto;font-size:.7rem;font-weight:400;text-transform:none;letter-spacing:0;color:#0284c7;text-decoration:none;">programma →</a>
      </h2>
      <div class="group-stats">
        <div class="stat"><span class="label">Temperatura</span><span class="value c-purple" id="c-uff-temp">—</span><span
            class="unit">°C</span></div>
        <div class="stat"><span class="label">Umidità</span><span class="value c-purple" id="c-uff-hum">—</span><span
            class="unit">%</span></div>
        <div class="stat"><span class="label">Setpoint</span><span class="value c-purple" id="c-uff-sp">—</span><span
            class="unit">°C</span></div>
        <div class="stat"><span class="label">Caldaia</span><span class="value" id="c-uff-heater">—</span><span
            class="unit"></span></div>
      </div>
      <canvas id="chart-ufficio" height="140"></canvas>
    </div>
  </div>

  <!-- ── Stat cards ── -->
  <div class="cards">
    <div class="card"><span class="label">Stazione ID</span><span class="value c-gray" id="c-station">—</span><span
        class="unit"></span></div>
  </div>

  <!-- ── Comparison modal (today vs yesterday) ── -->
  <div class="modal" id="compare-modal" hidden>
    <div class="modal-backdrop" data-close></div>
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="compare-title">Confronto</h2>
        <button class="modal-close" id="compare-close" aria-label="Chiudi" data-close>&times;</button>
      </div>
      <div class="modal-body">
        <div class="chart-box">
          <h2>Temperatura (°C) — oggi vs ieri</h2>
          <canvas id="compare-temp" height="180"></canvas>
        </div>
        <div class="chart-box">
          <h2>Umidità (%) — oggi vs ieri</h2>
          <div class="compare-avg">
            <span class="pill"><span class="swatch" id="compare-humi-swatch-today"></span>Media oggi:
              <strong id="compare-humi-avg-today">—</strong>%</span>
            <span class="pill"><span class="swatch" id="compare-humi-swatch-yday"
                style="background:rgba(100,116,139,.8)"></span>Media ieri:
              <strong id="compare-humi-avg-yday">—</strong>%</span>
          </div>
          <canvas id="compare-humi" height="180"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Sensors table ── -->
  <div class="section-title">Sensori IN (ultimi valori)</div>
  <table id="sensors-table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Nome</th>
        <th>Temp (°C)</th>
        <th>Pressione (hPa)</th>
        <th>Status</th>
        <th>Timestamp</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td colspan="6" style="color:#64748b">Loading…</td>
      </tr>
    </tbody>
  </table>

  <script>
    // ── Chart factory ─────────────────────────────────────────────────────────────
    const GRID = 'rgba(0,0,0,.06)';
    const FONT = '#64748b';

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
        label, data: [], borderColor: color, backgroundColor: fill ? color.replace(')', ',.15)').replace('rgb', 'rgba') : 'transparent',
        borderWidth: 1.5, pointRadius: 0, tension: .3, fill
      };
    }

    function makeTempHumiChart(id, tempColor, humiColor) {
      return new Chart(document.getElementById(id), {
        type: 'line',
        data: {
          labels: [],
          datasets: [
            {
              label: 'Temp', data: [], borderColor: tempColor, backgroundColor: 'transparent',
              borderWidth: 1.5, pointRadius: 0, tension: .3, yAxisID: 'yTemp'
            },
            {
              label: 'Umidità', data: [], borderColor: humiColor,
              backgroundColor: humiColor.replace(')', ',.15)').replace('rgb', 'rgba'),
              borderWidth: 1.5, pointRadius: 0, tension: .3, fill: true, yAxisID: 'yHumi'
            },
          ]
        },
        options: {
          animation: false,
          responsive: true,
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: { labels: { color: FONT, boxWidth: 12, font: { size: 11 } } } },
          scales: {
            x: { ticks: { color: FONT, maxTicksLimit: 8, font: { size: 10 } }, grid: { color: GRID } },
            yTemp: { type: 'linear', position: 'left', ticks: { color: FONT, font: { size: 10 } }, grid: { color: GRID }, title: { display: true, text: '°C', color: FONT, font: { size: 10 } } },
            yHumi: { type: 'linear', position: 'right', ticks: { color: FONT, font: { size: 10 } }, grid: { display: false }, title: { display: true, text: '%', color: FONT, font: { size: 10 } } },
          }
        }
      });
    }

    // ── Charts init ───────────────────────────────────────────────────────────────
    const charts = {
      fullsun: makeTempHumiChart('chart-fullsun', 'rgb(2,132,199)', 'rgb(99,102,241)'),
      serra: makeTempHumiChart('chart-serra', 'rgb(13,148,136)', 'rgb(99,102,241)'),
      ombra: makeTempHumiChart('chart-ombra', 'rgb(22,163,74)', 'rgb(99,102,241)'),
      power: makeChart('chart-power', [ds('Potenza', 'rgb(234,88,12)', true)]),
      cpu: makeChart('chart-cpu', [ds('CPU', 'rgb(220,38,38)')]),
      ufficio: makeTempHumiChart('chart-ufficio', 'rgb(147,51,234)', 'rgb(99,102,241)'),
    };

    // ── Update helpers ────────────────────────────────────────────────────────────
    function fmt(v, decimals = 1) {
      if (v === null || v === undefined) return '—';
      if (typeof v === 'number' && v <= -99) return '—';
      return typeof v === 'number' ? v.toFixed(decimals) : v;
    }

    function setCard(id, v, decimals = 1) {
      document.getElementById(id).textContent = fmt(v, decimals);
    }

    function updateChartData(chart, labels, ...series) {
      chart.data.labels = labels;
      series.forEach((s, i) => { chart.data.datasets[i].data = s; });
      chart.update('none');
    }

    function nullIfSentinel(v) { return (typeof v === 'number' && v <= -99) ? null : v; }
    function nullIfNegative(v) { return (typeof v === 'number' && v < 0) ? null : v; }

    // ── Time (Europe/Rome) ────────────────────────────────────────────────────────
    const ROME_TZ = 'Europe/Rome';

    function romeHHMM(ts) {
      const d = new Date(ts);
      if (Number.isNaN(d.getTime())) return null;
      return d.toLocaleTimeString('en-GB', { timeZone: ROME_TZ, hour: '2-digit', minute: '2-digit', hour12: false });
    }

    function romeMinuteOfDay(ts) {
      const hhmm = romeHHMM(ts);
      if (!hhmm) return null;
      const [h, m] = hhmm.split(':').map(Number);
      return h * 60 + m;
    }

    function romeFullDateTime(ts) {
      const d = new Date(ts);
      if (Number.isNaN(d.getTime())) return ts;
      return d.toLocaleString('it-IT', { timeZone: ROME_TZ, hour12: false });
    }

    function meanOf(rows, key, filterFn) {
      let sum = 0, n = 0;
      for (const r of rows) {
        const v = filterFn(r[key]);
        if (typeof v === 'number') { sum += v; n++; }
      }
      return n ? sum / n : null;
    }

    function maxOf(rows, key, filterFn = (v) => v) {
      let max = null;
      for (const r of rows) {
        const v = filterFn(r[key]);
        if (typeof v === 'number' && (max === null || v > max)) max = v;
      }
      return max;
    }

    function setText(id, v, decimals = 0) {
      document.getElementById(id).textContent = (v === null || v === undefined) ? '—' : v.toFixed(decimals);
    }

    // ── Fetch & render meteo ──────────────────────────────────────────────────────
    async function loadMeteo() {
      const [histRes, latestRes] = await Promise.all([
        fetch('?api=meteo&limit=1440'),
        fetch('?api=meteo_latest'),
      ]);

      if (!histRes.ok) {
        const body = await histRes.text();
        throw new Error(`HTTP ${histRes.status} on meteo: ${body.slice(0, 200)}`);
      }
      if (!latestRes.ok) {
        const body = await latestRes.text();
        throw new Error(`HTTP ${latestRes.status} on meteo_latest: ${body.slice(0, 200)}`);
      }

      const rows = await histRes.json();
      const latest = await latestRes.json();

      if (latest.error) { showError(latest.error); return; }
      if (rows.error) { showError(rows.error); return; }
      if (!Array.isArray(rows)) { showError('Unexpected response from meteo API'); return; }
      hideError();

      // Cards
      setCard('c-temp', latest.temp);
      setCard('c-humi', nullIfNegative(latest.humi), 0);
      setCard('c-tombra', latest.tombra);
      setCard('c-hombra', nullIfNegative(latest.hombra), 0);
      setCard('c-tmobile', latest.tMobile);
      setCard('c-hmobile', nullIfNegative(latest.hMobile), 0);
      setCard('c-power', latest.power, 0);
      setCard('c-cpu', latest.tempCpu);
      setCard('c-station', latest.station_id, 0);

      const fanEl = document.getElementById('c-fan');
      fanEl.textContent = latest.fan ? 'ON' : 'OFF';
      fanEl.className = 'value ' + (latest.fan ? 'c-orange' : 'c-green');

      // Charts
      const labels = rows.map(r => r.timestamp ? (romeHHMM(r.timestamp) ?? '') : '');
      updateChartData(charts.fullsun, labels,
        rows.map(r => nullIfSentinel(r.temp)),
        rows.map(r => nullIfNegative(r.humi)),
      );
      updateChartData(charts.serra, labels,
        rows.map(r => nullIfSentinel(r.tombra)),
        rows.map(r => nullIfNegative(r.hombra)),
      );
      updateChartData(charts.ombra, labels,
        rows.map(r => nullIfSentinel(r.tMobile)),
        rows.map(r => nullIfNegative(r.hMobile)),
      );
      updateChartData(charts.power, labels, rows.map(r => r.power));
      updateChartData(charts.cpu, labels, rows.map(r => r.tempCpu));

      // 24h humidity averages (rows already cover the last ~24h at 1 sample/min)
      setText('c-humi-avg',    meanOf(rows, 'humi',    nullIfNegative));
      setText('c-hombra-avg',  meanOf(rows, 'hombra',  nullIfNegative));
      setText('c-hmobile-avg', meanOf(rows, 'hMobile', nullIfNegative));

      // 24h peak photovoltaic power
      setText('c-power-peak', maxOf(rows, 'power'));

      document.getElementById('last-update').textContent =
        'Aggiornato: ' + romeFullDateTime(latest.timestamp || new Date().toISOString()) + ' (Roma)';
    }

    // ── Fetch & render office (ufficio) board ─────────────────────────────────────
    async function loadUfficio() {
      const [histRes, latestRes] = await Promise.all([
        fetch('?api=ufficio&limit=1440'),
        fetch('?api=ufficio_latest'),
      ]);
      if (!histRes.ok || !latestRes.ok) return;

      const rows = await histRes.json();
      const latest = await latestRes.json();
      if (!Array.isArray(rows) || rows.error) return;

      setCard('c-uff-temp', latest.temp);
      setCard('c-uff-hum', nullIfNegative(latest.hum), 0);
      setCard('c-uff-sp', latest.setpoint);

      const heaterEl = document.getElementById('c-uff-heater');
      const on = (latest.heater || '').toUpperCase() === 'ON';
      heaterEl.textContent = latest.heater ? (on ? 'ON' : 'OFF') : '—';
      heaterEl.className = 'value ' + (on ? 'c-red' : 'c-green');

      const labels = rows.map(r => r.timestamp ? (romeHHMM(r.timestamp) ?? '') : '');
      updateChartData(charts.ufficio, labels,
        rows.map(r => nullIfSentinel(r.temp)),
        rows.map(r => nullIfNegative(r.hum)),
      );
    }

    // ── Fetch & render sensors ────────────────────────────────────────────────────
    async function loadSensors() {
      const res = await fetch('?api=sensors_latest');
      const rows = await res.json();
      const tbody = document.querySelector('#sensors-table tbody');

      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="color:#64748b">Nessun dato</td></tr>';
        return;
      }

      tbody.innerHTML = rows.map(r => {
        const status = (r.status || '').toUpperCase();
        const badge = status === 'ON' ? 'badge-on'
          : status === 'OFF' ? 'badge-off' : 'badge-na';
        return `<tr>
      <td>${r.sensoreId}</td>
      <td>${r.sensorName ?? '—'}</td>
      <td>${fmt(r.temp)}</td>
      <td>${fmt(r.pressure)}</td>
      <td><span class="${badge}">${status || '—'}</span></td>
      <td>${r.timestamp ?? '—'}</td>
    </tr>`;
      }).join('');
    }

    // ── Error banner ──────────────────────────────────────────────────────────────
    function showError(msg) {
      const el = document.getElementById('db-error');
      el.textContent = 'DB Error: ' + msg;
      el.style.display = 'block';
    }
    function hideError() { document.getElementById('db-error').style.display = 'none'; }

    // ── Refresh loop ──────────────────────────────────────────────────────────────
    async function refresh() {
      try { await Promise.all([loadMeteo(), loadSensors(), loadUfficio()]); }
      catch (e) { showError(e.message); }
    }

    refresh();
    setInterval(refresh, 30_000);

    // ── Comparison modal (today vs yesterday) ───────────────────────────────────
    const COMPARE_CONFIG = {
      fullsun: { title: 'Full Sun', tempKey: 'temp',    humiKey: 'humi',    tempColor: 'rgb(2,132,199)',  humiColor: 'rgb(99,102,241)' },
      serra:   { title: 'Parametri Serra', tempKey: 'tombra', humiKey: 'hombra', tempColor: 'rgb(13,148,136)', humiColor: 'rgb(99,102,241)' },
      ombra:   { title: 'Ombra',   tempKey: 'tMobile', humiKey: 'hMobile', tempColor: 'rgb(22,163,74)',  humiColor: 'rgb(99,102,241)' },
    };

    let compareTempChart = null;
    let compareHumiChart = null;

    function minuteOfDayFromTs(ts) {
      // ts is "YYYY-MM-DDTHH:MM:SSZ" (UTC). Convert to Europe/Rome for display alignment.
      if (!ts) return null;
      return romeMinuteOfDay(ts);
    }

    function makeCompareChart(canvasId, yUnit, todayColor, yesterdayColor, valueFilter) {
      const ctx = document.getElementById(canvasId);
      return new Chart(ctx, {
        type: 'line',
        data: {
          datasets: [
            { label: 'Oggi',  data: [], borderColor: todayColor,     backgroundColor: 'transparent', borderWidth: 1.8, pointRadius: 0, tension: .3 },
            { label: 'Ieri',  data: [], borderColor: yesterdayColor, backgroundColor: 'transparent', borderWidth: 1.5, pointRadius: 0, tension: .3, borderDash: [6, 4] },
          ]
        },
        options: {
          animation: false,
          responsive: true,
          parsing: false,
          interaction: { mode: 'nearest', intersect: false },
          plugins: {
            legend: { labels: { color: FONT, boxWidth: 14, font: { size: 11 } } },
            tooltip: {
              callbacks: {
                title: (items) => {
                  const v = items[0]?.parsed?.x;
                  if (v == null) return '';
                  const h = Math.floor(v / 60), m = Math.round(v % 60);
                  return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
                }
              }
            }
          },
          scales: {
            x: {
              type: 'linear',
              min: 0,
              max: 1440,
              ticks: {
                color: FONT,
                font: { size: 10 },
                stepSize: 180,
                callback: (v) => {
                  const h = Math.floor(v / 60);
                  return String(h).padStart(2, '0') + ':00';
                },
              },
              grid: { color: GRID },
              title: { display: true, text: 'Ora (Roma)', color: FONT, font: { size: 10 } },
            },
            y: {
              ticks: { color: FONT, font: { size: 10 } },
              grid: { color: GRID },
              title: { display: true, text: yUnit, color: FONT, font: { size: 10 } },
            }
          }
        }
      });
    }

    function buildSeries(rows, key, valueFilter, sinceMs, untilMs) {
      const pts = [];
      for (const r of rows) {
        if (!r.timestamp) continue;
        const t = new Date(r.timestamp).getTime();
        if (Number.isNaN(t)) continue;
        if (t < sinceMs || t >= untilMs) continue;
        const y = valueFilter(r[key]);
        if (y === null || y === undefined) continue;
        const x = minuteOfDayFromTs(r.timestamp);
        if (x === null) continue;
        pts.push({ x, y });
      }
      pts.sort((a, b) => a.x - b.x);
      return pts;
    }

    async function openCompare(key) {
      const cfg = COMPARE_CONFIG[key];
      if (!cfg) return;

      document.getElementById('compare-title').textContent = `${cfg.title} — oggi vs ieri`;
      const modal = document.getElementById('compare-modal');
      modal.hidden = false;

      let rows;
      try {
        const res = await fetch('?api=meteo&limit=2880');
        rows = await res.json();
        if (!Array.isArray(rows)) throw new Error(rows.error || 'Bad response');
      } catch (e) {
        alert('Errore caricamento dati: ' + e.message);
        return;
      }

      const nowMs = Date.now();
      const day = 24 * 3600 * 1000;
      const todaySince = nowMs - day;
      const ydaySince  = nowMs - 2 * day;

      const todayTemp = buildSeries(rows, cfg.tempKey, nullIfSentinel, todaySince, nowMs);
      const ydayTemp  = buildSeries(rows, cfg.tempKey, nullIfSentinel, ydaySince, todaySince);
      const todayHumi = buildSeries(rows, cfg.humiKey, nullIfNegative, todaySince, nowMs);
      const ydayHumi  = buildSeries(rows, cfg.humiKey, nullIfNegative, ydaySince, todaySince);

      if (!compareTempChart) {
        compareTempChart = makeCompareChart('compare-temp', '°C', cfg.tempColor, 'rgba(100,116,139,.8)');
        compareHumiChart = makeCompareChart('compare-humi', '%',  cfg.humiColor, 'rgba(100,116,139,.8)');
      } else {
        compareTempChart.data.datasets[0].borderColor = cfg.tempColor;
        compareHumiChart.data.datasets[0].borderColor = cfg.humiColor;
      }

      compareTempChart.data.datasets[0].data = todayTemp;
      compareTempChart.data.datasets[1].data = ydayTemp;
      compareTempChart.update('none');

      compareHumiChart.data.datasets[0].data = todayHumi;
      compareHumiChart.data.datasets[1].data = ydayHumi;
      compareHumiChart.update('none');

      // Humidity averages (today vs yesterday) shown above the humidity chart
      const meanY = (pts) => pts.length ? pts.reduce((s, p) => s + p.y, 0) / pts.length : null;
      setText('compare-humi-avg-today', meanY(todayHumi));
      setText('compare-humi-avg-yday',  meanY(ydayHumi));
      document.getElementById('compare-humi-swatch-today').style.background = cfg.humiColor;
    }

    function closeCompare() {
      document.getElementById('compare-modal').hidden = true;
    }

    document.querySelectorAll('.group[data-compare]').forEach(el => {
      el.addEventListener('click', () => openCompare(el.dataset.compare));
    });

    document.getElementById('compare-modal').addEventListener('click', (e) => {
      if (e.target.hasAttribute('data-close')) closeCompare();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !document.getElementById('compare-modal').hidden) closeCompare();
    });
  </script>
</body>

</html>