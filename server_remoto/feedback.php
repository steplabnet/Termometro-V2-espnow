<?php
/**
 * feedback.php
 * --------------------------------------------------------------------------
 * Ground-truth logger for the weather forecast. You look outside, tap what
 * the sky is ACTUALLY doing, and we store that observation next to the full
 * sensor snapshot AND the label the algorithm predicted at that instant.
 *
 * The resulting `forecast_feedback` table is the dataset for tuning the rule
 * thresholds in forecast_lib.php: each row pairs the raw signals the engine
 * saw (mslp, 3h trend, humidity, temp, PV) with the verified outcome.
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');

require_once 'connessione.php';      // $link
require_once 'forecast_lib.php';

/* ---------- 0. ENSURE TABLE EXISTS ---------- */
$link->query("CREATE TABLE IF NOT EXISTS forecast_feedback (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ts            INT          NOT NULL,
    observed      VARCHAR(32)  NOT NULL,
    predicted     VARCHAR(32)  NULL,
    predicted_icon VARCHAR(24) NULL,
    sky_now       VARCHAR(32)  NULL,
    pres          FLOAT        NULL,
    mslp          FLOAT        NULL,
    trend3h       FLOAT        NULL,
    trend_valid   TINYINT      NULL,
    humi          FLOAT        NULL,
    t_shade       FLOAT        NULL,
    t_sun         FLOAT        NULL,
    pv            FLOAT        NULL,
    sun_frac      FLOAT        NULL,
    note          VARCHAR(120) NULL,
    created       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* The observation choices. Labels match the forecast vocabulary so tuning is
   a direct predicted-vs-observed comparison. */
$OPTIONS = [
    'Sereno'        => ['emoji' => '☀️', 'color' => 'var(--accent-orange)'],
    'Poco Nuvoloso' => ['emoji' => '🌤️', 'color' => 'var(--accent-orange)'],
    'Nuvoloso'      => ['emoji' => '☁️', 'color' => 'var(--text-muted)'],
    'Coperto'       => ['emoji' => '🌥️', 'color' => 'var(--text-muted)'],
    'Nebbia'        => ['emoji' => '🌫️', 'color' => 'var(--text-muted)'],
    'Pioggia'       => ['emoji' => '🌧️', 'color' => 'var(--accent-blue)'],
    'Temporale'     => ['emoji' => '⛈️', 'color' => 'var(--accent-red)'],
    'Nevischio'     => ['emoji' => '🌨️', 'color' => 'var(--accent-ice)'],
    'Neve'          => ['emoji' => '❄️', 'color' => 'var(--accent-ice)'],
];

/* ---------- 1. HANDLE SUBMISSION (Post/Redirect/Get) ---------- */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['observed'])) {
    $observed = (string)$_POST['observed'];
    if (isset($OPTIONS[$observed])) {
        // Re-gather the snapshot at submit time so the stored signals are
        // exactly what the engine sees now (not what an old page render saw).
        $in       = gatherForecastInputs($link);
        $forecast = computeForecast($in);
        $skyNow   = computeSkyNow($in['sun_frac'] !== null ? (float)$in['sun_frac'] : null);
        $note     = trim((string)($_POST['note'] ?? ''));
        if (strlen($note) > 120) $note = substr($note, 0, 120);

        $stmt = $link->prepare(
            "INSERT INTO forecast_feedback
                (ts, observed, predicted, predicted_icon, sky_now, pres, mslp,
                 trend3h, trend_valid, humi, t_shade, t_sun, pv, sun_frac, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $ts          = (int)$in['now'];
        $predicted   = $forecast['text'];
        $predIcon    = $forecast['icon'];
        $skyTxt      = $skyNow['text'] ?? null;
        $pres        = (float)$in['pres'];
        $mslp        = (float)$in['mslp'];
        $trend3h     = (float)$in['trend3h'];
        $trendValid  = $in['trend_valid'] ? 1 : 0;
        $humi        = (float)$in['humi'];
        $tShade      = (float)$in['t_shade'];
        $tSun        = (float)$in['t_sun'];
        $pv          = (float)$in['pv'];
        $sunFrac     = $in['sun_frac'] !== null ? (float)$in['sun_frac'] : null;

        $stmt->bind_param(
            'issssdddiddddds',
            $ts, $observed, $predicted, $predIcon, $skyTxt, $pres, $mslp,
            $trend3h, $trendValid, $humi, $tShade, $tSun, $pv, $sunFrac, $note
        );
        $stmt->execute();
        $stmt->close();

        $hit = ($predicted === $observed) ? 'centrato' : "previsto «{$predicted}»";
        header('Location: feedback.php?ok=' . urlencode($observed) . '&p=' . urlencode($hit));
        exit;
    }
}

if (isset($_GET['ok'])) {
    $flash = "Registrato: « " . htmlspecialchars($_GET['ok']) . " » — " . htmlspecialchars($_GET['p'] ?? '');
}

/* ---------- 2. CURRENT STATE (for the form header) ---------- */
$in       = gatherForecastInputs($link);
$forecast = computeForecast($in);
$skyNow   = computeSkyNow($in['sun_frac'] !== null ? (float)$in['sun_frac'] : null);
$fb       = computeForecastFeedback($link, $in, $forecast);   // calcolo + feedback

function f($v, $d = 1, $na = '--') {
    return is_numeric($v) ? number_format((float)$v, $d) : $na;
}

/**
 * Reduce any observed/forecast label to the 4-level cloud-cover scale the PV
 * classifier emits (computeSkyNow). This lets the sun_frac thresholds be
 * scored against real observations even when the observation was a "weather"
 * label (rain/snow/fog all mean overcast for irradiance purposes).
 */
function skyBucket(string $label): string {
    switch ($label) {
        case 'Sereno':        return 'Sereno';
        case 'Poco Nuvoloso': return 'Poco Nuvoloso';
        case 'Nuvoloso':      return 'Nuvoloso';
        default:              return 'Coperto'; // Coperto, Nebbia, Pioggia, Temporale, Neve, Nevischio
    }
}

/* ---------- 3. RECENT FEEDBACK + ACCURACY ---------- */
$recent = [];
$res = $link->query("SELECT * FROM forecast_feedback ORDER BY ts DESC LIMIT 40");
if ($res instanceof mysqli_result) {
    while ($r = $res->fetch_assoc()) $recent[] = $r;
}

$total = 0; $hits = 0;
$res2 = $link->query("SELECT COUNT(*) c, SUM(predicted = observed) h FROM forecast_feedback WHERE predicted IS NOT NULL");
if ($res2 && $rowA = $res2->fetch_assoc()) {
    $total = (int)$rowA['c'];
    $hits  = (int)$rowA['h'];
}
$accuracy = $total > 0 ? round(100 * $hits / $total) : null;

/* PV sky-cover classifier accuracy: observed (bucketed) vs sky_now, daytime
   rows only (sky_now is null at night). Bucketing is done in PHP, so scan the
   rows. Table is small, so a full scan is fine. */
$pvTotal = 0; $pvHits = 0;
$resPv = $link->query("SELECT observed, sky_now FROM forecast_feedback WHERE sky_now IS NOT NULL");
if ($resPv instanceof mysqli_result) {
    while ($rp = $resPv->fetch_assoc()) {
        $pvTotal++;
        if (skyBucket((string)$rp['observed']) === $rp['sky_now']) $pvHits++;
    }
}
$pvAccuracy = $pvTotal > 0 ? round(100 * $pvHits / $pvTotal) : null;
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Feedback Previsioni — Cesana</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg-color:#f0f2f5; --card-bg:#fff; --text-main:#1f2937; --text-muted:#6b7280;
      --accent-blue:#3b82f6; --accent-orange:#f59e0b; --accent-red:#ef4444;
      --accent-teal:#14b8a6; --accent-ice:#0ea5e9; --accent-purple:#8b5cf6;
      --shadow:0 4px 6px -1px rgba(0,0,0,.1);
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',sans-serif;background:var(--bg-color);color:var(--text-main);padding:20px;max-width:900px;margin:0 auto}
    header{text-align:center;margin-bottom:20px}
    header h1{font-weight:800;font-size:1.4rem}
    header p{color:var(--text-muted);font-size:.85rem;margin-top:4px}
    .back{display:inline-block;margin-bottom:16px;color:var(--accent-blue);font-weight:600;font-size:.85rem}
    .card{background:var(--card-bg);border-radius:16px;padding:20px;box-shadow:var(--shadow);margin-bottom:20px}
    .flash{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:12px 16px;border-radius:12px;margin-bottom:20px;font-weight:600;font-size:.9rem}
    .now-grid{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:10px}
    .now-chip{background:var(--bg-color);border-radius:10px;padding:8px 12px;font-size:.8rem;text-align:center;min-width:90px}
    .now-chip b{display:block;font-size:1.1rem;font-weight:800;margin-top:2px}
    .pred{text-align:center;margin-bottom:6px}
    .pred .lbl{font-size:.7rem;text-transform:uppercase;color:var(--text-muted);letter-spacing:.5px}
    .pred .val{font-size:1.5rem;font-weight:800}
    .corr{font-size:.72rem;color:var(--text-muted);margin-top:8px;line-height:1.4}
    .corr b{color:var(--text-main)}
    .corr.ok{color:#059669}
    .corr.faint{opacity:.7}
    .prompt{text-align:center;font-weight:700;margin:18px 0 12px}
    .opts{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px}
    .opt{border:0;cursor:pointer;background:var(--bg-color);border-radius:14px;padding:16px 8px;font-family:inherit;font-size:.85rem;font-weight:700;color:var(--text-main);transition:transform .15s,box-shadow .15s;display:flex;flex-direction:column;align-items:center;gap:6px}
    .opt:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
    .opt .em{font-size:1.8rem}
    .note{width:100%;margin-top:14px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-family:inherit;font-size:.85rem}
    h2{font-size:1rem;font-weight:800;margin-bottom:12px}
    .acc{font-size:.85rem;color:var(--text-muted);margin-bottom:12px}
    .acc b{color:var(--text-main);font-size:1rem}
    table{width:100%;border-collapse:collapse;font-size:.78rem}
    th,td{padding:7px 6px;text-align:left;border-bottom:1px solid #eee;white-space:nowrap}
    th{color:var(--text-muted);text-transform:uppercase;font-size:.65rem}
    .hit{color:#059669;font-weight:800}
    .miss{color:var(--accent-red);font-weight:800}
    .muted{color:var(--text-muted)}
    @media(max-width:560px){.scroll{overflow-x:auto}}
  </style>
</head>
<body>
  <a class="back" href="index.php">&larr; Torna alla dashboard</a>
  <header>
    <h1>Feedback Previsioni</h1>
    <p>Guarda fuori e indica cosa fa davvero il cielo: un solo tap tara sia la previsione barometrica sia il classificatore cielo del fotovoltaico.</p>
  </header>

  <?php if ($flash): ?>
    <div class="flash">✓ <?php echo $flash; ?></div>
  <?php endif; ?>

  <!-- Submission form -->
  <div class="card">
    <div class="pred">
      <div class="lbl"><?php echo $fb['corrected'] ? 'Previsione (calcolo + feedback)' : 'Previsione attuale dell\'algoritmo'; ?></div>
      <div class="val" style="color:<?php echo $fb['color']; ?>"><?php echo htmlspecialchars($fb['label']); ?></div>
      <?php if ($fb['corrected']): ?>
        <div class="corr">
          🔄 Calcolo: «<?php echo htmlspecialchars($forecast['text']); ?>» → corretto dal feedback
          <?php if ($fb['confidence'] !== null): ?> · fiducia <b><?php echo (int)$fb['confidence']; ?>%</b><?php endif; ?>
          · <?php echo (int)$fb['neighbors']; ?> oss. simili
        </div>
      <?php elseif ($fb['neighbors'] >= 2): ?>
        <div class="corr ok">
          ✓ <?php echo htmlspecialchars($fb['reason']); ?>
          <?php if ($fb['confidence'] !== null): ?> · fiducia <b><?php echo (int)$fb['confidence']; ?>%</b><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="corr faint"><?php echo htmlspecialchars($fb['reason']); ?></div>
      <?php endif; ?>
    </div>

    <div class="now-grid">
      <div class="now-chip">Pressione<b><?php echo f($in['pres'], 1); ?></b>hPa</div>
      <div class="now-chip">Liv. mare<b><?php echo f($in['mslp'], 1); ?></b>hPa</div>
      <div class="now-chip">Trend 3h<b><?php echo $in['trend_valid'] ? (($in['trend3h'] > 0 ? '+' : '') . f($in['trend3h'], 1)) : 'n/d'; ?></b>hPa</div>
      <div class="now-chip">Umidità<b><?php echo f($in['humi'], 0); ?></b>%</div>
      <div class="now-chip">T. ombra<b><?php echo $in['t_shade'] > -99 ? f($in['t_shade'], 1) : '--'; ?></b>°C</div>
      <div class="now-chip">Cielo (PV)<b style="font-size:.85rem"><?php echo $skyNow ? htmlspecialchars($skyNow['text']) : '— notte'; ?></b></div>
    </div>

    <div class="prompt">Cosa fa il cielo adesso?</div>
    <form method="post">
      <div class="opts">
        <?php foreach ($OPTIONS as $label => $meta): ?>
          <button class="opt" type="submit" name="observed" value="<?php echo htmlspecialchars($label); ?>" style="border-top:4px solid <?php echo $meta['color']; ?>">
            <span class="em"><?php echo $meta['emoji']; ?></span>
            <span><?php echo htmlspecialchars($label); ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <input class="note" type="text" name="note" maxlength="120" placeholder="Nota opzionale (es. «vento forte», «pioggia da 10 min»)">
    </form>
  </div>

  <!-- History / accuracy -->
  <div class="card">
    <h2>Storico osservazioni</h2>
    <div class="acc">
      Previsione (barometro): <b><?php echo $accuracy !== null ? $accuracy . '%' : '—'; ?></b>
      <?php if ($total): ?>(<?php echo $hits; ?>/<?php echo $total; ?> centrate)<?php endif; ?>
    </div>
    <div class="acc">
      Cielo fotovoltaico (PV): <b><?php echo $pvAccuracy !== null ? $pvAccuracy . '%' : '—'; ?></b>
      <?php if ($pvTotal): ?>(<?php echo $pvHits; ?>/<?php echo $pvTotal; ?> di giorno)<?php endif; ?>
    </div>
    <?php if (!$recent): ?>
      <p class="muted">Ancora nessuna osservazione. Le voci registrate appariranno qui.</p>
    <?php else: ?>
      <div class="scroll">
      <table>
        <thead>
          <tr>
            <th>Quando</th><th>Osservato</th><th>Previsto</th><th>✓</th>
            <th>Cielo PV</th><th>✓</th><th>Irr.%</th>
            <th>Liv.mare</th><th>Trend3h</th><th>Umid</th><th>T.ombra</th><th>PV W</th><th>Nota</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $r):
            $hit = ($r['predicted'] !== null && $r['predicted'] === $r['observed']);
            $hasPv = ($r['sky_now'] !== null && $r['sky_now'] !== '');
            $pvHit = ($hasPv && skyBucket((string)$r['observed']) === $r['sky_now']);
            $irr = ($r['sun_frac'] !== null && $r['sun_frac'] !== '') ? round((float)$r['sun_frac'] * 100) . '%' : '--'; ?>
            <tr>
              <td class="muted"><?php echo date('d/m H:i', (int)$r['ts']); ?></td>
              <td><b><?php echo htmlspecialchars($r['observed']); ?></b></td>
              <td class="muted"><?php echo htmlspecialchars($r['predicted'] ?? '—'); ?></td>
              <td class="<?php echo $hit ? 'hit' : 'miss'; ?>"><?php echo $r['predicted'] === null ? '' : ($hit ? '✓' : '✗'); ?></td>
              <td class="muted"><?php echo $hasPv ? htmlspecialchars($r['sky_now']) : '— notte'; ?></td>
              <td class="<?php echo $pvHit ? 'hit' : 'miss'; ?>"><?php echo $hasPv ? ($pvHit ? '✓' : '✗') : ''; ?></td>
              <td><?php echo $irr; ?></td>
              <td><?php echo f($r['mslp'], 1); ?></td>
              <td><?php echo $r['trend_valid'] ? f($r['trend3h'], 1) : 'n/d'; ?></td>
              <td><?php echo f($r['humi'], 0); ?></td>
              <td><?php echo $r['t_shade'] > -99 ? f($r['t_shade'], 1) : '--'; ?></td>
              <td><?php echo f($r['pv'], 0); ?></td>
              <td class="muted"><?php echo htmlspecialchars($r['note'] ?? ''); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
