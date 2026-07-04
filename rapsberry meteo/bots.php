<?php
// ── Configuration ────────────────────────────────────────────────────────────
// Bots are stored in the same persistent alarms.db shared with alarm_watcher.py.
// Rules reference a bot by its stable primary-key id (rules.bot_id), so this
// page performs targeted INSERT/UPDATE/DELETE — never a delete-all-reinsert,
// which would orphan every rule's bot_id.
define('ALARMS_DB', '/var/www/html/alarms.db');

function ensure_schema() {
  $db = new SQLite3(ALARMS_DB);
  $db->busyTimeout(2000);
  $db->exec("CREATE TABLE IF NOT EXISTS bots (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    name    TEXT NOT NULL DEFAULT '',
    token   TEXT NOT NULL DEFAULT '',
    chat_id TEXT NOT NULL DEFAULT ''
  )");
  return $db;
}

// ── Telegram ─────────────────────────────────────────────────────────────────
function send_telegram($token, $chat, $text) {
  if ($token === '' || $chat === '') {
    return [false, 'Token o Chat ID mancante.'];
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

// ── AJAX test endpoint (test a token/chat without saving) ───────────────────
if (($_GET['api'] ?? '') === 'test') {
  header('Content-Type: application/json');
  $token = trim($_POST['token'] ?? '');
  $chat  = trim($_POST['chat']  ?? '');
  $msg   = trim($_POST['msg']   ?? '');
  if ($msg === '') $msg = 'Test da bots.php ✅';
  list($ok, $info) = send_telegram($token, $chat, $msg);
  echo json_encode(['ok' => $ok, 'info' => $info]);
  exit;
}

$message = '';
$messageType = '';

// ── POST handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_bots') {
  $rows = $_POST['bot'] ?? [];
  $ins = $upd = $del = 0;
  try {
    $db = ensure_schema();
    $db->exec('BEGIN');

    $insStmt = $db->prepare('INSERT INTO bots (name, token, chat_id) VALUES (:n, :t, :c)');
    $updStmt = $db->prepare('UPDATE bots SET name = :n, token = :t, chat_id = :c WHERE id = :id');
    $delStmt = $db->prepare('DELETE FROM bots WHERE id = :id');
    // Clearing the association keeps rules valid (they fall back to the default bot).
    $clrStmt = $db->prepare('UPDATE rules SET bot_id = NULL WHERE bot_id = :id');

    foreach ($rows as $b) {
      $id      = isset($b['id']) && is_numeric($b['id']) ? (int)$b['id'] : null;
      $delete  = !empty($b['delete']) && $b['delete'] === '1';
      $name    = trim($b['name']    ?? '');
      $token   = trim($b['token']   ?? '');
      $chat    = trim($b['chat_id'] ?? '');

      if ($id !== null) {                          // existing row
        if ($delete) {
          $clrStmt->reset(); $clrStmt->clear();
          $clrStmt->bindValue(':id', $id, SQLITE3_INTEGER); $clrStmt->execute();
          $delStmt->reset(); $delStmt->clear();
          $delStmt->bindValue(':id', $id, SQLITE3_INTEGER); $delStmt->execute();
          $del++;
        } else {
          $updStmt->reset(); $updStmt->clear();
          $updStmt->bindValue(':n', $name,  SQLITE3_TEXT);
          $updStmt->bindValue(':t', $token, SQLITE3_TEXT);
          $updStmt->bindValue(':c', $chat,  SQLITE3_TEXT);
          $updStmt->bindValue(':id', $id,   SQLITE3_INTEGER);
          $updStmt->execute();
          $upd++;
        }
      } else {                                     // new row
        if ($delete) continue;
        if ($name === '' && $token === '' && $chat === '') continue;  // empty placeholder
        $insStmt->reset(); $insStmt->clear();
        $insStmt->bindValue(':n', $name,  SQLITE3_TEXT);
        $insStmt->bindValue(':t', $token, SQLITE3_TEXT);
        $insStmt->bindValue(':c', $chat,  SQLITE3_TEXT);
        $insStmt->execute();
        $ins++;
      }
    }

    $db->exec('COMMIT');
    $parts = [];
    if ($ins) $parts[] = "$ins aggiunti";
    if ($upd) $parts[] = "$upd aggiornati";
    if ($del) $parts[] = "$del eliminati";
    $message = 'Bot salvati' . ($parts ? ' (' . implode(', ', $parts) . ').' : '.');
    $messageType = 'ok';
  } catch (Throwable $e) {
    $message = 'Errore scrittura alarms.db: ' . $e->getMessage()
             . '. Verifica i permessi (deve essere scrivibile da www-data).';
    $messageType = 'err';
  }
}

// ── Load current bots ───────────────────────────────────────────────────────
$bots = [];
try {
  $db = ensure_schema();
  $res = $db->query('SELECT id, name, token, chat_id FROM bots ORDER BY name, id');
  while ($b = $res->fetchArray(SQLITE3_ASSOC)) $bots[] = $b;
} catch (Throwable $e) {
  if (!$message) { $message = 'Errore lettura alarms.db: ' . $e->getMessage(); $messageType = 'err'; }
}

// ── Render helper ───────────────────────────────────────────────────────────
function bot_card_html($i, $b = null) {
  $id    = $b['id']      ?? '';
  $name  = $b['name']    ?? '';
  $token = $b['token']   ?? '';
  $chat  = $b['chat_id'] ?? '';
  ob_start(); ?>
  <div class="bot-card" data-bot-idx="<?= htmlspecialchars((string)$i) ?>">
    <input type="hidden" name="bot[<?= $i ?>][id]" value="<?= htmlspecialchars((string)$id) ?>">
    <input type="hidden" name="bot[<?= $i ?>][delete]" value="0" class="bot-delete-flag">
    <div class="bot-row">
      <input type="text" name="bot[<?= $i ?>][name]" class="f-name" placeholder="Nome (es. Casa, Lavoro)"
        value="<?= htmlspecialchars($name) ?>">
      <input type="text" name="bot[<?= $i ?>][token]" class="f-token" placeholder="Bot token (123456:ABC-DEF…)"
        value="<?= htmlspecialchars($token) ?>" autocomplete="off" spellcheck="false">
      <input type="text" name="bot[<?= $i ?>][chat_id]" class="f-chat" placeholder="Chat ID (es. 12345678)"
        value="<?= htmlspecialchars($chat) ?>" autocomplete="off" spellcheck="false">
      <button type="button" class="btn-test" title="Invia un messaggio di prova">Test</button>
      <button type="button" class="btn-remove" title="Rimuovi bot">&times;</button>
    </div>
    <div class="bot-result" hidden></div>
  </div>
  <?php
  return ob_get_clean();
}

$tpl_bot = bot_card_html('__I__');
?>
<!DOCTYPE html>
<html lang="it">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stazione Meteo — Bot Telegram</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body { font-family: system-ui, sans-serif; background: #f1f5f9; color: #1e293b; min-height: 100vh; padding: 1.5rem; }

    header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
    header h1 { font-size: 1.4rem; font-weight: 600; letter-spacing: .02em; }
    header h1 svg { width: 1.5rem; height: 1.5rem; vertical-align: -.25rem; margin-right: .4rem; color: #0284c7; }
    .back-link { font-size: .85rem; color: #0284c7; text-decoration: none; }
    .back-link:hover { text-decoration: underline; }

    .panel { background: #fff; border-radius: .75rem; padding: 1.25rem 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 1rem; }
    .panel h2 { font-size: .85rem; color: #64748b; margin-bottom: .9rem; text-transform: uppercase; letter-spacing: .05em; }

    .bot-card { border: 1px solid #e2e8f0; border-radius: .5rem; padding: .75rem .9rem; margin-bottom: .75rem; background: #f8fafc; }
    .bot-row { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
    .bot-row .f-name  { flex: 1 1 9rem; }
    .bot-row .f-token { flex: 2 1 18rem; }
    .bot-row .f-chat  { flex: 1 1 9rem; }

    input[type="text"] {
      padding: .4rem .55rem; font-size: .85rem; font-family: inherit;
      border: 1px solid #cbd5e1; border-radius: .35rem; background: #fff; color: #1e293b; width: 100%;
    }
    input:focus { outline: none; border-color: #0284c7; box-shadow: 0 0 0 2px rgba(2,132,199,.15); }

    button[type="submit"] {
      background: #0284c7; color: #fff; border: none; border-radius: .45rem;
      padding: .55rem 1.15rem; font-size: .9rem; font-weight: 600; cursor: pointer; font-family: inherit;
    }
    button[type="submit"]:hover { background: #0369a1; }

    #add-bot {
      background: #e2e8f0; color: #1e293b; border: none; border-radius: .45rem;
      padding: .4rem .85rem; font-size: .8rem; font-weight: 600; cursor: pointer; font-family: inherit;
    }
    #add-bot:hover { background: #cbd5e1; }

    .btn-test {
      background: #fff; color: #0284c7; border: 1px solid #bae6fd; border-radius: .35rem;
      padding: .35rem .7rem; font-size: .8rem; font-weight: 600; cursor: pointer; font-family: inherit;
    }
    .btn-test:hover { background: #f0f9ff; }
    .btn-test:disabled { opacity: .6; cursor: default; }

    .btn-remove {
      background: transparent; color: #dc2626; border: 1px solid #fecaca; border-radius: .35rem;
      padding: 0; width: 1.8rem; height: 1.8rem; font-size: 1rem; line-height: 1; cursor: pointer; flex: 0 0 auto;
    }
    .btn-remove:hover { background: #fee2e2; }

    .bot-result { margin-top: .5rem; font-size: .8rem; border-radius: .35rem; padding: .4rem .6rem; }
    .bot-result.ok  { background: #dcfce7; color: #16a34a; }
    .bot-result.err { background: #fee2e2; color: #dc2626; }

    .actions { display: flex; justify-content: flex-end; gap: .75rem; align-items: center; margin-top: 1rem; }

    .msg { border-radius: .5rem; padding: .65rem 1rem; margin-bottom: 1rem; font-size: .85rem; }
    .msg.ok  { background: #dcfce7; color: #16a34a; }
    .msg.err { background: #fee2e2; color: #dc2626; }

    .help { font-size: .78rem; color: #64748b; margin-top: .6rem; line-height: 1.45; }

    .empty { padding: 1.25rem; color: #94a3b8; text-align: center; font-style: italic; border: 1px dashed #cbd5e1; border-radius: .5rem; margin-bottom: .75rem; }
  </style>
</head>

<body>

  <header>
    <h1>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
        stroke-linejoin="round" aria-hidden="true">
        <path d="M22 2 11 13" />
        <path d="M22 2 15 22l-4-9-9-4 20-7z" />
      </svg>
      Bot Telegram
    </h1>
    <div style="display:flex;gap:1rem;align-items:center">
      <a class="back-link" href="alarms.php">← Allarmi</a>
      <a class="back-link" href="index.php">Dashboard</a>
    </div>
  </header>

  <?php if ($message): ?>
    <div class="msg <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="save_bots">
    <div class="panel">
      <h2>Bot e chat configurati</h2>

      <div id="bots-container">
        <?php if (!$bots): ?>
          <div class="empty" id="empty-state">Nessun bot. Aggiungine uno con il pulsante qui sotto.</div>
        <?php else: ?>
          <?php foreach ($bots as $i => $b) echo bot_card_html($i, $b); ?>
        <?php endif; ?>
      </div>

      <button type="button" id="add-bot">+ Aggiungi bot</button>

      <p class="help">
        Ogni bot ha un <strong>token</strong> (da <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>)
        e un <strong>Chat ID</strong> di destinazione (il tuo ID utente, o quello di un gruppo).
        Usa <em>Test</em> per inviare un messaggio di prova con i valori correnti, anche prima di salvare.
        Associa poi un bot a ciascuna regola dalla pagina <a href="alarms.php">Allarmi</a>.
        Il bot di <code>zbot.py</code> resta il predefinito e gestisce i comandi in arrivo (/status, /alarms…).
      </p>
    </div>

    <div class="actions">
      <button type="submit">Salva bot</button>
    </div>
  </form>

  <script>
  (function () {
    const tplBot = <?= json_encode($tpl_bot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const container = document.getElementById('bots-container');
    let nextIdx = <?= count($bots) ?>;

    function clearEmptyState() {
      const empty = document.getElementById('empty-state');
      if (empty) empty.remove();
    }

    document.getElementById('add-bot').addEventListener('click', function () {
      clearEmptyState();
      const html = tplBot.split('__I__').join(String(nextIdx++));
      const wrap = document.createElement('div');
      wrap.innerHTML = html;
      container.appendChild(wrap.firstElementChild);
    });

    container.addEventListener('click', function (e) {
      // Remove: drop unsaved cards from the DOM; flag saved ones for deletion.
      if (e.target.matches('.btn-remove')) {
        const card = e.target.closest('.bot-card');
        const idInput = card.querySelector('input[name$="[id]"]');
        if (idInput && idInput.value !== '') {
          card.querySelector('.bot-delete-flag').value = '1';
          card.style.display = 'none';
        } else {
          card.remove();
        }
        return;
      }
      // Test: POST the current field values to the AJAX endpoint.
      const testBtn = e.target.closest('.btn-test');
      if (testBtn) {
        const card = testBtn.closest('.bot-card');
        const token = card.querySelector('.f-token').value.trim();
        const chat  = card.querySelector('.f-chat').value.trim();
        const out   = card.querySelector('.bot-result');
        const show = (ok, txt) => {
          out.hidden = false;
          out.className = 'bot-result ' + (ok ? 'ok' : 'err');
          out.textContent = txt;
        };
        if (!token || !chat) { show(false, 'Inserisci token e Chat ID prima del test.'); return; }
        testBtn.disabled = true;
        const body = new URLSearchParams({ token, chat, msg: 'Test da bots.php ✅' });
        fetch('?api=test', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
          .then(r => r.json())
          .then(d => show(!!d.ok, d.info || (d.ok ? 'OK' : 'Errore')))
          .catch(() => show(false, 'Errore di rete durante il test.'))
          .finally(() => { testBtn.disabled = false; });
        return;
      }
    });
  })();
  </script>

</body>

</html>
