<?php
declare(strict_types=1);

/**
 * live.php — long-poll endpoint feeding the dashboard's AJAX refresh.
 *
 * The browser sends the `rev` it is currently showing. If the snapshot in
 * /dev/shm already carries a different rev we answer immediately; otherwise we
 * hold the request open and answer the instant carica_dati.php (or a pressure
 * update from the thermostat) writes a new one. That is what makes the numbers
 * appear "as soon as they come" instead of on a fixed timer.
 *
 *   GET live.php?rev=123        -> hold up to LIVE_HOLD_SECONDS, then answer
 *   GET live.php?rev=123&wait=0 -> answer straight away (plain polling)
 *
 * Answer is either the full payload (with a new `rev`) or {"nochange":true}.
 *
 * NOTE: every waiting browser tab occupies one PHP worker for the whole hold.
 * On a cramped shared host set LIVE_HOLD_SECONDS to 0 to fall back to plain
 * short-polling; the front-end handles both without changes.
 */

require_once __DIR__ . '/instant_lib.php';

/** How long a single request may block waiting for new data. */
const LIVE_HOLD_SECONDS = 20;
/** How often the hold loop re-checks the snapshot. */
const LIVE_POLL_USEC = 400000; // 0.4 s

date_default_timezone_set('Europe/Rome');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Accel-Buffering: no');

// Buffer everything: a stray newline from an included file would otherwise be
// prepended to the body and make the response unparseable as JSON.
ob_start();

/** Emit $data as the sole body of the response. */
function live_send(array $data): void
{
  if (ob_get_length()) {
    ob_clean();
  }
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ob_end_flush();
  exit;
}

$clientRev = isset($_GET['rev']) && is_numeric($_GET['rev']) ? (int) $_GET['rev'] : 0;
$wait = (isset($_GET['wait']) && $_GET['wait'] === '0') ? 0 : LIVE_HOLD_SECONDS;

set_time_limit($wait + 20);

$deadline = time() + $wait;

while (true) {
  // Cheap while the snapshot is fresh (one file read); rebuilds from MySQL only
  // when it aged out or the thermostat rewrote state.json / pres_history.csv.
  try {
    $payload = meteo_payload_get();
  } catch (Throwable $e) {
    // A hiccup in the rebuild must not leave the browser with a broken poller:
    // report it and let the client back off and retry.
    http_response_code(503);
    live_send(['error' => 'build failed']);
  }

  if ($payload === null) {
    http_response_code(503);
    live_send(['error' => 'no data']);
  }

  if ((int) ($payload['rev'] ?? 0) !== $clientRev) {
    live_send($payload);
  }

  if (time() >= $deadline) {
    break;
  }
  usleep(LIVE_POLL_USEC);
}

live_send(['rev' => $clientRev, 'nochange' => true]);
