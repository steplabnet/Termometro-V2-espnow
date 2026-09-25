<?php
// Mobile-first control page for the office thermostat. Quick actions only —
// the weekly schedule is still edited on cronotermostato.php. Reads/writes the
// same crono.db that crono_watcher.py polls (~30s), so every change here is
// picked up on the watcher's next cycle.
// PHP on the Pi defaults to UTC while the system (and crono_watcher.py) runs on
// local time: without this, hours, command timestamps and learning are 2h off.
date_default_timezone_set('Europe/Rome');

define('CRONO_DB',    '/var/www/html/crono.db');
define('CRONO_STATE', '/dev/shm/crono_state.json');
define('METEO_DB',    '/dev/shm/meteo.db');

const MORNING_HOUR = 6;       // matches crono_watcher.py: "fino a domani" ends here
const PERM_WINDOW_MIN = 120;  // matches crono_watcher.py: span a "permanente" command teaches
const DAY_LABELS = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
const MODE_LABELS = ['auto' => 'Auto', 'manual' => 'Manuale', 'off' => 'Spento'];

function open_db() {
  $db = new SQLite3(CRONO_DB);
  $db->busyTimeout(2000);
  $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
  $db->exec("CREATE TABLE IF NOT EXISTS override (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    type TEXT, target REAL, expires_at TEXT, created_at TEXT
  )");
  // Queue read by crono_watcher.py, which learns from these like Telegram commands.
  $db->exec("CREATE TABLE IF NOT EXISTS web_commands (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT, type TEXT, target REAL, minutes INTEGER,
    permanent INTEGER NOT NULL DEFAULT 0, processed INTEGER NOT NULL DEFAULT 0
  )");
  return $db;
}

function load_settings($db) {
  $s = ['mode' => 'auto', 'manual_target' => '20', 'hysteresis' => '0.3', 'default_target' => '7.0'];
  $res = $db->query('SELECT key, value FROM settings');
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) $s[$r['key']] = $r['value'];
  return $s;
}

function set_setting($db, $k, $v) {
  $st = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:k, :v)');
  $st->bindValue(':k', $k, SQLITE3_TEXT);
  $st->bindValue(':v', (string)$v, SQLITE3_TEXT);
  $st->execute();
}

function active_override($db) {
  $row = $db->querySingle("SELECT type, target, expires_at FROM override WHERE id = 1", true);
  if (!$row || empty($row['expires_at'])) return null;
  $exp = strtotime($row['expires_at']);
  return ($exp !== false && $exp > time()) ? $row + ['expires_ts' => $exp] : null;
}

