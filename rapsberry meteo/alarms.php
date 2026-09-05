<?php
// ── Configuration ────────────────────────────────────────────────────────────
// Persistent storage for rules — survives reboots. Hardcoded absolute path so
// the location is unambiguous regardless of where this script is invoked from.
define('ALARMS_DB',        '/var/www/html/alarms.db');
define('ZBOT_FILE',        __DIR__ . '/zbot.py');
define('ALARM_STATE_FILE', '/dev/shm/alarm_state.json');

$SOURCES = [
  'temp'    => ['label' => 'Full Sun — Temperatura', 'unit' => '°C'],
  'humi'    => ['label' => 'Full Sun — Umidità',     'unit' => '%'],
  'tombra'  => ['label' => 'Ombra — Temperatura',    'unit' => '°C'],
  'hombra'  => ['label' => 'Ombra — Umidità',        'unit' => '%'],
  'tMobile' => ['label' => 'Interno — Temperatura',  'unit' => '°C'],
  'hMobile' => ['label' => 'Interno — Umidità',      'unit' => '%'],
  'power'   => ['label' => 'Fotovoltaico — Potenza', 'unit' => 'W'],
  'tempCpu' => ['label' => 'CPU — Temperatura',      'unit' => '°C'],
  // Raspberry cooling fan state (0 = spenta, 1 = accesa). Written by meteo.py.
  // Use a value condition "Ventola = 1" for an alarm when the fan turns on.
  'fan'     => ['label' => 'Raspberry — Ventola',    'unit' => '0/1'],
  // Office thermostat board (casa/ufficio/data → ufficio table). Merged into
  // the evaluated reading by alarm_watcher.py under these uff_* keys.
  'uff_temp'     => ['label' => 'Ufficio — Temperatura', 'unit' => '°C'],
  'uff_hum'      => ['label' => 'Ufficio — Umidità',     'unit' => '%'],
  'uff_pres'     => ['label' => 'Ufficio — Pressione',   'unit' => 'hPa'],
  'uff_setpoint' => ['label' => 'Ufficio — Setpoint',    'unit' => '°C'],
  // Shelly Pro EM-50 (centralino/status/em1:* → energia table). Merged into
  // the evaluated reading by alarm_watcher.py.
  // "Produzione reale" is the value as the Shelly reports it, senza la soglia
  // di 10 W che azzera il rumore: una regola "Produzione reale = 0" scatta solo
  // quando il contatore legge davvero zero (inverter fermo), non quando la
  // produzione è solo bassa. Abbinala a una finestra oraria per non ricevere
  // l'allarme di notte.
  'pv_raw'     => ['label' => 'Shelly — Produzione reale', 'unit' => 'W'],
  'pv_power'   => ['label' => 'Shelly — Produzione',       'unit' => 'W'],
  'grid_power' => ['label' => 'Shelly — Scambio rete',     'unit' => 'W'],
  'casa_power' => ['label' => 'Shelly — Consumo casa',     'unit' => 'W'],
  // Virtual sources — computed in alarm_watcher.py (clear-sky cooling model,
  // floored at the dew point). Useful for frost-warning rules combined with
  // a Pianificazione condition (e.g. fire at 23:00 if forecast 6am < 2°C).
  'forecast_temp_6am'    => ['label' => 'Previsione 6am — Full Sun', 'unit' => '°C'],
  'forecast_tombra_6am'  => ['label' => 'Previsione 6am — Ombra',    'unit' => '°C'],
  'forecast_tMobile_6am' => ['label' => 'Previsione 6am — Interno',  'unit' => '°C'],
];

$OPS = ['>', '<', '='];

// Optional icon prepended to the Telegram message of a rule. The empty string
// means "no icon". Only values from this list are accepted on save, so the
// stored text can never be arbitrary user input.
$ICONS = ['', '🔔', '⚠️', '🚨', '🔥', '❄️', '🌡️', '💧', '☀️', '🌧️', '💨', '⚡',
          '🔌', '🔋', '🏠', '🖥️', '🌀', '⏰', '📈', '📉', '✅', '❌', 'ℹ️'];

// Special source for a "Non trasmette" condition: matches ANY physical meteo.py
// sensor. Must match STALE_ANY in alarm_watcher.py.
define('STALE_ANY_SOURCE', '__any__');

// ── Allarmi predefiniti ─────────────────────────────────────────────────────
// Regole "chiavi in mano": la condizione è cablata nel watcher, qui si possono
// solo accendere e spegnere. Servono per i casi che non si esprimono con una
// soglia su una sorgente di alarms.php (qui: la batteria non vede più la rete,
// che si legge dalla tabella `batteria` e non dalla riga meteo).
// Le chiavi devono coincidere con PRESET_ALARMS in alarm_watcher.py.
$PRESETS = [
  'battery_no_ac' => [
    'label'           => 'Batteria — Rete AC assente',
    'hint'            => "Scatta quando la batteria è raggiungibile ma la tensione di rete "
                       . "letta dall'inverter è sotto 100 V (blackout o distacco). "
                       . "Se la batteria stessa non risponde l'allarme non scatta: quel caso "
                       . "è un guasto del ponte Modbus, non un'assenza di rete.",
    'icon'            => '⚡',
    'message'         => 'Batteria: rete AC assente.',
    'restore_icon'    => '✅',
    'restore_message' => 'Batteria: rete AC ripristinata.',
  ],
];

