<?php
// Shared view of the thermostat control page. The Pi's crono.php builds the
// snapshot from crono.db, the remote server's crono.php gets the same snapshot
// over MQTT (published by crono_remote.py), and both render it with
// render_crono_page() — so the two pages can't drift apart.
//
// The single source of this file is "rapsberry meteo/crono_ui.php"; the remote
// deploy.sh copies it from there.
//
// Snapshot shape (see build_snapshot() in the Pi's crono.php):
//   settings  {mode, manual_target, default_target, learning}
//   override  null | {type, target, until}
//   state     crono_state.json as written by crono_watcher.py
//   ufficio   latest office reading {temp, heater, ...} or null
//   today     today's enabled bands [{time_from, time_to, target, origin}]
//   plan      24 × {h, target, src}      actual  24 × null|{temp, on}
//   now       {hm, hour, dow}            (Pi local time, dow 1 = Monday)

const DAY_LABELS = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
const MODE_LABELS = ['auto' => 'Auto', 'manual' => 'Manuale', 'off' => 'Spento'];
const MORNING_HOUR = 6;       // matches crono_watcher.py: "fino a domani" ends here

function fmt_t($v) {
  return rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
}

// $opt: flash, flash_ok, load_err, stale (banner text or ''), other_href,
// other_label, schedule_href (null hides the editor link), schedule_label.
function render_crono_page(array $snap, array $opt) {
  $settings = ($snap['settings'] ?? []) + ['mode' => 'auto', 'manual_target' => 20, 'default_target' => 7, 'learning' => true];
  $override = $snap['override'] ?? null;
  $today    = $snap['today'] ?? [];
  $plan     = $snap['plan'] ?? [];
  $actual   = $snap['actual'] ?? array_fill(0, 24, null);
  $now      = ($snap['now'] ?? []) + ['hm' => date('H:i'), 'hour' => (int)date('G'), 'dow' => (int)date('N')];
  $mode     = isset(MODE_LABELS[$settings['mode']]) ? $settings['mode'] : 'auto';
  $manualT  = (float)$settings['manual_target'];
  $learning = (bool)$settings['learning'];
  $nowHm    = $now['hm'];
  $dayLabel = DAY_LABELS[max(1, min(7, (int)$now['dow'])) - 1];
  $flash    = (string)($opt['flash'] ?? '');
  $flashOk  = (bool)($opt['flash_ok'] ?? true);
  $loadErr  = (string)($opt['load_err'] ?? '');
  $stale    = (string)($opt['stale'] ?? '');
  $offLabel = 'Antigelo ' . fmt_t($settings['default_target']) . '°C';
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
    .pill.fault { background: var(--hot); color: #fff; }
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
    <div class="flash err"><?= htmlspecialchars($loadErr) ?></div>
  <?php endif; ?>
  <div class="flash err" id="s-stale" <?= $stale !== '' ? '' : 'hidden' ?>><?= htmlspecialchars($stale) ?></div>

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
      Comando attivo: <b id="ov-label"><?= $override ? htmlspecialchars($override['type'] === 'off' ? $offLabel : fmt_t($override['target']) . '°C') : '' ?></b><br>
      fino alle <strong id="ov-until"><?= $override ? htmlspecialchars((string)$override['until']) : '' ?></strong>
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
        <div class="val"><span>19</span><small>°C</small></div>
        <button type="button" data-d="1" aria-label="Aumenta">+</button>
        <input type="hidden" name="target" value="19">
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
        <button type="submit" class="btn primary" data-type="set">Scalda a <span class="echo">19</span>°C</button>
        <button type="submit" class="btn ghost" data-type="off">Spegni · <?= fmt_t($settings['default_target']) ?>°</button>
      </div>
    </form>
  </div>

  <!-- ── Today's schedule ── -->
  <div class="card">
    <h2>Programma di oggi · <?= $dayLabel ?></h2>
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
            <?php foreach ($plan as $p): $a = $actual[$p['h']] ?? null; ?>
              <tr>
                <td><?= sprintf('%02d:00', $p['h']) ?></td>
                <td><?= fmt_t($p['target']) ?>°</td>
                <td><?= ['manual' => 'Manuale', 'learned' => 'Appreso', 'default' => 'Antigelo'][$p['src']] ?? '' ?></td>
                <td><?= $a ? fmt_t($a['temp']) . '°' : '—' ?></td>
                <td><?= $a ? (int)$a['on'] . '%' : '—' ?></td>
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

  <div class="foot">
    <?php if (!empty($opt['schedule_href'])): ?>
      <a href="<?= htmlspecialchars($opt['schedule_href']) ?>"><?= htmlspecialchars($opt['schedule_label'] ?? 'Modifica programma e impostazioni →') ?></a><br>
    <?php endif; ?>
    <?php if (!empty($opt['other_href'])): ?>
      <a href="<?= htmlspecialchars($opt['other_href']) ?>"><?= htmlspecialchars($opt['other_label'] ?? '') ?></a>
    <?php endif; ?>
  </div>

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
      const PLAN = <?= json_encode(array_values($plan)) ?>;
      const ACT = <?= json_encode(array_values($actual)) ?>;
      const NOW_H = <?= (int)$now['hour'] ?>;
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
    const OFF_LABEL = <?= json_encode($offLabel) ?>;

    async function poll() {
      try {
        const res = await fetch('?api=status', { cache: 'no-store' });
        const d = await res.json();
        const st = d.state || {}, uff = d.ufficio || {};

        // Remote page only: the Pi stopped publishing, values below are its last ones.
        $('s-stale').hidden = !d.stale;
        if (d.stale) $('s-stale').textContent = d.stale;

        const temp = (st.temp !== undefined && st.temp !== null) ? st.temp : uff.temp;
        $('s-temp').textContent = fmt(temp);
        // The watcher's decided target; null means forced OFF (command/mode) or failsafe.
        const tgt = $('s-target');
        const hasTgt = st.target !== undefined && st.target !== null;
        tgt.textContent = hasTgt ? fmt(st.target) + '°' : (st.heater === 'OFF' ? 'OFF' : '—');
        tgt.className = 'sp-val' + (hasTgt ? '' : ' off');

        // An unreachable Shelly can't be switching the relay, whatever the watcher wants.
        const heaterEl = $('s-heater');
        if (st.shelly_available === false) {
          heaterEl.textContent = 'Caldaia spenta';
          heaterEl.className = 'pill fault';
          heaterEl.title = 'Shelly della caldaia non raggiungibile';
        } else {
          const heater = (st.heater || uff.heater || '').toUpperCase();
          heaterEl.textContent = 'Caldaia ' + (heater || '—');
          heaterEl.className = 'pill' + (heater === 'ON' ? ' on' : heater === 'OFF' ? ' off' : '');
          heaterEl.title = '';
        }

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
          $('ov-label').textContent = ov.type === 'off' ? OFF_LABEL : (+Number(ov.target).toFixed(1)) + '°C';
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
<?php
}