function load_state() {
  if (!is_readable(CRONO_STATE)) return [];
  $data = json_decode((string)@file_get_contents(CRONO_STATE), true);
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
    return $db->query('SELECT * FROM ufficio ORDER BY id DESC LIMIT 1')->fetch() ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function hm_to_min($s) {
  $p = explode(':', (string)$s);
  return (int)($p[0] ?? 0) * 60 + (int)($p[1] ?? 0);
}

// Hourly setpoints for today, evaluated like crono_watcher.active_target():
// manual bands then learned ones, last match wins, default outside any band.
// Each hour is sampled at its four quarter midpoints and the value holding
// most of the hour is kept, so a band starting at :30 doesn't flip the bar.
function hourly_plan($db, $default) {
  $bit = 1 << ((int)date('N') - 1);
  $bands = [];
  $res = @$db->query("SELECT time_from, time_to, target, origin FROM bands
                      WHERE enabled = 1 AND (days_mask & $bit)
                      ORDER BY CASE WHEN origin = 'learned' THEN 1 ELSE 0 END, time_from, id");
  if ($res) while ($b = $res->fetchArray(SQLITE3_ASSOC)) {
    $bands[] = ['f' => hm_to_min($b['time_from']), 't' => hm_to_min($b['time_to']),
                'target' => (float)$b['target'], 'src' => $b['origin'] === 'learned' ? 'learned' : 'manual'];
  }
  $plan = [];
  for ($h = 0; $h < 24; $h++) {
    $votes = [];
    foreach ([7, 22, 37, 52] as $q) {
      $m = $h * 60 + $q;
      $pick = ['target' => (float)$default, 'src' => 'default'];
      foreach ($bands as $b) {
        $in = $b['f'] <= $b['t'] ? ($m >= $b['f'] && $m <= $b['t']) : ($m >= $b['f'] || $m <= $b['t']);
        if ($in) $pick = ['target' => $b['target'], 'src' => $b['src']];
      }
      $k = $pick['src'] . '|' . $pick['target'];
      $votes[$k] = ($votes[$k] ?? 0) + 1;
    }
    arsort($votes);
    [$src, $tgt] = explode('|', array_key_first($votes));
    $plan[] = ['h' => $h, 'target' => (float)$tgt, 'src' => $src];
  }
  return $plan;
}

// Today's office readings averaged per local hour (meteo.db stores UTC ISO).
function hourly_actual() {
  $out = array_fill(0, 24, null);
  if (!file_exists(METEO_DB)) return $out;
  try {
    $db = new PDO('sqlite:' . METEO_DB, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_TIMEOUT => 2,
    ]);
    $st = $db->prepare('SELECT timestamp, temp, heater FROM ufficio WHERE timestamp >= ? ORDER BY id');
    $st->execute([gmdate('Y-m-d\TH:i:s\Z', strtotime('today'))]);
    $acc = [];
    foreach ($st as $r) {
      $ts = strtotime($r['timestamp']);
      if ($ts === false || $r['temp'] === null) continue;
      $h = (int)date('G', $ts);
      $acc[$h]['t'][] = (float)$r['temp'];
      $acc[$h]['on'][] = strtoupper((string)$r['heater']) === 'ON' ? 1 : 0;
    }
    foreach ($acc as $h => $a) {
      $out[$h] = ['temp' => round(array_sum($a['t']) / count($a['t']), 1),
                  'on'   => (int)round(100 * array_sum($a['on']) / count($a['on']))];
    }
  } catch (Throwable $e) { /* no readings: chart shows the plan only */ }
  return $out;
}

function fmt_t($v) {
  return rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
}

function clamp_target($raw, $fallback) {
  $raw = str_replace(',', '.', trim((string)$raw));
  return is_numeric($raw) ? max(5.0, min(30.0, round((float)$raw * 2) / 2)) : $fallback;
}

// ── JSON status API (polled by the page) ────────────────────────────────────
if (($_GET['api'] ?? '') === 'status') {
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  $ov = null;
  try { $ov = active_override(open_db()); } catch (Throwable $e) {}
  if ($ov) $ov['until'] = date('H:i', $ov['expires_ts']);
  echo json_encode(['state' => load_state(), 'ufficio' => latest_ufficio(), 'override' => $ov]);
  exit;
}

// ── POST actions (Post/Redirect/Get so a phone refresh never re-submits) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $msg = '';
  $ok = true;
  try {
    $db = open_db();
    $settings = load_settings($db);

    if ($action === 'mode') {
      $mode = $_POST['mode'] ?? '';
      if (!isset(MODE_LABELS[$mode])) throw new RuntimeException('Modalità non valida');
      set_setting($db, 'mode', $mode);
      $msg = 'Modalità: ' . MODE_LABELS[$mode];

    } elseif ($action === 'manual_target') {
      $t = clamp_target($_POST['target'] ?? '', (float)$settings['manual_target']);
      set_setting($db, 'manual_target', $t);
      set_setting($db, 'mode', 'manual');
      $msg = 'Manuale a ' . fmt_t($t) . '°C';

    } elseif ($action === 'default_target') {
      $t = clamp_target($_POST['target'] ?? '', (float)$settings['default_target']);
      set_setting($db, 'default_target', $t);
      $msg = 'Antigelo a ' . fmt_t($t) . '°C';

    } elseif ($action === 'command') {
      // Same semantics as a Telegram command: a timed command sets the override
      // (row format as crono_watcher.py writes it); "permanente" sets none and
      // only teaches the schedule. Either way it is queued for learning.
      $type = ($_POST['type'] ?? '') === 'off' ? 'off' : 'set';
      $target = $type === 'set' ? clamp_target($_POST['target'] ?? '', 20.0) : null;
      $dur = $_POST['dur'] ?? '60';
      $perm = $dur === 'perm';
      if ($perm) {
        $mins = PERM_WINDOW_MIN;
      } elseif ($dur === 'morning') {
        $end = strtotime('today ' . MORNING_HOUR . ':00');
        if ($end <= time()) $end = strtotime('tomorrow ' . MORNING_HOUR . ':00');
        $mins = max(1, intdiv($end - time(), 60));
      } else {
        $mins = (int)$dur;
        if (!in_array($mins, [30, 60, 120, 180], true)) $mins = 60;
        $end = time() + $mins * 60;
      }
      $learning = ($settings['learning'] ?? 'on') !== 'off';
      if ($perm && !$learning) throw new RuntimeException('Apprendimento disattivato: «Permanente» non è disponibile');

      $db->exec('BEGIN');
      if (!$perm) {
        $db->exec('DELETE FROM override WHERE id = 1');
        $st = $db->prepare("INSERT INTO override (id, type, target, expires_at, created_at)
                            VALUES (1, :ty, :tg, :ex, :cr)");
        $st->bindValue(':ty', $type, SQLITE3_TEXT);
        $st->bindValue(':tg', $target, $target === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $st->bindValue(':ex', gmdate('Y-m-d\TH:i:s\Z', $end), SQLITE3_TEXT);
        $st->bindValue(':cr', gmdate('Y-m-d\TH:i:s\Z'), SQLITE3_TEXT);
        $st->execute();
      }
      $q = $db->prepare("INSERT INTO web_commands (ts, type, target, minutes, permanent)
                         VALUES (:ts, :ty, :tg, :mi, :pe)");
      $q->bindValue(':ts', date('Y-m-d H:i:s'), SQLITE3_TEXT);
      $q->bindValue(':ty', $type, SQLITE3_TEXT);
      $q->bindValue(':tg', $target, $target === null ? SQLITE3_NULL : SQLITE3_FLOAT);
      $q->bindValue(':mi', $mins, SQLITE3_INTEGER);
      $q->bindValue(':pe', $perm ? 1 : 0, SQLITE3_INTEGER);
      $q->execute();
      $db->exec('COMMIT');

      $what = $type === 'off' ? 'Antigelo ' . fmt_t($settings['default_target']) . '°C' : fmt_t($target) . '°C';
      $msg = $perm
        ? "$what memorizzato nel programma di " . DAY_LABELS[(int)date('N') - 1] . ' da ' . date('H:i')
        : "$what fino alle " . date('H:i', $end) . ($learning ? ' · lo imparo' : '');

    } elseif ($action === 'cancel_override') {
      $db->exec('DELETE FROM override WHERE id = 1');
      $msg = 'Comando annullato, si torna al programma';

    } else {
      throw new RuntimeException('Azione sconosciuta');
    }
  } catch (Throwable $e) {
    $ok = false;
    $msg = 'Errore: ' . $e->getMessage();
  }
  header('Location: crono.php?' . http_build_query(['m' => $msg, 'ok' => $ok ? 1 : 0]), true, 303);
  exit;
}

// ── Page data ───────────────────────────────────────────────────────────────
$flash   = (string)($_GET['m'] ?? '');
$flashOk = ($_GET['ok'] ?? '1') === '1';
$settings = ['mode' => 'auto', 'manual_target' => '20', 'default_target' => '7.0'];
$override = null;
$today = [];
$plan = [];
$actual = hourly_actual();
$loadErr = '';
try {
  $db = open_db();
  $settings = load_settings($db);
  $override = active_override($db);

  // Today's enabled bands, manual then learned (same order the watcher evaluates).
  $bit = 1 << ((int)date('N') - 1);
  $res = @$db->query("SELECT time_from, time_to, target, origin FROM bands
                      WHERE enabled = 1 AND (days_mask & $bit)
                      ORDER BY time_from, CASE WHEN origin = 'learned' THEN 1 ELSE 0 END");
  if ($res) while ($b = $res->fetchArray(SQLITE3_ASSOC)) $today[] = $b;
  $plan = hourly_plan($db, $settings['default_target']);
} catch (Throwable $e) {
  $loadErr = $e->getMessage();
}
$mode = isset(MODE_LABELS[$settings['mode']]) ? $settings['mode'] : 'auto';
$manualT = (float)$settings['manual_target'];
$learning = ($settings['learning'] ?? 'on') !== 'off';
$nowHm = date('H:i');
?>
<!DOCTYPE html>
<html lang="it">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#9333ea">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <title>Crono Ufficio</title>
  <style>
    :root {
      --bg: #f1f5f9; --card: #ffffff; --text: #1e293b; --muted: #64748b; --faint: #94a3b8;
      --line: #e2e8f0; --chip: #f1f5f9;
      --accent: #9333ea; --accent-soft: #f3e8ff; --accent-ink: #7e22ce;
      --hot: #dc2626; --hot-soft: #fee2e2; --cool: #16a34a; --cool-soft: #dcfce7;
      --s-manual: #2a78d6; --s-learned: #eb6834; --s-default: #c3c2b7;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #0f172a; --card: #1e293b; --text: #f1f5f9; --muted: #94a3b8; --faint: #64748b;
        --line: #334155; --chip: #273449;
        --accent: #a855f7; --accent-soft: #3b1f5c; --accent-ink: #e9d5ff;
        --hot: #f87171; --hot-soft: #45202a; --cool: #4ade80; --cool-soft: #16352a;
        --s-manual: #3987e5; --s-learned: #d95926; --s-default: #52514e;
      }
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { -webkit-text-size-adjust: 100%; }
    body {
      font-family: system-ui, -apple-system, sans-serif;
      background: var(--bg); color: var(--text);
      padding: max(12px, env(safe-area-inset-top)) 14px calc(24px + env(safe-area-inset-bottom));
      max-width: 520px; margin: 0 auto;
      -webkit-tap-highlight-color: transparent;
    }
    button { font-family: inherit; color: inherit; cursor: pointer; touch-action: manipulation; }

    header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    header h1 { font-size: 1.15rem; font-weight: 700; }
    header a { color: var(--accent); text-decoration: none; font-size: .9rem; padding: 8px 0 8px 12px; }

    .card { background: var(--card); border-radius: 18px; padding: 16px; margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .card h2 { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em;
               color: var(--muted); margin-bottom: 12px; font-weight: 600; }

    .flash { border-radius: 14px; padding: 12px 14px; margin-bottom: 12px; font-size: .95rem; font-weight: 500;
             transition: opacity .4s; }
    .flash.ok  { background: var(--cool-soft); color: var(--cool); }
    .flash.err { background: var(--hot-soft); color: var(--hot); }

    /* Hero */
    .hero { text-align: center; padding: 22px 16px 18px; position: relative; }
    .hero .temp { padding-top: 14px; }
    .sp { position: absolute; top: 12px; right: 14px; text-align: right; line-height: 1.1; }
    .sp-label { display: block; font-size: .68rem; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
    .sp-val { font-size: 1.35rem; font-weight: 800; color: var(--accent); font-variant-numeric: tabular-nums; }
    .sp-val.off { color: var(--muted); }
    .hero .temp { font-size: 4.2rem; font-weight: 800; line-height: 1; letter-spacing: -.03em;
                  font-variant-numeric: tabular-nums; }
    .hero .temp small { font-size: 1.6rem; font-weight: 600; color: var(--muted); margin-left: 2px; }
    .pills { display: flex; justify-content: center; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
    .pill { padding: 6px 12px; border-radius: 999px; font-size: .85rem; font-weight: 700;
            background: var(--chip); color: var(--muted); }
    .pill.on  { background: var(--hot-soft); color: var(--hot); }
    .pill.off { background: var(--cool-soft); color: var(--cool); }
    .pill.cmd { background: var(--accent-soft); color: var(--accent-ink); }
    .note { margin-top: 12px; font-size: .8rem; color: var(--faint); line-height: 1.4; }

    /* Override banner */
    .override { display: flex; align-items: center; gap: 12px; border: 2px solid var(--accent); }
    .override .txt { flex: 1; font-size: .95rem; line-height: 1.35; }
    .override .txt b { font-size: 1.15rem; color: var(--accent-ink); }

    /* Segmented mode control */
    .seg { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; background: var(--chip);
           padding: 5px; border-radius: 14px; }
    .seg button { border: 0; background: transparent; border-radius: 10px; min-height: 52px;
                  font-size: 1rem; font-weight: 600; color: var(--muted); }
    .seg button.active { background: var(--card); color: var(--text); box-shadow: 0 1px 4px rgba(0,0,0,.15); }
    .seg button.active[value="off"] { color: var(--hot); }
    .seg button.active[value="manual"], .seg button.active[value="auto"] { color: var(--accent); }

    /* Stepper */
    .stepper { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .stepper button { width: 64px; height: 64px; border-radius: 50%; border: 0; background: var(--chip);
                      font-size: 2rem; font-weight: 500; line-height: 1; }
    .stepper button:active { background: var(--line); }
    .stepper .val { font-size: 2.6rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .stepper .val small { font-size: 1.1rem; color: var(--muted); font-weight: 600; }
    .row-label { font-size: .85rem; color: var(--muted); margin: 16px 0 8px; font-weight: 600; }

    .chips { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
    .chips button { border: 2px solid transparent; background: var(--chip); border-radius: 12px;
                    min-height: 48px; font-size: .95rem; font-weight: 600; }
    .chips button.active { border-color: var(--accent); background: var(--accent-soft); color: var(--accent-ink); }
    .chips .wide { grid-column: span 2; }
    .chips button:disabled { opacity: .4; }

    .btn { display: block; width: 100%; border: 0; border-radius: 14px; min-height: 54px;
           font-size: 1.05rem; font-weight: 700; margin-top: 14px; }
    .btn.primary { background: var(--accent); color: #fff; }
    .btn.primary:active { filter: brightness(.9); }
    .btn.ghost { background: var(--chip); color: var(--text); }
    .btn.danger { background: var(--hot-soft); color: var(--hot); }
    .btn.small { display: inline-block; width: auto; min-height: 44px; padding: 0 16px; margin: 0; font-size: .9rem; }
    .btn-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

    /* Today's schedule */
    .bands { list-style: none; }
    .bands li { display: flex; align-items: center; gap: 10px; padding: 12px 0; border-top: 1px solid var(--line);
                font-variant-numeric: tabular-nums; }
    .bands li:first-child { border-top: 0; }
    .bands .hrs { flex: 1; font-size: 1rem; }
    .bands .tg { font-weight: 700; font-size: 1.05rem; }
    .bands .tag { font-size: .7rem; color: var(--faint); text-transform: uppercase; letter-spacing: .05em; }
    .bands li.now { color: var(--accent-ink); }
    .bands li.now .hrs::before { content: '● '; color: var(--accent); }
    .empty { color: var(--faint); font-style: italic; font-size: .9rem; }
    .foot { text-align: center; margin-top: 8px; }
    .foot a { color: var(--accent); font-weight: 600; text-decoration: none; display: inline-block; padding: 12px; }

    /* Hourly plan chart */
    .legend { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: .78rem; color: var(--muted); margin-bottom: 8px; }
    .legend span { display: inline-flex; align-items: center; gap: 6px; }
    .sw { display: inline-block; width: 12px; height: 12px; border-radius: 3px; }
    .sw-manual { background: var(--s-manual); }
    .sw-learned { background: var(--s-learned); }
    .sw-default { background: var(--s-default); }
    .sw-actual { height: 2px; width: 16px; border-radius: 0; background: var(--text); position: relative; }
    .sw-actual::after { content: ''; position: absolute; left: 4px; top: -3px; width: 8px; height: 8px;
                        border-radius: 50%; background: var(--text); box-shadow: 0 0 0 2px var(--card); }
    .chart { position: relative; margin: 0 -4px; }
    .chart svg { display: block; width: 100%; height: auto; overflow: visible; touch-action: pan-y; }
    .chart .grid { stroke: var(--line); stroke-width: 1; }
    .chart .axis { fill: var(--faint); font-size: 10px; font-variant-numeric: tabular-nums; }
    .chart .axis.now { fill: var(--accent); font-weight: 700; }
    .chart .bar.manual { fill: var(--s-manual); }
    .chart .bar.learned { fill: var(--s-learned); }
    .chart .bar.default { fill: var(--s-default); }
    .chart .bar.dim { opacity: .35; }
    .chart .act { fill: none; stroke: var(--text); stroke-width: 2; stroke-linejoin: round; }
    .chart .dot { fill: var(--text); stroke: var(--card); stroke-width: 2; }
    .chart .hit { fill: transparent; cursor: pointer; }
    .chart .sel { fill: var(--text); opacity: .07; }
    .tip { position: absolute; top: 0; transform: translateX(-50%); pointer-events: none; white-space: nowrap;
           background: var(--text); color: var(--card); border-radius: 10px; padding: 7px 10px;
           font-size: .8rem; line-height: 1.4; box-shadow: 0 4px 12px rgba(0,0,0,.2); z-index: 2; }
    .tip b { font-size: .9rem; }
    .tbl { margin-top: 10px; font-size: .85rem; }
    .tbl summary { color: var(--accent); font-weight: 600; cursor: pointer; padding: 6px 0; }
    .tbl table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
    .tbl th { text-align: left; color: var(--faint); font-size: .72rem; text-transform: uppercase; padding: 6px 4px; }
    .tbl td { padding: 6px 4px; border-top: 1px solid var(--line); }

    [hidden] { display: none !important; }
  </style>
</head>

<body>

  <header>
    <h1>🔥 Crono Ufficio</h1>
    <a href="index.php">Dashboard</a>
  </header>

  <?php if ($flash !== ''): ?>
    <div class="flash <?= $flashOk ? 'ok' : 'err' ?>" id="flash"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>
  <?php if ($loadErr): ?>
    <div class="flash err">Errore lettura crono.db: <?= htmlspecialchars($loadErr) ?></div>
  <?php endif; ?>

  <!-- ── Live status ── -->
  <div class="card hero">
    <div class="sp"><span class="sp-label">Setpoint</span><span class="sp-val" id="s-target">—</span></div>
    <div class="temp"><span id="s-temp">—</span><small>°C</small></div>
    <div class="pills">
      <span class="pill" id="s-heater">Caldaia —</span>
      <span class="pill" id="s-mode"><?= htmlspecialchars(MODE_LABELS[$mode]) ?></span>
    </div>
    <p class="note" id="s-note"></p>
  </div>

  <!-- ── Active temporary command ── -->
  <div class="card override" id="ov-card" <?= $override ? '' : 'hidden' ?>>
    <div class="txt">
      Comando attivo: <b id="ov-label"><?= $override ? htmlspecialchars($override['type'] === 'off' ? 'Antigelo ' . fmt_t($settings['default_target']) . '°C' : fmt_t($override['target']) . '°C') : '' ?></b><br>
      fino alle <strong id="ov-until"><?= $override ? date('H:i', $override['expires_ts']) : '' ?></strong>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="cancel_override">
      <button type="submit" class="btn danger small">Annulla</button>
    </form>
  </div>

  <!-- ── Mode ── -->
  <div class="card">
    <h2>Modalità</h2>
    <form method="post" class="seg" id="mode-form">
      <input type="hidden" name="action" value="mode">
      <?php foreach (MODE_LABELS as $k => $lbl): ?>
        <button type="submit" name="mode" value="<?= $k ?>" class="<?= $mode === $k ? 'active' : '' ?>"><?= $lbl ?></button>
      <?php endforeach; ?>
    </form>

    <form method="post" id="manual-box" <?= $mode === 'manual' ? '' : 'hidden' ?>>
      <input type="hidden" name="action" value="manual_target">
      <div class="row-label">Temperatura manuale</div>
      <div class="stepper" data-min="5" data-max="30" data-step="0.5">
        <button type="button" data-d="-1" aria-label="Diminuisci">−</button>
        <div class="val"><span><?= fmt_t($manualT) ?></span><small>°C</small></div>
        <button type="button" data-d="1" aria-label="Aumenta">+</button>
        <input type="hidden" name="target" value="<?= fmt_t($manualT) ?>">
      </div>
      <button type="submit" class="btn primary">Conferma <span class="echo"><?= fmt_t($manualT) ?></span>°C</button>
    </form>
  </div>

  <!-- ── Temporary command ── -->
  <div class="card">
    <h2>Comando temporaneo</h2>
    <form method="post" id="cmd-form">
      <input type="hidden" name="action" value="command">
      <input type="hidden" name="type" value="set">
      <input type="hidden" name="dur" value="60">
      <div class="stepper" data-min="5" data-max="30" data-step="0.5">
        <button type="button" data-d="-1" aria-label="Diminuisci">−</button>
        <div class="val"><span>21</span><small>°C</small></div>
        <button type="button" data-d="1" aria-label="Aumenta">+</button>
        <input type="hidden" name="target" value="21">
      </div>
      <div class="row-label">Per quanto</div>
      <div class="chips" id="dur-chips">
        <button type="button" data-dur="30">30′</button>
        <button type="button" data-dur="60" class="active">1h</button>
        <button type="button" data-dur="120">2h</button>
        <button type="button" data-dur="180">3h</button>
        <button type="button" data-dur="morning" class="wide">Fino a domani <?= MORNING_HOUR ?>:00</button>
        <button type="button" data-dur="perm" class="wide" <?= $learning ? '' : 'disabled' ?>>Permanente</button>
      </div>
      <p class="note" id="dur-hint"></p>
      <div class="btn-pair">
        <button type="submit" class="btn primary" data-type="set">Scalda a <span class="echo">21</span>°C</button>
        <button type="submit" class="btn ghost" data-type="off">Spegni · <?= fmt_t($settings['default_target']) ?>°</button>
      </div>
    </form>
  </div>

  <!-- ── Today's schedule ── -->
  <div class="card">
    <h2>Programma di oggi · <?= DAY_LABELS[(int)date('N') - 1] ?></h2>
    <?php if ($plan): ?>
      <div class="legend">
        <span><i class="sw sw-manual"></i>Manuale</span>
        <span><i class="sw sw-learned"></i>Appreso</span>
        <span><i class="sw sw-default"></i>Antigelo</span>
        <span><i class="sw sw-actual"></i>Temp. reale</span>
      </div>
      <div class="chart" id="chart">
        <svg id="chart-svg" viewBox="0 0 360 190" role="img"
          aria-label="Target orario di oggi e temperatura reale, dettaglio nella tabella sotto"></svg>
        <div class="tip" id="chart-tip" hidden></div>
      </div>
      <p class="note">Tocca una barra per il dettaglio. Se la linea resta sotto/sopra il target nelle ore
        in cui sei in ufficio, correggi con un comando <strong>Permanente</strong> a quell'ora.</p>
      <?php endif; ?>

      <!-- Antigelo sets the grey bars above, and what OFF holds. -->
      <form method="post">
        <input type="hidden" name="action" value="default_target">
        <div class="row-label">Antigelo (fuori fascia e con OFF)</div>
        <div class="stepper" data-min="5" data-max="30" data-step="0.5">
          <button type="button" data-d="-1" aria-label="Diminuisci">−</button>
          <div class="val"><span><?= fmt_t($settings['default_target']) ?></span><small>°C</small></div>
          <button type="button" data-d="1" aria-label="Aumenta">+</button>
          <input type="hidden" name="target" value="<?= fmt_t($settings['default_target']) ?>">
        </div>
        <button type="submit" class="btn ghost">Salva antigelo <span class="echo"><?= fmt_t($settings['default_target']) ?></span>°C</button>
      </form>

      <?php if ($plan): ?>
      <details class="tbl">
        <summary>Tabella oraria</summary>
        <table>
          <thead><tr><th>Ora</th><th>Target</th><th>Fonte</th><th>Reale</th><th>Caldaia</th></tr></thead>
          <tbody>
            <?php foreach ($plan as $p): $a = $actual[$p['h']]; ?>
              <tr>
                <td><?= sprintf('%02d:00', $p['h']) ?></td>
                <td><?= fmt_t($p['target']) ?>°</td>
                <td><?= ['manual' => 'Manuale', 'learned' => 'Appreso', 'default' => 'Antigelo'][$p['src']] ?></td>
                <td><?= $a ? fmt_t($a['temp']) . '°' : '—' ?></td>
                <td><?= $a ? $a['on'] . '%' : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </details>
      <?php if ($mode !== 'auto'): ?>
        <p class="note">Attenzione: in modalità <?= htmlspecialchars(MODE_LABELS[$mode]) ?> questo programma è ignorato.</p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!$today): ?>
      <p class="empty">Nessuna fascia oggi: antigelo a <?= fmt_t($settings['default_target']) ?>°C.</p>
    <?php else: ?>
      <ul class="bands">
        <?php foreach ($today as $b):
          $tf = $b['time_from']; $tt = $b['time_to'];
          $isNow = $tf <= $tt ? ($nowHm >= $tf && $nowHm <= $tt) : ($nowHm >= $tf || $nowHm <= $tt); ?>
          <li class="<?= $isNow ? 'now' : '' ?>">
            <span class="hrs"><?= htmlspecialchars("$tf – $tt") ?></span>
            <?php if ($b['origin'] === 'learned'): ?><span class="tag">appresa</span><?php endif; ?>
            <span class="tg"><?= fmt_t($b['target']) ?>°C</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="foot"><a href="cronotermostato.php">Modifica programma e impostazioni →</a></div>

  <script>
  (function () {
    // Steppers: ± buttons update the visible value, the hidden input and any ".echo" in the form.
    document.querySelectorAll('.stepper').forEach(function (st) {
      const min = parseFloat(st.dataset.min), max = parseFloat(st.dataset.max), step = parseFloat(st.dataset.step);
      const out = st.querySelector('.val span');
      const inp = st.querySelector('input');
      const echoes = st.closest('form').querySelectorAll('.echo');
      st.addEventListener('click', function (e) {
        const b = e.target.closest('button[data-d]');
        if (!b) return;
        let v = parseFloat(inp.value) + step * parseInt(b.dataset.d, 10);
        v = Math.min(max, Math.max(min, Math.round(v / step) * step));
        const txt = String(+v.toFixed(1));
        inp.value = out.textContent = txt;
        echoes.forEach(function (el) { el.textContent = txt; });
        if (navigator.vibrate) navigator.vibrate(8);
      });
    });

    // Duration chips + which submit button (heat / off) was pressed.
    const cmd = document.getElementById('cmd-form');
    const LEARNING = <?= $learning ? 'true' : 'false' ?>;
    const durHint = document.getElementById('dur-hint');
    function showHint(dur) {
      durHint.textContent = !LEARNING
        ? 'Apprendimento disattivato: i comandi non modificano il programma.'
        : dur === 'perm'
          ? 'Nessun timer: diventa subito una fascia appresa per oggi a quest\'ora.'
          : 'Il comando scade e torna al programma; se lo ripeti spesso, diventa una fascia appresa.';
    }
    showHint('60');
    document.getElementById('dur-chips').addEventListener('click', function (e) {
      const b = e.target.closest('button[data-dur]');
      if (!b || b.disabled) return;
      this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('active', x === b); });
      cmd.elements['dur'].value = b.dataset.dur;
      showHint(b.dataset.dur);
    });
    cmd.addEventListener('submit', function (e) {
      if (e.submitter && e.submitter.dataset.type) cmd.elements['type'].value = e.submitter.dataset.type;
    });

    // Prevent double taps from submitting twice.
    document.querySelectorAll('form').forEach(function (f) {
      f.addEventListener('submit', function () {
        setTimeout(function () { f.querySelectorAll('button').forEach(function (b) { b.disabled = true; }); }, 0);
      });
    });

    // Fade out the flash and drop ?m= from the URL so a refresh doesn't show it again.
    const flash = document.getElementById('flash');
    if (flash) {
      history.replaceState(null, '', location.pathname);
      setTimeout(function () { flash.style.opacity = '0'; }, 3500);
      setTimeout(function () { flash.remove(); }, 4000);
    }

    // ── Hourly plan chart (inline SVG) ──
    (function () {
      const svg = document.getElementById('chart-svg');
      if (!svg) return;
      const PLAN = <?= json_encode($plan) ?>;
      const ACT = <?= json_encode($actual) ?>;
      const NOW_H = <?= (int)date('G') ?>;
      const SRC = { manual: 'Manuale', learned: 'Appreso', default: 'Antigelo' };
      const W = 360, H = 190, L = 24, R = 4, T = 8, B = 20;
      const pw = W - L - R, ph = H - T - B, slot = pw / 24, bw = slot - 2;

      // Bars start at 0 °C; top snaps to the next 5 above the highest value.
      let hi = 0;
      PLAN.forEach(function (p) { hi = Math.max(hi, p.target); });
      ACT.forEach(function (a) { if (a) hi = Math.max(hi, a.temp); });
      const yMax = Math.max(25, Math.ceil((hi + 1) / 5) * 5);
      const y = function (v) { return T + ph - (v / yMax) * ph; };
      const x = function (h) { return L + h * slot; };
      const NS = 'http://www.w3.org/2000/svg';
      function el(name, attrs, parent) {
        const e = document.createElementNS(NS, name);
        for (const k in attrs) e.setAttribute(k, attrs[k]);
        (parent || svg).appendChild(e);
        return e;
      }

      for (let v = 0; v <= yMax; v += 5) {
        el('line', { class: 'grid', x1: L, x2: W - R, y1: y(v), y2: y(v) });
        el('text', { class: 'axis', x: L - 4, y: y(v) + 3, 'text-anchor': 'end' }).textContent = v + '°';
      }
      for (let h = 0; h < 24; h += 3) {
        el('text', { class: 'axis', x: x(h) + slot / 2, y: H - 5, 'text-anchor': 'middle' }).textContent = h;
      }
      el('text', { class: 'axis now', x: x(NOW_H) + slot / 2, y: H - 5, 'text-anchor': 'middle' })
        .textContent = NOW_H % 3 ? '▲' : NOW_H;

      const sel = el('rect', { class: 'sel', y: T, width: slot, height: ph, visibility: 'hidden' });

      // Bars: rounded top, square at the baseline; hours already past are dimmed.
      PLAN.forEach(function (p) {
        const x0 = x(p.h) + 1, y0 = y(p.target), yb = y(0), r = Math.min(3, (yb - y0) / 2);
        el('path', {
          class: 'bar ' + p.src + (p.h < NOW_H ? ' dim' : ''),
          d: 'M' + x0 + ',' + yb + 'V' + (y0 + r) + 'Q' + x0 + ',' + y0 + ' ' + (x0 + r) + ',' + y0 +
             'H' + (x0 + bw - r) + 'Q' + (x0 + bw) + ',' + y0 + ' ' + (x0 + bw) + ',' + (y0 + r) + 'V' + yb + 'Z'
        });
      });

      // Actual temperature: one line per run of consecutive hours with data.
      let run = [];
      function flush() {
        if (run.length > 1) el('polyline', { class: 'act', points: run.join(' ') });
        run = [];
      }
      ACT.forEach(function (a, h) {
        if (a) run.push((x(h) + slot / 2) + ',' + y(a.temp)); else flush();
      });
      flush();
      ACT.forEach(function (a, h) {
        if (a) el('circle', { class: 'dot', cx: x(h) + slot / 2, cy: y(a.temp), r: 3.5 });
      });

      // Tooltip: full-height hit targets per hour; tap or hover shows the detail.
      const tip = document.getElementById('chart-tip');
      const box = document.getElementById('chart');
      function show(h) {
        const p = PLAN[h], a = ACT[h];
        let html = '<b>' + String(h).padStart(2, '0') + ':00–' + String((h + 1) % 24).padStart(2, '0') + ':00</b><br>' +
          'Target ' + p.target.toFixed(1) + '° · ' + SRC[p.src];
        if (a) {
          const d = a.temp - p.target;
          html += '<br>Reale ' + a.temp.toFixed(1) + '° (' + (d >= 0 ? '+' : '') + d.toFixed(1) + ') · caldaia ' + a.on + '%';
        }
        tip.innerHTML = html;
        tip.hidden = false;
        sel.setAttribute('x', x(h));
        sel.setAttribute('visibility', 'visible');
        const bx = box.getBoundingClientRect(), s = bx.width / W;
        const cx = (x(h) + slot / 2) * s, half = tip.offsetWidth / 2;
        tip.style.left = Math.min(bx.width - half, Math.max(half, cx)) + 'px';
        tip.style.top = (-tip.offsetHeight - 6) + 'px';
      }
      function hide() { tip.hidden = true; sel.setAttribute('visibility', 'hidden'); }
      for (let h = 0; h < 24; h++) {
        const hit = el('rect', { class: 'hit', x: x(h), y: 0, width: slot, height: H });
        hit.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') show(h); });
        hit.addEventListener('click', function (e) { e.stopPropagation(); show(h); });
      }
      svg.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') hide(); });
      document.addEventListener('click', hide);
    })();

    // ── Live status polling ──
    const $ = function (id) { return document.getElementById(id); };
    const fmt = function (v) { return (v === null || v === undefined || v === '') ? '—' : Number(v).toFixed(1); };
    const MODES = { auto: 'Auto', manual: 'Manuale', off: 'Spento' };

    async function poll() {
      try {
        const res = await fetch('?api=status', { cache: 'no-store' });
        const d = await res.json();
        const st = d.state || {}, uff = d.ufficio || {};

        const temp = (st.temp !== undefined && st.temp !== null) ? st.temp : uff.temp;
        $('s-temp').textContent = fmt(temp);
        // The watcher's decided target; null means forced OFF (command/mode) or failsafe.
        const tgt = $('s-target');
        const hasTgt = st.target !== undefined && st.target !== null;
        tgt.textContent = hasTgt ? fmt(st.target) + '°' : (st.heater === 'OFF' ? 'OFF' : '—');
        tgt.className = 'sp-val' + (hasTgt ? '' : ' off');

        const heater = (st.heater || uff.heater || '').toUpperCase();
        $('s-heater').textContent = 'Caldaia ' + (heater || '—');
        $('s-heater').className = 'pill' + (heater === 'ON' ? ' on' : heater === 'OFF' ? ' off' : '');

        const modeEl = $('s-mode');
        if (st.source === 'override') {
          modeEl.textContent = 'Comando'; modeEl.className = 'pill cmd';
        } else if (st.mode) {
          modeEl.textContent = MODES[st.mode] || st.mode; modeEl.className = 'pill';
        }
        $('s-note').textContent = (st.note || '') + (st.updated ? ' · ' + st.updated : '');

        const ov = d.override;
        $('ov-card').hidden = !ov;
        if (ov) {
          $('ov-label').textContent = ov.type === 'off' ? 'Antigelo <?= fmt_t($settings['default_target']) ?>°C' : (+Number(ov.target).toFixed(1)) + '°C';
          $('ov-until').textContent = ov.until;
        }
      } catch (e) { /* keep last values on transient errors */ }
    }
    poll();
    setInterval(poll, 10000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  })();
  </script>

</body>

</html>