// Day-of-week presets exposed in the UI (bit 0=Mon … bit 6=Sun, matches Python).
const DAYS_ALL    = 127;
const DAYS_MONFRI = 31;   // 0b0011111
const DAYS_MONSAT = 63;   // 0b0111111
const DAYS_WEEKEND = 96;  // 0b1100000

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
  $db = new SQLite3(ALARMS_DB);
  $db->exec('PRAGMA foreign_keys = ON');

  // Migration: previous version of the file had a flat `rules` table with
  // source/op/value columns. That schema is incompatible with the new model.
  $cols = [];
  $res = $db->query('PRAGMA table_info(rules)');
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) $cols[$r['name']] = true;
  if (isset($cols['source'])) {
    $db->exec('DROP TABLE rules');
  }

  $db->exec("CREATE TABLE IF NOT EXISTS rules (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    enabled         INTEGER NOT NULL DEFAULT 1,
    message         TEXT    NOT NULL DEFAULT '',
    icon            TEXT    NOT NULL DEFAULT '',
    restore_icon    TEXT    NOT NULL DEFAULT '',
    restore_message TEXT    NOT NULL DEFAULT '',
    bot_id          INTEGER
  )");
  // Telegram bots that can be associated to a rule. A rule with bot_id = NULL
  // uses the default bot from zbot.py (BOT_TOKEN / CHAT_ID). Managed by bots.php.
  $db->exec("CREATE TABLE IF NOT EXISTS bots (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    name    TEXT NOT NULL DEFAULT '',
    token   TEXT NOT NULL DEFAULT '',
    chat_id TEXT NOT NULL DEFAULT ''
  )");
  $db->exec("CREATE TABLE IF NOT EXISTS conditions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    rule_id     INTEGER NOT NULL,
    kind        TEXT    NOT NULL,
    source      TEXT,
    source2     TEXT,
    op          TEXT,
    value       REAL,
    time_from   TEXT,
    time_to     TEXT,
    schedule_at TEXT,
    days_mask   INTEGER DEFAULT 127,
    FOREIGN KEY (rule_id) REFERENCES rules(id) ON DELETE CASCADE
  )");

  // Allarmi predefiniti: solo un interruttore per chiave. Tabella separata dalle
  // regole perché il salvataggio delle regole riscrive `rules` da zero e
  // cancellerebbe i preset a ogni salvataggio.
  $db->exec("CREATE TABLE IF NOT EXISTS presets (
    key     TEXT PRIMARY KEY,
    enabled INTEGER NOT NULL DEFAULT 0
  )");

  // Add days_mask to a pre-existing conditions table (deployed before this column).
  $cCols = [];
  $res = $db->query('PRAGMA table_info(conditions)');
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) $cCols[$r['name']] = true;
  if ($cCols && !isset($cCols['days_mask'])) {
    $db->exec('ALTER TABLE conditions ADD COLUMN days_mask INTEGER DEFAULT 127');
  }
  // Add source2 to a pre-existing conditions table (deployed before the
  // source-vs-source "compare" condition kind).
  if ($cCols && !isset($cCols['source2'])) {
    $db->exec('ALTER TABLE conditions ADD COLUMN source2 TEXT');
  }

  // Add bot_id to a pre-existing rules table (deployed before multi-bot support).
  $rCols = [];
  $res = $db->query('PRAGMA table_info(rules)');
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rCols[$r['name']] = true;
  if ($rCols && !isset($rCols['bot_id'])) {
    $db->exec('ALTER TABLE rules ADD COLUMN bot_id INTEGER');
  }
  // Optional message icon and "rientro" (alarm cleared) message, added later.
  if ($rCols && !isset($rCols['icon'])) {
    $db->exec("ALTER TABLE rules ADD COLUMN icon TEXT NOT NULL DEFAULT ''");
  }
  if ($rCols && !isset($rCols['restore_icon'])) {
    $db->exec("ALTER TABLE rules ADD COLUMN restore_icon TEXT NOT NULL DEFAULT ''");
  }
  if ($rCols && !isset($rCols['restore_message'])) {
    $db->exec("ALTER TABLE rules ADD COLUMN restore_message TEXT NOT NULL DEFAULT ''");
  }

  return $db;
}

// Load configured bots as [id => ['name'=>, 'token'=>, 'chat_id'=>]].
function load_bots($db) {
  $bots = [];
  try {
    $res = $db->query('SELECT id, name, token, chat_id FROM bots ORDER BY name, id');
    while ($b = $res->fetchArray(SQLITE3_ASSOC)) $bots[(int)$b['id']] = $b;
  } catch (Throwable $e) { /* table may not exist yet */ }
  return $bots;
}

// Enabled state of the predefined alarms as [key => bool]. Keys never seen
// before default to off.
function load_preset_state($db) {
  $out = [];
  try {
    $res = $db->query('SELECT key, enabled FROM presets');
    while ($p = $res->fetchArray(SQLITE3_ASSOC)) $out[$p['key']] = ((int)$p['enabled'] === 1);
  } catch (Throwable $e) { /* table may not exist yet */ }
  return $out;
}

// ── Telegram credentials (shared with zbot.py) ──────────────────────────────
function load_bot_credentials() {
  if (!is_readable(ZBOT_FILE)) return [null, null];
  $src = file_get_contents(ZBOT_FILE);
  $token = null; $chat = null;
  if (preg_match('/^\s*BOT_TOKEN\s*=\s*["\']([^"\']+)["\']/m', $src, $m)) $token = $m[1];
  if (preg_match('/^\s*CHAT_ID\s*=\s*(\d+)/m',                $src, $m)) $chat  = $m[1];
  return [$token, $chat];
}

function send_telegram_test($text) {
  list($token, $chat) = load_bot_credentials();
  if (!$token || !$chat) {
    return [false, 'Impossibile leggere BOT_TOKEN o CHAT_ID da zbot.py.'];
  }
  $url = "https://api.telegram.org/bot{$token}/sendMessage";
  $payload = http_build_query(['chat_id' => $chat, 'text' => $text]);
  $ctx = stream_context_create([
    'http' => [
      'method'        => 'POST',
      'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
      'content'       => $payload,
      'timeout'       => 10,
      'ignore_errors' => true,
    ],
  ]);
  $res = @file_get_contents($url, false, $ctx);
  if ($res === false) {
    return [false, 'Errore di rete contattando l\'API Telegram.'];
  }
  $data = json_decode($res, true);
  if (is_array($data) && !empty($data['ok'])) {
    $mid = $data['result']['message_id'] ?? '?';
    return [true, "Messaggio inviato (message_id={$mid})."];
  }
  $desc = (is_array($data) && isset($data['description'])) ? $data['description'] : 'risposta inattesa';
  return [false, 'Telegram ha rifiutato il messaggio: ' . $desc];
}

// ── Rule state reset ────────────────────────────────────────────────────────
/**
 * Drop one rule's entry from the watcher's state file, so an edge-triggered
 * rule rearms (and a scheduled one can fire again today) without waiting for
 * the condition to clear on its own.
 *
 * The file is rewritten IN PLACE, never replaced: it lives in /dev/shm (sticky
 * bit) and belongs to the user running alarm_watcher.py, so www-data may write
 * its contents but cannot rename or unlink it. alarm_watcher.py chmods it 666
 * on every save for exactly this. The watcher re-reads the file at the top of
 * each check, so the reset takes effect on its next cycle.
 */
function reset_rule_state($rule_id) {
  // Rule ids are integers; predefined alarms use the "preset:<key>" state key
  // the watcher writes for them, so both are accepted as-is.
  $key = is_numeric($rule_id) ? (string)(int)$rule_id : (string)$rule_id;
  if (!file_exists(ALARM_STATE_FILE)) {
    return [true, "Nessuno stato memorizzato: la regola #{$key} può già scattare."];
  }
  $fh = @fopen(ALARM_STATE_FILE, 'r+');
  if (!$fh) {
    return [false, 'Impossibile aprire ' . ALARM_STATE_FILE
                 . ' in scrittura. Riavvia meteo-alarms.service (il watcher imposta i permessi al primo salvataggio).'];
  }
  try {
    flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh);
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];
    if (!array_key_exists($key, $data)) {
      return [true, "La regola #{$key} non risulta scattata: nulla da azzerare."];
    }
    unset($data[$key]);
    // FORCE_OBJECT: an emptied state must stay a JSON object, or the watcher's
    // load_state() sees a list, rejects it and logs nothing useful.
    $json = json_encode($data, JSON_FORCE_OBJECT);
    rewind($fh);
    ftruncate($fh, 0);
    if (fwrite($fh, $json) === false) {
      return [false, "Errore scrittura di " . ALARM_STATE_FILE . '.'];
    }
    fflush($fh);
    return [true, "Stato della regola #{$key} azzerato: può scattare di nuovo."];
  } finally {
    flock($fh, LOCK_UN);
    fclose($fh);
  }
}

