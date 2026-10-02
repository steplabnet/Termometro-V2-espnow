<?php
// Remote copy of the Pi's thermostat page (rapsberry meteo/crono.php).
//
// The Pi stays the thermostat; this page is a remote control for it. It never
// touches a database: crono_remote.py on the Pi publishes the page snapshot
// retained on casa/crono/state, and every form post here is sent to the Pi on
// casa/crono/cmd and confirmed on casa/crono/ack/<id>. The HTML is the same
// crono_ui.php the Pi renders (deploy.sh copies it over).
//
// If the internet drops, use the Pi's own page on the local network instead.

declare(strict_types=1);
date_default_timezone_set('Europe/Rome');

require __DIR__ . '/crono_ui.php';
require __DIR__ . '/mqtt_lite.php';
require __DIR__ . '/crono_secret.php';  // CRONO_PASS_HASH, CRONO_COOKIE_KEY, CRONO_MQTT_*

const TOPIC_STATE  = 'casa/crono/state';
const TOPIC_STATUS = 'casa/crono/status';
const TOPIC_CMD    = 'casa/crono/cmd';
const TOPIC_ACK    = 'casa/crono/ack/';
const STALE_AFTER  = 90;     // snapshot older than this ⇒ the Pi is not reaching us
const ACK_WAIT     = 8.0;    // seconds to wait for the Pi to confirm a command
const LOCAL_URL    = 'http://stazionemeteo.local/crono.php';
const LOCAL_EDITOR = 'http://stazionemeteo.local/cronotermostato.php';
const AUTH_COOKIE  = 'crono_auth';
const AUTH_DAYS    = 180;

// ── Auth: password → signed cookie (no PHP session, so it survives for months) ─
function auth_ok(): bool {
  $c = (string)($_COOKIE[AUTH_COOKIE] ?? '');
  if (!preg_match('/^(\d+)\.([0-9a-f]{64})$/', $c, $m)) return false;
  return (int)$m[1] > time() && hash_equals(hash_hmac('sha256', $m[1], CRONO_COOKIE_KEY), $m[2]);
}

function set_auth_cookie(): void {
  $exp = (string)(time() + AUTH_DAYS * 86400);
  setcookie(AUTH_COOKIE, $exp . '.' . hash_hmac('sha256', $exp, CRONO_COOKIE_KEY), [
    'expires' => (int)$exp, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
  ]);
}

function login_page(string $err): void {
  header('Cache-Control: no-store'); ?>
<!DOCTYPE html>
<html lang="it"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Crono Ufficio</title>
<style>
  :root { --bg:#f1f5f9; --card:#fff; --text:#1e293b; --muted:#64748b; --accent:#9333ea; --hot:#dc2626; --line:#e2e8f0; }
  @media (prefers-color-scheme: dark) { :root { --bg:#0f172a; --card:#1e293b; --text:#f1f5f9; --muted:#94a3b8; --accent:#a855f7; --hot:#f87171; --line:#334155; } }
  body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 14vh 16px; }
  form { max-width: 360px; margin: 0 auto; background: var(--card); border-radius: 18px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  h1 { font-size: 1.15rem; margin: 0 0 16px; }
  input { width: 100%; box-sizing: border-box; font-size: 1.1rem; padding: 14px; border: 1px solid var(--line); border-radius: 12px; background: var(--bg); color: var(--text); }
  button { width: 100%; margin-top: 14px; min-height: 52px; border: 0; border-radius: 14px; background: var(--accent); color: #fff; font-size: 1.05rem; font-weight: 700; }
  p { color: var(--hot); margin: 12px 0 0; font-size: .9rem; }
</style></head>
<body><form method="post">
  <h1>🔥 Crono Ufficio</h1>
  <input type="password" name="password" placeholder="Password" autocomplete="current-password" autofocus required>
  <button type="submit">Entra</button>
  <?php if ($err): ?><p><?= htmlspecialchars($err) ?></p><?php endif; ?>
</form></body></html>
<?php
}

if (!auth_ok()) {
  if (($_GET['api'] ?? '') !== '') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'login']);
    exit;
  }
  $err = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (password_verify((string)$_POST['password'], CRONO_PASS_HASH)) {
      set_auth_cookie();
      header('Location: crono.php', true, 303);
      exit;
    }
    sleep(2);
    $err = 'Password errata';
  }
  login_page($err);
  exit;
}

