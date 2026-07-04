<?php
// ── Configuration ────────────────────────────────────────────────────────────
// Persistent storage for the weekly schedule — survives reboots. Hardcoded
// absolute path so the location is unambiguous regardless of the CWD. Shared
// (read-only) with crono_watcher.py, which evaluates the schedule and pushes
// heater ON/OFF commands to the office board over MQTT.
define('CRONO_DB',    '/var/www/html/crono.db');
define('CRONO_STATE', '/dev/shm/crono_state.json');
define('METEO_DB',    '/dev/shm/meteo.db');
define('ALARMS_DB',   '/var/www/html/alarms.db');   // shared bots table

const DAY_LABELS = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];

function days_mask_label($mask) {
  $mask = (int)$mask;
  if ($mask === DAYS_ALL)     return 'Tutti';
  if ($mask === DAYS_MONFRI)  return 'Lun-Ven';
  if ($mask === DAYS_MONSAT)  return 'Lun-Sab';
  if ($mask === DAYS_WEEKEND) return 'Weekend';
  $out = [];
  for ($d = 0; $d < 7; $d++) if ($mask & (1 << $d)) $out[] = DAY_LABELS[$d];
  return $out ? implode(', ', $out) : '—';
}

// Day-of-week presets (bit 0=Mon … bit 6=Sun, matches Python weekday()).
const DAYS_ALL     = 127;
const DAYS_MONFRI  = 31;   // 0b0011111
const DAYS_MONSAT  = 63;   // 0b0111111
const DAYS_WEEKEND = 96;   // 0b1100000

function days_mask_from_post($days) {
  $mask = 0;
  if (is_array($days)) {
    foreach ($days as $idx => $val) {
      $i = (int)$idx;
      if ($i >= 0 && $i <= 6) $mask |= (1 << $i);
    }
  }
  return $mask === 0 ? DAYS_ALL : $mask;  // unchecked = all days, friendlier default
}

// ── Database ────────────────────────────────────────────────────────────────
function ensure_schema() {
  $db = new SQLite3(CRONO_DB);
  $db->busyTimeout(2000);
  $db->exec("CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
  )");
  $db->exec("CREATE TABLE IF NOT EXISTS bands (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    enabled   INTEGER NOT NULL DEFAULT 1,
    days_mask INTEGER NOT NULL DEFAULT 127,
    time_from TEXT    NOT NULL,
    time_to   TEXT    NOT NULL,
    target    REAL    NOT NULL,
    origin    TEXT    NOT NULL DEFAULT 'manual'
  )");
  // origin distinguishes hand-authored bands from those learned by the watcher.
  $cols = [];
  $res = $db->query('PRAGMA table_info(bands)');
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) $cols[$r['name']] = true;
  if ($cols && !isset($cols['origin'])) {
    $db->exec("ALTER TABLE bands ADD COLUMN origin TEXT NOT NULL DEFAULT 'manual'");
  }
  // Override + learning tables — written mostly by crono_watcher.py, but
  // created here too so the page works before the watcher's first run.
  $db->exec("CREATE TABLE IF NOT EXISTS override (
    id         INTEGER PRIMARY KEY CHECK (id = 1),
    type       TEXT, target REAL, expires_at TEXT, created_at TEXT
  )");
  $db->exec("CREATE TABLE IF NOT EXISTS commands (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT, dow INTEGER, start_min INTEGER, end_min INTEGER,
    type TEXT, target REAL, permanent INTEGER NOT NULL DEFAULT 0
  )");
  $db->exec("CREATE TABLE IF NOT EXISTS slots (
    dow INTEGER NOT NULL, slot INTEGER NOT NULL,
    target REAL, off_score REAL NOT NULL DEFAULT 0, weight REAL NOT NULL DEFAULT 0,
    PRIMARY KEY (dow, slot)
  )");
  return $db;
}