$message = '';
$messageType = '';

// ── POST handlers ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'reset_rule') {
    $rid = $_POST['reset_id'] ?? '';
    $is_preset = strpos((string)$rid, 'preset:') === 0
              && isset($PRESETS[substr((string)$rid, 7)]);
    if (!is_numeric($rid) && !$is_preset) {
      $message = 'Regola non valida.';
      $messageType = 'err';
    } else {
      list($ok, $info) = reset_rule_state($rid);
      $message = $info;
      $messageType = $ok ? 'ok' : 'err';
    }

  } elseif ($action === 'test_bot') {
    $text = trim($_POST['test_msg'] ?? '');
    if ($text === '') $text = 'Test message from alarms.php';
    list($ok, $info) = send_telegram_test($text);
    $message = $info;
    $messageType = $ok ? 'ok' : 'err';

  } elseif ($action === 'save_presets') {
    // Predefined alarms: nothing to validate beyond "known key, on or off".
    $posted = $_POST['preset'] ?? [];
    try {
      $db = ensure_schema();
      $db->exec('BEGIN');
      $ins = $db->prepare('INSERT OR REPLACE INTO presets (key, enabled) VALUES (:k, :e)');
      $on = 0;
      foreach (array_keys($PRESETS) as $key) {
        $enabled = !empty($posted[$key]) ? 1 : 0;
        $on += $enabled;
        $ins->reset();
        $ins->clear();
        $ins->bindValue(':k', $key,     SQLITE3_TEXT);
        $ins->bindValue(':e', $enabled, SQLITE3_INTEGER);
        $ins->execute();
      }
      $db->exec('COMMIT');
      $message = "Allarmi predefiniti salvati ({$on} attivi).";
      $messageType = 'ok';
    } catch (Throwable $e) {
      $message = 'Errore scrittura alarms.db: ' . $e->getMessage()
               . '. Verifica i permessi (deve essere scrivibile da www-data).';
      $messageType = 'err';
    }

  } elseif ($action === 'save_rules') {
    $rows = $_POST['rule'] ?? [];
    $rule_kept = $cond_kept = $cond_skipped = 0;
    try {
      $db = ensure_schema();
      $db->exec('BEGIN');
      $db->exec('DELETE FROM conditions');
      $db->exec('DELETE FROM rules');

      $valid_bot_ids = array_keys(load_bots($db));
      $insRule = $db->prepare(
        'INSERT INTO rules (enabled, message, icon, restore_icon, restore_message, bot_id)
         VALUES (:e, :m, :i, :ri, :rm, :b)'
      );
      $insCond = $db->prepare(
        'INSERT INTO conditions (rule_id, kind, source, source2, op, value, time_from, time_to, schedule_at, days_mask)
         VALUES (:rid, :k, :s, :s2, :o, :v, :tf, :tt, :sa, :dm)'
      );

      foreach ($rows as $r) {
        $rule_enabled = !empty($r['enabled']) ? 1 : 0;
        $rule_message = trim($r['message'] ?? '');
        $rule_icon    = in_array(($r['icon'] ?? ''), $GLOBALS['ICONS'], true) ? ($r['icon'] ?? '') : '';
        $rule_ricon   = in_array(($r['restore_icon'] ?? ''), $GLOBALS['ICONS'], true) ? ($r['restore_icon'] ?? '') : '';
        $rule_rmsg    = trim($r['restore_message'] ?? '');
        $rule_bot     = $r['bot_id'] ?? '';
        $rule_bot     = (is_numeric($rule_bot) && in_array((int)$rule_bot, $valid_bot_ids, true)) ? (int)$rule_bot : null;
        $conds = $r['cond'] ?? [];

        // Validate and normalise each condition
        $valid = [];
        foreach ($conds as $c) {
          $kind = $c['kind'] ?? '';
          if ($kind === 'value') {
            $source = $c['source'] ?? '';
            $op     = $c['op']     ?? '';
            $val    = trim($c['value'] ?? '');
            if (!isset($GLOBALS['SOURCES'][$source]) || !in_array($op, $GLOBALS['OPS'], true) || $val === '' || !is_numeric($val)) {
              $cond_skipped++; continue;
            }
            $valid[] = ['kind' => 'value', 'source' => $source, 'op' => $op, 'value' => (float)$val];
          } elseif ($kind === 'compare') {
            $source  = $c['source']  ?? '';
            $op      = $c['op']      ?? '';
            $source2 = $c['source2'] ?? '';
            if (!isset($GLOBALS['SOURCES'][$source]) || !in_array($op, $GLOBALS['OPS'], true) || !isset($GLOBALS['SOURCES'][$source2])) {
              $cond_skipped++; continue;
            }
            $valid[] = ['kind' => 'compare', 'source' => $source, 'op' => $op, 'source2' => $source2];
          } elseif ($kind === 'stale') {
            // "Not transmitting": fires when the source is missing/sentinel, or the
            // whole reading is older than `value` minutes (blank = watcher default).
            $source = $c['source'] ?? '';
            if ($source !== STALE_ANY_SOURCE && !isset($GLOBALS['SOURCES'][$source])) {
              $cond_skipped++; continue;
            }
            $val     = trim($c['value'] ?? '');
            $minutes = ($val === '' || !is_numeric($val)) ? null : max(1.0, (float)$val);
            $valid[] = ['kind' => 'stale', 'source' => $source, 'value' => $minutes];
          } elseif ($kind === 'time_window') {
            $tf = trim($c['time_from'] ?? '');
            $tt = trim($c['time_to']   ?? '');
            if (!preg_match('/^\d{2}:\d{2}$/', $tf) || !preg_match('/^\d{2}:\d{2}$/', $tt)) {
              $cond_skipped++; continue;
            }
            $valid[] = ['kind' => 'time_window', 'time_from' => $tf, 'time_to' => $tt,
                        'days_mask' => days_mask_from_post($c['days'] ?? null)];
          } elseif ($kind === 'schedule') {
            $sa = trim($c['schedule_at'] ?? '');
            if (!preg_match('/^\d{2}:\d{2}$/', $sa)) {
              $cond_skipped++; continue;
            }
            $valid[] = ['kind' => 'schedule', 'schedule_at' => $sa,
                        'days_mask' => days_mask_from_post($c['days'] ?? null)];
          } else {
            $cond_skipped++;
          }
        }

        if (!$valid) continue;  // a rule needs at least one valid condition

        $insRule->reset();
        $insRule->clear();
        $insRule->bindValue(':e', $rule_enabled, SQLITE3_INTEGER);
        $insRule->bindValue(':m', $rule_message, SQLITE3_TEXT);
        $insRule->bindValue(':i',  $rule_icon,  SQLITE3_TEXT);
        $insRule->bindValue(':ri', $rule_ricon, SQLITE3_TEXT);
        $insRule->bindValue(':rm', $rule_rmsg,  SQLITE3_TEXT);
        $insRule->bindValue(':b', $rule_bot, $rule_bot === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $insRule->execute();
        $rid = $db->lastInsertRowID();
        $rule_kept++;

        foreach ($valid as $vc) {
          $insCond->reset();
          $insCond->clear();
          $insCond->bindValue(':rid', $rid,         SQLITE3_INTEGER);
          $insCond->bindValue(':k',   $vc['kind'],  SQLITE3_TEXT);
          $insCond->bindValue(':s',   $vc['source']      ?? null, isset($vc['source'])      ? SQLITE3_TEXT  : SQLITE3_NULL);
          $insCond->bindValue(':s2',  $vc['source2']     ?? null, isset($vc['source2'])     ? SQLITE3_TEXT  : SQLITE3_NULL);
          $insCond->bindValue(':o',   $vc['op']          ?? null, isset($vc['op'])          ? SQLITE3_TEXT  : SQLITE3_NULL);
          $insCond->bindValue(':v',   $vc['value']       ?? null, isset($vc['value'])       ? SQLITE3_FLOAT : SQLITE3_NULL);
          $insCond->bindValue(':tf',  $vc['time_from']   ?? null, isset($vc['time_from'])   ? SQLITE3_TEXT  : SQLITE3_NULL);
          $insCond->bindValue(':tt',  $vc['time_to']     ?? null, isset($vc['time_to'])     ? SQLITE3_TEXT  : SQLITE3_NULL);
          $insCond->bindValue(':sa',  $vc['schedule_at'] ?? null, isset($vc['schedule_at']) ? SQLITE3_TEXT  : SQLITE3_NULL);
          $insCond->bindValue(':dm',  $vc['days_mask']   ?? null, isset($vc['days_mask'])  ? SQLITE3_INTEGER : SQLITE3_NULL);
          $insCond->execute();
          $cond_kept++;
        }
      }
      $db->exec('COMMIT');
      $message = "Salvate {$rule_kept} regole con {$cond_kept} condizioni"
               . ($cond_skipped ? " ({$cond_skipped} condizioni incomplete ignorate)." : '.');
      $messageType = 'ok';
    } catch (Throwable $e) {
      $message = 'Errore scrittura alarms.db: ' . $e->getMessage()
               . '. Verifica i permessi (deve essere scrivibile da www-data).';
      $messageType = 'err';
    }
  }
}

