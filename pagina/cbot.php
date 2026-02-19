<?php
/*************************************************
 * Telegram Bot Configuration
 *************************************************/
define('BOT_TOKEN', '8589110198:AAETPSIWj0VXXXXXXXXXXXXXXXXXX');
define('CHAT_ID', 5629087724);

/*************************************************
 * Simple CSRF token
 *************************************************/
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$statusMsg = "";
$statusOk  = false;

function sendTelegramMessage(string $text): array {
    $apiUrl = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";

    $postData = [
        'chat_id' => CHAT_ID,
        'text'    => $text,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => "cURL error: $error", 'http' => $httpCode, 'raw' => null];
    }

    $json = json_decode($response, true);
    if (!is_array($json) || ($json['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => $json['description'] ?? 'Telegram API error', 'http' => $httpCode, 'raw' => $response];
    }

    return ['ok' => true, 'error' => null, 'http' => $httpCode, 'raw' => $response];
}

/*************************************************
 * Handle form submission
 *************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $csrf)) {
        $statusMsg = "❌ Security check failed (CSRF). Refresh the page and try again.";
    } else {
        $name    = trim($_POST['name'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($message === '') {
            $statusMsg = "❌ Please type a message.";
        } else {
            // Basic HTML escaping (since parse_mode=HTML)
            $nameEsc    = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $subjectEsc = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $messageEsc = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $text =
                "📩 <b>New form message</b>\n" .
                ($nameEsc !== '' ? "👤 <b>Name:</b> {$nameEsc}\n" : "") .
                ($subjectEsc !== '' ? "🧾 <b>Subject:</b> {$subjectEsc}\n" : "") .
                "📝 <b>Message:</b>\n{$messageEsc}\n\n" .
                "🌐 <b>IP:</b> " . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'unknown', ENT_QUOTES, 'UTF-8');

            $result = sendTelegramMessage($text);

            if ($result['ok']) {
                $statusOk  = true;
                $statusMsg = "✅ Sent! Check Telegram.";
                // rotate CSRF after successful submit
                $_SESSION['csrf'] = bin2hex(random_bytes(16));
            } else {
                $statusMsg = "❌ Failed: " . $result['error'] . " (HTTP " . $result['http'] . ")";
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Send to Telegram</title>
  <style>
    body { font-family: system-ui, Arial, sans-serif; margin: 24px; }
    .wrap { max-width: 620px; margin: 0 auto; }
    label { display:block; margin-top: 12px; font-weight: 600; }
    input, textarea { width: 100%; padding: 10px; margin-top: 6px; box-sizing: border-box; }
    textarea { min-height: 140px; resize: vertical; }
    button { margin-top: 14px; padding: 10px 14px; cursor: pointer; }
    .status { margin: 14px 0; padding: 10px; border-radius: 8px; }
    .ok { background: #e8fff0; border: 1px solid #b8f0c9; }
    .bad { background: #fff0f0; border: 1px solid #f0b8b8; }
    .hint { color: #444; font-size: 0.95rem; margin-top: 8px; }
  </style>
</head>
<body>
<div class="wrap">
  <h2>Send a Telegram Message</h2>

  <?php if ($statusMsg !== ""): ?>
    <div class="status <?= $statusOk ? 'ok' : 'bad' ?>">
      <?= htmlspecialchars($statusMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">

    <label for="name">Name (optional)</label>
    <input id="name" name="name" type="text" placeholder="Your name">

    <label for="subject">Subject (optional)</label>
    <input id="subject" name="subject" type="text" placeholder="Subject">

    <label for="message">Message *</label>
    <textarea id="message" name="message" placeholder="Type your message..." required></textarea>

    <button type="submit">Send to Telegram</button>
  </form>

  <p class="hint">
    Tip: you must have started the bot at least once (send <b>/start</b> to it), otherwise Telegram won’t deliver messages to your user chat.
  </p>
</div>
</body>
</html>