// ── Broker ──────────────────────────────────────────────────────────────────
function broker(): MqttLite {
  $m = new MqttLite(CRONO_MQTT_HOST, CRONO_MQTT_PORT, CRONO_MQTT_USER, CRONO_MQTT_PASS);
  $m->connect();
  return $m;
}

// The Pi's retained snapshot and online flag. Returns [snap|null, error].
function fetch_snapshot(): array {
  try {
    $m = broker();
    $m->subscribe([TOPIC_STATUS, TOPIC_STATE]);
    $got = $m->collect([TOPIC_STATUS, TOPIC_STATE], 3.0, fn($g) => isset($g[TOPIC_STATE]));
    $m->close();
  } catch (Throwable $e) {
    return [null, 'Broker MQTT non raggiungibile: ' . $e->getMessage()];
  }
  $snap = json_decode($got[TOPIC_STATE] ?? '', true);
  if (!is_array($snap)) return [null, 'Nessun dato dal Pi: il ponte crono_remote.py non ha mai pubblicato.'];
  $snap['_online'] = ($got[TOPIC_STATUS] ?? 'online') !== 'offline';
  return [$snap, ''];
}

// Banner text when the snapshot can't be trusted to be current, else ''.
function stale_text(?array $snap): string {
  if (!$snap) return '';
  $age = time() - (int)($snap['ts'] ?? 0);
  if ($snap['_online'] && $age <= STALE_AFTER) return '';
  return 'Pi non raggiungibile: dati delle ' . date('H:i', (int)($snap['ts'] ?? 0))
       . '. I comandi non verranno applicati finché non torna in linea.';
}

// ── JSON status API (polled by the page) ────────────────────────────────────
if (($_GET['api'] ?? '') === 'status') {
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  [$snap, $err] = fetch_snapshot();
  echo json_encode([
    'state'    => $snap['state'] ?? [],
    'ufficio'  => $snap['ufficio'] ?? null,
    'override' => $snap['override'] ?? null,
    'stale'    => $snap ? stale_text($snap) : $err,
  ]);
  exit;
}

// ── POST: send the form to the Pi and wait for its answer ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = bin2hex(random_bytes(12));
  $cmd = ['id' => $id, 'ts' => time(), 'action' => (string)($_POST['action'] ?? '')];
  foreach (['mode', 'target', 'type', 'dur'] as $k) {
    if (isset($_POST[$k])) $cmd[$k] = substr((string)$_POST[$k], 0, 16);
  }
  try {
    $m = broker();
    $m->subscribe([TOPIC_ACK . $id]);   // before publishing, so the ack can't slip past
    $m->publish(TOPIC_CMD, json_encode($cmd));
    $got = $m->collect([TOPIC_ACK . $id], ACK_WAIT, fn($g) => $g !== []);
    $m->close();
    $ack = json_decode($got[TOPIC_ACK . $id] ?? '', true);
    if (is_array($ack)) {
      $ok = (bool)($ack['ok'] ?? false);
      $msg = (string)($ack['msg'] ?? '');
    } else {
      $ok = false;
      $msg = 'Il Pi non ha risposto: comando non confermato (controlla che sia in linea).';
    }
  } catch (Throwable $e) {
    $ok = false;
    $msg = 'Errore: ' . $e->getMessage();
  }
  header('Location: crono.php?' . http_build_query(['m' => $msg, 'ok' => $ok ? 1 : 0]), true, 303);
  exit;
}

// ── Page ────────────────────────────────────────────────────────────────────
[$snap, $err] = fetch_snapshot();
render_crono_page($snap ?? [], [
  'flash'          => (string)($_GET['m'] ?? ''),
  'flash_ok'       => ($_GET['ok'] ?? '1') === '1',
  'load_err'       => $err,
  'stale'          => stale_text($snap),
  'schedule_href'  => LOCAL_EDITOR,
  'schedule_label' => 'Modifica programma (solo da casa) →',
  'other_href'     => LOCAL_URL,
  'other_label'    => 'Versione locale (senza internet) →',
]);
