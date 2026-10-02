<?php
// Mobile-first control page for the office thermostat. Quick actions only —
// the weekly schedule is still edited on cronotermostato.php. Reads/writes the
// same crono.db that crono_watcher.py polls (~30s), so every change here is
// picked up on the watcher's next cycle.
//
// The remote copy of this page (server_remoto/crono.php) goes through here too:
// crono_remote.py publishes ?api=snapshot over MQTT and relays the remote
// page's form posts back to this file with reply=json. The HTML lives in
// crono_ui.php, shared by both.
// PHP on the Pi defaults to UTC while the system (and crono_watcher.py) runs on
// local time: without this, hours, command timestamps and learning are 2h off.
date_default_timezone_set('Europe/Rome');

require __DIR__ . '/crono_ui.php';

define('CRONO_DB',    '/var/www/html/crono.db');
define('CRONO_STATE', '/dev/shm/crono_state.json');
define('METEO_DB',    '/dev/shm/meteo.db');

const PERM_WINDOW_MIN = 120;  // matches crono_watcher.py: span a "permanente" command teaches
const REMOTE_URL = 'https://cesana.steplab.net/crono.php';

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

function clamp_target($raw, $fallback) {
  $raw = str_replace(',', '.', trim((string)$raw));
  return is_numeric($raw) ? max(5.0, min(30.0, round((float)$raw * 2) / 2)) : $fallback;
}

function override_view($ov) {
  return $ov ? ['type' => $ov['type'], 'target' => $ov['target'], 'until' => date('H:i', $ov['expires_ts'])] : null;
}

// Everything the page shows, in the shape render_crono_page() takes. Also what
// crono_remote.py publishes for the remote page. Throws if crono.db can't be read.
function build_snapshot() {
  $db = open_db();
  $settings = load_settings($db);
  // Today's enabled bands, manual then learned (same order the watcher evaluates).
  $bit = 1 << ((int)date('N') - 1);
  $today = [];
  $res = @$db->query("SELECT time_from, time_to, target, origin FROM bands
                      WHERE enabled = 1 AND (days_mask & $bit)
                      ORDER BY time_from, CASE WHEN origin = 'learned' THEN 1 ELSE 0 END");
  if ($res) while ($b = $res->fetchArray(SQLITE3_ASSOC)) $today[] = $b;
  $uff = latest_ufficio();
  return [
    'settings' => [
      'mode'           => $settings['mode'],
      'manual_target'  => (float)$settings['manual_target'],
      'default_target' => (float)$settings['default_target'],
      'learning'       => ($settings['learning'] ?? 'on') !== 'off',
    ],
    'override' => override_view(active_override($db)),
    'state'    => load_state(),
    'ufficio'  => $uff ? ['temp' => $uff['temp'] ?? null, 'heater' => $uff['heater'] ?? null,
                          'timestamp' => $uff['timestamp'] ?? null] : null,
    'today'    => $today,
    'plan'     => hourly_plan($db, $settings['default_target']),
    'actual'   => hourly_actual(),
    'now'      => ['hm' => date('H:i'), 'hour' => (int)date('G'), 'dow' => (int)date('N')],
  ];
}

// ── JSON APIs: status (polled by the page), snapshot (for crono_remote.py) ──
if (($_GET['api'] ?? '') === 'status') {
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  $ov = null;
  try { $ov = override_view(active_override(open_db())); } catch (Throwable $e) {}
  echo json_encode(['state' => load_state(), 'ufficio' => latest_ufficio(), 'override' => $ov]);
  exit;
}
if (($_GET['api'] ?? '') === 'snapshot') {
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  try {
    echo json_encode(build_snapshot());
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
  }
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
      $target = $type === 'set' ? clamp_target($_POST['target'] ?? '', 19.0) : null;
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
  // crono_remote.py relays the remote page's posts here and wants the outcome, not a redirect.
  if (($_POST['reply'] ?? '') === 'json') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok, 'msg' => $msg]);
    exit;
  }
  header('Location: crono.php?' . http_build_query(['m' => $msg, 'ok' => $ok ? 1 : 0]), true, 303);
  exit;
}

// ── Page ────────────────────────────────────────────────────────────────────
$snap = [];
$loadErr = '';
try {
  $snap = build_snapshot();
} catch (Throwable $e) {
  $loadErr = 'Errore lettura crono.db: ' . $e->getMessage();
}
render_crono_page($snap, [
  'flash'          => (string)($_GET['m'] ?? ''),
  'flash_ok'       => ($_GET['ok'] ?? '1') === '1',
  'load_err'       => $loadErr,
  'schedule_href'  => 'cronotermostato.php',
  'schedule_label' => 'Modifica programma e impostazioni →',
  'other_href'     => REMOTE_URL,
  'other_label'    => 'Versione remota (da fuori casa) →',
]);
