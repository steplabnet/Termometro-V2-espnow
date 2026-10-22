<?php
/* Energia giorno per giorno: per il giorno scelto (?d=YYYY-MM-DD, oggi se
 * manca) le stesse barre orizzontali della card "Energia ora per ora" di
 * index.php -- produzione FV (usata, di cui in batteria, immessa) e consumo
 * casa con le tre sorgenti -- sommate dalle righe di `energia_oraria`.
 * Le ore prima del 18/10/2026 sono ricostruite dalle letture ogni 10 minuti di
 * dati_meteo, quindi sono stime.
 */
error_reporting(E_ALL);
ini_set('display_errors', true);
date_default_timezone_set('Europe/Rome');

include('connessione.php');
require_once __DIR__ . '/instant_lib.php';   // ENERGY_PV_FROM

$today = date('Y-m-d');
$d = $_GET['d'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
  $d = $today;
}
[$y, $m, $g] = array_map('intval', explode('-', $d));
$t0 = mktime(0, 0, 0, $m, $g, $y);
$t1 = mktime(0, 0, 0, $m, $g + 1, $y);   // 23 o 25 ore nei giorni del cambio d'ora
$prev = date('Y-m-d', mktime(0, 0, 0, $m, $g - 1, $y));
$next = date('Y-m-d', $t1);

// Primo giorno con dati, per spegnere la freccia indietro.
$firstDay = null;
try {
  $r = $link->query('SELECT MIN(ora) FROM energia_oraria');
  $min = ($r instanceof mysqli_result) ? $r->fetch_row()[0] : null;
  $firstDay = $min ? date('Y-m-d', (int) $min) : null;
} catch (Throwable $e) {
}

$chans = ['pv', 'casa', 'batt', 'casa_pv', 'casa_batt', 'casa_grid', 'export'];
$t = null;
try {
  $sel = implode(', ', array_map(static fn($c) => "SUM({$c}_wh) AS {$c}", $chans));
  $r = $link->query("SELECT COUNT(*) AS n, {$sel} FROM energia_oraria WHERE ora >= {$t0} AND ora < {$t1}");
  $row = ($r instanceof mysqli_result) ? $r->fetch_assoc() : null;
  if ($row && (int) $row['n'] > 0) {
    $t = array_map('floatval', array_intersect_key($row, array_flip($chans)));
  }
} catch (Throwable $e) {
}

function fmtWh(float $wh): string
{
  return $wh >= 1000 ? number_format($wh / 1000, 2, ',', '') . ' kWh' : round($wh) . ' Wh';
}

/* Barre orizzontali in SVG, come renderTotalsChart() di index.php: la prima
 * riga e' il totale, le altre portano la loro quota (tranne quelle con il
 * quarto campo a true). viewBox fisso e larghezza
 * al 100%, il testo resta leggibile perche' la card non scende sotto ~320px. */
function bars(array $rows, string $aria): string
{
  $W = 600; $rowH = 30; $H = count($rows) * $rowH;
  $L = 84; $R = 140; $pw = $W - $L - $R;
  $tot = $rows[0][2];
  $max = max(1.0, ...array_map(static fn($r) => $r[2], $rows));
  $o = "<svg class=\"bars\" viewBox=\"0 0 {$W} {$H}\" role=\"img\" aria-label=\"" . htmlspecialchars($aria) . '">';
  foreach ($rows as $i => $row) {
    [$label, $color, $wh] = $row;
    $cy = $i * $rowH + $rowH / 2;
    $w = round($wh / $max * $pw, 1);
    $pct = ($i > 0 && $tot > 0 && empty($row[3])) ? ' (' . round($wh / $tot * 100) . '%)' : '';
    $o .= sprintf('<text x="%d" y="%.1f" text-anchor="end">%s</text>', $L - 8, $cy + 4, htmlspecialchars($label));
    $o .= sprintf('<rect class="track" x="%d" y="%.1f" width="%d" height="18" rx="3"/>', $L, $cy - 9, $pw);
    if ($w > 0) {
      $o .= sprintf('<rect x="%d" y="%.1f" width="%.1f" height="18" rx="3" fill="%s"/>', $L, $cy - 9, $w, $color);
    }
    $o .= sprintf('<text x="%d" y="%.1f">%s</text>', $L + $pw + 8, $cy + 4, fmtWh($wh) . $pct);
  }
  return $o . '</svg>';
}

$giorni = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];
$mesi = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio',
  'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