// Telegram bots configured in alarms.db (read-only) — for the command-bot picker.
function load_bots() {
  $bots = [];
  if (!file_exists(ALARMS_DB)) return $bots;
  try {
    $db = new SQLite3(ALARMS_DB, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);
    $res = $db->query('SELECT id, name FROM bots ORDER BY name, id');
    while ($b = $res->fetchArray(SQLITE3_ASSOC)) $bots[(int)$b['id']] = $b['name'];
    $db->close();
  } catch (Throwable $e) { /* alarms.db / bots table may not exist */ }
  return $bots;
}

function load_settings($db) {
  $defaults = ['mode' => 'auto', 'hysteresis' => '0.3', 'default_target' => '7.0',
               'bot_id' => '', 'learning' => 'on'];
  $res = $db->query('SELECT key, value FROM settings');
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $defaults[$r['key']] = $r['value'];
  }
  return $defaults;
}

// ── Live status (state file + latest office reading) ────────────────────────
function load_state() {
  if (!is_readable(CRONO_STATE)) return [];
  $raw = @file_get_contents(CRONO_STATE);
  if ($raw === false) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function latest_ufficio() {
  if (!file_exists(METEO_DB)) return null;
  try {
    $db = new PDO('sqlite:' . METEO_DB, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_TIMEOUT => 2,
    ]);
    $row = $db->query('SELECT * FROM ufficio ORDER BY id DESC LIMIT 1')->fetch();
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

// ── JSON status API (polled by the page) ────────────────────────────────────
if (($_GET['api'] ?? '') === 'status') {
  header('Content-Type: application/json');
  echo json_encode([
    'state'   => load_state(),
    'ufficio' => latest_ufficio(),
  ]);
  exit;
}

$message = '';
$messageType = '';

// ── POST handlers ───────────────────────────────────────────────────────────
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

if ($action === 'cancel_override') {
  try {
    $db = ensure_schema();
    $db->exec('DELETE FROM override WHERE id = 1');
    $message = 'Comando annullato: si torna subito al programma.';
    $messageType = 'ok';
  } catch (Throwable $e) {
    $message = 'Errore: ' . $e->getMessage();
    $messageType = 'err';
  }

} elseif ($action === 'forget_learned') {
  try {
    $db = ensure_schema();
    $db->exec('BEGIN');
    $db->exec("DELETE FROM bands WHERE origin = 'learned'");
    $db->exec('DELETE FROM slots');
    $db->exec('COMMIT');
    $message = 'Apprendimento azzerato: fasce apprese e modello rimossi (lo storico comandi resta).';
    $messageType = 'ok';
  } catch (Throwable $e) {
    $message = 'Errore: ' . $e->getMessage();
    $messageType = 'err';
  }

} elseif ($action === 'save_schedule') {
  $mode = ($_POST['mode'] ?? 'auto') === 'off' ? 'off' : 'auto';
  $hyst = trim($_POST['hysteresis'] ?? '');
  $hyst = is_numeric($hyst) ? max(0.0, (float)$hyst) : 0.3;
  $dflt = trim($_POST['default_target'] ?? '');
  $dflt = is_numeric($dflt) ? (float)$dflt : 7.0;
  $learning = ($_POST['learning'] ?? 'on') === 'off' ? 'off' : 'on';
  $bot_id_raw = trim($_POST['bot_id'] ?? '');
  $bot_id = ($bot_id_raw !== '' && ctype_digit($bot_id_raw)) ? $bot_id_raw : '';

  $rows = $_POST['band'] ?? [];
  $kept = $skipped = 0;
  try {
    $db = ensure_schema();
    $db->exec('BEGIN');
    // Only manual bands are owned by this form — learned bands stay untouched.
    $db->exec("DELETE FROM bands WHERE origin = 'manual' OR origin IS NULL");

    $ins = $db->prepare(
      "INSERT INTO bands (enabled, days_mask, time_from, time_to, target, origin)
       VALUES (:e, :dm, :tf, :tt, :tg, 'manual')"
    );
    foreach ($rows as $b) {
      $tf = trim($b['time_from'] ?? '');
      $tt = trim($b['time_to']   ?? '');
      $tg = trim($b['target']    ?? '');
      if (!preg_match('/^\d{2}:\d{2}$/', $tf) || !preg_match('/^\d{2}:\d{2}$/', $tt) || $tg === '' || !is_numeric($tg)) {
        $skipped++;
        continue;
      }
      $ins->reset();
      $ins->clear();
      $ins->bindValue(':e',  !empty($b['enabled']) ? 1 : 0, SQLITE3_INTEGER);
      $ins->bindValue(':dm', days_mask_from_post($b['days'] ?? null), SQLITE3_INTEGER);
      $ins->bindValue(':tf', $tf, SQLITE3_TEXT);
      $ins->bindValue(':tt', $tt, SQLITE3_TEXT);
      $ins->bindValue(':tg', (float)$tg, SQLITE3_FLOAT);
      $ins->execute();
      $kept++;
    }

    $setStmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:k, :v)');
    foreach (['mode' => $mode, 'hysteresis' => (string)$hyst, 'default_target' => (string)$dflt,
              'learning' => $learning, 'bot_id' => $bot_id] as $k => $v) {
      $setStmt->reset();
      $setStmt->clear();
      $setStmt->bindValue(':k', $k, SQLITE3_TEXT);
      $setStmt->bindValue(':v', $v, SQLITE3_TEXT);
      $setStmt->execute();
    }

    $db->exec('COMMIT');
    $message = "Salvate {$kept} fasce orarie"
             . ($skipped ? " ({$skipped} incomplete ignorate)." : '.')
             . " Il cronotermostato applica le modifiche entro ~30s.";
    $messageType = 'ok';
  } catch (Throwable $e) {
    $message = 'Errore scrittura crono.db: ' . $e->getMessage()
             . '. Verifica i permessi (deve essere scrivibile da www-data e dal watcher).';
    $messageType = 'err';
  }
}

// ── Load current schedule, learned bands, override and recent commands ──────
$bands = [];           // manual bands (editable)
$learned = [];         // learned bands (read-only)
$override = null;
$commands = [];
$BOTS = load_bots();
$settings = ['mode' => 'auto', 'hysteresis' => '0.3', 'default_target' => '7.0',
             'bot_id' => '', 'learning' => 'on'];
try {
  $db = ensure_schema();
  $settings = load_settings($db);

  $res = $db->query("SELECT id, enabled, days_mask, time_from, time_to, target
                     FROM bands WHERE origin = 'manual' OR origin IS NULL ORDER BY time_from, id");
  while ($b = $res->fetchArray(SQLITE3_ASSOC)) $bands[] = $b;

  $res = $db->query("SELECT days_mask, time_from, time_to, target
                     FROM bands WHERE origin = 'learned' ORDER BY days_mask, time_from");
  while ($b = $res->fetchArray(SQLITE3_ASSOC)) $learned[] = $b;

  $row = $db->querySingle("SELECT type, target, expires_at FROM override WHERE id = 1", true);
  if ($row && !empty($row['expires_at'])) {
    $exp = strtotime($row['expires_at']);  // UTC ISO
    if ($exp !== false && $exp > time()) $override = $row;
  }

  $res = $db->query('SELECT ts, dow, start_min, type, target, permanent
                     FROM commands ORDER BY id DESC LIMIT 12');
  while ($c = $res->fetchArray(SQLITE3_ASSOC)) $commands[] = $c;
} catch (Throwable $e) {
  if (!$message) {
    $message = 'Errore lettura crono.db: ' . $e->getMessage();
    $messageType = 'err';
  }
}

// ── Render helpers ──────────────────────────────────────────────────────────
function days_toggle_html($i, $mask) {
  $labels = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
  ob_start(); ?>
  <div class="day-row">
    <?php for ($d = 0; $d < 7; $d++):
      $checked = ($mask & (1 << $d)) ? 'checked' : ''; ?>
      <label><input type="checkbox" name="band[<?= $i ?>][days][<?= $d ?>]" value="1" <?= $checked ?>><?= $labels[$d] ?></label>
    <?php endfor; ?>
    <span class="preset-row">
      <button type="button" class="btn-preset" data-mask="<?= DAYS_ALL ?>">Tutti</button>
      <button type="button" class="btn-preset" data-mask="<?= DAYS_MONFRI ?>">Lun-Ven</button>
      <button type="button" class="btn-preset" data-mask="<?= DAYS_MONSAT ?>">Lun-Sab</button>
      <button type="button" class="btn-preset" data-mask="<?= DAYS_WEEKEND ?>">Weekend</button>
    </span>
  </div>
  <?php
  return ob_get_clean();
}

function band_card_html($i, $b = null) {
  $enabled = $b ? !empty($b['enabled']) : true;
  $tf      = $b['time_from'] ?? '06:00';
  $tt      = $b['time_to']   ?? '22:00';
  $target  = isset($b['target']) ? (string)$b['target'] : '20';
  $mask    = isset($b['days_mask']) ? (int)$b['days_mask'] : DAYS_ALL;
  ob_start(); ?>
  <div class="band-card" data-band-idx="<?= htmlspecialchars((string)$i) ?>">
    <div class="band-head">
      <label class="band-enable" title="Attiva la fascia">
        <input type="checkbox" name="band[<?= $i ?>][enabled]" value="1" <?= $enabled ? 'checked' : '' ?>>
      </label>
      <div class="band-times">
        <span>Dalle</span>
        <input type="time" name="band[<?= $i ?>][time_from]" value="<?= htmlspecialchars($tf) ?>">
        <span>alle</span>
        <input type="time" name="band[<?= $i ?>][time_to]" value="<?= htmlspecialchars($tt) ?>">
      </div>
      <div class="band-target">
        <span>Target</span>
        <input type="number" step="0.5" name="band[<?= $i ?>][target]" value="<?= htmlspecialchars($target) ?>">
        <span class="unit">°C</span>
      </div>
      <button type="button" class="btn-remove" title="Rimuovi fascia">&times;</button>
    </div>
    <?= days_toggle_html($i, $mask) ?>
  </div>
  <?php
  return ob_get_clean();
}

// Template for client-side cloning.
$tpl_band = band_card_html('__I__');
?>
<!DOCTYPE html>
<html lang="it">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stazione Meteo — Cronotermostato Ufficio</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

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

    header h1 { font-size: 1.4rem; font-weight: 600; letter-spacing: .02em; }
    header h1 svg {
      width: 1.5rem; height: 1.5rem;
      vertical-align: -.25rem; margin-right: .4rem;
      color: #9333ea;
    }

    .back-link { font-size: .85rem; color: #0284c7; text-decoration: none; }
    .back-link:hover { text-decoration: underline; }

    .panel {
      background: #ffffff;
      border-radius: .75rem;
      padding: 1.25rem 1.5rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
      margin-bottom: 1rem;
    }

    .panel h2 {
      font-size: .85rem;
      color: #64748b;
      margin-bottom: .9rem;
      text-transform: uppercase;
      letter-spacing: .05em;
    }

    /* Live status */
    .status-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      gap: .9rem;
    }
    .status-item { display: flex; flex-direction: column; gap: .2rem; }
    .status-item .label {
      font-size: .7rem; text-transform: uppercase; color: #94a3b8; letter-spacing: .05em;
    }
    .status-item .value { font-size: 1.4rem; font-weight: 700; }
    .badge-on  { color: #dc2626; }
    .badge-off { color: #16a34a; }
    .badge-mode-off { color: #64748b; }
    .badge-cmd { color: #9333ea; }

    /* Band cards */
    .band-card {
      border: 1px solid #e2e8f0;
      border-radius: .5rem;
      padding: .75rem .9rem;
      margin-bottom: .75rem;
      background: #f8fafc;
    }
    .band-head {
      display: flex;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
      margin-bottom: .6rem;
    }
    .band-enable { display: inline-flex; align-items: center; }
    .band-times, .band-target { display: inline-flex; align-items: center; gap: .4rem; }
    .band-times span, .band-target span { color: #64748b; font-size: .8rem; }
    .band-times input[type="time"] { width: 7rem; }
    .band-target input[type="number"] { width: 5rem; }
    .band-target .unit { color: #64748b; }
    .band-head .btn-remove { margin-left: auto; }

    .day-row { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
    .day-row label {
      display: inline-flex; align-items: center; gap: .25rem;
      font-size: .75rem; color: #475569; user-select: none;
    }
    .day-row input[type="checkbox"] { width: .9rem; height: .9rem; }

    .preset-row { display: inline-flex; gap: .3rem; flex-wrap: wrap; margin-left: .5rem; }
    .btn-preset {
      background: #ffffff; color: #475569;
      border: 1px solid #cbd5e1; border-radius: .35rem;
      padding: .15rem .55rem; font-size: .72rem; cursor: pointer; font-family: inherit;
    }
    .btn-preset:hover { background: #f1f5f9; }

    /* Settings row */
    .settings-row { display: flex; gap: 1.5rem; flex-wrap: wrap; align-items: flex-end; }
    .field { display: flex; flex-direction: column; gap: .3rem; }
    .field label { font-size: .75rem; color: #64748b; font-weight: 600; }

    /* Form controls */
    input[type="number"], input[type="time"], select {
      padding: .35rem .5rem; font-size: .85rem; font-family: inherit;
      border: 1px solid #cbd5e1; border-radius: .35rem;
      background: #ffffff; color: #1e293b;
    }
    input:focus, select:focus {
      outline: none; border-color: #9333ea;
      box-shadow: 0 0 0 2px rgba(147, 51, 234, .15);
    }
    input[type="checkbox"] { width: 1.1rem; height: 1.1rem; cursor: pointer; }

    button[type="submit"], button.primary {
      background: #9333ea; color: #fff; border: none; border-radius: .45rem;
      padding: .55rem 1.15rem; font-size: .9rem; font-weight: 600; cursor: pointer; font-family: inherit;
    }
    button[type="submit"]:hover, button.primary:hover { background: #7e22ce; }

    #add-band {
      background: #e2e8f0; color: #1e293b; border: none; border-radius: .45rem;
      padding: .4rem .85rem; font-size: .8rem; font-weight: 600; cursor: pointer; font-family: inherit;
    }
    #add-band:hover { background: #cbd5e1; }

    .btn-remove {
      background: transparent; color: #dc2626;
      border: 1px solid #fecaca; border-radius: .35rem;
      padding: 0; width: 1.8rem; height: 1.8rem;
      font-size: 1rem; line-height: 1; cursor: pointer;
    }
    .btn-remove:hover { background: #fee2e2; }

    .actions { display: flex; justify-content: flex-end; gap: .75rem; align-items: center; margin-top: 1rem; }

    .msg { border-radius: .5rem; padding: .65rem 1rem; margin-bottom: 1rem; font-size: .85rem; }
    .msg.ok  { background: #dcfce7; color: #16a34a; }
    .msg.err { background: #fee2e2; color: #dc2626; }

    .help { font-size: .78rem; color: #64748b; margin-top: .6rem; line-height: 1.45; }

    .empty {
      padding: 1.25rem; color: #94a3b8; text-align: center; font-style: italic;
      border: 1px dashed #cbd5e1; border-radius: .5rem; margin-bottom: .75rem;
    }

    /* Active override */
    .override-panel { border-left: 4px solid #9333ea; }
    .override-row { display: flex; align-items: center; gap: .9rem; flex-wrap: wrap; }
    .override-badge {
      background: #f3e8ff; color: #7e22ce; font-weight: 700; font-size: 1.05rem;
      padding: .3rem .7rem; border-radius: .45rem;
    }
    .override-info { color: #475569; font-size: .85rem; }
    .btn-cancel {
      background: #fff; color: #dc2626; border: 1px solid #fecaca; border-radius: .45rem;
      padding: .45rem .9rem; font-size: .82rem; font-weight: 600; cursor: pointer; font-family: inherit;
    }
    .btn-cancel:hover { background: #fee2e2; }

    /* Learned / commands tables */
    .learn-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    .learn-table th {
      text-align: left; color: #94a3b8; font-weight: 600; font-size: .72rem;
      text-transform: uppercase; letter-spacing: .05em; padding: .4rem .6rem; border-bottom: 1px solid #e2e8f0;
    }
    .learn-table td { padding: .4rem .6rem; }
    .learn-table tr:not(:last-child) td { border-bottom: 1px solid #f1f5f9; }
  </style>
</head>

<body>

  <header>
    <h1>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
        stroke-linejoin="round" aria-hidden="true">
        <path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z" />
      </svg>
      Cronotermostato Ufficio
    </h1>
    <a class="back-link" href="index.php">← Dashboard</a>
  </header>

  <?php if ($message): ?>
    <div class="msg <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <!-- ── Live status ── -->
  <div class="panel">
    <h2>Stato corrente</h2>
    <div class="status-grid">
      <div class="status-item"><span class="label">Modalità</span><span class="value" id="s-mode">—</span></div>
      <div class="status-item"><span class="label">Temp. ufficio</span><span class="value" id="s-temp">—</span></div>
      <div class="status-item"><span class="label">Target attivo</span><span class="value" id="s-target">—</span></div>
      <div class="status-item"><span class="label">Caldaia</span><span class="value" id="s-heater">—</span></div>
      <div class="status-item"><span class="label">Aggiornato</span><span class="value" id="s-updated" style="font-size:.85rem;font-weight:500;color:#64748b">—</span></div>
    </div>
    <p class="help" id="s-note"></p>
  </div>

  <?php if ($override): ?>
    <?php
      $ov_exp_local = !empty($override['expires_at']) ? date('H:i', strtotime($override['expires_at'])) : '—';
      $ov_label = $override['type'] === 'off'
        ? 'Spenta'
        : (isset($override['target']) ? rtrim(rtrim(number_format((float)$override['target'], 1, '.', ''), '0'), '.') . '°C' : '—');
    ?>
    <div class="panel override-panel">
      <h2>Comando attivo (priorità sul programma)</h2>
      <div class="override-row">
        <span class="override-badge"><?= htmlspecialchars($ov_label) ?></span>
        <span class="override-info">Attivo fino alle <strong><?= htmlspecialchars($ov_exp_local) ?></strong>, poi torna al programma.</span>
        <form method="post" style="margin-left:auto">
          <input type="hidden" name="action" value="cancel_override">
          <button type="submit" class="btn-cancel">Annulla comando</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="save_schedule">

    <!-- ── Settings ── -->
    <div class="panel">
      <h2>Impostazioni</h2>
      <div class="settings-row">
        <div class="field">
          <label for="mode">Modalità</label>
          <select name="mode" id="mode">
            <option value="auto" <?= $settings['mode'] !== 'off' ? 'selected' : '' ?>>Auto (segui programma)</option>
            <option value="off"  <?= $settings['mode'] === 'off' ? 'selected' : '' ?>>Spento (caldaia sempre OFF)</option>
          </select>
        </div>
        <div class="field">
          <label for="hysteresis">Isteresi (±°C)</label>
          <input type="number" step="0.1" min="0" name="hysteresis" id="hysteresis"
            value="<?= htmlspecialchars($settings['hysteresis']) ?>">
        </div>
        <div class="field">
          <label for="default_target">Antigelo / fuori fascia (°C)</label>
          <input type="number" step="0.5" name="default_target" id="default_target"
            value="<?= htmlspecialchars($settings['default_target']) ?>">
        </div>
        <div class="field">
          <label for="bot_id">Bot Telegram (comandi)</label>
          <select name="bot_id" id="bot_id">
            <option value="">Nessuno (disattivato)</option>
            <?php foreach ($BOTS as $bid => $bname): ?>
              <option value="<?= (int)$bid ?>" <?= (string)$settings['bot_id'] === (string)$bid ? 'selected' : '' ?>>
                <?= htmlspecialchars($bname !== '' ? $bname : ('Bot #' . $bid)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="learning">Apprendimento</label>
          <select name="learning" id="learning">
            <option value="on"  <?= $settings['learning'] !== 'off' ? 'selected' : '' ?>>Attivo (adatta le fasce)</option>
            <option value="off" <?= $settings['learning'] === 'off' ? 'selected' : '' ?>>Disattivato</option>
          </select>
        </div>
      </div>
      <p class="help">
        L'isteresi evita l'oscillazione del relè: la caldaia si accende quando
        <code>temp &lt; target − isteresi</code> e si spegne quando <code>temp &gt; target + isteresi</code>.
        Il valore <em>antigelo</em> è il target usato negli orari non coperti da alcuna fascia.
        Il <strong>bot Telegram</strong> (configurato in <a href="bots.php">Bot Telegram</a>) riceve i comandi
        ON/OFF/temperatura: scegline uno <em>diverso</em> da quello degli allarmi.
        Con l'<strong>apprendimento</strong> attivo i comandi ricorrenti diventano automaticamente fasce «apprese».
      </p>
    </div>

    <!-- ── Weekly schedule ── -->
    <div class="panel">
      <h2>Fasce orarie settimanali</h2>
      <div id="bands-container">
        <?php if (!$bands): ?>
          <div class="empty" id="empty-state">Nessuna fascia. Aggiungine una con il pulsante qui sotto.</div>
        <?php else: ?>
          <?php foreach ($bands as $i => $b) echo band_card_html($i, $b); ?>
        <?php endif; ?>
      </div>
      <button type="button" id="add-band">+ Aggiungi fascia</button>
      <p class="help">
        Ogni fascia imposta una temperatura target nei giorni e nell'intervallo orario scelti.
        Se più fasce si sovrappongono, vince <strong>l'ultima</strong> della lista (ordinata per ora di inizio).
        Una fascia che attraversa la mezzanotte (es. 22:00–06:00) è ammessa.
      </p>
    </div>

    <div class="actions">
      <button type="submit">Salva programma</button>
    </div>
  </form>

  <!-- ── Learned bands (managed by the watcher) ── -->
  <div class="panel">
    <h2>Fasce apprese
      <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#94a3b8">— generate dai comandi Telegram</span>
    </h2>
    <?php if (!$learned): ?>
      <div class="empty">Nessuna fascia appresa per ora. Invia comandi al bot e verranno imparati qui.</div>
    <?php else: ?>
      <table class="learn-table">
        <thead><tr><th>Giorni</th><th>Dalle</th><th>Alle</th><th>Target</th></tr></thead>
        <tbody>
          <?php foreach ($learned as $b): ?>
            <tr>
              <td><?= htmlspecialchars(days_mask_label($b['days_mask'])) ?></td>
              <td><?= htmlspecialchars($b['time_from']) ?></td>
              <td><?= htmlspecialchars($b['time_to']) ?></td>
              <td><?= htmlspecialchars(rtrim(rtrim(number_format((float)$b['target'], 1, '.', ''), '0'), '.')) ?> °C</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" style="margin-top:.75rem">
        <input type="hidden" name="action" value="forget_learned">
        <button type="submit" class="btn-cancel"
          onclick="return confirm('Azzerare tutte le fasce apprese e il modello di apprendimento?');">
          Dimentica apprese
        </button>
      </form>
    <?php endif; ?>
    <p class="help">
      Le fasce apprese hanno priorità sulle fasce manuali sovrapposte e si adattano nel tempo
      (i comandi recenti pesano di più). I comandi inviati al bot le aggiornano automaticamente.
    </p>
  </div>

  <!-- ── Recent commands ── -->
  <?php if ($commands): ?>
    <div class="panel">
      <h2>Ultimi comandi ricevuti</h2>
      <table class="learn-table">
        <thead><tr><th>Quando</th><th>Giorno</th><th>Ora</th><th>Comando</th></tr></thead>
        <tbody>
          <?php foreach ($commands as $c):
            $cmd = $c['type'] === 'off'
              ? 'OFF'
              : (isset($c['target']) ? rtrim(rtrim(number_format((float)$c['target'], 1, '.', ''), '0'), '.') . '°C' : '—');
            if (!empty($c['permanent'])) $cmd .= ' (permanente)';
            $hh = sprintf('%02d:%02d', intdiv((int)$c['start_min'], 60), (int)$c['start_min'] % 60);
          ?>
            <tr>
              <td><?= htmlspecialchars($c['ts']) ?></td>
              <td><?= htmlspecialchars(DAY_LABELS[(int)$c['dow']] ?? '—') ?></td>
              <td><?= htmlspecialchars($hh) ?></td>
              <td><?= htmlspecialchars($cmd) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <script>
  (function () {
    const tplBand = <?= json_encode($tpl_band, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const container = document.getElementById('bands-container');
    let nextIdx = <?= count($bands) ?>;

    function clearEmptyState() {
      const empty = document.getElementById('empty-state');
      if (empty) empty.remove();
    }

    document.getElementById('add-band').addEventListener('click', function () {
      clearEmptyState();
      const html = tplBand.split('__I__').join(String(nextIdx++));
      const wrap = document.createElement('div');
      wrap.innerHTML = html;
      container.appendChild(wrap.firstElementChild);
    });

    container.addEventListener('click', function (e) {
      if (e.target.matches('.btn-remove')) {
        e.target.closest('.band-card').remove();
        return;
      }
      const preset = e.target.closest('.btn-preset');
      if (preset) {
        const mask = parseInt(preset.dataset.mask, 10);
        const dayRow = preset.closest('.day-row');
        if (dayRow) {
          dayRow.querySelectorAll('input[type="checkbox"]').forEach(function (cb, k) {
            cb.checked = !!(mask & (1 << k));
          });
        }
      }
    });

    // ── Live status polling ──
    const fmt = (v, d = 1) => (v === null || v === undefined || v === '') ? '—' : Number(v).toFixed(d);

    async function pollStatus() {
      try {
        const res = await fetch('?api=status', { cache: 'no-store' });
        const data = await res.json();
        const st = data.state || {};
        const uff = data.ufficio || {};

        const modeEl = document.getElementById('s-mode');
        const mode = st.mode || '<?= htmlspecialchars($settings['mode']) ?>';
        if (st.source === 'override') {
          modeEl.textContent = 'Comando';
          modeEl.className = 'value badge-cmd';
        } else {
          modeEl.textContent = mode === 'off' ? 'Spento' : 'Auto';
          modeEl.className = 'value' + (mode === 'off' ? ' badge-mode-off' : '');
        }

        // Prefer the temperature the watcher actually decided on; fall back to DB.
        const temp = (st.temp !== undefined && st.temp !== null) ? st.temp : uff.temp;
        document.getElementById('s-temp').textContent = fmt(temp, 1) + ' °C';
        document.getElementById('s-target').textContent =
          (st.target === undefined || st.target === null) ? '—' : fmt(st.target, 1) + ' °C';

        const heaterEl = document.getElementById('s-heater');
        const heater = (st.heater || uff.heater || '').toUpperCase();
        heaterEl.textContent = heater || '—';
        heaterEl.className = 'value' + (heater === 'ON' ? ' badge-on' : heater === 'OFF' ? ' badge-off' : '');

        document.getElementById('s-updated').textContent = st.updated || (uff.timestamp ? uff.timestamp + ' (UTC)' : '—');
        document.getElementById('s-note').textContent = st.note || '';
      } catch (e) { /* keep last values on transient errors */ }
    }
    pollStatus();
    setInterval(pollStatus, 10000);
  })();
  </script>

</body>

</html>
