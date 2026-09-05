<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Europe/Rome');

$file_path = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . "/icache.html";

ob_start();

require_once 'funzioni.php';
require_once 'connessione.php'; // Defines $link
require_once __DIR__ . '/instant_lib.php';

/**
 * Every number on this page comes from ONE render-ready snapshot kept in RAM
 * (/dev/shm/thermo_data/instant.json — see instant_store.php). carica_dati.php
 * rebuilds it on each upload from the Raspberry, so a normal page load costs
 * zero MySQL queries; we only rebuild here when the snapshot is missing or has
 * aged out. live.php serves the very same structure to the browser, which is
 * why the AJAX refresh can never disagree with this first paint.
 */
$p = meteo_payload_get($link instanceof mysqli ? $link : null);

if ($p === null) {
  http_response_code(503);
  exit('Dati non disponibili');
}

$emAvailable = !empty($p['emAvailable']);
$rev = (int) ($p['rev'] ?? 0);

/**
 * Inline `display:none` for the markup variants that are rendered but inactive
 * (forecast icons, the river's dry/wet glyph, blocks with nothing to say).
 * All variants stay in the DOM so the poller can swap them without a reload.
 */
function showStyle($cond): string
{
  return $cond ? '' : 'display:none;';
}
?>
<!doctype html>
<html lang="it">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard Meteo Cesana</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg-color: #f0f2f5;
      --card-bg: #ffffff;
      --text-main: #1f2937;
      --text-muted: #6b7280;
      --accent-blue: #3b82f6;
      --accent-orange: #f59e0b;
      --accent-red: #ef4444;
      --accent-teal: #14b8a6;
      --accent-ice: #0ea5e9;
      --accent-purple: #8b5cf6;
      --accent-dry: #92400e;
      --accent-green: #16a34a;
      --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', sans-serif;
      background-color: var(--bg-color);
      color: var(--text-main);
      padding: 20px;
    }

    a {
      text-decoration: none !important;
      color: inherit;
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 100%;
      height: 100%;
    }

    header {
      text-align: center;
      margin-bottom: 30px;
    }

    header h1 {
      font-weight: 800;
      font-size: 1.5rem;
    }

    .dashboard-grid {
      display: grid;
      gap: 20px;
      max-width: 1200px;
      margin: 0 auto;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    }

    .card {
      background: var(--card-bg);
      border-radius: 16px;
      padding: 20px;
      box-shadow: var(--shadow);
      border-top: 5px solid transparent;
      transition: transform 0.2s;
      min-height: 250px;
    }

    .card:hover {
      transform: translateY(-3px);
    }

    .card-icon {
      width: 48px;
      height: 48px;
      margin-bottom: 10px;
      fill: currentColor;
    }

    .icon-temp {
      color: var(--accent-red);
    }

    .icon-power {
      color: var(--accent-orange);
    }

    .icon-humi {
      color: var(--accent-teal);
    }

    .icon-water {
      color: var(--accent-blue);
    }

    .icon-cold {
      display: none;
    }

    .card.freezing .icon-warm {
      display: none;
    }

    .card.freezing .icon-cold {
      display: block;
      color: var(--accent-ice);
      fill: none;
      stroke: var(--accent-ice);
      stroke-width: 2;
    }

    .icon-night {
      display: none;
    }

    .card.night-mode .icon-day {
      display: none;
    }

    .card.night-mode .icon-night {
      display: block;
    }

    .card-label {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      margin-bottom: 5px;
    }

    .card-value {
      font-size: 2.2rem;
      font-weight: 800;
      line-height: 1;
    }

    .card-unit {
      font-size: 1rem;
      color: var(--text-muted);
      margin-left: 3px;
    }

    .minmax-row {
      display: flex;
      justify-content: center;
      width: 100%;
      padding-top: 15px;
      border-top: 1px solid #eee;
      margin-top: auto;
    }

    .minmax-item {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      font-size: 0.75rem;
    }

    .minmax-label {
      display: block;
      color: #9ca3af;
      font-size: 0.65rem;
      text-transform: uppercase;
      margin-bottom: 2px;
    }

    .ice-prediction {
      font-size: 0.65rem;
      color: var(--accent-ice);
      margin-top: 2px;
      text-align: center;
      width: 100%;
    }

    .minmax-val {
      font-weight: 700;
    }

    .val-min {
      color: var(--accent-blue);
    }

    .val-max {
      color: var(--accent-red);
    }

    .trend-up {
      color: var(--accent-red);
    }

    .trend-down {
      color: var(--accent-blue);
    }

    .border-temp {
      border-top-color: var(--accent-red);
    }

    .border-pres {
      border-top-color: var(--accent-purple);
    }

    .border-rain {
      border-top-color: var(--accent-blue);
    }

    .icon-rain {
      color: var(--accent-blue);
    }

    /* Six 15-minute buckets in one row: same idea as .minmax-row but tighter,
       since six clock times have to fit on a phone without wrapping. */
    .rain-row {
      display: flex;
      justify-content: space-between;
      width: 100%;
      padding-top: 15px;
      border-top: 1px solid #eee;
      margin-top: auto;
      gap: 2px;
    }

    .rain-item {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      min-width: 0;
    }

    .rain-when {
      color: #9ca3af;
      font-size: 0.6rem;
      font-variant-numeric: tabular-nums;
      text-transform: uppercase;
      margin-bottom: 3px;
      white-space: nowrap;
    }

    .rain-mm {
      font-weight: 700;
      font-size: 0.8rem;
      font-variant-numeric: tabular-nums;
      color: var(--text-muted);
    }

    .rain-prob {
      font-size: 0.6rem;
      color: #9ca3af;
      font-variant-numeric: tabular-nums;
      margin-top: 1px;
    }

    .border-power {
      border-top-color: var(--accent-orange);
    }

    .border-humi {
      border-top-color: var(--accent-teal);
    }

    .border-water {
      border-top-color: var(--accent-blue);
    }

    .river-dry {
      border-top-color: var(--accent-dry);
    }

    .border-feedback {
      border-top-color: var(--accent-purple);
    }

    .icon-feedback {
      color: var(--accent-purple);
    }

    .border-multi {
      border-top-color: var(--accent-teal);
    }

    .border-grid {
      border-top-color: var(--accent-blue);
    }

    .border-grid.exporting {
      border-top-color: var(--accent-green);
    }

    .border-house {
      border-top-color: var(--accent-purple);
    }

    /* Prelievo: verde quando e' zero (tutto da fotovoltaico), rosso quando
       stiamo comprando dalla rete. */
    .border-draw {
      border-top-color: var(--accent-green);
    }

    .border-draw.drawing {
      border-top-color: var(--accent-red);
    }

    .icon-draw {
      color: var(--accent-green);
    }

    .border-draw.drawing .icon-draw {
      color: var(--accent-red);
    }

    .icon-grid {
      color: var(--accent-blue);
    }

    .border-grid.exporting .icon-grid {
      color: var(--accent-green);
    }

    .icon-house {
      color: var(--accent-purple);
    }

    .border-casabatt {
      border-top-color: var(--accent-teal);
    }

    .icon-casabatt {
      color: var(--accent-teal);
    }

    /* Batteria: verde mentre carica o riposa, arancione mentre scarica --
       stessa logica del bordo e dell'icona, cosi' il colore si legge da lontano. */
    .border-batt {
      border-top-color: var(--accent-green);
    }

    .border-batt.discharging {
      border-top-color: var(--accent-orange);
    }

    .icon-batt {
      color: var(--accent-green);
    }

    .border-batt.discharging .icon-batt {
      color: var(--accent-orange);
    }

    /* Card diagnostica: una griglia etichetta/valore, tre famiglie separate da
       un titoletto. Non e' una card da un solo numero grande, quindi non usa
       .card-value: qui contano quattordici numeri piccoli e leggibili. */
    .border-diag {
      border-top-color: var(--accent-ice);
    }

    .icon-diag {
      color: var(--accent-ice);
    }

    /* Quadro principale: stessa griglia della diagnostica batteria (riusa
       .diag-group / .diag-rows), altro colore perche' guarda l'impianto e non
       il pacco. Nessun valore e' un link: queste letture non sono storicizzate,
       quindi non c'e' grafico da aprire. */
    .border-quadro {
      border-top-color: var(--accent-purple);
    }

    .icon-quadro {
      color: var(--accent-purple);
    }

    .diag-rows .v .dir {
      font-weight: 600;
      color: var(--text-muted);
    }

    .diag-group {
      width: 100%;
      font-size: 0.62rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #9ca3af;
      margin: 10px 0 3px;
      text-align: left;
    }

    .diag-rows {
      width: 100%;
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 1px 8px;
      font-size: 0.72rem;
      font-variant-numeric: tabular-nums;
    }

    .diag-rows .k {
      color: var(--text-muted);
      text-align: left;
    }

    .diag-rows .v {
      font-weight: 700;
      text-align: right;
    }

    /* Ogni valore e' un link al proprio grafico. La regola globale su <a> in
       cima al file li stira a colonna a piena altezza (serve alle card, che
       sono interamente cliccabili): qui va annullata, altrimenti la griglia
       si sfascia. */
    .diag-rows a {
      display: inline;
      width: auto;
      height: auto;
      border-bottom: 1px dotted rgba(107, 114, 128, 0.5);
    }

    .diag-rows a:hover {
      border-bottom-style: solid;
      color: var(--accent-ice);
    }

    .diag-rows .v.warn {
      color: var(--accent-orange);
    }

    /* Livello di carica: la barra dice a colpo d'occhio cio' che la percentuale
       dice con precisione. La larghezza arriva dal payload (batt.fill). */
    .batt-bar {
      width: 100%;
      height: 8px;
      margin-top: 10px;
      border-radius: 999px;
      background: #e5e7eb;
      overflow: hidden;
    }

    .batt-fill {
      height: 100%;
      border-radius: 999px;
      transition: width 0.4s ease, background-color 0.4s ease;
    }

    .flow-state {
      font-weight: 800;
      font-size: 0.78rem;
      margin-top: 5px;
      text-transform: uppercase;
    }

    .icon-multi {
      color: var(--accent-teal);
    }

    /* Background blink when a value is patched in by the live poller: a single
       fade was easy to miss on a dashboard nobody is staring at, so the changed
       number flashes three times before settling. */
    .pulse {
      animation: pulse-blink 1.35s ease-in-out;
    }

    @keyframes pulse-blink {
      0%   { background: transparent; }
      8%   { background: rgba(59, 130, 246, 0.35); }
      25%  { background: transparent; }
      40%  { background: rgba(59, 130, 246, 0.35); }
      55%  { background: transparent; }
      70%  { background: rgba(59, 130, 246, 0.35); }
      85%  { background: transparent; }
      100% { background: transparent; }
    }

    /* The negative margin cancels the padding, so the highlight is wider than
       the glyphs without nudging the layout when it appears. */
    .card-value, .minmax-val, .flow-state, [data-live], [data-live-html] {
      border-radius: 6px;
      padding: 0 4px;
      margin-left: -4px;
      margin-right: -4px;
    }

    .header-meta {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      align-items: baseline;
      gap: 6px;
      font-size: 0.85rem;
      font-variant-numeric: tabular-nums;
      color: var(--text-muted);
    }

    .header-meta .meta-label {
      font-size: 0.62rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      opacity: 0.7;
    }

    .header-meta .meta-sep {
      opacity: 0.4;
    }

    /* The poller stopped getting answers: the time above is no longer current. */
    #live-updated.stale {
      color: var(--accent-red);
      font-weight: 700;
    }
  </style>