$titolo = $giorni[(int) date('w', $t0)] . ' ' . $g . ' ' . $mesi[$m] . ' ' . $y;
?>
<!DOCTYPE html>
<html lang="it">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Energia giorno per giorno</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg-color: #f0f2f5;
      --card-bg: #ffffff;
      --text-main: #1f2937;
      --text-muted: #6b7280;
      --track: #f3f4f6;
      --border: #e5e7eb;
      --accent-blue: #3b82f6;
      --accent-yellow: #eab308;
      --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    body {
      font-family: 'Inter', sans-serif;
      background-color: var(--bg-color);
      color: var(--text-main);
      margin: 0;
      padding: 20px 16px 40px;
    }

    .wrap {
      max-width: 760px;
      margin: 0 auto;
    }

    header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 16px;
    }

    h1 {
      font-size: 1.4rem;
      font-weight: 800;
      margin: 0;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      min-height: 40px;
      padding: 0 14px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text-main);
      font: 600 0.9rem 'Inter', sans-serif;
      text-decoration: none;
      cursor: pointer;
      box-shadow: var(--shadow);
    }

    .btn:hover {
      border-color: var(--accent-blue);
      color: var(--accent-blue);
    }

    .btn.off {
      opacity: 0.35;
      pointer-events: none;
    }

    .nav {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }

    .nav form {
      display: flex;
      gap: 8px;
      flex: 1;
      min-width: 0;
    }

    .nav input[type=date] {
      flex: 1;
      min-width: 0;
      min-height: 40px;
      padding: 0 12px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text-main);
      font: 600 0.95rem 'Inter', sans-serif;
    }

    .day {
      font-size: 1.05rem;
      font-weight: 600;
      margin: 0 0 12px;
      text-transform: capitalize;
    }

    .card {
      background: var(--card-bg);
      border-radius: 16px;
      box-shadow: var(--shadow);
      padding: 18px 18px 12px;
      margin-bottom: 16px;
      border-top: 4px solid var(--accent-yellow);
    }

    .card.casa {
      border-top-color: #8b5cf6;
    }

    .card h2 {
      font-size: 0.85rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--text-muted);
      margin: 0 0 8px;
    }

    .bars {
      width: 100%;
      height: auto;
      display: block;
    }

    .bars text {
      font-size: 13px;
      fill: var(--text-muted);
    }

    .bars .track {
      fill: var(--track);
    }

    .note {
      font-size: 0.8rem;
      color: var(--text-muted);
    }

    .empty {
      text-align: center;
      padding: 30px 0;
      color: var(--text-muted);
    }
  </style>
</head>

<body>
  <div class="wrap">
    <header>
      <h1>Energia giorno per giorno</h1>
      <a class="btn" href="index.php">Home</a>
    </header>

    <div class="nav">
      <a class="btn <?php echo ($firstDay && $prev < $firstDay) ? 'off' : ''; ?>"
        href="?d=<?php echo $prev; ?>" aria-label="Giorno prima">&#9664;</a>
      <form method="get">
        <input type="date" name="d" value="<?php echo $d; ?>" max="<?php echo $today; ?>"
          <?php echo $firstDay ? 'min="' . $firstDay . '"' : ''; ?> onchange="this.form.submit()">
        <noscript><button class="btn" type="submit">Vai</button></noscript>
      </form>
      <a class="btn <?php echo ($next > $today) ? 'off' : ''; ?>"
        href="?d=<?php echo $next; ?>" aria-label="Giorno dopo">&#9654;</a>
      <a class="btn <?php echo ($d === $today) ? 'off' : ''; ?>" href="?">Oggi</a>
    </div>

    <p class="day"><?php echo $titolo; ?></p>

    <?php if (!$t): ?>
      <div class="card">
        <div class="empty">Nessun dato per questo giorno.</div>
      </div>
    <?php else:
      $used = max(0.0, $t['pv'] - $t['export']);
      $pvFrom = strtotime(ENERGY_PV_FROM);
      ?>
      <div class="card">
        <h2>Produzione FV</h2>
        <?php if ($t0 < $pvFrom): ?>
          <div class="empty">Contatori FV dal <?php echo date('d/m/Y', $pvFrom); ?>.</div>
        <?php else: echo bars([
          ['Totale', '#eab308', $t['pv']],
          ['Usata', '#16a34a', $used],
          ['Immessa', '#3b82f6', min($t['export'], $t['pv'])],
          ['Caricata', '#14b8a6', max(0.0, $used - $t['casa_pv'])],
          ['Scaricata', '#f59e0b', $t['batt'], true],   // non e' una quota del FV
        ], "Produzione FV del {$titolo}"); endif; ?>
      </div>

      <div class="card casa">
        <h2>Consumo casa</h2>
        <?php echo bars([
          ['Totale', '#8b5cf6', $t['casa']],
          ['FV', '#16a34a', $t['casa_pv']],
          ['Batteria', '#eab308', $t['casa_batt']],
          ['Rete', '#ef4444', $t['casa_grid']],
        ], "Consumo casa del {$titolo}"); ?>
      </div>

      <?php if ($t0 < mktime(14, 0, 0, 10, 18, 2026)): ?>
        <p class="note">Valori stimati, in tutto o in parte, dalle letture ogni 10 minuti
          (prima del 18/10/2026 alle 14 l'energia oraria non si misurava ancora).</p>
      <?php elseif ($d === $today): ?>
        <p class="note">Giorno in corso: i valori crescono fino a mezzanotte.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>

</html>