// ── Watcher state (used to highlight fired rules) ──────────────────────────
function load_alarm_state() {
  if (!is_readable(ALARM_STATE_FILE)) return [];
  $raw = @file_get_contents(ALARM_STATE_FILE);
  if ($raw === false) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function rule_is_active($state, $rule_id) {
  $key = (string)$rule_id;
  if (!isset($state[$key]) || !is_array($state[$key])) return false;
  $entry = $state[$key];
  if (!empty($entry['fired'])) return true;
  if (!empty($entry['last_fired']) && $entry['last_fired'] === date('Y-m-d')) return true;
  return false;
}

// ── Load current rules with their conditions ───────────────────────────────
$rules = [];
$BOTS = [];
$PRESET_ON = [];
try {
  $db = ensure_schema();
  $BOTS = load_bots($db);
  $PRESET_ON = load_preset_state($db);
  $byId = [];
  $rRes = $db->query('SELECT id, enabled, message, icon, restore_icon, restore_message, bot_id FROM rules ORDER BY id');
  while ($r = $rRes->fetchArray(SQLITE3_ASSOC)) {
    $r['conds'] = [];
    $byId[$r['id']] = $r;
  }
  if ($byId) {
    $cRes = $db->query(
      'SELECT rule_id, kind, source, source2, op, value, time_from, time_to, schedule_at, days_mask
       FROM conditions ORDER BY rule_id, id'
    );
    while ($c = $cRes->fetchArray(SQLITE3_ASSOC)) {
      if (isset($byId[$c['rule_id']])) $byId[$c['rule_id']]['conds'][] = $c;
    }
  }
  $rules = array_values($byId);
} catch (Throwable $e) {
  if (!$message) {
    $message = 'Errore lettura alarms.db: ' . $e->getMessage();
    $messageType = 'err';
  }
}

// ── Render helpers (one per condition kind, plus the rule card) ─────────────
function value_cond_html($SOURCES, $OPS, $i, $j, $c = null) {
  $source = $c['source'] ?? '';
  $op     = $c['op']     ?? '>';
  $value  = isset($c['value']) ? (string)$c['value'] : '';
  ob_start(); ?>
  <tr class="cond-row" data-kind="value">
    <td class="cond-kind">Sensore</td>
    <td>
      <input type="hidden" name="rule[<?= $i ?>][cond][<?= $j ?>][kind]" value="value">
      <select name="rule[<?= $i ?>][cond][<?= $j ?>][source]">
        <?php foreach ($SOURCES as $k => $meta): ?>
          <option value="<?= htmlspecialchars($k) ?>" <?= $k === $source ? 'selected' : '' ?>>
            <?= htmlspecialchars($meta['label']) ?> (<?= htmlspecialchars($meta['unit']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </td>
    <td class="cond-op">
      <select name="rule[<?= $i ?>][cond][<?= $j ?>][op]">
        <?php foreach ($OPS as $o): ?>
          <option value="<?= htmlspecialchars($o) ?>" <?= $o === $op ? 'selected' : '' ?>><?= htmlspecialchars($o) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td class="cond-val">
      <input type="number" step="any" name="rule[<?= $i ?>][cond][<?= $j ?>][value]" value="<?= htmlspecialchars($value) ?>">
    </td>
    <td class="cond-act"><button type="button" class="btn-remove" title="Rimuovi">&times;</button></td>
  </tr>
  <?php
  return ob_get_clean();
}

function compare_cond_html($SOURCES, $OPS, $i, $j, $c = null) {
  $source  = $c['source']  ?? '';
  $op      = $c['op']      ?? '>';
  $source2 = $c['source2'] ?? '';
  ob_start(); ?>
  <tr class="cond-row" data-kind="compare">
    <td class="cond-kind">Confronto</td>
    <td colspan="3">
      <div class="cond-compare">
        <input type="hidden" name="rule[<?= $i ?>][cond][<?= $j ?>][kind]" value="compare">
        <select name="rule[<?= $i ?>][cond][<?= $j ?>][source]">
          <?php foreach ($SOURCES as $k => $meta): ?>
            <option value="<?= htmlspecialchars($k) ?>" <?= $k === $source ? 'selected' : '' ?>>
              <?= htmlspecialchars($meta['label']) ?> (<?= htmlspecialchars($meta['unit']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <select class="cmp-op" name="rule[<?= $i ?>][cond][<?= $j ?>][op]">
          <?php foreach ($OPS as $o): ?>
            <option value="<?= htmlspecialchars($o) ?>" <?= $o === $op ? 'selected' : '' ?>><?= htmlspecialchars($o) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="rule[<?= $i ?>][cond][<?= $j ?>][source2]">
          <?php foreach ($SOURCES as $k => $meta): ?>
            <option value="<?= htmlspecialchars($k) ?>" <?= $k === $source2 ? 'selected' : '' ?>>
              <?= htmlspecialchars($meta['label']) ?> (<?= htmlspecialchars($meta['unit']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </td>
    <td class="cond-act"><button type="button" class="btn-remove" title="Rimuovi">&times;</button></td>
  </tr>
  <?php
  return ob_get_clean();
}

function stale_cond_html($SOURCES, $i, $j, $c = null) {
  $source = $c['source'] ?? '';
  // value holds the max-age threshold in minutes (blank = watcher default).
  $mins   = (isset($c['value']) && $c['value'] !== null && $c['value'] !== '')
            ? (string)(0 + $c['value']) : '';
  ob_start(); ?>
  <tr class="cond-row" data-kind="stale">
    <td class="cond-kind">Non trasmette</td>
    <td>
      <input type="hidden" name="rule[<?= $i ?>][cond][<?= $j ?>][kind]" value="stale">
      <select name="rule[<?= $i ?>][cond][<?= $j ?>][source]">
        <option value="<?= STALE_ANY_SOURCE ?>" <?= $source === STALE_ANY_SOURCE ? 'selected' : '' ?>>
          Qualsiasi sensore meteo
        </option>
        <?php foreach ($SOURCES as $k => $meta): ?>
          <option value="<?= htmlspecialchars($k) ?>" <?= $k === $source ? 'selected' : '' ?>>
            <?= htmlspecialchars($meta['label']) ?> (<?= htmlspecialchars($meta['unit']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </td>
    <td class="cond-op"><span class="cond-hint">ferma da &gt;</span></td>
    <td class="cond-val">
      <input type="number" step="1" min="1" placeholder="15 min"
        name="rule[<?= $i ?>][cond][<?= $j ?>][value]" value="<?= htmlspecialchars($mins) ?>"
        title="Minuti senza dati oltre i quali il sensore è considerato fermo (vuoto = predefinito)">
    </td>
    <td class="cond-act"><button type="button" class="btn-remove" title="Rimuovi">&times;</button></td>
  </tr>
  <?php
  return ob_get_clean();
}

function days_toggle_html($i, $j, $mask) {
  $labels = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
  ob_start(); ?>
  <div class="day-row">
    <?php for ($d = 0; $d < 7; $d++):
      $checked = ($mask & (1 << $d)) ? 'checked' : ''; ?>
      <label><input type="checkbox" name="rule[<?= $i ?>][cond][<?= $j ?>][days][<?= $d ?>]" value="1" <?= $checked ?>><?= $labels[$d] ?></label>
    <?php endfor; ?>
  </div>
  <div class="preset-row">
    <button type="button" class="btn-preset" data-mask="<?= DAYS_ALL ?>">Tutti</button>
    <button type="button" class="btn-preset" data-mask="<?= DAYS_MONFRI ?>">Lun-Ven</button>
    <button type="button" class="btn-preset" data-mask="<?= DAYS_MONSAT ?>">Lun-Sab</button>
    <button type="button" class="btn-preset" data-mask="<?= DAYS_WEEKEND ?>">Weekend</button>
  </div>
  <?php
  return ob_get_clean();
}

function time_window_cond_html($i, $j, $c = null) {
  $tf   = $c['time_from'] ?? '08:00';
  $tt   = $c['time_to']   ?? '20:00';
  $mask = isset($c['days_mask']) ? (int)$c['days_mask'] : DAYS_ALL;
  ob_start(); ?>
  <tr class="cond-row" data-kind="time_window">
    <td class="cond-kind">Finestra</td>
    <td colspan="3">
      <div class="cond-stack">
        <input type="hidden" name="rule[<?= $i ?>][cond][<?= $j ?>][kind]" value="time_window">
        <div class="cond-times">
          <span>Dalle</span>
          <input type="time" name="rule[<?= $i ?>][cond][<?= $j ?>][time_from]" value="<?= htmlspecialchars($tf) ?>">
          <span>alle</span>
          <input type="time" name="rule[<?= $i ?>][cond][<?= $j ?>][time_to]" value="<?= htmlspecialchars($tt) ?>">
        </div>
        <?= days_toggle_html($i, $j, $mask) ?>
      </div>
    </td>
    <td class="cond-act"><button type="button" class="btn-remove" title="Rimuovi">&times;</button></td>
  </tr>
  <?php
  return ob_get_clean();
}

function schedule_cond_html($i, $j, $c = null) {
  $at   = $c['schedule_at'] ?? '08:00';
  $mask = isset($c['days_mask']) ? (int)$c['days_mask'] : DAYS_ALL;
  ob_start(); ?>
  <tr class="cond-row" data-kind="schedule">
    <td class="cond-kind">Pianific.</td>
    <td colspan="3">
      <div class="cond-stack">
        <input type="hidden" name="rule[<?= $i ?>][cond][<?= $j ?>][kind]" value="schedule">
        <div class="cond-times">
          <span>Alle</span>
          <input type="time" name="rule[<?= $i ?>][cond][<?= $j ?>][schedule_at]" value="<?= htmlspecialchars($at) ?>">
          <span class="cond-hint">(una volta al giorno, nei giorni selezionati)</span>
        </div>
        <?= days_toggle_html($i, $j, $mask) ?>
      </div>
    </td>
    <td class="cond-act"><button type="button" class="btn-remove" title="Rimuovi">&times;</button></td>
  </tr>
  <?php
  return ob_get_clean();
}

// Optional emoji picker. An empty value means "no icon".
function icon_select_html($name, $selected, $title) {
  ob_start(); ?>
  <select name="<?= $name ?>" class="rule-icon" title="<?= htmlspecialchars($title) ?>">
    <?php foreach ($GLOBALS['ICONS'] as $ic): ?>
      <option value="<?= htmlspecialchars($ic) ?>" <?= $ic === $selected ? 'selected' : '' ?>>
        <?= $ic === '' ? '–' : htmlspecialchars($ic) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php
  return ob_get_clean();
}

function rule_card_html($SOURCES, $OPS, $BOTS, $i, $r = null, $active = false) {
  $enabled = $r ? !empty($r['enabled']) : true;
  $message = $r['message'] ?? '';
  $icon    = $r['icon']    ?? '';
  $ricon   = $r['restore_icon']    ?? '';
  $rmsg    = $r['restore_message'] ?? '';
  $conds   = $r['conds']   ?? [];
  // Only a saved rule has a state entry to reset; the JS clone template has no id.
  $rid     = (is_array($r) && isset($r['id'])) ? (int)$r['id'] : null;
  $sel_bot = (is_array($r) && isset($r['bot_id']) && $r['bot_id'] !== null) ? (int)$r['bot_id'] : null;
  $cls     = 'rule-card' . ($active ? ' active' : '');
  ob_start(); ?>
  <div class="<?= $cls ?>" data-rule-idx="<?= htmlspecialchars((string)$i) ?>" data-cond-next="<?= count($conds) ?>">
    <div class="rule-head">
      <input type="checkbox" name="rule[<?= $i ?>][enabled]" value="1" <?= $enabled ? 'checked' : '' ?> title="Attiva la regola">
      <?= icon_select_html("rule[$i][icon]", $icon, 'Icona del messaggio di allarme (opzionale)') ?>
      <input type="text" name="rule[<?= $i ?>][message]" value="<?= htmlspecialchars($message) ?>"
        placeholder="Messaggio Telegram (opzionale)">
      <select name="rule[<?= $i ?>][bot_id]" class="rule-bot" title="Bot Telegram di destinazione">
        <option value="">Bot predefinito</option>
        <?php foreach ($BOTS as $bid => $b): ?>
          <option value="<?= (int)$bid ?>" <?= $sel_bot === (int)$bid ? 'selected' : '' ?>>
            <?= htmlspecialchars(($b['name'] ?? '') !== '' ? $b['name'] : ('Bot #' . $bid)) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($rid !== null): ?>
        <!-- Submits the small #reset-form below, not this one: the rules are
             left exactly as they are on screen, only the fired state is cleared. -->
        <button type="submit" form="reset-form" name="reset_id" value="<?= $rid ?>"
          class="btn-reset" title="Azzera lo scatto: la regola torna a essere verificata">&#8635;</button>
      <?php endif; ?>
      <button type="button" class="btn-remove-rule" title="Rimuovi regola">&times;</button>
    </div>

    <div class="rule-restore">
      <span class="restore-label" title="Inviato quando la condizione rientra">Rientro</span>
      <?= icon_select_html("rule[$i][restore_icon]", $ricon, 'Icona del messaggio di rientro (opzionale)') ?>
      <input type="text" name="rule[<?= $i ?>][restore_message]" value="<?= htmlspecialchars($rmsg) ?>"
        placeholder="Messaggio di rientro (opzionale — lascia vuoto per non inviarlo)">
    </div>

    <table class="cond-table">
      <tbody>
        <?php foreach ($conds as $j => $c): ?>
          <?php
          if     ($c['kind'] === 'value')       echo value_cond_html($SOURCES, $OPS, $i, $j, $c);
          elseif ($c['kind'] === 'compare')     echo compare_cond_html($SOURCES, $OPS, $i, $j, $c);
          elseif ($c['kind'] === 'stale')       echo stale_cond_html($SOURCES, $i, $j, $c);
          elseif ($c['kind'] === 'time_window') echo time_window_cond_html($i, $j, $c);
          elseif ($c['kind'] === 'schedule')    echo schedule_cond_html($i, $j, $c);
          ?>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="cond-add">
      <button type="button" class="btn-add-cond" data-kind="value">+ Sensore</button>
      <button type="button" class="btn-add-cond" data-kind="compare">+ Confronto</button>
      <button type="button" class="btn-add-cond" data-kind="stale">+ Non trasmette</button>
      <button type="button" class="btn-add-cond" data-kind="time_window">+ Finestra oraria</button>
      <button type="button" class="btn-add-cond" data-kind="schedule">+ Pianificazione</button>
      <span class="cond-hint">Le condizioni sono combinate in AND.</span>
    </div>
  </div>
  <?php
  return ob_get_clean();
}

// Templates for client-side cloning. Use placeholders the JS fills in.
$tpl_rule           = rule_card_html($SOURCES, $OPS, $BOTS, '__I__');
$tpl_cond_value     = value_cond_html($SOURCES, $OPS, '__I__', '__J__');
$tpl_cond_compare   = compare_cond_html($SOURCES, $OPS, '__I__', '__J__');
$tpl_cond_stale     = stale_cond_html($SOURCES, '__I__', '__J__');
$tpl_cond_window    = time_window_cond_html('__I__', '__J__');
$tpl_cond_schedule  = schedule_cond_html('__I__', '__J__');
?>
<!DOCTYPE html>
<html lang="it">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stazione Meteo — Allarmi</title>
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
      color: #dc2626;
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

    /* Rule cards */
    .rule-card {
      border: 1px solid #e2e8f0;
      border-radius: .5rem;
      padding: .85rem 1rem 1rem;
      margin-bottom: .9rem;
      background: #f8fafc;
      transition: background .15s, border-color .15s;
    }

    .rule-card.active {
      background: #dcfce7;
      border-color: #86efac;
    }

    .rule-head {
      display: flex;
      align-items: center;
      gap: .65rem;
      margin-bottom: .8rem;
    }

    .rule-head input[type="text"] { flex: 1; }
    .rule-head .rule-bot { width: auto; flex: 0 0 auto; max-width: 12rem; }
    .rule-icon { width: auto; flex: 0 0 auto; font-size: 1rem; padding: .3rem .2rem; }

    .rule-restore {
      display: flex;
      align-items: center;
      gap: .65rem;
      margin: 0 0 .6rem;
    }

    .rule-restore input[type="text"] { flex: 1; }

    .restore-label {
      font-size: .8rem;
      color: #64748b;
      flex: 0 0 auto;
    }

    .cond-table { width: 100%; border-collapse: collapse; }
    .cond-table td {
      padding: .35rem .5rem;
      font-size: .85rem;
      vertical-align: middle;
    }
    .cond-table tr:not(:last-child) td { border-bottom: 1px solid #f1f5f9; }
    .cond-table tr:first-child td { border-top: 1px solid #f1f5f9; }

    .cond-kind {
      width: 6rem;
      color: #64748b;
      font-weight: 600;
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .cond-op  { width: 4rem; }
    .cond-val { width: 7rem; }
    .cond-act { width: 2.5rem; text-align: center; }

    .cond-compare {
      display: flex;
      align-items: center;
      gap: .4rem;
      flex-wrap: wrap;
    }
    .cond-compare select { width: auto; flex: 1 1 9rem; min-width: 8rem; }
    .cond-compare .cmp-op { flex: 0 0 3.5rem; min-width: 3.5rem; text-align: center; }

    .cond-stack {
      display: flex;
      flex-direction: column;
      gap: .35rem;
      width: 100%;
    }

    .cond-times {
      display: flex;
      align-items: center;
      gap: .4rem;
      flex-wrap: wrap;
    }
    .cond-times input[type="time"] { width: 7rem; }
    .cond-times span { color: #64748b; font-size: .8rem; }
    .cond-hint { color: #94a3b8; font-size: .75rem; font-style: italic; }

    .day-row {
      display: flex;
      gap: .4rem;
      flex-wrap: wrap;
    }
    .day-row label {
      display: inline-flex;
      align-items: center;
      gap: .25rem;
      font-size: .75rem;
      color: #475569;
      user-select: none;
    }
    .day-row input[type="checkbox"] { width: .9rem; height: .9rem; }

    .preset-row {
      display: flex;
      gap: .3rem;
      flex-wrap: wrap;
    }
    .btn-preset {
      background: #ffffff;
      color: #475569;
      border: 1px solid #cbd5e1;
      border-radius: .35rem;
      padding: .15rem .55rem;
      font-size: .72rem;
      cursor: pointer;
      font-family: inherit;
    }
    .btn-preset:hover { background: #f1f5f9; }

    .cond-add {
      margin-top: .55rem;
      display: flex;
      gap: .4rem;
      align-items: center;
      flex-wrap: wrap;
    }

    /* Form controls */
    input[type="text"], input[type="number"], input[type="time"], select {
      padding: .35rem .5rem;
      font-size: .85rem;
      font-family: inherit;
      border: 1px solid #cbd5e1;
      border-radius: .35rem;
      background: #ffffff;
      color: #1e293b;
      width: 100%;
    }
    input:focus, select:focus {
      outline: none;
      border-color: #0284c7;
      box-shadow: 0 0 0 2px rgba(2, 132, 199, .15);
    }
    input[type="checkbox"] { width: 1.1rem; height: 1.1rem; cursor: pointer; }

    /* Buttons */
    button.primary, button[type="submit"] {
      background: #0284c7;
      color: #ffffff;
      border: none;
      border-radius: .45rem;
      padding: .55rem 1.15rem;
      font-size: .9rem;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
    }
    button.primary:hover, button[type="submit"]:hover { background: #0369a1; }

    button.secondary, .btn-add-cond, #add-rule {
      background: #e2e8f0;
      color: #1e293b;
      border: none;
      border-radius: .45rem;
      padding: .4rem .85rem;
      font-size: .8rem;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
    }
    button.secondary:hover, .btn-add-cond:hover, #add-rule:hover { background: #cbd5e1; }

    .btn-remove, .btn-remove-rule {
      background: transparent;
      color: #dc2626;
      border: 1px solid #fecaca;
      border-radius: .35rem;
      padding: 0;
      width: 1.8rem; height: 1.8rem;
      font-size: 1rem; line-height: 1;
      cursor: pointer;
    }
    .btn-remove:hover, .btn-remove-rule:hover { background: #fee2e2; }

    /* Rearm: same footprint as the remove button, but never red — it undoes a
       trigger, it doesn't delete anything. */
    .btn-reset {
      background: transparent;
      color: #0369a1;
      border: 1px solid #bae6fd;
      border-radius: .35rem;
      padding: 0;
      width: 1.8rem; height: 1.8rem;
      font-size: 1rem; line-height: 1;
      cursor: pointer;
    }

    .btn-reset:hover { background: #e0f2fe; }

    .actions {
      display: flex;
      justify-content: flex-end;
      gap: .75rem;
      align-items: center;
      margin-top: 1rem;
    }

    .dirty-flag {
      color: #ea580c;
      font-size: .8rem;
      font-weight: 600;
      margin-right: auto;
    }

    .msg {
      border-radius: .5rem;
      padding: .65rem 1rem;
      margin-bottom: 1rem;
      font-size: .85rem;
    }
    .msg.ok  { background: #dcfce7; color: #16a34a; }
    .msg.err { background: #fee2e2; color: #dc2626; }

    .help { font-size: .78rem; color: #64748b; margin-top: .5rem; line-height: 1.45; }

    .empty {
      padding: 1.25rem;
      color: #94a3b8;
      text-align: center;
      font-style: italic;
      border: 1px dashed #cbd5e1;
      border-radius: .5rem;
      margin-bottom: .9rem;
    }

    /* Predefined alarms — toggle only */
    .preset-row {
      display: flex;
      align-items: flex-start;
      gap: .6rem;
      border: 1px solid #e2e8f0;
      border-radius: .5rem;
      padding: .6rem .75rem;
      margin-bottom: .5rem;
    }
    .preset-toggle {
      display: flex;
      align-items: flex-start;
      gap: .6rem;
      flex: 1;
      cursor: pointer;
    }
    .preset-row.active { background: #dcfce7; border-color: #86efac; }
    .preset-row input[type="checkbox"] { margin-top: .15rem; }
    .preset-icon { font-size: 1rem; line-height: 1.2; }
    .preset-body { display: flex; flex-direction: column; gap: .15rem; }
    .preset-label { font-size: .9rem; font-weight: 600; }
    .preset-hint { font-size: .78rem; color: #64748b; line-height: 1.4; }

    .test-row { display: flex; gap: .5rem; align-items: center; }
    .test-row input[type="text"] { flex: 1; }
  </style>
</head>

<body>

  <header>
    <h1>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
        stroke-linejoin="round" aria-hidden="true">
        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
        <line x1="12" y1="9" x2="12" y2="13" />
        <line x1="12" y1="17" x2="12.01" y2="17" />
      </svg>
      Configurazione Allarmi
    </h1>
    <div style="display:flex;gap:1rem;align-items:center">
      <a class="back-link" href="bots.php">Bot Telegram →</a>
      <a class="back-link" href="index.php">← Dashboard</a>
    </div>
  </header>

  <?php if ($message): ?>
    <div class="msg <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <form method="post" class="panel">
    <input type="hidden" name="action" value="save_presets">
    <h2>Allarmi predefiniti</h2>
    <?php $preset_state = load_alarm_state(); ?>
    <?php foreach ($PRESETS as $key => $pr): ?>
      <?php $active = rule_is_active($preset_state, 'preset:' . $key); ?>
      <div class="preset-row<?= $active ? ' active' : '' ?>">
        <label class="preset-toggle">
          <input type="checkbox" name="preset[<?= htmlspecialchars($key) ?>]" value="1"
            <?= !empty($PRESET_ON[$key]) ? 'checked' : '' ?>>
          <span class="preset-icon"><?= htmlspecialchars($pr['icon']) ?></span>
          <span class="preset-body">
            <span class="preset-label"><?= htmlspecialchars($pr['label']) ?></span>
            <span class="preset-hint"><?= htmlspecialchars($pr['hint']) ?></span>
          </span>
        </label>
        <?php if ($active): ?>
          <!-- Same as the rule cards: submits #reset-form, clearing only the
               latched state so the alarm can fire again. -->
          <button type="submit" form="reset-form" name="reset_id" value="preset:<?= htmlspecialchars($key) ?>"
            class="btn-reset" title="Azzera lo scatto: l'allarme torna a essere verificato">&#8635;</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="actions">
      <button type="submit">Salva predefiniti</button>
    </div>
    <p class="help">
      Questi allarmi hanno la condizione già cablata in <code>alarm_watcher.py</code>:
      si possono solo attivare o disattivare, non modificare. Usano il bot predefinito
      di <code>zbot.py</code> e si comportano come le regole normali (anti-flapping di
      2 letture, messaggio di rientro quando la condizione si risolve).
    </p>
  </form>

  <form method="post">
    <input type="hidden" name="action" value="save_rules">
    <div class="panel">
      <h2>Regole di allarme</h2>

      <div id="rules-container">
        <?php if (!$rules): ?>
          <div class="empty" id="empty-state">Nessuna regola. Aggiungine una con il pulsante qui sotto.</div>
        <?php else: ?>
          <?php $alarm_state = load_alarm_state(); ?>
          <?php foreach ($rules as $i => $r) echo rule_card_html($SOURCES, $OPS, $BOTS, $i, $r, rule_is_active($alarm_state, $r['id'])); ?>
        <?php endif; ?>
      </div>

      <button type="button" id="add-rule">+ Aggiungi regola</button>

      <p class="help">
        Una regola scatta quando <strong>tutte</strong> le sue condizioni sono vere.
        Una condizione <em>Sensore</em> confronta una grandezza con un valore fisso;
        una condizione <em>Confronto</em> mette a confronto due grandezze tra loro
        (es. <code>Full Sun Temperatura &lt; Ombra Temperatura</code>).
        Una condizione <em>Non trasmette</em> scatta quando un sensore smette di
        inviare dati: valore assente/non valido, oppure ultima lettura più vecchia
        dei minuti indicati (lascia vuoto per il valore predefinito, 15 min).
        Scegli <em>Qualsiasi sensore meteo</em> come sorgente per un allarme
        generico che scatta se <strong>uno qualsiasi</strong> dei sensori gestiti
        da <code>meteo.py</code> non trasmette (es. da oltre 30 minuti).
        Le regole senza pianificazione inviano il messaggio quando le condizioni
        diventano vere (anti-flapping di 2 letture) e si <strong>riarmano automaticamente</strong>
        quando tornano false: ogni nuovo fronte di salita genera un altro messaggio.
        Le regole con condizione di tipo <em>Pianificazione</em> inviano il messaggio
        una volta al giorno al raggiungimento dell'orario, se le altre condizioni
        sono soddisfatte. La riga diventa <span style="background:#dcfce7;padding:0 .25rem;border-radius:.2rem;">verde</span>
        quando la regola è attiva.
        Con il menu a tendina puoi inviare il messaggio a un <strong>bot Telegram</strong>
        specifico (configurabili in <a href="bots.php">Bot Telegram</a>); lascia
        <em>Bot predefinito</em> per usare quello di <code>zbot.py</code>.
      </p>
    </div>

    <div class="actions">
      <span id="dirty-flag" class="dirty-flag" hidden>● Modifiche non salvate</span>
      <button type="submit">Salva regole</button>
    </div>
  </form>

  <form id="reset-form" method="post">
    <input type="hidden" name="action" value="reset_rule">
  </form>

  <form method="post" class="panel">
    <h2>Test bot Telegram</h2>
    <input type="hidden" name="action" value="test_bot">
    <div class="test-row">
      <input type="text" name="test_msg" placeholder="Test message from alarms.php"
        value="<?= htmlspecialchars($_POST['test_msg'] ?? '') ?>">
      <button type="submit">Invia test</button>
    </div>
    <p class="help">
      Invia un messaggio al chat configurato in <code>zbot.py</code> per verificare credenziali e connettività.
    </p>
  </form>

  <script>
  (function () {
    const tplRule     = <?= json_encode($tpl_rule,          JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const tplValue    = <?= json_encode($tpl_cond_value,    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const tplCompare  = <?= json_encode($tpl_cond_compare,  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const tplStale    = <?= json_encode($tpl_cond_stale,    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const tplWindow   = <?= json_encode($tpl_cond_window,   JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const tplSchedule = <?= json_encode($tpl_cond_schedule, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const condTpls    = { value: tplValue, compare: tplCompare, stale: tplStale, time_window: tplWindow, schedule: tplSchedule };

    const container = document.getElementById('rules-container');
    let nextRuleIdx = <?= count($rules) ?>;

    function clearEmptyState() {
      const empty = document.getElementById('empty-state');
      if (empty) empty.remove();
    }

    document.getElementById('add-rule').addEventListener('click', function () {
      clearEmptyState();
      const html = tplRule.split('__I__').join(String(nextRuleIdx++));
      const wrap = document.createElement('div');
      wrap.innerHTML = html;
      container.appendChild(wrap.firstElementChild);
    });

    container.addEventListener('click', function (e) {
      if (e.target.matches('.btn-remove-rule')) {
        e.target.closest('.rule-card').remove();
        return;
      }
      if (e.target.matches('.btn-remove')) {
        e.target.closest('tr').remove();
        return;
      }
      const addBtn = e.target.closest('.btn-add-cond');
      if (addBtn) {
        const card = addBtn.closest('.rule-card');
        const i    = card.dataset.ruleIdx;
        const j    = card.dataset.condNext;
        card.dataset.condNext = String(parseInt(j, 10) + 1);
        const kind = addBtn.dataset.kind;
        const html = condTpls[kind].split('__I__').join(i).split('__J__').join(j);
        const tbody = card.querySelector('.cond-table tbody');
        const tmp = document.createElement('tbody');
        tmp.innerHTML = html;
        tbody.appendChild(tmp.firstElementChild);
        return;
      }
      const preset = e.target.closest('.btn-preset');
      if (preset) {
        const mask = parseInt(preset.dataset.mask, 10);
        const stack = preset.closest('.cond-stack');
        const dayRow = stack && stack.querySelector('.day-row');
        if (dayRow) {
          dayRow.querySelectorAll('input[type="checkbox"]').forEach(function (cb, k) {
            cb.checked = !!(mask & (1 << k));
          });
        }
      }
    });

    // ── Unsaved-changes guard ──────────────────────────────────────────────
    // Removing/adding/editing a rule only changes the DOM; nothing is written
    // until "Salva regole" submits the form. Warn before a reload/navigation
    // would silently discard those pending changes.
    const rulesForm = container.closest('form');
    const dirtyFlag = document.getElementById('dirty-flag');
    let dirty = false;
    function markDirty() {
      if (dirty) return;
      dirty = true;
      if (dirtyFlag) dirtyFlag.hidden = false;
    }

    // Field edits (text/number/time/select/checkbox).
    rulesForm.addEventListener('input', markDirty);
    rulesForm.addEventListener('change', markDirty);
    // Structural changes (add rule, remove rule/condition, add condition, day preset).
    document.getElementById('add-rule').addEventListener('click', markDirty);
    container.addEventListener('click', function (e) {
      if (e.target.closest('.btn-remove-rule, .btn-remove, .btn-add-cond, .btn-preset')) markDirty();
    });

    // The predefined alarms live in their own form with their own save button:
    // toggling one and leaving without saving must warn just the same.
    const presetInput = document.querySelector('input[name="action"][value="save_presets"]');
    const presetForm = presetInput ? presetInput.form : null;
    if (presetForm) {
      presetForm.addEventListener('change', markDirty);
      presetForm.addEventListener('submit', function () { dirty = false; });
    }

    // Saving clears the dirty state so the submit itself isn't blocked.
    rulesForm.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = '';  // triggers the browser's native confirmation dialog
    });
  })();
  </script>

</body>

</html>
