<?php
// ── Configuration ────────────────────────────────────────────────────────────
define('DB_PATH', '/dev/shm/meteo.db');
define('METEO_LIMIT', 1440);   // default history points (~2 h at 1/min)
define('SENSORS_LIMIT', 50);
// Alarm rules DB + live fired-state file — written by alarm_watcher.py / alarms.php.
// Paths pinned to match those two so all three processes agree.
define('ALARMS_DB_PATH', '/var/www/html/alarms.db');
define('ALARM_STATE_PATH', '/dev/shm/alarm_state.json');

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

  // Row of $table whose timestamp is closest to $targetTs (UTC ISO-Z string),
  // searched inside a ±$windowMin window. Returns null when the logger was down
  // around that moment, so the caller can show "—" instead of a stale value.
  function row_near(PDO $db, string $table, string $targetTs, int $windowMin = 20)
  {
    $t = strtotime($targetTs);
    $lo = gmdate('Y-m-d\TH:i:s\Z', $t - $windowMin * 60);
    $hi = gmdate('Y-m-d\TH:i:s\Z', $t + $windowMin * 60);
    try {
      $st = $db->prepare("SELECT * FROM $table WHERE timestamp BETWEEN :lo AND :hi");
      $st->execute([':lo' => $lo, ':hi' => $hi]);
      $rows = $st->fetchAll();
    } catch (Exception $e) {
      return null;
    }

    $best = null;
    $bestDiff = PHP_INT_MAX;
    foreach ($rows as $r) {
      $rt = strtotime((string) ($r['timestamp'] ?? ''));
      if ($rt === false) continue;
      $diff = abs($rt - $t);
      if ($diff < $bestDiff) { $bestDiff = $diff; $best = $r; }
    }
    return $best;
  }

  // Currently-triggered alarms, derived the same way alarm_watcher.py reports
  // "Allarmi attivi": an enabled edge rule with state.fired, or a scheduled rule
  // whose last_fired == today. Reads the rules DB + live state file directly so
  // the dashboard reflects exactly what the watcher has sent.
  function active_alarms(): array
  {
    if (!file_exists(ALARMS_DB_PATH)) {
      return ['available' => false, 'alarms' => []];
    }
    try {
      $adb = new PDO('sqlite:' . ALARMS_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 2,
      ]);
      $rules = $adb->query("SELECT id, enabled, message FROM rules ORDER BY id")->fetchAll();
    } catch (Exception $e) {
      // Schema not created yet / DB unreadable — treat as "no config available".
      return ['available' => false, 'alarms' => []];
    }

    // Which rules carry a schedule condition (fire-once-per-day semantics).
    $scheduled = [];
    try {
      foreach ($adb->query("SELECT DISTINCT rule_id FROM conditions WHERE kind = 'schedule'") as $c) {
        $scheduled[(int) $c['rule_id']] = true;
      }
    } catch (Exception $e) { /* conditions table absent — no scheduled rules */ }
    $adb = null;

    // Live fired-state (edge rules: {fired, fired_at}; scheduled: {last_fired}).
    $state = [];
    if (file_exists(ALARM_STATE_PATH)) {
      $j = json_decode((string) file_get_contents(ALARM_STATE_PATH), true);
      if (is_array($j)) $state = $j;
    }

    $today = date('Y-m-d');
    $alarms = [];
    foreach ($rules as $rule) {
      if (empty($rule['enabled'])) continue;
      $rid = (string) $rule['id'];
      $entry = (isset($state[$rid]) && is_array($state[$rid])) ? $state[$rid] : [];
      $isSched = !empty($scheduled[(int) $rule['id']]);

      $active = false;
      $when = null;
      if ($isSched) {
        if (($entry['last_fired'] ?? null) === $today) { $active = true; $when = $entry['last_fired']; }
      } else {
        if (!empty($entry['fired'])) { $active = true; $when = $entry['fired_at'] ?? null; }
      }
      if (!$active) continue;

      $msg = trim((string) ($rule['message'] ?? ''));
      $alarms[] = [
        'id'        => (int) $rule['id'],
        'label'     => $msg !== '' ? $msg : ('Regola #' . $rid),
        'when'      => $when,
        'scheduled' => $isSched,
      ];
    }

    return ['available' => true, 'alarms' => $alarms];
  }

  /**
   * Apply the PV deadband to one energia row: production under 10 W is noise
   * (clamp leakage / inverter standby), so it is reported as 0 W and
   * casa_power is kept consistent with it. mqtt_receiver.py already clamps on
   * write; this also cleans rows logged before that.
   */
  function pv_deadband($row)
  {
    if (!is_array($row) || !isset($row['pv_power']) || !is_numeric($row['pv_power'])) {
      return $row;
    }
    if (abs((float) $row['pv_power']) < 10.0) {
      $row['pv_power'] = 0.0;
      if (isset($row['grid_power']) && is_numeric($row['grid_power'])) {
        $row['casa_power'] = (float) $row['grid_power'];
      }
    }
    return $row;
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

      case 'yesterday':
        // Snapshot of meteo + ufficio at (now − 24 h), used by the "oggi vs ieri"
        // comparison table. Timestamps are stored as UTC "YYYY-MM-DDTHH:MM:SSZ",
        // so lexicographic BETWEEN over a ±window is a valid time range filter.
        $targetTs = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
        echo json_encode([
          'target'  => $targetTs,
          'meteo'   => row_near($db, 'meteo', $targetTs),
          'ufficio' => row_near($db, 'ufficio', $targetTs),
          'energia' => pv_deadband(row_near($db, 'energia', $targetTs)),
        ]);
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

      case 'energia':
        // Shelly Pro EM-50 history (pv / grid / house load), written ~1/min by
        // mqtt_receiver.py. Same limits as the meteo series.
        $limit = min((int) ($_GET['limit'] ?? METEO_LIMIT), 2880);
        $rows = db_rows($db, "SELECT * FROM energia ORDER BY id DESC LIMIT $limit");
        echo json_encode(array_map('pv_deadband', array_reverse($rows)));
        break;

      case 'energia_latest':
        $rows = db_rows($db, "SELECT * FROM energia ORDER BY id DESC LIMIT 1");
        echo json_encode(isset($rows[0]) ? pv_deadband($rows[0]) : (object) []);
        break;

      case 'sensor_status':
        // Per-role station/sensor ids (main / mobile / ombra), upserted by meteo.py.
        $rows = db_rows($db, "SELECT sensor_key, identifier, online, last_seen FROM sensor_status");
        echo json_encode($rows);
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

      case 'alarms':
        echo json_encode(active_alarms());
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

    /* ── Triggered-alarms panel ── */
    .alarms-panel {
      background: #ffffff;
      border-radius: .75rem;
      padding: 1rem 1.2rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
      margin-bottom: 1.5rem;
      border-left: 4px solid #16a34a;
    }

    .alarms-panel.has-alarms {
      border-left-color: #dc2626;
      background: #fef2f2;
    }

    .alarms-head {
      display: flex;
      align-items: center;
      gap: .5rem;
      font-size: .85rem;
      text-transform: uppercase;
      letter-spacing: .05em;
      color: #64748b;
      font-weight: 600;
      margin-bottom: .6rem;
    }

    .alarms-head svg {
      width: 1.1rem;
      height: 1.1rem;
      color: #16a34a;
      flex-shrink: 0;
    }

    .alarms-panel.has-alarms .alarms-head svg {
      color: #dc2626;
    }

    .alarms-count {
      margin-left: auto;
      font-size: .72rem;
      font-weight: 700;
      background: #dcfce7;
      color: #16a34a;
      border-radius: 999px;
      padding: .1rem .55rem;
    }

    .alarms-panel.has-alarms .alarms-count {
      background: #fee2e2;
      color: #dc2626;
    }

    .alarms-list {
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: .4rem;
    }

    .alarm-item {
      display: flex;
      align-items: baseline;
      gap: .6rem;
      padding: .5rem .7rem;
      background: #ffffff;
      border: 1px solid #fecaca;
      border-radius: .5rem;
    }

    .alarm-item .alarm-label {
      font-weight: 600;
      color: #991b1b;
    }

    .alarm-item .alarm-sched {
      font-size: .62rem;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #9333ea;
      border: 1px solid #e9d5ff;
      border-radius: 999px;
      padding: .05rem .4rem;
    }

    .alarm-item .alarm-when {
      margin-left: auto;
      font-size: .75rem;
      color: #64748b;
      white-space: nowrap;
    }

    .alarms-empty {
      font-size: .85rem;
      color: #16a34a;
    }

    .alarms-panel.has-alarms .alarms-empty {
      color: #dc2626;
    }

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

    .sensor-id {
      margin-left: auto;
      font-size: .7rem;
      font-weight: 600;
      letter-spacing: 0;
      text-transform: none;
      color: #64748b;
      background: #f1f5f9;
      border-radius: 999px;
      padding: .1rem .55rem;
      white-space: nowrap;
    }

    .sensor-id.offline {
      color: #dc2626;
      background: #fee2e2;
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

    .icon-plug {
      color: #f97316;
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

    /* ── Today vs yesterday table ── */
    .section-head {
      display: flex;
      align-items: baseline;
      gap: .6rem;
      flex-wrap: wrap;
      margin-bottom: .6rem;
    }

    .section-head .section-title {
      margin-bottom: 0;
    }

    .section-note {
      font-size: .75rem;
      color: #94a3b8;
    }

    #yday-table td.num,
    #yday-table th.num {
      text-align: right;
      font-variant-numeric: tabular-nums;
    }

    #yday-table td.metric {
      font-weight: 600;
      color: #334155;
    }

    #yday-table td.num.now {
      font-weight: 700;
    }

    .delta-up {
      color: #dc2626;
    }

    .delta-down {
      color: #0284c7;
    }

    .delta-flat {
      color: #64748b;
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

  <!-- ── Triggered alarms ── -->
  <div class="alarms-panel" id="alarms-panel">
    <div class="alarms-head">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
        stroke-linejoin="round" aria-hidden="true">
        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
        <line x1="12" y1="9" x2="12" y2="13" />
        <line x1="12" y1="17" x2="12.01" y2="17" />
      </svg>
      Allarmi Attivi
      <span class="alarms-count" id="alarms-count">—</span>
    </div>
    <ul class="alarms-list" id="alarms-list">
      <li class="alarms-empty">Caricamento…</li>
    </ul>
  </div>

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
        <span class="sensor-id" id="id-fullsun" title="ID stazione">ID —</span>
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
        <span class="sensor-id" id="id-serra" title="ID sensore">ID —</span>
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
        <span class="sensor-id" id="id-ombra" title="ID sensore">ID —</span>
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
    <div class="group" data-compare="energia" title="Clicca per confronto con ieri">
      <h2>
        <svg class="icon-plug" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M9 2v6M15 2v6" />
          <path d="M6 8h12v3a6 6 0 0 1-12 0V8z" />
          <path d="M12 17v5" />
        </svg>
        Energia (Shelly EM)
      </h2>
      <div class="group-stats">
        <div class="stat"><span class="label">Produzione FV</span><span class="value c-orange"
            id="c-pv">—</span><span class="unit">W</span></div>
        <div class="stat"><span class="label">Scambio Rete</span><span class="value c-blue"
            id="c-grid">—</span><span class="unit" id="c-grid-dir">W</span></div>
        <div class="stat"><span class="label">Consumo Casa</span><span class="value c-purple"
            id="c-casa">—</span><span class="unit">W</span></div>
        <div class="stat"><span class="label">Autoconsumo</span><span class="value c-green"
            id="c-selfuse">—</span><span class="unit">%</span></div>
      </div>
      <canvas id="chart-energia" height="140"></canvas>
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
          <h2 id="compare-title-a">—</h2>
          <canvas id="compare-a" height="180"></canvas>
        </div>
        <div class="chart-box">
          <h2 id="compare-title-b">—</h2>
          <div class="compare-avg" id="compare-avg-row">
            <span class="pill"><span class="swatch" id="compare-swatch-today"></span>Media oggi:
              <strong id="compare-avg-today">—</strong><span id="compare-avg-unit-a"></span></span>
            <span class="pill"><span class="swatch" style="background:rgba(100,116,139,.8)"></span>Media ieri:
              <strong id="compare-avg-yday">—</strong><span id="compare-avg-unit-b"></span></span>
          </div>
          <canvas id="compare-b" height="180"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Today vs same time yesterday ── -->
  <div class="section-head">
    <div class="section-title">Confronto con ieri (stessa ora)</div>
    <span class="section-note" id="yday-when">—</span>
  </div>
  <table id="yday-table" style="margin-bottom:1.5rem">
    <thead>
      <tr>
        <th>Grandezza</th>
        <th class="num">Adesso</th>
        <th class="num">Ieri</th>
        <th class="num">Δ</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td colspan="4" style="color:#64748b">Loading…</td>
      </tr>
    </tbody>
  </table>

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
      energia: makeChart('chart-energia', [
        ds('Produzione FV', 'rgb(249,115,22)', true),
        ds('Scambio Rete', 'rgb(14,165,233)'),
        ds('Consumo Casa', 'rgb(168,85,247)'),
      ], 'W'),
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

    // Latest rows kept around so the "confronto con ieri" table can reuse them
    // instead of re-fetching the same two endpoints every refresh.
    let latestMeteo = null;
    let latestUfficio = null;
    let latestEnergia = null;

    // Signed numeric coercion: keeps negatives (grid export) but rejects junk.
    function num(v) {
      if (v === null || v === undefined || v === '') return null;
      const n = Number(v);
      return Number.isFinite(n) ? n : null;
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
      latestMeteo = latest;

      // Cards
      setCard('c-temp', latest.temp);
      setCard('c-humi', nullIfNegative(latest.humi), 0);
      // Serra panel = mobile sensor (tMobile/hMobile); Ombra panel = ombra sensor
      // (tombra/hombra). Element ids are kept as-is, so c-tombra/c-tmobile no
      // longer match the DB column they show — the panel each lives in is the guide.
      setCard('c-tombra', latest.tMobile);
      setCard('c-hombra', nullIfNegative(latest.hMobile), 0);
      setCard('c-tmobile', latest.tombra);
      setCard('c-hmobile', nullIfNegative(latest.hombra), 0);
      setCard('c-power', latest.power, 0);
      setCard('c-cpu', latest.tempCpu);

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
        rows.map(r => nullIfSentinel(r.tMobile)),
        rows.map(r => nullIfNegative(r.hMobile)),
      );
      updateChartData(charts.ombra, labels,
        rows.map(r => nullIfSentinel(r.tombra)),
        rows.map(r => nullIfNegative(r.hombra)),
      );
      updateChartData(charts.power, labels, rows.map(r => r.power));
      updateChartData(charts.cpu, labels, rows.map(r => r.tempCpu));

      // 24h humidity averages (rows already cover the last ~24h at 1 sample/min)
      setText('c-humi-avg',    meanOf(rows, 'humi',    nullIfNegative));
      setText('c-hombra-avg',  meanOf(rows, 'hMobile', nullIfNegative));  // Serra = mobile
      setText('c-hmobile-avg', meanOf(rows, 'hombra',  nullIfNegative));  // Ombra = ombra

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
      latestUfficio = latest;

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

    // ── Fetch & render energy meter (Shelly Pro EM-50) ────────────────────────────
    // grid_power is signed: > 0 while importing from the grid, < 0 while the PV
    // surplus is being exported, so no negative-value filter is applied here.
    async function loadEnergia() {
      const [histRes, latestRes] = await Promise.all([
        fetch('?api=energia&limit=1440'),
        fetch('?api=energia_latest'),
      ]);
      if (!histRes.ok || !latestRes.ok) return;

      const rows = await histRes.json();
      const latest = await latestRes.json();
      if (!Array.isArray(rows) || rows.error) return;
      latestEnergia = latest;

      const pv = num(latest.pv_power);
      const grid = num(latest.grid_power);
      const casa = num(latest.casa_power);

      // setText (not setCard): these are watt figures, and setCard's formatter
      // would blank anything <= -99 as a dead-sensor sentinel.
      setText('c-pv', pv);
      setText('c-grid', grid === null ? null : Math.abs(grid));
      setText('c-casa', casa);

      // Direction is carried by the unit label + colour, so the figure itself
      // can stay unsigned and readable.
      const gridEl = document.getElementById('c-grid');
      const dirEl = document.getElementById('c-grid-dir');
      if (grid === null) {
        dirEl.textContent = 'W';
        gridEl.className = 'value c-blue';
      } else if (grid < -5) {
        dirEl.textContent = 'W immessi';
        gridEl.className = 'value c-green';
      } else if (grid > 5) {
        dirEl.textContent = 'W prelevati';
        gridEl.className = 'value c-blue';
      } else {
        dirEl.textContent = 'W';
        gridEl.className = 'value c-teal';
      }

      // Share of the house load covered by the panels right now.
      const selfUse = (pv !== null && casa !== null && casa > 0)
        ? Math.min(100, (pv / casa) * 100)
        : null;
      setText('c-selfuse', selfUse);

      const labels = rows.map(r => r.timestamp ? (romeHHMM(r.timestamp) ?? '') : '');
      updateChartData(charts.energia, labels,
        rows.map(r => num(r.pv_power)),
        rows.map(r => num(r.grid_power)),
        rows.map(r => num(r.casa_power)),
      );
    }

    // ── Fetch & render per-role sensor ids ────────────────────────────────────────
    // sensor_key -> group header span. Chips track role names (user preference):
    //   main→Full Sun, ombra→Ombra, mobile→Parametri Serra.
    // Note: the temp/humi DATA charted in each panel is unchanged — the Serra
    // panel still plots tombra/hombra and the Ombra panel still plots tMobile/hMobile.
    const SENSOR_ID_TARGETS = { main: 'id-fullsun', ombra: 'id-ombra', mobile: 'id-serra' };

    async function loadSensorIds() {
      let rows;
      try {
        const res = await fetch('?api=sensor_status');
        rows = await res.json();
      } catch (e) { return; }
      if (!Array.isArray(rows)) return;

      for (const r of rows) {
        const elId = SENSOR_ID_TARGETS[r.sensor_key];
        if (!elId) continue;
        const el = document.getElementById(elId);
        if (!el) continue;
        el.textContent = 'ID ' + (r.identifier ?? '—');
        el.classList.toggle('offline', Number(r.online) !== 1);
        el.title = 'ID ' + (r.identifier ?? '—') +
          (r.last_seen ? ' — visto: ' + romeFullDateTime(r.last_seen) : '');
      }
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

    // ── Fetch & render triggered alarms ───────────────────────────────────────────
    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function formatAlarmWhen(when, scheduled) {
      if (!when) return '';
      if (scheduled) return 'oggi';
      // Edge rules store "YYYY-MM-DD HH:MM:SS" (already Rome local time on the Pi).
      const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(when);
      return m ? `${m[3]}/${m[2]} ${m[4]}:${m[5]}` : when;
    }

    async function loadAlarms() {
      const panel = document.getElementById('alarms-panel');
      const list = document.getElementById('alarms-list');
      const count = document.getElementById('alarms-count');

      let data;
      try {
        const res = await fetch('?api=alarms');
        data = await res.json();
      } catch (e) {
        return; // leave the last rendered state on a transient fetch error
      }

      if (!data || data.available === false) {
        panel.classList.remove('has-alarms');
        count.textContent = 'n/d';
        list.innerHTML = '<li class="alarms-empty">Configurazione allarmi non disponibile</li>';
        return;
      }

      const alarms = Array.isArray(data.alarms) ? data.alarms : [];
      if (!alarms.length) {
        panel.classList.remove('has-alarms');
        count.textContent = '0';
        list.innerHTML = '<li class="alarms-empty">✓ Nessun allarme attivo</li>';
        return;
      }

      panel.classList.add('has-alarms');
      count.textContent = String(alarms.length);
      list.innerHTML = alarms.map(a => {
        const when = formatAlarmWhen(a.when, a.scheduled);
        const sched = a.scheduled ? '<span class="alarm-sched">pianificato</span>' : '';
        return `<li class="alarm-item">
          <span class="alarm-label">${escapeHtml(a.label)}</span>
          ${sched}
          <span class="alarm-when">${when}</span>
        </li>`;
      }).join('');
    }

    // ── Today vs same time yesterday ──────────────────────────────────────────────
    // Panel/column pairing mirrors the group panels above: Serra shows tMobile/hMobile
    // and Ombra shows tombra/hombra (see the note near SENSOR_ID_TARGETS).
    const YDAY_ROWS = [
      { label: 'Full Sun — Temperatura', src: 'meteo',   key: 'temp',    unit: '°C', dec: 1, filter: nullIfSentinel },
      { label: 'Full Sun — Umidità',     src: 'meteo',   key: 'humi',    unit: '%',  dec: 0, filter: nullIfNegative },
      { label: 'Serra — Temperatura',    src: 'meteo',   key: 'tMobile', unit: '°C', dec: 1, filter: nullIfSentinel },
      { label: 'Serra — Umidità',        src: 'meteo',   key: 'hMobile', unit: '%',  dec: 0, filter: nullIfNegative },
      { label: 'Ombra — Temperatura',    src: 'meteo',   key: 'tombra',  unit: '°C', dec: 1, filter: nullIfSentinel },
      { label: 'Ombra — Umidità',        src: 'meteo',   key: 'hombra',  unit: '%',  dec: 0, filter: nullIfNegative },
      { label: 'Fotovoltaico — Potenza', src: 'meteo',   key: 'power',   unit: 'W',  dec: 0, filter: nullIfNegative },
      { label: 'Server — CPU Temp',      src: 'meteo',   key: 'tempCpu', unit: '°C', dec: 1, filter: nullIfSentinel },
      { label: 'Ufficio — Temperatura',  src: 'ufficio', key: 'temp',    unit: '°C', dec: 1, filter: nullIfSentinel },
      { label: 'Ufficio — Umidità',      src: 'ufficio', key: 'hum',     unit: '%',  dec: 0, filter: nullIfNegative },
      // signed: these are watt readings that may legitimately sit below -99
      // (grid export), so they bypass the "<= -99 means dead sensor" formatter.
      { label: 'Energia — Produzione FV', src: 'energia', key: 'pv_power',   unit: 'W', dec: 0, filter: (v) => v, signed: true },
      { label: 'Energia — Scambio Rete',  src: 'energia', key: 'grid_power', unit: 'W', dec: 0, filter: (v) => v, signed: true },
      { label: 'Energia — Consumo Casa',  src: 'energia', key: 'casa_power', unit: 'W', dec: 0, filter: (v) => v, signed: true },
    ];

    function pickValue(row, def) {
      if (!row) return null;
      let v = row[def.key];
      if (v === null || v === undefined || v === '') return null;
      if (typeof v === 'string') v = Number(v);
      if (typeof v !== 'number' || Number.isNaN(v)) return null;
      v = def.filter(v);
      return typeof v === 'number' ? v : null;
    }

    // fmt() blanks anything <= -99 as a dead-sensor sentinel; signed rows need
    // their negative values printed as-is.
    function fmtRow(v, dec, signed) {
      if (v === null || v === undefined) return '—';
      return signed ? v.toFixed(dec) : fmt(v, dec);
    }

    function deltaCell(now, yday, dec) {
      if (now === null || yday === null) return '<td class="num delta-flat">—</td>';
      const d = now - yday;
      const cls = d > 0 ? 'delta-up' : d < 0 ? 'delta-down' : 'delta-flat';
      const arrow = d > 0 ? '▲' : d < 0 ? '▼' : '=';
      const sign = d > 0 ? '+' : '';
      return `<td class="num ${cls}">${arrow} ${sign}${d.toFixed(dec)}</td>`;
    }

    async function loadYesterdayTable() {
      const tbody = document.querySelector('#yday-table tbody');
      const whenEl = document.getElementById('yday-when');

      let data;
      try {
        const res = await fetch('?api=yesterday');
        data = await res.json();
      } catch (e) {
        return; // keep the previously rendered table on a transient error
      }
      if (!data || data.error) return;

      const past = { meteo: data.meteo || null, ufficio: data.ufficio || null, energia: data.energia || null };
      const nowSrc = { meteo: latestMeteo, ufficio: latestUfficio, energia: latestEnergia };

      const stamp = past.meteo?.timestamp || past.ufficio?.timestamp || data.target;
      whenEl.textContent = stamp
        ? 'riferimento: ' + romeFullDateTime(stamp) + ' (Roma)'
        : 'nessun dato di ieri';

      if (!past.meteo && !past.ufficio && !past.energia) {
        tbody.innerHTML = '<tr><td colspan="4" style="color:#64748b">Nessun dato registrato a quest\'ora ieri</td></tr>';
        return;
      }

      tbody.innerHTML = YDAY_ROWS.map(def => {
        const now = pickValue(nowSrc[def.src], def);
        const yday = pickValue(past[def.src], def);
        return `<tr>
      <td class="metric">${escapeHtml(def.label)}</td>
      <td class="num now">${fmtRow(now, def.dec, def.signed)} ${def.unit}</td>
      <td class="num">${fmtRow(yday, def.dec, def.signed)} ${def.unit}</td>
      ${deltaCell(now, yday, def.dec)}
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
      try {
        await Promise.all([loadMeteo(), loadSensors(), loadUfficio(), loadEnergia(), loadSensorIds(), loadAlarms()]);
        await loadYesterdayTable();   // needs latestMeteo/latestUfficio to be populated
      }
      catch (e) { showError(e.message); }
    }

    refresh();
    setInterval(refresh, 30_000);

    // ── Comparison modal (today vs yesterday) ───────────────────────────────────
    // Each entry drives the two stacked charts in the modal: `api` is the history
    // endpoint to pull, `charts` the two series to plot (today vs yesterday).
    // `avg` adds the mean pills above the lower chart.
    const COMPARE_CONFIG = {
      fullsun: {
        title: 'Full Sun', api: 'meteo', charts: [
          { key: 'temp', label: 'Temperatura', unit: '°C', color: 'rgb(2,132,199)', filter: nullIfSentinel, dec: 1 },
          { key: 'humi', label: 'Umidità', unit: '%', color: 'rgb(99,102,241)', filter: nullIfNegative, dec: 0, avg: true },
        ]
      },
      serra: {
        title: 'Parametri Serra', api: 'meteo', charts: [
          { key: 'tMobile', label: 'Temperatura', unit: '°C', color: 'rgb(13,148,136)', filter: nullIfSentinel, dec: 1 },
          { key: 'hMobile', label: 'Umidità', unit: '%', color: 'rgb(99,102,241)', filter: nullIfNegative, dec: 0, avg: true },
        ]
      },
      ombra: {
        title: 'Ombra', api: 'meteo', charts: [
          { key: 'tombra', label: 'Temperatura', unit: '°C', color: 'rgb(22,163,74)', filter: nullIfSentinel, dec: 1 },
          { key: 'hombra', label: 'Umidità', unit: '%', color: 'rgb(99,102,241)', filter: nullIfNegative, dec: 0, avg: true },
        ]
      },
      energia: {
        title: 'Energia (Shelly EM)', api: 'energia', charts: [
          { key: 'pv_power', label: 'Produzione FV', unit: 'W', color: 'rgb(249,115,22)', filter: num, dec: 0 },
          // Signed: a negative value is surplus exported to the grid.
          { key: 'grid_power', label: 'Scambio Rete', unit: 'W', color: 'rgb(14,165,233)', filter: num, dec: 0, avg: true },
        ]
      },
    };

    let compareChartA = null;
    let compareChartB = null;

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

    // Feed one modal chart with today's and yesterday's series for a variable.
    function fillCompareChart(chart, def, rows, todaySince, nowMs, ydaySince) {
      const today = buildSeries(rows, def.key, def.filter, todaySince, nowMs);
      const yday = buildSeries(rows, def.key, def.filter, ydaySince, todaySince);
      chart.data.datasets[0].borderColor = def.color;
      chart.data.datasets[0].data = today;
      chart.data.datasets[1].data = yday;
      chart.options.scales.y.title.text = def.unit;
      chart.update('none');
      return { today, yday };
    }

    async function openCompare(key) {
      const cfg = COMPARE_CONFIG[key];
      if (!cfg) return;

      const [defA, defB] = cfg.charts;
      document.getElementById('compare-title').textContent = `${cfg.title} — oggi vs ieri`;
      document.getElementById('compare-title-a').textContent = `${defA.label} (${defA.unit}) — oggi vs ieri`;
      document.getElementById('compare-title-b').textContent = `${defB.label} (${defB.unit}) — oggi vs ieri`;
      const modal = document.getElementById('compare-modal');
      modal.hidden = false;

      let rows;
      try {
        const res = await fetch(`?api=${cfg.api}&limit=2880`);
        rows = await res.json();
        if (!Array.isArray(rows)) throw new Error(rows.error || 'Bad response');
      } catch (e) {
        alert('Errore caricamento dati: ' + e.message);
        return;
      }

      const nowMs = Date.now();
      const day = 24 * 3600 * 1000;
      const todaySince = nowMs - day;
      const ydaySince = nowMs - 2 * day;

      if (!compareChartA) {
        compareChartA = makeCompareChart('compare-a', defA.unit, defA.color, 'rgba(100,116,139,.8)');
        compareChartB = makeCompareChart('compare-b', defB.unit, defB.color, 'rgba(100,116,139,.8)');
      }

      fillCompareChart(compareChartA, defA, rows, todaySince, nowMs, ydaySince);
      const seriesB = fillCompareChart(compareChartB, defB, rows, todaySince, nowMs, ydaySince);

      // Mean of the lower series (today vs yesterday), when it has a useful one.
      const avgRow = document.getElementById('compare-avg-row');
      avgRow.style.display = defB.avg ? '' : 'none';
      if (defB.avg) {
        const meanY = (pts) => pts.length ? pts.reduce((s, p) => s + p.y, 0) / pts.length : null;
        setText('compare-avg-today', meanY(seriesB.today), defB.dec ?? 0);
        setText('compare-avg-yday', meanY(seriesB.yday), defB.dec ?? 0);
        document.getElementById('compare-avg-unit-a').textContent = ' ' + defB.unit;
        document.getElementById('compare-avg-unit-b').textContent = ' ' + defB.unit;
        document.getElementById('compare-swatch-today').style.background = defB.color;
      }
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