</head>

<body>
  <header>
    <h1>Stazione Meteo Cesana</h1>
    <!-- Two different clocks: when the station took the reading (server time,
         travels with the payload) and when this browser last heard back from
         live.php (the visitor's own clock, filled in by the poller). -->
    <p class="header-meta">
      <span class="meta-label">Dato</span>
      <span data-live="headerTime"><?php echo $p['headerTime']; ?></span>
      <span class="meta-sep">&middot;</span>
      <span class="meta-label">Aggiornato</span>
      <span id="live-updated">&mdash;</span>
    </p>
  </header>

  <div class="dashboard-grid">
    <!-- 1. Temp Sole -->
    <div class="card border-temp <?php echo $p['tempSole']['freezing'] ? 'freezing' : ''; ?>" data-card="tempSole">
      <a href="grafico.php?var=temperatura">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24">
          <path
            d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z" />
        </svg>
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-cold" viewBox="0 0 24 24">
          <path d="M2 12h20M12 2v20M20 4l-8 8-8-8M4 20l8-8 8 8" />
        </svg>
        <div class="card-label">Temperatura Sole</div>
        <div class="card-value"><span data-live="tempSole.val"><?php echo $p['tempSole']['val']; ?></span><span
            class="card-unit">°C</span></div>
        <div class="minmax-row">
          <div class="minmax-item"><span class="minmax-label">Min</span><span class="minmax-val val-min"
              data-live="tempSole.min" data-suffix="°"><?php echo $p['tempSole']['min']; ?>°</span></div>
          <div class="minmax-item"><span class="minmax-label">Trend</span><span
              data-live-html="tempSole.trend"><?php echo $p['tempSole']['trend']; ?></span></div>
          <div class="minmax-item"><span class="minmax-label">Max</span><span class="minmax-val val-max"
              data-live="tempSole.max" data-suffix="°"><?php echo $p['tempSole']['max']; ?>°</span></div>
        </div>
      </a>
    </div>

    <!-- 2. Temp Ombra (DB column: tombra) -->
    <div class="card border-temp <?php echo $p['tempOmbra']['freezing'] ? 'freezing' : ''; ?>" data-card="tempOmbra">
      <a href="grafico.php?var=tombra">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24">
          <path
            d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z" />
        </svg>
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-cold" viewBox="0 0 24 24">
          <path d="M2 12h20M12 2v20M20 4l-8 8-8-8M4 20l8-8 8 8" />
        </svg>
        <div class="card-label">Temperatura Ombra</div>
        <div class="card-value"><span data-live="tempOmbra.val"><?php echo $p['tempOmbra']['val']; ?></span><span
            class="card-unit">°C</span></div>
        <div class="minmax-row">
          <div class="minmax-item"><span class="minmax-label">Min</span><span class="minmax-val val-min"
              data-live="tempOmbra.min" data-suffix="°"><?php echo $p['tempOmbra']['min']; ?>°</span></div>
          <div class="minmax-item"><span class="minmax-label">Trend</span><span
              data-live-html="tempOmbra.trend"><?php echo $p['tempOmbra']['trend']; ?></span></div>
          <div class="minmax-item"><span class="minmax-label">Max</span><span class="minmax-val val-max"
              data-live="tempOmbra.max" data-suffix="°"><?php echo $p['tempOmbra']['max']; ?>°</span></div>
        </div>
      </a>
    </div>

    <!-- 3. Nowcast pioggia (Open-Meteo, lato browser: nessun dato nostro) -->
    <div class="card border-rain" data-card="rain">
      <a href="previsioni.php">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-rain" viewBox="0 0 24 24" fill="currentColor">
          <path
            d="M17.5 18c-3 0-5.5-2.5-5.5-5.5 0-3 2.5-5.5 5.5-5.5.4 0 .7 0 1.1.1C17.7 4.8 15 3 12 3 8.1 3 5 6.1 5 10c0 .1 0 .3 0 .4C3.3 11.3 2 13 2 15c0 2.8 2.2 5 5 5h10.5c2.5 0 4.5-2 4.5-4.5s-2-4.5-4.5-4.5" />
          <g stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <line x1="8" y1="21" x2="8" y2="23" />
            <line x1="12" y1="21" x2="12" y2="23" />
            <line x1="16" y1="21" x2="16" y2="23" />
          </g>
        </svg>
        <div class="card-label">Pioggia Prevista</div>
        <div class="card-value"><span id="rain-total">&mdash;</span><span class="card-unit">mm/90'</span></div>

        <div id="rain-summary"
          style="font-weight:800; font-size:0.75rem; color:var(--text-muted); margin-top:5px; text-transform:uppercase;">
          Caricamento&hellip;
        </div>

        <div id="rain-next" style="margin-top:3px; font-size:0.68rem; color:var(--text-muted); display:none;">
          &#128337; <span id="rain-next-text"></span>
        </div>

        <!-- One column per native 15-minute bucket of the model, six of them:
             the current one plus the next 90 minutes. Labels are filled in
             with the bucket's own clock time, so the card never implies a
             resolution the API does not have. -->
        <div class="rain-row">
          <div class="rain-item"><span class="rain-when" data-rain-when="0">&nbsp;</span><span class="rain-mm"
              data-rain="0">&mdash;</span><span class="rain-prob" data-rain-prob="0"></span></div>
          <div class="rain-item"><span class="rain-when" data-rain-when="1">&nbsp;</span><span class="rain-mm"
              data-rain="1">&mdash;</span><span class="rain-prob" data-rain-prob="1"></span></div>
          <div class="rain-item"><span class="rain-when" data-rain-when="2">&nbsp;</span><span class="rain-mm"
              data-rain="2">&mdash;</span><span class="rain-prob" data-rain-prob="2"></span></div>
          <div class="rain-item"><span class="rain-when" data-rain-when="3">&nbsp;</span><span class="rain-mm"
              data-rain="3">&mdash;</span><span class="rain-prob" data-rain-prob="3"></span></div>
          <div class="rain-item"><span class="rain-when" data-rain-when="4">&nbsp;</span><span class="rain-mm"
              data-rain="4">&mdash;</span><span class="rain-prob" data-rain-prob="4"></span></div>
          <div class="rain-item"><span class="rain-when" data-rain-when="5">&nbsp;</span><span class="rain-mm"
              data-rain="5">&mdash;</span><span class="rain-prob" data-rain-prob="5"></span></div>
        </div>
      </a>
    </div>

    <!-- 4. Pressione / Forecast -->
    <!-- Every icon variant is in the DOM; the poller shows the one that matches
         pres.icon, so the forecast can change without a page reload. -->
    <div class="card border-pres" data-card="pres">
      <a href="grafico.php?var=press">

        <svg viewBox="0 0 24 24" class="card-icon" fill="currentColor"
          data-fc-icon="sun"
          style="color:var(--accent-orange); <?php echo showStyle($p['pres']['icon'] === 'sun'); ?>">
          <circle cx="12" cy="12" r="5" />
          <g stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <line x1="12" y1="1" x2="12" y2="3" />
            <line x1="12" y1="21" x2="12" y2="23" />
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" />
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" />
            <line x1="1" y1="12" x2="3" y2="12" />
            <line x1="21" y1="12" x2="23" y2="12" />
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" />
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" />
          </g>
        </svg>

        <svg viewBox="0 0 24 24" class="card-icon" fill="currentColor"
          data-fc-icon="partly_cloudy"
          style="<?php echo showStyle($p['pres']['icon'] === 'partly_cloudy'); ?>">
          <g style="color:var(--accent-orange)">
            <circle cx="16" cy="8" r="4" />
            <g stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <line x1="16" y1="1" x2="16" y2="2.5" />
              <line x1="16" y1="13.5" x2="16" y2="15" />
              <line x1="21" y1="3" x2="22" y2="2" />
              <line x1="10" y1="13" x2="11" y2="14" />
              <line x1="23" y1="8" x2="21.5" y2="8" />
              <line x1="10.5" y1="8" x2="9" y2="8" />
              <line x1="21" y1="13" x2="22" y2="14" />
              <line x1="10" y1="3" x2="11" y2="2" />
            </g>
          </g>
          <path
            d="M16.5 19c-3 0-5.5-2.5-5.5-5.5 0-3 2.5-5.5 5.5-5.5.4 0 .7 0 1.1.1C16.7 5.8 14 4 11 4 7.1 4 4 7.1 4 11c0 .1 0 .3 0 .4C2.3 12.3 1 14 1 16c0 2.8 2.2 5 5 5h10.5c2.5 0 4.5-2 4.5-4.5s-2-4.5-4.5-4.5c0 0 0 0 0 0"
            style="color:var(--text-muted)" />
        </svg>

        <svg viewBox="0 0 24 24" class="card-icon" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round"
          data-fc-icon="snow"
          style="color:var(--accent-ice); <?php echo showStyle($p['pres']['icon'] === 'snow'); ?>">
          <path d="M12 2v20M2 12h20M4 4l16 16M20 4L4 20" />
        </svg>

        <svg viewBox="0 0 24 24" class="card-icon" fill="currentColor"
          data-fc-icon="sleet"
          style="color:var(--accent-ice); <?php echo showStyle($p['pres']['icon'] === 'sleet'); ?>">
          <path
            d="M17.5 18c-3 0-5.5-2.5-5.5-5.5S14.5 7 17.5 7c.4 0 .7 0 1.1.1C17.7 4.8 15 3 12 3 8.1 3 5 6.1 5 10c0 .1 0 .3 0 .4C3.3 11.3 2 13 2 15c0 2.8 2.2 5 5 5h10.5c2.5 0 4.5-2 4.5-4.5S20 11 17.5 11" />
          <g stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none">
            <line x1="9" y1="21" x2="9" y2="23" />
            <line x1="15" y1="21" x2="15" y2="23" />
          </g>
        </svg>

        <svg viewBox="0 0 24 24" class="card-icon" fill="currentColor"
          data-fc-icon="snow_storm"
          style="color:var(--accent-ice); <?php echo showStyle($p['pres']['icon'] === 'snow_storm'); ?>">
          <path
            d="M17.5 18c-3 0-5.5-2.5-5.5-5.5 0-3 2.5-5.5 5.5-5.5.4 0 .7 0 1.1.1C17.7 4.8 15 3 12 3 8.1 3 5 6.1 5 10c0 .1 0 .3 0 .4C3.3 11.3 2 13 2 15c0 2.8 2.2 5 5 5h10.5c2.4 0 4.5-2 4.5-4.5s-2-4.5-4.5-4.5" />
          <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M9 20h6" />
            <path d="M12 18v6" />
            <path d="M10 19l4 4" />
            <path d="M14 19l-4 4" />
          </g>
        </svg>

        <svg viewBox="0 0 24 24" class="card-icon" fill="currentColor"
          data-fc-icon="rain"
          style="color:var(--accent-blue); <?php echo showStyle($p['pres']['icon'] === 'rain'); ?>">
          <path
            d="M17.5 18c-3 0-5.5-2.5-5.5-5.5 0-3 2.5-5.5 5.5-5.5.4 0 .7 0 1.1.1C17.7 4.8 15 3 12 3 8.1 3 5 6.1 5 10c0 .1 0 .3 0 .4C3.3 11.3 2 13 2 15c0 2.8 2.2 5 5 5h10.5c2.5 0 4.5-2 4.5-4.5s-2-4.5-4.5-4.5" />
          <g stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <line x1="8" y1="21" x2="8" y2="23" />
            <line x1="12" y1="21" x2="12" y2="23" />
            <line x1="16" y1="21" x2="16" y2="23" />
          </g>
        </svg>

        <svg viewBox="0 0 24 24" class="card-icon" fill="currentColor"
          data-fc-icon="storm"
          style="color:var(--accent-red); <?php echo showStyle($p['pres']['icon'] === 'storm'); ?>">
          <path
            d="M17.5 18c-3 0-5.5-2.5-5.5-5.5 0-3 2.5-5.5 5.5-5.5.4 0 .7 0 1.1.1C17.7 4.8 15 3 12 3 8.1 3 5 6.1 5 10c0 .1 0 .3 0 .4C3.3 11.3 2 13 2 15c0 2.8 2.2 5 5 5h10.5c2.4 0 4.5-2 4.5-4.5s-2-4.5-4.5-4.5" />
          <path d="M13 20l-2 3h3l-2 3" stroke="currentColor" stroke-width="1.5" fill="none" />
        </svg>

        <svg viewBox="0 0 24 24" class="card-icon" fill="currentColor"
          data-fc-icon="cloud"
          style="color:var(--text-muted); <?php echo showStyle($p['pres']['icon'] === 'cloud'); ?>">
          <path
            d="M17.5 19c-3 0-5.5-2.5-5.5-5.5 0-3 2.5-5.5 5.5-5.5.4 0 .7 0 1.1.1C17.7 5.8 15 4 12 4 8.1 4 5 7.1 5 11c0 .1 0 .3 0 .4C3.3 12.3 2 14 2 16c0 2.8 2.2 5 5 5h10.5c2.5 0 4.5-2 4.5-4.5s-2-4.5-4.5-4.5" />
        </svg>

        <div class="card-label">Pressione</div>
        <div class="card-value"><span data-live="pres.val"><?php echo $p['pres']['val']; ?></span><span
            class="card-unit">hPa</span></div>

        <div style="font-weight:800; font-size: 0.75rem; color:<?php echo $p['pres']['color']; ?>; margin-top:5px; text-transform:uppercase;"
          data-live="pres.text" data-live-color="pres.color">
          <?php echo $p['pres']['text']; ?>
        </div>

        <div style="margin-top:3px; font-size:0.68rem; color:var(--text-muted); <?php echo showStyle($p['pres']['timing'] !== ''); ?>"
          data-live-show="pres.timing">
          &#128337; <span data-live="pres.timing"><?php echo $p['pres']['timing']; ?></span>
        </div>

        <div style="margin-top:4px; font-size:0.7rem; font-weight:700; color:var(--accent-ice); <?php echo showStyle($p['pres']['ice']); ?>"
          data-live-show="pres.ice">
          &#10052; Rischio gelo
        </div>

        <div class="minmax-row">
          <div class="minmax-item"><span class="minmax-label">Liv. Mare</span><span class="minmax-val"
              data-live="pres.mslp"><?php echo $p['pres']['mslp']; ?></span></div>
          <div class="minmax-item"><span class="minmax-label">Trend 3h</span><span
              class="minmax-val <?php echo $p['pres']['trendClass']; ?>" data-live="pres.trendText"
              data-live-trendclass="pres.trendClass"><?php echo $p['pres']['trendText']; ?></span>
          </div>
        </div>
      </a>
    </div>

    <!-- 4. Produzione Fotovoltaico (Shelly Pro EM-50, canale em1:1) -->
    <div class="card border-power <?php echo $p['pv']['night'] ? 'night-mode' : ''; ?>" data-card="pv">
      <a href="<?php echo $p['pv']['link']; ?>" data-live-href="pv.link">

        <!-- Day icon: solar panel + lightning -->
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-power icon-day" viewBox="0 0 24 24"
          fill="currentColor">
          <!-- panel -->
          <path d="M3 11h18l-1 8H4l-1-8zm2 2 .5 4h13L19 13H5z" opacity="0.9" />
          <path d="M4 9h16v2H4V9z" />
          <!-- panel grid -->
          <path d="M8 11.5v7M12 11.5v7M16 11.5v7" opacity="0.35" />
          <path d="M5.8 14.5h12.4" opacity="0.35" />
          <!-- lightning -->
          <path d="M13 2 8 12h4l-1 10 5-10h-4l1-10z" />
        </svg>

        <!-- Night icon: solar panel + moon -->
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-night" viewBox="0 0 24 24" fill="currentColor">
          <!-- moon -->
          <path
            d="M18.5 2.5c-3.6.6-6.3 3.7-6.3 7.5 0 4.2 3.4 7.6 7.6 7.6 1.1 0 2.1-.2 3-.6-1.2 2.8-4 4.8-7.2 4.8-4.3 0-7.8-3.5-7.8-7.8 0-3.8 2.7-6.9 6.3-7.5-.6-.2-1.1-.3-1.6-.3z"
            opacity="0.9" />
          <!-- panel -->
          <path d="M3 11h18l-1 8H4l-1-8zm2 2 .5 4h13L19 13H5z" opacity="0.55" />
          <path d="M4 9h16v2H4V9z" opacity="0.55" />
          <!-- panel grid -->
          <path d="M8 11.5v7M12 11.5v7M16 11.5v7" opacity="0.25" />
          <path d="M5.8 14.5h12.4" opacity="0.25" />
        </svg>

        <div class="card-label">Produzione Fotovoltaico</div>
        <div class="card-value"><span data-live="pv.val"><?php echo $p['pv']['val']; ?></span><span
            class="card-unit">W</span></div>

        <div style="font-weight:800; font-size:0.75rem; color:<?php echo $p['pv']['skyColor']; ?>; margin-top:5px; text-transform:uppercase; <?php echo showStyle($p['pv']['skyText'] !== ''); ?>"
          data-live-show="pv.skyText" data-live-color="pv.skyColor">
          &#9728; <span data-live="pv.skyText"><?php echo $p['pv']['skyText']; ?></span>
        </div>

        <div class="minmax-row">
          <div class="minmax-item">
            <span class="minmax-label">Picco 24h</span>
            <span class="minmax-val" data-live="pv.peak"><?php echo $p['pv']['peak']; ?></span>
          </div>
          <div class="minmax-item">
            <span class="minmax-label">Irraggiamento</span>
            <span class="minmax-val" data-live="pv.sunPct"><?php echo $p['pv']['sunPct']; ?></span>
          </div>
        </div>
      </a>
    </div>

    <!-- 5. Umidità -->
    <div class="card border-humi" data-card="humi">
      <a href="grafico.php?var=hombra">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-humi" viewBox="0 0 24 24" fill="currentColor">
          <path
            d="M12 2.25c0 7.039 4.343 11.233 4.343 14.5a5.093 5.093 0 0 1-10.186 0c0-3.267 4.343-7.461 4.343-14.5a.75.75 0 0 1 .75-.75Z" />
        </svg>
        <div class="card-label">Umidità</div>
        <div class="card-value"><span data-live="humi.val"><?php echo $p['humi']['val']; ?></span><span
            class="card-unit">%</span></div>
        <div class="minmax-row">
          <div class="minmax-item"><span class="minmax-label">Range 24h</span><span class="minmax-val"
              data-live="humi.range"><?php echo $p['humi']['range']; ?></span></div>
        </div>
      </a>
    </div>

    <!-- 6. Piave -->
    <div class="card border-water <?php echo ($p['piave']['status'] == 'dry' ? 'river-dry' : ''); ?>"
      data-card="piave">
      <a href="grafico.php?var=portata">
        <svg viewBox="0 0 24 24" class="card-icon" fill="currentColor"
          data-piave-icon="dry"
          style="color:var(--accent-dry); <?php echo showStyle(($p['piave']['status'] == 'dry' ? 'dry' : 'wet') === 'dry'); ?>">
          <path d="M2 13h20v2H2v-2zm2-4h16v2H4V9zm4-4h8v2H8V5z" opacity="0.3" />
          <path
            d="M12 22a9 9 0 0 1-9-9c0-1.5.5-3 1.5-4l1.5 1.5c-.6.7-1 1.6-1 2.5 0 3.9 3.1 7 7 7s7-3.1 7-7c0-.9-.4-1.8-1-2.5l1.5-1.5c1 1 1.5 2.5 1.5 4a9 9 0 0 1-9 9z" />
        </svg>
        <svg viewBox="0 0 24 24" class="card-icon" fill="currentColor"
          data-piave-icon="wet"
          style="color:var(--accent-blue); <?php echo showStyle(($p['piave']['status'] == 'dry' ? 'dry' : 'wet') === 'wet'); ?>">
          <path d="M3 14c2 0 3-1 3-3s1-3 3-3 3 1 3 3 1 3 3 3 3-1 3-3 1-3 3-3 3 1 3 3-1 3-3 3H3z" />
          <path d="M3 19c2 0 3-1 3-3s1-3 3-3 3 1 3 3 1 3 3 3 3-1 3-3 1-3 3-3 3 1 3 3-1 3-3 3H3z" opacity="0.4" />
          <path d="M12 2l-4 4h8l-4-4z" style="color:var(--accent-red); <?php echo showStyle($p['piave']['rising']); ?>"
            data-live-show="piave.rising" />
        </svg>
        <div class="card-label">Fiume Piave</div>
        <div class="card-value"><span data-live="piave.val"><?php echo $p['piave']['val']; ?></span><span
            class="card-unit">m³/s</span></div>
        <div style="font-weight:800; font-size:0.8rem; margin-top:5px; text-transform:uppercase; color:<?php echo $p['piave']['statusColor']; ?>;"
          data-live="piave.statusText" data-live-color="piave.statusColor">
          <?php echo $p['piave']['statusText']; ?>
        </div>
        <div class="minmax-row">
          <div class="minmax-item"><span class="minmax-label">Trend Orario</span><span
              data-live-html="piave.trend"><?php echo $p['piave']['trend']; ?></span></div>
        </div>
      </a>
    </div>

    <!-- 7. Temp Interno (DB column: tMobile) -->
    <div class="card border-temp <?php echo $p['tempInterno']['freezing'] ? 'freezing' : ''; ?>"
      data-card="tempInterno">
      <a href="grafico.php?var=tMobile">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-temp icon-warm" viewBox="0 0 24 24">
          <path
            d="M14 2a5 5 0 0 0-5 5v8a5 5 0 0 0-2.15 4.096A5 5 0 0 0 12 24a5 5 0 0 0 5.15-4.904A5 5 0 0 0 15 15V7a5 5 0 0 0-1-3Z" />
        </svg>
        <div class="card-label">Temperatura Interno</div>
        <div class="card-value"><span data-live="tempInterno.val"><?php echo $p['tempInterno']['val']; ?></span><span
            class="card-unit">°C</span></div>
        <div class="minmax-row">
          <div class="minmax-item"><span class="minmax-label">Min</span><span class="minmax-val val-min"
              data-live="tempInterno.min" data-suffix="°"><?php echo $p['tempInterno']['min']; ?>°</span></div>
          <div class="minmax-item"><span class="minmax-label">Trend</span><span
              data-live-html="tempInterno.trend"><?php echo $p['tempInterno']['trend']; ?></span></div>
          <div class="minmax-item"><span class="minmax-label">Max</span><span class="minmax-val val-max"
              data-live="tempInterno.max" data-suffix="°"><?php echo $p['tempInterno']['max']; ?>°</span></div>
        </div>
      </a>
    </div>

    <?php if ($emAvailable): ?>
      <!-- 8. Scambio con la rete (canale em1:0): >0 prelievo, <0 immissione -->
      <div class="card border-grid <?php echo $p['grid']['exporting'] ? 'exporting' : ''; ?>" data-card="grid">
        <a href="grafico.php?var=gridPower">
          <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-grid" viewBox="0 0 24 24" fill="currentColor">
            <path d="M11 2h2v4h-2V2zM6.2 4.6l1.6 1.2-2.4 3.2-1.6-1.2 2.4-3.2zm11.6 0 2.4 3.2-1.6 1.2-2.4-3.2 1.6-1.2z" />
            <path d="M7 8h10l3 12H4L7 8zm2 2-.6 2.5h7.2L15 10H9zm-1.1 4.5-.9 3.5h9.9l-.9-3.5H7.9z" opacity="0.85" />
          </svg>
          <div class="card-label">Scambio Rete</div>
          <div class="card-value"><span data-live="grid.val"><?php echo $p['grid']['val']; ?></span><span
              class="card-unit">W</span></div>
          <div class="flow-state" style="color:<?php echo $p['grid']['flowColor']; ?>;"
            data-live-html="grid.flow" data-live-color="grid.flowColor">
            <?php echo $p['grid']['flow']; ?>
          </div>
          <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Max prelievo</span><span class="minmax-val val-max"
                data-live="grid.maxIn"><?php echo $p['grid']['maxIn']; ?></span></div>
            <div class="minmax-item"><span class="minmax-label">Max immissione</span><span class="minmax-val val-min"
                data-live="grid.maxOut"><?php echo $p['grid']['maxOut']; ?></span>
            </div>
          </div>
        </a>
      </div>

    <?php endif; ?>

    <?php if (!empty($p['battAvailable'])): ?>
      <!-- 9. Batteria Marstek Venus E. Il consumo casa qui sopra e' gia' al
           netto di questa: quando la batteria carica, quei watt non sono
           consumo della casa ma accumulo. -->
      <div class="card border-batt <?php echo $p['batteria']['discharging'] ? 'discharging' : ''; ?>"
        data-card="batteria">
        <a href="grafico.php?var=battSoc">
          <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-batt" viewBox="0 0 24 24"
            fill="currentColor">
            <path d="M4 7h13a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2zm0 2v6h13V9H4z" />
            <path d="M20 10h2v4h-2v-4z" />
            <path d="M11 8.5 7.5 13H10l-1 3.5L12.5 12H10l1-3.5z" />
          </svg>
          <div class="card-label">Batteria</div>
          <div class="card-value"><span data-live="batteria.val"><?php echo $p['batteria']['val']; ?></span><span
              class="card-unit">%</span></div>

          <div class="batt-bar">
            <div class="batt-fill" data-live-width="batteria.fill" data-live-bg="batteria.fillColor"
              style="width:<?php echo $p['batteria']['fill']; ?>; background:<?php echo $p['batteria']['fillColor']; ?>;">
            </div>
          </div>

          <div class="flow-state" style="color:<?php echo $p['batteria']['flowColor']; ?>;"
            data-live-html="batteria.flow" data-live-color="batteria.flowColor">
            <?php echo $p['batteria']['flow']; ?>
          </div>

          <!-- Quanto manca, a che potenza media, a che ritmo e fino a che
               ora: in scarica fino al 12% di riserva, in carica fino al 100%.
               Sempre sul ritmo dell'ultima mezz'ora. -->
          <div style="margin-top:4px; font-size:0.7rem; font-weight:700; color:<?php echo $p['batteria']['etaColor']; ?>; <?php echo showStyle($p['batteria']['etaShow']); ?>"
            data-live-show="batteria.etaShow" data-live-color="batteria.etaColor">
            &#9203; <span data-live="batteria.eta"><?php echo $p['batteria']['eta']; ?></span><span
              data-live-html="batteria.etaClock"><?php echo $p['batteria']['etaClock']; ?></span>
          </div>

          <!-- Cella piu' calda, non l'elettronica: e' quella su cui lavorano i
               limiti del BMS. Sotto 0 gradi la carica si ferma da sola. -->
          <div style="margin-top:4px; font-size:0.7rem; font-weight:700; color:<?php echo $p['batteria']['tempColor']; ?>; <?php echo showStyle($p['batteria']['tempShow']); ?>"
            data-live-show="batteria.tempShow" data-live-color="batteria.tempColor">
            &#127777; <span data-live="batteria.temp"><?php echo $p['batteria']['temp']; ?></span><span
              data-live="batteria.tempNote"><?php echo $p['batteria']['tempNote']; ?></span>
          </div>

          <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Potenza</span><span class="minmax-val"
                data-live="batteria.power"><?php echo $p['batteria']['power']; ?></span></div>
            <div class="minmax-item"><span class="minmax-label">Residuo</span><span class="minmax-val"
                data-live="batteria.residuo"><?php echo $p['batteria']['residuo']; ?></span></div>
            <div class="minmax-item"><span class="minmax-label">Range 24h</span><span class="minmax-val"
                data-live="batteria.range"><?php echo $p['batteria']['range']; ?></span></div>
          </div>
        </a>
      </div>
    <?php endif; ?>

    <?php if ($emAvailable): ?>
      <!-- 9b. Prelievo dalla rete: 0 quando il fotovoltaico copre tutto il
           consumo, altrimenti la parte importata dello scambio rete. -->
      <div class="card border-draw <?php echo $p['prelievo']['drawing'] ? 'drawing' : ''; ?>" data-card="prelievo">
        <a href="grafico.php?var=prelievo">
          <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-draw" viewBox="0 0 24 24" fill="currentColor">
            <path d="M7 2h10l3 12H4L7 2zm2.2 2-.5 2h6.6l-.5-2H9.2zm-1 4-.6 2.5h8.8L15.4 8H8.2z" opacity="0.85" />
            <path d="M11 15h2v3h3l-4 6-4-6h3v-3z" />
          </svg>
          <div class="card-label">Prelievo Rete</div>
          <div class="card-value"><span data-live="prelievo.val"><?php echo $p['prelievo']['val']; ?></span><span
              class="card-unit">W</span></div>
          <div class="flow-state" style="color:<?php echo $p['prelievo']['stateColor']; ?>;"
            data-live-html="prelievo.stateText" data-live-color="prelievo.stateColor">
            <?php echo $p['prelievo']['stateText']; ?>
          </div>
          <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Quota consumo</span><span class="minmax-val"
                style="<?php echo showStyle($p['prelievo']['shareShow']); ?>" data-live-show="prelievo.shareShow"
                data-live="prelievo.share"><?php echo $p['prelievo']['share']; ?></span></div>
            <div class="minmax-item"><span class="minmax-label">Max 24h</span><span class="minmax-val val-max"
                data-live="prelievo.max"><?php echo $p['prelievo']['max']; ?></span></div>
          </div>
        </a>
      </div>

      <!-- 10. Consumo di casa = produzione + scambio rete -->
      <div class="card border-house" data-card="casa">
        <a href="grafico.php?var=casa">
          <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-house" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 3 2 12h3v9h6v-6h2v6h6v-9h3L12 3z" />
          </svg>
          <div class="card-label">Consumo Casa</div>
          <div class="card-value"><span data-live="casa.val"><?php echo $p['casa']['val']; ?></span><span
              class="card-unit">W</span></div>
          <div class="flow-state" style="color:var(--accent-purple); <?php echo showStyle($p['casa']['shareShow']); ?>"
            data-live-show="casa.shareShow" data-live="casa.share">
            <?php echo $p['casa']['share']; ?>
          </div>
          <div class="minmax-row">
            <div class="minmax-item"><span class="minmax-label">Picco 24h</span><span class="minmax-val"
                data-live="casa.peak"><?php echo $p['casa']['peak']; ?></span></div>
          </div>
        </a>
      </div>
      <?php if (!empty($p['battAvailable'])): ?>
        <!-- 10b. Casa + batteria: quello che l'impianto tira davvero mentre
             riempie anche il pacco. La batteria entra solo se sta caricando:
             in scarica non e' un consumo, e' la sorgente.

             Fuori dalla carica la card sparisce del tutto, invece di ripetere
             il valore della card Consumo Casa qui accanto. Resta pero' nel
             DOM, nascosta: cosi' il poller la fa ricomparire appena la
             batteria riprende a caricare, senza ricaricare la pagina (e' la
             stessa ragione per cui esistono le varianti di icona altrove). -->
        <div class="card border-casabatt" data-card="casaBatt"
          style="<?php echo showStyle($p['casaBatt']['charging']); ?>"
          data-live-show="casaBatt.charging">
          <a href="grafico.php?var=casaBatt">
            <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-casabatt" viewBox="0 0 24 24"
              fill="currentColor">
              <path d="M9 2 1 9h2.5v7h5v-4h1v4h5V9H17L9 2z" opacity="0.9" />
              <path d="M16.5 12H22a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1h-5.5a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1zm.5 2v5h4v-5h-4z" />
              <path d="M18 10h3v1.5h-3V10z" />
            </svg>
            <div class="card-label">Casa + Batteria</div>
            <div class="card-value"><span data-live="casaBatt.val"><?php echo $p['casaBatt']['val']; ?></span><span
                class="card-unit">W</span></div>
            <div class="flow-state" style="color:<?php echo $p['casaBatt']['breakdownColor']; ?>;"
              data-live="casaBatt.breakdown" data-live-color="casaBatt.breakdownColor">
              <?php echo $p['casaBatt']['breakdown']; ?>
            </div>
            <div class="minmax-row">
              <div class="minmax-item"><span class="minmax-label">Picco 24h</span><span class="minmax-val"
                  data-live="casaBatt.peak"><?php echo $p['casaBatt']['peak']; ?></span></div>
            </div>
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($emAvailable && !empty($p['quadro']['show'])): ?>
      <!-- 12. Quadro principale: la fotografia elettrica dietro alle due card
           di potenza. Tensione e frequenza sono di linea (un solo contatore,
           due TA sulla stessa monofase: le due tensioni misurate sono la
           stessa), le correnti no -- una e' quella dell'inverter, l'altra
           quella che attraversa il contatore.
           Le correnti arrivano SENZA segno: il verso sta solo nel segno di
           gridPower, per questo accanto agli ampere della rete c'e' la parola
           che lo dice. -->
      <div class="card border-quadro" data-card="quadro"
        style="display:flex; flex-direction:column; align-items:center;">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-quadro" viewBox="0 0 24 24"
          fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="18" height="18" rx="2" />
          <line x1="3" y1="9" x2="21" y2="9" />
          <line x1="9" y1="9" x2="9" y2="21" />
          <line x1="6" y1="6" x2="6" y2="6" />
        </svg>
        <div class="card-label">Quadro Principale</div>
        <div style="font-size:0.6rem; color:var(--text-muted); margin-bottom:2px;">letture dei due TA del contatore</div>

        <div class="diag-group">Linea</div>
        <div class="diag-rows">
            <span class="k">Tensione</span><span class="v" data-live="quadro.vLine"><?php echo $p['quadro']['vLine']; ?></span>
            <span class="k">Frequenza</span><span class="v" data-live="quadro.hz"><?php echo $p['quadro']['hz']; ?></span>
        </div>

        <div class="diag-group">Scambio rete</div>
        <div class="diag-rows">
            <span class="k">Corrente</span><span class="v"><span data-live="quadro.iGrid"><?php echo $p['quadro']['iGrid']; ?></span> <span class="dir" data-live="quadro.dirGrid"><?php echo $p['quadro']['dirGrid']; ?></span></span>
            <span class="k">Potenza attiva</span><span class="v" data-live="quadro.wGrid"><?php echo $p['quadro']['wGrid']; ?></span>
            <span class="k">Fattore di potenza</span><span class="v" data-live="quadro.pfGrid"><?php echo $p['quadro']['pfGrid']; ?></span>
            <span class="k">Tensione</span><span class="v" data-live="quadro.vGrid"><?php echo $p['quadro']['vGrid']; ?></span>
        </div>

        <div class="diag-group">Fotovoltaico</div>
        <div class="diag-rows">
            <span class="k">Corrente</span><span class="v" data-live="quadro.iPv"><?php echo $p['quadro']['iPv']; ?></span>
            <span class="k">Potenza attiva</span><span class="v" data-live="quadro.wPv"><?php echo $p['quadro']['wPv']; ?></span>
            <span class="k">Fattore di potenza</span><span class="v" data-live="quadro.pfPv"><?php echo $p['quadro']['pfPv']; ?></span>
            <span class="k">Tensione</span><span class="v" data-live="quadro.vPv"><?php echo $p['quadro']['vPv']; ?></span>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($p['battAvailable']) && !empty($p['battDiag']['show'])): ?>
      <!-- 13. Tutto quello che il pacco dice di se'. La corrente AC e' ricavata
           da |W| / V: il registro che la mappa chiama ac_current, su questo
           firmware, restituisce la potenza. -->
      <!-- Questa card NON e' avvolta in un <a> come le altre: ogni valore
           porta al proprio grafico, e un link dentro un link non e' HTML
           valido. Da qui il markup a mano e la regola CSS .diag-rows a. -->
      <div class="card border-diag" data-card="battDiag"
        style="display:flex; flex-direction:column; align-items:center;">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-diag" viewBox="0 0 24 24"
          fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 4v10.5a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0z" />
          <line x1="14" y1="8" x2="17.5" y2="8" />
          <line x1="14" y1="12" x2="17.5" y2="12" />
        </svg>
        <div class="card-label">Diagnostica Batteria</div>
        <div style="font-size:0.6rem; color:var(--text-muted); margin-bottom:2px;">tocca un valore per il grafico</div>

        <div class="diag-group">Temperature</div>
        <div class="diag-rows">
            <span class="k">Cella max</span><span class="v"><a href="grafico.php?var=battTemp" data-live="battDiag.tCellMax"><?php echo $p['battDiag']['tCellMax']; ?></a></span>
            <span class="k">Cella min</span><span class="v"><a href="grafico.php?var=battTempMin" data-live="battDiag.tCellMin"><?php echo $p['battDiag']['tCellMin']; ?></a></span>
            <span class="k">&Delta; celle</span><span class="v <?php echo $p['battDiag']['tSpreadWarn'] ? 'warn' : ''; ?>" data-live-warn="battDiag.tSpreadWarn"><a href="grafico.php?var=battTempSpread" data-live="battDiag.tSpread"><?php echo $p['battDiag']['tSpread']; ?></a></span>
            <span class="k">Interna</span><span class="v"><a href="grafico.php?var=battTempInt" data-live="battDiag.tInt"><?php echo $p['battDiag']['tInt']; ?></a></span>
            <span class="k">MOS 1</span><span class="v"><a href="grafico.php?var=battTempMos1" data-live="battDiag.tMos1"><?php echo $p['battDiag']['tMos1']; ?></a></span>
            <span class="k">MOS 2</span><span class="v"><a href="grafico.php?var=battTempMos2" data-live="battDiag.tMos2"><?php echo $p['battDiag']['tMos2']; ?></a></span>
        </div>

        <div class="diag-group">Tensioni</div>
        <div class="diag-rows">
            <span class="k">Pacco</span><span class="v"><a href="grafico.php?var=battVolt" data-live="battDiag.vPack"><?php echo $p['battDiag']['vPack']; ?></a></span>
            <span class="k">Cella max</span><span class="v"><a href="grafico.php?var=battCellVMax" data-live="battDiag.vCellMax"><?php echo $p['battDiag']['vCellMax']; ?></a></span>
            <span class="k">Cella min</span><span class="v"><a href="grafico.php?var=battCellVMin" data-live="battDiag.vCellMin"><?php echo $p['battDiag']['vCellMin']; ?></a></span>
            <span class="k">&Delta; celle</span><span class="v <?php echo $p['battDiag']['vSpreadWarn'] ? 'warn' : ''; ?>" data-live-warn="battDiag.vSpreadWarn"><a href="grafico.php?var=battCellVSpread" data-live="battDiag.vSpread"><?php echo $p['battDiag']['vSpread']; ?></a></span>
            <span class="k">Rete AC</span><span class="v"><a href="grafico.php?var=battAcV" data-live="battDiag.vAc"><?php echo $p['battDiag']['vAc']; ?></a></span>
            <span class="k">Frequenza</span><span class="v"><a href="grafico.php?var=battAcHz" data-live="battDiag.hz"><?php echo $p['battDiag']['hz']; ?></a></span>
        </div>

        <div class="diag-group">Correnti</div>
        <div class="diag-rows">
            <span class="k">Pacco</span><span class="v"><a href="grafico.php?var=battCurr" data-live="battDiag.iPack"><?php echo $p['battDiag']['iPack']; ?></a></span>
            <span class="k">Rete AC (da W/V)</span><span class="v"><a href="grafico.php?var=battAcCurr" data-live="battDiag.iAc"><?php echo $p['battDiag']['iAc']; ?></a></span>
        </div>
      </div>
    <?php endif; ?>

    <!-- 10. Feedback Previsioni -->
    <div class="card border-feedback">
      <a href="feedback.php">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-feedback" viewBox="0 0 24 24" fill="currentColor">
          <path
            d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zM9.5 12.5l-2.5-3 1.4-1.1 1 1.3 3.2-3.9 1.4 1.2-4.5 5.5z" />
        </svg>
        <div class="card-label">Feedback Previsioni</div>
        <div class="card-value" style="font-size:1.3rem; margin-top:6px;">Com'è il cielo?</div>
        <div style="font-weight:600; font-size:0.78rem; color:var(--text-muted); margin-top:8px; text-align:center;">
          Segnala il meteo reale<br>per tarare l'algoritmo
        </div>
        <div class="minmax-row">
          <div class="minmax-item"><span class="minmax-label">Previsto ora</span><span class="minmax-val"
              style="color:<?php echo $p['pres']['color']; ?>" data-live="pres.text"
              data-live-color="pres.color"><?php echo $p['pres']['text']; ?></span></div>
        </div>
      </a>
    </div>

    <!-- 11. Multi Plot -->
    <div class="card border-multi">
      <a href="grafico.php?var=multi">
        <svg xmlns="http://www.w3.org/2000/svg" class="card-icon icon-multi" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 3v18h18" />
          <path d="M7 15l3-4 3 3 4-6" />
        </svg>
        <div class="card-label">Multi Plot</div>
        <div class="card-value" style="font-size:1.3rem; margin-top:6px;">Confronta dati</div>
        <div style="font-weight:600; font-size:0.78rem; color:var(--text-muted); margin-top:8px; text-align:center;">
          Sovrapponi più variabili<br>sullo stesso grafico
        </div>
        <div class="minmax-row">
          <div class="minmax-item"><span class="minmax-label">Grafico</span><span class="minmax-val"
              style="color:var(--accent-teal)">Comparativo</span></div>
        </div>
      </a>
    </div>
  </div>

  <footer style="margin-top:40px; text-align:center; font-size:0.8rem; color:var(--text-muted);">
    &copy; <?php echo date("Y"); ?> Cesana Beach | Alt: 264m slm | Powered by Steplab
  </footer>

  <script>
    /**
     * Live refresh: long-poll live.php with the revision we are showing and
     * patch the DOM the moment the server has a newer one. The connection is
     * held open server-side, so a new reading from the Raspberry lands here in
     * well under a second instead of waiting for a timed page reload.
     */
    (function () {
      var rev = <?php echo $rev; ?>;
      var emAvailable = <?php echo $emAvailable ? 'true' : 'false'; ?>;
      var battAvailable = <?php echo !empty($p['battAvailable']) ? 'true' : 'false'; ?>;
      var backoff = 0;          // grows only while the endpoint is failing
      var inFlight = false;
      var firstPoll = true;     // the first request skips the server-side hold

      /** "pres.trendText" -> value, so markup can address fields by path. */
      function flatten(obj, prefix, out) {
        for (var k in obj) {
          if (!Object.prototype.hasOwnProperty.call(obj, k)) continue;
          var v = obj[k];
          var key = prefix ? prefix + '.' + k : k;
          if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, key, out);
          else out[key] = v;
        }
        return out;
      }

      function pulse(el) {
        el.classList.remove('pulse');
        void el.offsetWidth;      // restart the animation
        el.classList.add('pulse');
      }

      function card(name) {
        return document.querySelector('[data-card="' + name + '"]');
      }

      var updatedEl = document.getElementById('live-updated');

      /**
       * Stamp the header with the moment this browser last heard from the
       * server. Deliberately the visitor's own clock, not the server's: it
       * answers "is what I am looking at current?", which is a question about
       * the here and now. The reading's own timestamp sits next to it.
       */
      function markUpdated() {
        if (!updatedEl) return;
        updatedEl.textContent = new Date().toLocaleTimeString('it-IT', { hour12: false });
        updatedEl.classList.remove('stale');
      }

      /** Poll failed: the time on display is no longer being kept current. */
      function markStale() {
        if (updatedEl) updatedEl.classList.add('stale');
      }

      function apply(p) {
        var f = flatten(p, '', {});

        document.querySelectorAll('[data-live]').forEach(function (el) {
          var k = el.getAttribute('data-live');
          if (!(k in f)) return;
          var next = String(f[k]) + (el.getAttribute('data-suffix') || '');
          // Compare trimmed: PHP renders some of these with surrounding
          // newlines, which would otherwise look like a change on every poll.
          if (el.textContent.trim() === next.trim()) return;
          el.textContent = next;
          pulse(el);
        });

        document.querySelectorAll('[data-live-html]').forEach(function (el) {
          var k = el.getAttribute('data-live-html');
          if (!(k in f) || el.innerHTML.trim() === String(f[k]).trim()) return;
          el.innerHTML = f[k];
          pulse(el);
        });

        document.querySelectorAll('[data-live-color]').forEach(function (el) {
          var k = el.getAttribute('data-live-color');
          if (!(k in f)) return;
          // Compared against the last value we applied, not el.style.color: the
          // browser normalises "#3b82f6" to "rgb(...)", so reading it back would
          // look like a change on every single poll.
          var prev = el.__liveColor;
          el.style.color = f[k];
          el.__liveColor = f[k];
          if (prev !== undefined && prev !== f[k]) pulse(el);
        });

        document.querySelectorAll('[data-live-show]').forEach(function (el) {
          var k = el.getAttribute('data-live-show');
          if (!(k in f)) return;
          var v = f[k];
          var next = (v === '' || v === false || v === null) ? 'none' : '';
          if (el.style.display === next) return;
          el.style.display = next;
          if (next === '') pulse(el);   // blink only when a block appears
        });

        // The battery's fill bar: a width and a colour rather than text, so it
        // gets its own two hooks instead of squeezing into data-live.
        document.querySelectorAll('[data-live-width]').forEach(function (el) {
          var k = el.getAttribute('data-live-width');
          if (!(k in f) || el.style.width === f[k]) return;
          el.style.width = f[k];
        });

        document.querySelectorAll('[data-live-bg]').forEach(function (el) {
          var k = el.getAttribute('data-live-bg');
          if (!(k in f)) return;
          // Same reason as data-live-color: the browser rewrites the value it
          // stores, so the comparison is against what we last set.
          if (el.__liveBg === f[k]) return;
          el.style.background = f[k];
          el.__liveBg = f[k];
        });

        // Amber when a spread goes out of range: a class, not a colour, so
        // the threshold stays in PHP with the value it judges.
        document.querySelectorAll('[data-live-warn]').forEach(function (el) {
          var k = el.getAttribute('data-live-warn');
          if (!(k in f)) return;
          el.classList.toggle('warn', !!f[k]);
        });

        document.querySelectorAll('[data-live-href]').forEach(function (el) {
          var k = el.getAttribute('data-live-href');
          if (k in f) el.setAttribute('href', f[k]);
        });

        document.querySelectorAll('[data-live-trendclass]').forEach(function (el) {
          var k = el.getAttribute('data-live-trendclass');
          if (!(k in f)) return;
          el.classList.remove('trend-up', 'trend-down');
          if (f[k]) el.classList.add(f[k]);
        });

        // Icon swaps: only the variant matching the current state stays visible.
        document.querySelectorAll('[data-fc-icon]').forEach(function (el) {
          el.style.display = (el.getAttribute('data-fc-icon') === p.pres.icon) ? '' : 'none';
        });
        var piaveIcon = (p.piave.status === 'dry') ? 'dry' : 'wet';
        document.querySelectorAll('[data-piave-icon]').forEach(function (el) {
          el.style.display = (el.getAttribute('data-piave-icon') === piaveIcon) ? '' : 'none';
        });

        // Card-level state classes.
        [['tempSole', p.tempSole.freezing], ['tempOmbra', p.tempOmbra.freezing],
        ['tempInterno', p.tempInterno.freezing]].forEach(function (pair) {
          var c = card(pair[0]);
          if (c) c.classList.toggle('freezing', !!pair[1]);
        });
        if (card('pv')) card('pv').classList.toggle('night-mode', !!p.pv.night);
        if (card('piave')) card('piave').classList.toggle('river-dry', p.piave.status === 'dry');
        if (card('grid')) card('grid').classList.toggle('exporting', !!p.grid.exporting);
        if (card('prelievo')) card('prelievo').classList.toggle('drawing', !!p.prelievo.drawing);
        if (card('batteria') && p.batteria) {
          card('batteria').classList.toggle('discharging', !!p.batteria.discharging);
        }
      }

      function schedule(ms) {
        setTimeout(function () { if (!document.hidden) poll(); }, ms);
      }

      function poll() {
        if (inFlight) return;
        inFlight = true;

        // The server holds the request; abort a little past its own deadline so
        // a dead proxy can never leave us hanging forever.
        var ctrl = ('AbortController' in window) ? new AbortController() : null;
        var killer = setTimeout(function () { if (ctrl) ctrl.abort(); }, 45000);
        var started = Date.now();

        // The first request asks the server not to hold: it answers at once,
        // which stamps the "Aggiornato" clock straight away instead of leaving
        // a dash on screen for the length of the first hold. Every request
        // after it long-polls normally.
        var wasFirst = firstPoll;
        firstPoll = false;

        fetch('live.php?rev=' + rev + (wasFirst ? '&wait=0' : ''), {
          cache: 'no-store',
          signal: ctrl ? ctrl.signal : undefined
        })
          .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
          })
          .then(function (data) {
            backoff = 0;
            // A "nothing new" answer is still a completed round trip, so it
            // counts: this clock doubles as the proof the link is alive.
            markUpdated();
            var changed = false;
            if (data && !data.nochange && typeof data.rev === 'number') {
              changed = true;
              // The two meter cards only exist in the markup when the Shelly is
              // reporting; if that flips, the layout itself has to change.
              if (!!data.emAvailable !== emAvailable) { location.reload(); return; }
              // Same for the battery card: it is in the markup only while the
              // battery reports, so its arrival or departure is a layout change.
              if (!!data.battAvailable !== battAvailable) { location.reload(); return; }
              rev = data.rev;
              apply(data);
            }
            // Reconnect at once: normally the server just held the request for
            // its full window. Only an immediate "nothing new" means the hold
            // is not working (disabled, or a proxy buffering us), and then we
            // degrade to a plain 5 s poll rather than spinning.
            schedule((!changed && !wasFirst && Date.now() - started < 2000) ? 5000 : 0);
          })
          .catch(function () {
            markStale();
            backoff = Math.min(backoff ? backoff * 2 : 5000, 60000);
            schedule(backoff);
          })
          .then(function () {
            clearTimeout(killer);
            inFlight = false;
          });
      }

      // A hidden tab holds a PHP worker for nothing: stop, and catch up on return.
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) poll();
      });

      poll();
    })();
  </script>

  <script>
    /**
     * Rain nowcast (Open-Meteo, no key, no quota registration).
     *
     * This is the one card whose numbers do NOT come from our own snapshot:
     * the browser talks to api.open-meteo.com directly, so a failure here can
     * never hold up a PHP worker or delay the rest of the dashboard.
     *
     * The columns are the model's OWN 15-minute buckets (mm fallen in each),
     * not round-number horizons: 15 minutes is the finest grid Open-Meteo
     * publishes for Central Europe (ICON-D2 / AROME), so anything labelled
     * "5 minuti" would only be that same bucket wearing a nicer label. Each
     * bucket is shown as mm/h, with the probability read from the hourly
     * series -- the only resolution at which Open-Meteo exposes it.
     */
    (function () {
      var LAT = 46.0186, LON = 11.9931;   // Cesana di Lentiai (BL), 264 m slm
      var SLOTS = 6;                      // current bucket + the next 90 minutes
      var REFRESH_MS = 10 * 60 * 1000;

      var URL = 'https://api.open-meteo.com/v1/forecast'
        + '?latitude=' + LAT + '&longitude=' + LON
        + '&minutely_15=precipitation'
        + '&hourly=precipitation_probability'
        + '&forecast_days=2&timezone=UTC';

      var summaryEl = document.getElementById('rain-summary');
      var totalEl = document.getElementById('rain-total');
      var nextEl = document.getElementById('rain-next');
      var nextTextEl = document.getElementById('rain-next-text');

      /** Open-Meteo timestamps come back as "2026-08-29T14:30" in UTC. */
      function ts(s) { return Date.parse(s + ':00Z'); }

      /** Index of the entry whose slot of `stepMin` minutes contains `t`. */
      function slotIndex(times, t, stepMin) {
        var span = stepMin * 60000;
        for (var i = 0; i < times.length; i++) {
          var start = ts(times[i]);
          if (t >= start && t < start + span) return i;
        }
        return -1;
      }

      function num(v) { return (typeof v === 'number' && isFinite(v)) ? v : null; }

      /** mm/h -> the wording a person would use looking out of the window. */
      function describe(mmh) {
        if (mmh < 0.1) return ['Asciutto', 'var(--text-muted)'];
        if (mmh < 1) return ['Pioviggine', 'var(--accent-teal)'];
        if (mmh < 4) return ['Pioggia debole', 'var(--accent-blue)'];
        if (mmh < 10) return ['Pioggia', 'var(--accent-blue)'];
        return ['Rovescio', 'var(--accent-red)'];
      }

      function fmt(mmh) {
        if (mmh === null) return '—';
        if (mmh < 0.1) return '0';
        return mmh < 10 ? mmh.toFixed(1) : Math.round(mmh).toString();
      }

      function fail(msg) {
        summaryEl.textContent = msg;
        summaryEl.style.color = 'var(--text-muted)';
        totalEl.textContent = '—';
      }

      function render(data) {
        var mTimes = data && data.minutely_15 && data.minutely_15.time;
        var mPrec = data && data.minutely_15 && data.minutely_15.precipitation;
        if (!mTimes || !mPrec) { fail('Previsione non disponibile'); return; }

        var hTimes = (data.hourly && data.hourly.time) || [];
        var hProb = (data.hourly && data.hourly.precipitation_probability) || [];

        var now = Date.now();
        var maxMmh = 0, total = 0, maxProb = null, firstWet = null;

        // The bucket we are inside right now; the row walks forward from it.
        var base = slotIndex(mTimes, now, 15);
        if (base < 0) { fail('Previsione non disponibile'); return; }

        for (var k = 0; k < SLOTS; k++) {
          var i = base + k;
          var start = i < mTimes.length ? ts(mTimes[i]) : null;
          var mm = start === null ? null : num(mPrec[i]);
          var mmh = mm === null ? null : mm * 4;

          var j = start === null ? -1 : slotIndex(hTimes, start, 60);
          var prob = j >= 0 ? num(hProb[j]) : null;

          var whenEl = document.querySelector('[data-rain-when="' + k + '"]');
          var valEl = document.querySelector('[data-rain="' + k + '"]');
          var probEl = document.querySelector('[data-rain-prob="' + k + '"]');

          if (whenEl) {
            // The first column is the quarter-hour in progress, so it gets the
            // word rather than a clock time that is already partly in the past.
            whenEl.textContent = start === null ? '—' : (k === 0 ? 'ora' :
              new Date(start).toLocaleTimeString('it-IT',
                { hour: '2-digit', minute: '2-digit', hour12: false }));
          }
          if (valEl) {
            valEl.textContent = fmt(mmh);
            valEl.style.color = (mmh !== null && mmh >= 0.1) ? describe(mmh)[1] : 'var(--text-muted)';
          }
          if (probEl) probEl.textContent = prob === null ? '' : Math.round(prob) + '%';

          if (mmh !== null && mmh > maxMmh) maxMmh = mmh;
          if (prob !== null && (maxProb === null || prob > maxProb)) maxProb = prob;
          if (mm !== null) {
            total += mm;
            if (firstWet === null && mm >= 0.05) firstWet = start;
          }
        }

        totalEl.textContent = total < 0.05 ? '0' : total.toFixed(1);

        var d = describe(maxMmh);
        summaryEl.textContent = d[0] + (maxProb !== null ? ' · ' + Math.round(maxProb) + '%' : '');
        summaryEl.style.color = d[1];

        if (firstWet !== null) {
          var mins = Math.max(0, Math.round((firstWet - now) / 60000));
          nextTextEl.textContent = mins <= 0 ? 'in corso' : 'inizio tra ~' + mins + ' min';
          nextEl.style.display = '';
        } else {
          nextEl.style.display = 'none';
        }
      }

      function load() {
        fetch(URL, { cache: 'no-store' })
          .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
          })
          .then(render)
          .catch(function () { fail('Previsione non disponibile'); });
      }

      load();
      setInterval(function () { if (!document.hidden) load(); }, REFRESH_MS);
    })();
  </script>
</body>
</html>
<?php
$output = ob_get_contents();
ob_end_flush();
if ($output !== false)
  file_put_contents($file_path, $output);
?>