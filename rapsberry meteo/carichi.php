<?php
// ── Configuration ────────────────────────────────────────────────────────────
// "Carichi" — registratore dei profili di consumo dei grandi elettrodomestici
// (lavastoviglie, lavatrice, forno, pompa di calore...).
//
// Si preme "Avvia" quando il carico parte e "Ferma" quando ha finito: la pagina
// ritaglia dalla tabella `energia` (Shelly Pro EM-50, una riga al minuto scritta
// da mqtt_receiver.py) la finestra corrispondente e la copia in un DB suo,
// persistente. Da più registrazioni dello stesso carico ricava un profilo medio
// e, incrociandolo con la produzione fotovoltaica tipica, calcola l'ora di
// avvio che massimizza l'autoconsumo.
//
// Il ritaglio avviene dalla tabella, non dal browser: la registrazione va avanti
// anche a pagina chiusa, e alla pressione di "Ferma" i campioni mancanti vengono
// recuperati comunque. L'unico caso che perde dati è un riavvio del Pi durante
// il ciclo, perché meteo.db sta in tmpfs.
//
// Per lo stesso motivo — meteo.db si azzera a ogni riavvio — a ogni richiesta la
// pagina consolida qui il profilo FV del giorno (medie per quarto d'ora). Così
// lo storico che serve all'ottimizzatore sopravvive ai riavvii senza bisogno di
// un demone dedicato.
define('METEO_DB',   '/dev/shm/meteo.db');
define('CARICHI_DB', '/var/www/html/carichi.db');

// Live Shelly snapshot, rewritten by mqtt_receiver.py on EVERY meter message
// (~2 s). I riquadri istantanei leggono questo, come fanno index.php e
// batteria.php, così seguono il contatore e non la tabella al minuto.
define('ENERGY_LATEST_PATH',    '/dev/shm/energy_latest.json');
define('ENERGY_LATEST_MAX_AGE', 150);   // seconds; matches mqtt_receiver.py
define('BATTERY_LATEST_PATH',   '/dev/shm/battery_latest.json');

// Marstek Venus E: capacità nominale e potenza massima di scarica. Servono solo
// all'ottimizzatore per stimare quanta parte del ciclo la batteria può coprire.
// 800 W è il limite di uscita di questo impianto: un carico che tira di più (il
// forno, la resistenza della lavastoviglie) prende dalla batteria solo 800 W e
// il resto lo va a chiedere alla rete, ed è proprio quello che l'ottimizzatore
// deve tenere in conto quando confronta due orari.
define('BATTERY_CAPACITY_KWH',    5.12);
define('BATTERY_MAX_DISCHARGE_W', 800);

define('BASELINE_WINDOW_MIN', 15);  // minuti prima dell'avvio usati come fondo casa
define('SLOT_MIN',            15);  // granularità del profilo FV giornaliero
define('SLOTS_PER_DAY',       96);  // 24 h / SLOT_MIN
define('PROFILE_DAYS',        30);  // giorni di storico FV tenuti per l'ottimizzatore
define('SAMPLE_GAP_MAX',     300);  // s: oltre questo un buco non viene integrato
define('CONSOLIDATE_EVERY',  300);  // s fra due consolidamenti del profilo FV

// ── Database ────────────────────────────────────────────────────────────────
function carichi_db(): PDO
{
  static $db = null;
  if ($db !== null) return $db;

  $db = new PDO('sqlite:' . CARICHI_DB, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT            => 5,
  ]);

  $db->exec("CREATE TABLE IF NOT EXISTS sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    load_name  TEXT NOT NULL,
    started_at TEXT NOT NULL,
    ended_at   TEXT,
    baseline_w REAL,
    note       TEXT NOT NULL DEFAULT ''
  )");

  // Un campione per riga della tabella `energia` ricadente nella sessione.
  // La chiave unica su (session_id, ts) rende il recupero idempotente: si può
  // rileggere la stessa finestra quante volte si vuole senza duplicare nulla.
  $db->exec("CREATE TABLE IF NOT EXISTS samples (
    session_id INTEGER NOT NULL,
    ts         TEXT    NOT NULL,
    casa_power REAL,
    pv_power   REAL,
    grid_power REAL,
    PRIMARY KEY (session_id, ts)
  ) WITHOUT ROWID");

  // Coppie chiave/valore di servizio (per ora solo l'istante dell'ultimo
  // consolidamento, che serve a non rifarlo a ogni poll).
  $db->exec("CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT
  ) WITHOUT ROWID");

  // Profilo fotovoltaico giornaliero consolidato: una riga per quarto d'ora.
  // casa_min è il minimo del quarto d'ora, cioè la stima del carico di fondo
  // della casa in quella fascia (nel minimo gli elettrodomestici sono spenti).
  $db->exec("CREATE TABLE IF NOT EXISTS pv_profile (
    day      TEXT    NOT NULL,
    slot     INTEGER NOT NULL,
    pv_avg   REAL,
    casa_avg REAL,
    casa_min REAL,
    n        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (day, slot)
  ) WITHOUT ROWID");

  return $db;
}

function meteo_db(): ?PDO
{
  static $db = false;
  if ($db !== false) return $db;

  $db = null;
  if (!file_exists(METEO_DB)) return null;
  try {
    $db = new PDO('sqlite:' . METEO_DB, null, null, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_TIMEOUT            => 2,
    ]);
  } catch (Exception $e) {
    $db = null;
  }
  return $db;
}

// Taglio a $max caratteri. Non usa mbstring, che su questo Pi non è installato:
// la regex in modo /u conta i caratteri UTF-8, così un accento non resta spezzato
// a metà e finisce nel DB come byte invalido.
function clip(string $s, int $max): string
{
  if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) return $m[0];
  return substr($s, 0, $max);   // stringa non valida UTF-8: taglio grezzo
}

function utc_now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function utc_at(int $t): string { return gmdate('Y-m-d\TH:i:s\Z', $t); }

// Snapshot tmpfs, oppure null se manca o è vecchio. Stessa logica di index.php.
function snapshot(string $path, int $maxAge = ENERGY_LATEST_MAX_AGE): ?array
{
  if (!is_readable($path)) return null;
  $raw = @file_get_contents($path);
  if ($raw === false) return null;
  $row = json_decode($raw, true);
  if (!is_array($row)) return null;
  $ts = strtotime((string)($row['timestamp'] ?? ''));
  if ($ts === false || time() - $ts > $maxAge) return null;
  return $row;
}

// ── Ritaglio dei campioni ───────────────────────────────────────────────────
// Copia in `samples` le righe di `energia` comprese nella finestra della
// sessione. Idempotente (INSERT OR IGNORE): viene chiamata a ogni poll mentre la
// registrazione è in corso e di nuovo alla chiusura, così i minuti trascorsi a
// pagina chiusa rientrano lo stesso.
function ingest_session(int $sessionId, string $from, ?string $to): int
{
  $m = meteo_db();
  if ($m === null) return 0;

  $to = $to ?? utc_now();
  try {
    $st = $m->prepare("SELECT timestamp, casa_power, pv_power, grid_power
                         FROM energia
                        WHERE timestamp >= :a AND timestamp <= :b
                        ORDER BY timestamp");
    $st->execute([':a' => $from, ':b' => $to]);
    $rows = $st->fetchAll();
  } catch (Exception $e) {
    return 0;
  }

  $c = carichi_db();
  $ins = $c->prepare("INSERT OR IGNORE INTO samples (session_id, ts, casa_power, pv_power, grid_power)
                      VALUES (:s, :t, :c, :p, :g)");
  $n = 0;
  $c->beginTransaction();
  foreach ($rows as $r) {
    if ($r['casa_power'] === null) continue;   // riga senza pinza casa: inutile
    $ins->execute([
      ':s' => $sessionId,
      ':t' => $r['timestamp'],
      ':c' => $r['casa_power'],
      ':p' => $r['pv_power'],
      ':g' => $r['grid_power'],
    ]);
    $n += $ins->rowCount();
  }
  $c->commit();
  return $n;
}

// Carico di fondo della casa immediatamente prima dell'avvio: mediana di
// casa_power nei BASELINE_WINDOW_MIN minuti precedenti. Mediana e non media
// perché un picco isolato (il forno che si spegne, la pompa che parte) non deve
// gonfiare il fondo e sottrarre consumo al carico registrato.
function baseline_before(string $startedAt): ?float
{
  $m = meteo_db();
  if ($m === null) return null;

  $t = strtotime($startedAt);
  try {
    $st = $m->prepare("SELECT casa_power FROM energia
                        WHERE timestamp >= :a AND timestamp < :b AND casa_power IS NOT NULL");
    $st->execute([':a' => utc_at($t - BASELINE_WINDOW_MIN * 60), ':b' => $startedAt]);
    $vals = array_map('floatval', array_column($st->fetchAll(), 'casa_power'));
  } catch (Exception $e) {
    return null;
  }
  if (!$vals) return null;

  sort($vals);
  $n = count($vals);
  return $n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
}

// ── Profilo fotovoltaico persistente ────────────────────────────────────────
// Consolida in pv_profile i quarti d'ora presenti in `energia`. Gira a ogni
// richiesta: costa una scansione di una tabella che sta in RAM ed è l'unico modo
// per non perdere lo storico al riavvio senza aggiungere un servizio.
function consolidate_pv_profile(): void
{
  $m = meteo_db();
  if ($m === null) return;

  // Rifarlo a ogni poll (5 s) significherebbe rileggere tutta la tabella per
  // riscrivere gli stessi quarti d'ora: uno ogni CONSOLIDATE_EVERY secondi è
  // abbondante, visto che uno slot si chiude ogni quarto d'ora.
  $c = carichi_db();
  $last = $c->query("SELECT value FROM meta WHERE key = 'pv_consolidated_at'")->fetchColumn();
  if ($last !== false && time() - (int)$last < CONSOLIDATE_EVERY) return;

  try {
    $rows = $m->query("SELECT timestamp, pv_power, casa_power FROM energia
                        WHERE pv_power IS NOT NULL OR casa_power IS NOT NULL")->fetchAll();
  } catch (Exception $e) {
    return;
  }
  if (!$rows) return;

  // Aggregazione per (giorno locale, slot): l'ottimizzatore ragiona in ora
  // locale, che è quella in cui si fa partire la lavastoviglie.
  $acc = [];
  foreach ($rows as $r) {
    $t = strtotime((string)$r['timestamp']);
    if ($t === false) continue;
    $day  = date('Y-m-d', $t);
    $slot = intdiv((int)date('H', $t) * 60 + (int)date('i', $t), SLOT_MIN);
    $k    = $day . '|' . $slot;
    if (!isset($acc[$k])) $acc[$k] = ['pv' => [], 'casa' => []];
    if ($r['pv_power']   !== null) $acc[$k]['pv'][]   = (float)$r['pv_power'];
    if ($r['casa_power'] !== null) $acc[$k]['casa'][] = (float)$r['casa_power'];
  }

  // Si sovrascrive solo con un aggregato calcolato su almeno altrettanti
  // campioni: dopo un riavvio la tabella in RAM riparte con pochi punti e non
  // deve peggiorare uno slot già consolidato.
  $ins = $c->prepare("INSERT INTO pv_profile (day, slot, pv_avg, casa_avg, casa_min, n)
                      VALUES (:d, :s, :pv, :ca, :cm, :n)
                      ON CONFLICT(day, slot) DO UPDATE SET
                        pv_avg = excluded.pv_avg, casa_avg = excluded.casa_avg,
                        casa_min = excluded.casa_min, n = excluded.n
                      WHERE excluded.n >= pv_profile.n");
  $c->beginTransaction();
  foreach ($acc as $k => $v) {
    [$day, $slot] = explode('|', $k);
    $n = max(count($v['pv']), count($v['casa']));
    $ins->execute([
      ':d'  => $day,
      ':s'  => (int)$slot,
      ':pv' => $v['pv']   ? array_sum($v['pv']) / count($v['pv']) : null,
      ':ca' => $v['casa'] ? array_sum($v['casa']) / count($v['casa']) : null,
      ':cm' => $v['casa'] ? min($v['casa']) : null,
      ':n'  => $n,
    ]);
  }
  // Oltre PROFILE_DAYS lo storico non serve più: la stagione è cambiata e il
  // profilo di due mesi fa peggiorerebbe la previsione invece di migliorarla.
  $c->prepare("DELETE FROM pv_profile WHERE day < :cut")
    ->execute([':cut' => date('Y-m-d', time() - PROFILE_DAYS * 86400)]);
  $c->prepare("INSERT INTO meta (key, value) VALUES ('pv_consolidated_at', :t)
               ON CONFLICT(key) DO UPDATE SET value = excluded.value")
    ->execute([':t' => (string)time()]);
  $c->commit();
}

// Giornata "tipo": per ogni quarto d'ora la media dei giorni disponibili.
function typical_day(): array
{
  $rows = carichi_db()->query("SELECT slot,
                                      AVG(pv_avg)   AS pv,
                                      AVG(casa_min) AS base,
                                      COUNT(DISTINCT day) AS days
                                 FROM pv_profile GROUP BY slot")->fetchAll();

  $pv   = array_fill(0, SLOTS_PER_DAY, null);
  $base = array_fill(0, SLOTS_PER_DAY, null);
  $days = 0;
  foreach ($rows as $r) {
    $s = (int)$r['slot'];
    if ($s < 0 || $s >= SLOTS_PER_DAY) continue;
    $pv[$s]   = $r['pv']   === null ? null : (float)$r['pv'];
    $base[$s] = $r['base'] === null ? null : (float)$r['base'];
    $days = max($days, (int)$r['days']);
  }
  return ['pv' => $pv, 'base' => $base, 'days' => $days];
}

// ── Analisi di una registrazione ────────────────────────────────────────────
// Potenza netta = consumo casa − fondo misurato prima dell'avvio, mai negativa:
// è la quota attribuibile al carico registrato.
function analyze(array $samples, ?float $baseline): array
{
  $b = $baseline ?? 0.0;
  $out = [
    'points'     => [],
    'duration_s' => 0,
    'energy_wh'  => 0.0,
    'peak_w'     => null,
    'avg_w'      => null,
    'grid_wh'    => 0.0,
    'pv_share'   => null,
    'baseline_w' => $baseline,
    'samples'    => count($samples),
  ];
  if (!$samples) return $out;

  $t0 = strtotime($samples[0]['ts']);
  $prevT = null; $prevNet = null; $prevGrid = 0.0;
  $gridWh = 0.0; $peak = null;

  foreach ($samples as $s) {
    $t   = strtotime($s['ts']);
    $net = max(0.0, (float)$s['casa_power'] - $b);
    $out['points'][] = [
      't'    => $t - $t0,
      'net'  => round($net, 1),
      'casa' => $s['casa_power'] === null ? null : round((float)$s['casa_power'], 1),
      'pv'   => $s['pv_power']   === null ? null : round((float)$s['pv_power'], 1),
      'grid' => $s['grid_power'] === null ? null : round((float)$s['grid_power'], 1),
      'ts'   => $s['ts'],
    ];
    $peak = $peak === null ? $net : max($peak, $net);

    // Trapezi sui delta reali. Un buco più lungo di SAMPLE_GAP_MAX (ricevitore
    // fermo) non viene integrato: meglio sottostimare che inventare energia.
    if ($prevT !== null) {
      $dt = $t - $prevT;
      if ($dt > 0 && $dt <= SAMPLE_GAP_MAX) {
        $out['energy_wh'] += ($net + $prevNet) / 2 * $dt / 3600;
        $g  = max(0.0, (float)($s['grid_power'] ?? 0));
        $pg = max(0.0, (float)$prevGrid);
        $gridWh += ($g + $pg) / 2 * $dt / 3600;
      }
    }
    $prevT = $t; $prevNet = $net; $prevGrid = $s['grid_power'] ?? 0;
  }

  $out['duration_s'] = $prevT - $t0;
  $out['energy_wh']  = round($out['energy_wh'], 1);
  $out['grid_wh']    = round($gridWh, 1);
  $out['peak_w']     = $peak === null ? null : round($peak, 1);
  if ($out['duration_s'] > 0) {
    $out['avg_w'] = round($out['energy_wh'] * 3600 / $out['duration_s'], 1);
  }
  // Quota del ciclo coperta da autoconsumo: quanto NON è stato prelevato dalla
  // rete rispetto all'energia del carico. Sopra 1 vuol dire che il fondo casa è
  // cambiato durante il ciclo; si taglia a 1 per non mostrare numeri assurdi.
  if ($out['energy_wh'] > 0) {
    $out['pv_share'] = round(max(0, min(1, 1 - $gridWh / $out['energy_wh'])) * 100, 0);
  }
  return $out;
}

function session_samples(int $id): array
{
  $st = carichi_db()->prepare("SELECT ts, casa_power, pv_power, grid_power
                                 FROM samples WHERE session_id = :s ORDER BY ts");
  $st->execute([':s' => $id]);
  return $st->fetchAll();
}

function session_row(int $id): ?array
{
  $st = carichi_db()->prepare("SELECT * FROM sessions WHERE id = :s");
  $st->execute([':s' => $id]);
  $r = $st->fetch();
  return $r ?: null;
}

function active_session(): ?array
{
  $r = carichi_db()->query("SELECT * FROM sessions WHERE ended_at IS NULL
                             ORDER BY started_at DESC LIMIT 1")->fetch();
  return $r ?: null;
}

// ── Profilo medio di un carico ──────────────────────────────────────────────
// Le sessioni chiuse dello stesso carico vengono riportate a minuti dall'avvio e
// mediate. Le sessioni più corte non tirano giù la coda: ogni minuto è la media
// dei soli cicli ancora in corso a quel minuto, e `coverage` dice su quanti cicli
// è calcolato.
function load_profile(string $name): array
{
  $st = carichi_db()->prepare("SELECT id, baseline_w FROM sessions
                                WHERE load_name = :n AND ended_at IS NOT NULL
                                ORDER BY started_at DESC");
  $st->execute([':n' => $name]);
  $sessions = $st->fetchAll();

  $sum = []; $cnt = []; $used = 0;
  foreach ($sessions as $s) {
    $a = analyze(session_samples((int)$s['id']),
                 $s['baseline_w'] === null ? null : (float)$s['baseline_w']);
    if (count($a['points']) < 2) continue;
    $used++;

    // Un punto al minuto: si media dentro il minuto prima di mediare tra cicli,
    // così un ciclo campionato più fitto non pesa più degli altri.
    $bins = [];
    foreach ($a['points'] as $p) {
      $bins[intdiv((int)$p['t'], 60)][] = (float)$p['net'];
    }
    foreach ($bins as $m => $vals) {
      $sum[$m] = ($sum[$m] ?? 0) + array_sum($vals) / count($vals);
      $cnt[$m] = ($cnt[$m] ?? 0) + 1;
    }
  }
  if (!$sum) {
    return ['minutes' => [], 'sessions' => 0, 'energy_wh' => 0, 'peak_w' => 0, 'duration_min' => 0];
  }

  ksort($sum, SORT_NUMERIC);
  $minutes = [];
  $energy = 0.0; $peak = 0.0;
  foreach ($sum as $m => $tot) {
    $w = $tot / $cnt[$m];
    $minutes[] = ['m' => (int)$m, 'w' => round($w, 1), 'coverage' => $cnt[$m]];
    $energy += $w / 60;
    $peak = max($peak, $w);
  }
  return [
    'minutes'      => $minutes,
    'sessions'     => $used,
    'energy_wh'    => round($energy, 1),
    'peak_w'       => round($peak, 1),
    'duration_min' => count($minutes),
  ];
}

// ── Ottimizzatore ───────────────────────────────────────────────────────────
// Fa scorrere il profilo medio su tutti i 96 quarti d'ora della giornata tipo e
// per ognuno conta quanta energia arriverebbe dal sole, quanta dalla batteria e
// quanta dalla rete. Il punteggio è la quota che NON viene dalla rete.
//
// Modello batteria volutamente grossolano: parte dal SoC attuale, si ricarica
// col surplus che il carico non usa e scarica al massimo BATTERY_MAX_DISCHARGE_W.
// Serve a confrontare gli orari fra loro, non come previsione assoluta — per gli
// orari lontani il SoC di partenza è quello di adesso, quindi è ottimista.
function optimize(array $profile, array $typical, ?float $soc, bool $useBattery): array
{
  $mins = $profile['minutes'];
  if (!$mins || $typical['days'] === 0) return ['slots' => [], 'days' => $typical['days']];

  // Profilo per quarto d'ora: media dei minuti che cadono nello slot.
  $slotW = [];
  foreach ($mins as $p) {
    $slotW[intdiv((int)$p['m'], SLOT_MIN)][] = (float)$p['w'];
  }
  ksort($slotW, SORT_NUMERIC);
  $prof = [];
  foreach ($slotW as $vals) $prof[] = array_sum($vals) / count($vals);

  $dtH      = SLOT_MIN / 60;
  $capacity = BATTERY_CAPACITY_KWH * 1000;
  $budget0  = $useBattery && $soc !== null ? max(0.0, $soc / 100 * $capacity) : 0.0;

  $out = [];
  for ($s = 0; $s < SLOTS_PER_DAY; $s++) {
    $pvWh = 0.0; $battWh = 0.0; $gridWh = 0.0; $tot = 0.0;
    $budget = $budget0;
    $known = true;

    foreach ($prof as $k => $w) {
      $slot = ($s + $k) % SLOTS_PER_DAY;
      $pv   = $typical['pv'][$slot];
      if ($pv === null) { $known = false; break; }   // fascia oraria mai osservata
      $base = $typical['base'][$slot] ?? 0.0;

      $surplus = max(0.0, $pv - $base);
      $tot    += $w * $dtH;

      $fromPv = min($w, $surplus);
      $pvWh  += $fromPv * $dtH;
      $rest   = $w - $fromPv;

      // Il surplus avanzato ricarica la batteria — è quello che succede davvero
      // quando l'accumulo non è pieno — e il resto del carico la scarica.
      $budget = min($capacity, $budget + ($surplus - $fromPv) * $dtH);
      if ($useBattery && $rest > 0 && $budget > 0) {
        $fromBatt = min($rest, (float)BATTERY_MAX_DISCHARGE_W, $budget / $dtH);
        $battWh  += $fromBatt * $dtH;
        $budget  -= $fromBatt * $dtH;
        $rest    -= $fromBatt;
      }
      $gridWh += $rest * $dtH;
    }
    if (!$known || $tot <= 0) continue;

    $out[] = [
      'slot'     => $s,
      'time'     => sprintf('%02d:%02d', intdiv($s * SLOT_MIN, 60), ($s * SLOT_MIN) % 60),
      'pv_wh'    => round($pvWh, 0),
      'batt_wh'  => round($battWh, 0),
      'grid_wh'  => round($gridWh, 0),
      'total_wh' => round($tot, 0),
      'score'    => round(($pvWh + $battWh) / $tot * 100, 0),
    ];
  }

  // A parità di punteggio vince l'orario che prende meno dalla rete; se pareggia
  // anche quello, il più presto nella giornata (di solito il più comodo).
  usort($out, function ($a, $b) {
    return [$b['score'], $a['grid_wh'], $a['slot']] <=> [$a['score'], $b['grid_wh'], $b['slot']];
  });
  return ['slots' => $out, 'days' => $typical['days']];
}

// ── API ─────────────────────────────────────────────────────────────────────
$api = $_GET['api'] ?? '';

if ($api !== '') {
  header('Content-Type: application/json');

  if (!in_array('sqlite', PDO::getAvailableDrivers())) {
    echo json_encode(['error' => 'PDO SQLite driver not available. Run: sudo apt install php-sqlite3']);
    exit;
  }
  try {
    carichi_db();
  } catch (Exception $e) {
    echo json_encode(['error' => 'Cannot open ' . CARICHI_DB . ': ' . $e->getMessage()]);
    exit;
  }

  $reply = function ($data) { echo json_encode($data); exit; };

  try {
    switch ($api) {

      case 'start': {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') $reply(['error' => 'Nome del carico mancante']);
        $name = clip($name, 60);
        if (active_session()) $reply(['error' => "C'è già una registrazione in corso"]);

        $started = utc_now();
        $st = carichi_db()->prepare("INSERT INTO sessions (load_name, started_at, baseline_w, note)
                                     VALUES (:n, :s, :b, :o)");
        $st->execute([
          ':n' => $name,
          ':s' => $started,
          ':b' => baseline_before($started),
          ':o' => clip(trim((string)($_POST['note'] ?? '')), 200),
        ]);
        $reply(['ok' => true, 'id' => (int)carichi_db()->lastInsertId()]);
      }

      case 'stop': {
        $sess = active_session();
        if (!$sess) $reply(['error' => 'Nessuna registrazione in corso']);
        $end = utc_now();

        // Il fondo si ricalcola alla chiusura se all'avvio non c'era: la finestra
        // precedente poteva non essere ancora nella tabella (Pi appena riavviato).
        $baseline = $sess['baseline_w'] !== null
                  ? (float)$sess['baseline_w']
                  : baseline_before($sess['started_at']);
        carichi_db()->prepare("UPDATE sessions SET ended_at = :e, baseline_w = :b WHERE id = :i")
                    ->execute([':e' => $end, ':b' => $baseline, ':i' => $sess['id']]);
        ingest_session((int)$sess['id'], $sess['started_at'], $end);

        $a = analyze(session_samples((int)$sess['id']), $baseline);
        unset($a['points']);
        $reply(['ok' => true, 'id' => (int)$sess['id'], 'stats' => $a]);
      }

      case 'cancel': {
        $sess = active_session();
        if (!$sess) $reply(['error' => 'Nessuna registrazione in corso']);
        carichi_db()->prepare("DELETE FROM samples WHERE session_id = :i")->execute([':i' => $sess['id']]);
        carichi_db()->prepare("DELETE FROM sessions WHERE id = :i")->execute([':i' => $sess['id']]);
        $reply(['ok' => true]);
      }

      case 'delete': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) $reply(['error' => 'id mancante']);
        carichi_db()->prepare("DELETE FROM samples WHERE session_id = :i")->execute([':i' => $id]);
        carichi_db()->prepare("DELETE FROM sessions WHERE id = :i")->execute([':i' => $id]);
        $reply(['ok' => true]);
      }

      // Elimina tutte le registrazioni di un carico, cioè il suo profilo.
      case 'delete_load': {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') $reply(['error' => 'Carico mancante']);

        // `samples` non ha una foreign key verso `sessions`: le righe vanno
        // tolte per sottoquery, altrimenti restano orfane a gonfiare il DB.
        $c = carichi_db();
        $c->prepare("DELETE FROM samples
                      WHERE session_id IN (SELECT id FROM sessions WHERE load_name = :n)")
          ->execute([':n' => $name]);
        $st = $c->prepare("DELETE FROM sessions WHERE load_name = :n");
        $st->execute([':n' => $name]);
        $reply(['ok' => true, 'deleted' => $st->rowCount()]);
      }

      // Stato vivo: valori istantanei, sessione in corso (con i campioni già
      // ritagliati) ed elenco di tutte le registrazioni chiuse.
      case 'state': {
        consolidate_pv_profile();
        $energy  = snapshot(ENERGY_LATEST_PATH);
        $battery = snapshot(BATTERY_LATEST_PATH);
        $sess    = active_session();

        $live = null;
        if ($sess) {
          ingest_session((int)$sess['id'], $sess['started_at'], null);
          $live = [
            'id'         => (int)$sess['id'],
            'name'       => $sess['load_name'],
            'started_at' => $sess['started_at'],
            'stats'      => analyze(session_samples((int)$sess['id']),
                                    $sess['baseline_w'] === null ? null : (float)$sess['baseline_w']),
          ];
        }

        $rows = carichi_db()->query("SELECT * FROM sessions WHERE ended_at IS NOT NULL
                                      ORDER BY started_at DESC")->fetchAll();
        $sessions = [];
        foreach ($rows as $r) {
          $a = analyze(session_samples((int)$r['id']),
                       $r['baseline_w'] === null ? null : (float)$r['baseline_w']);
          unset($a['points']);
          $sessions[] = [
            'id'         => (int)$r['id'],
            'name'       => $r['load_name'],
            'started_at' => $r['started_at'],
            'ended_at'   => $r['ended_at'],
            'note'       => $r['note'],
            'stats'      => $a,
          ];
        }

        $names = array_column(carichi_db()->query(
          "SELECT DISTINCT load_name FROM sessions ORDER BY load_name")->fetchAll(), 'load_name');

        $reply([
          'now'      => utc_now(),
          'energy'   => $energy,
          'battery'  => $battery ? ['soc'           => $battery['soc'] ?? null,
                                    'battery_power' => $battery['battery_power'] ?? null] : null,
          'live'     => $live,
          'sessions' => $sessions,
          'names'    => $names,
        ]);
      }

      case 'session': {
        $id = (int)($_GET['id'] ?? 0);
        $r  = session_row($id);
        if (!$r) $reply(['error' => 'Registrazione non trovata']);
        $reply([
          'session' => $r,
          'stats'   => analyze(session_samples($id),
                               $r['baseline_w'] === null ? null : (float)$r['baseline_w']),
        ]);
      }

      case 'analysis': {
        $name = (string)($_GET['load'] ?? '');
        if ($name === '') $reply(['error' => 'Carico mancante']);
        consolidate_pv_profile();

        $profile = load_profile($name);
        $typical = typical_day();
        $battery = snapshot(BATTERY_LATEST_PATH);
        $soc     = $battery['soc'] ?? null;
        $useBatt = ($_GET['battery'] ?? '1') !== '0';

        $reply([
          'load'     => $name,
          'profile'  => $profile,
          'typical'  => $typical,
          'soc'      => $soc,
          'optimize' => optimize($profile, $typical, $soc === null ? null : (float)$soc, $useBatt),
        ]);
      }

      default:
        $reply(['error' => 'API sconosciuta']);
    }
  } catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
  }
}

// Pagina normale: il consolidamento gira comunque, così basta aprire ogni tanto
// la pagina perché lo storico FV si accumuli.
$bootError = '';
try {
  carichi_db();
  consolidate_pv_profile();
} catch (Exception $e) {
  $bootError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="it">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Carichi — Profili di consumo</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

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
      flex-wrap: wrap;
      gap: .75rem;
      margin-bottom: 1.5rem;
    }

    header h1 {
      font-size: 1.4rem;
      font-weight: 600;
      letter-spacing: .02em;
    }

    header h1 svg {
      width: 1.5rem;
      height: 1.5rem;
      vertical-align: -.25rem;
      margin-right: .4rem;
      color: #0284c7;
    }

    .header-meta {
      display: flex;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .header-link {
      font-size: .85rem;
      color: #0284c7;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: .3rem;
    }

    .header-link svg { width: 1rem; height: 1rem; }
    .header-link:hover { text-decoration: underline; }

    #last-update { font-size: .8rem; color: #64748b; }

    section {
      background: #fff;
      border-radius: .75rem;
      padding: 1.1rem 1.2rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
      margin-bottom: 1.25rem;
    }

    section h2 {
      font-size: .95rem;
      font-weight: 600;
      margin-bottom: .9rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .75rem;
      flex-wrap: wrap;
    }

    .hint {
      font-size: .78rem;
      color: #64748b;
      line-height: 1.45;
      margin-bottom: .9rem;
    }

    /* ── Cards ── */
    .cards {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      gap: .75rem;
      margin-bottom: 1rem;
    }

    .card {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: .6rem;
      padding: .7rem .8rem;
      display: flex;
      flex-direction: column;
      gap: .2rem;
    }

    .card .label {
      font-size: .68rem;
      text-transform: uppercase;
      color: #94a3b8;
      letter-spacing: .05em;
    }

    .card .value { font-size: 1.35rem; font-weight: 700; }
    .card .unit  { font-size: .72rem; color: #64748b; font-weight: 500; }

    /* ── Controlli ── */
    .controls {
      display: flex;
      gap: .6rem;
      align-items: center;
      flex-wrap: wrap;
    }

    input[type=text], select {
      font: inherit;
      padding: .5rem .65rem;
      border: 1px solid #cbd5e1;
      border-radius: .5rem;
      background: #fff;
      color: inherit;
      min-width: 12rem;
    }

    button {
      font: inherit;
      font-weight: 600;
      padding: .5rem .95rem;
      border: 0;
      border-radius: .5rem;
      cursor: pointer;
      background: #e2e8f0;
      color: #1e293b;
    }

    button:hover { filter: brightness(.96); }
    button:disabled { opacity: .5; cursor: not-allowed; }

    button.start { background: #16a34a; color: #fff; }
    button.stop  { background: #dc2626; color: #fff; }
    button.ghost { background: transparent; color: #64748b; padding: .35rem .5rem; }
    button.ghost:hover { color: #dc2626; }

    .rec-badge {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      font-size: .8rem;
      font-weight: 600;
      color: #dc2626;
    }

    .rec-badge::before {
      content: '';
      width: .55rem;
      height: .55rem;
      border-radius: 50%;
      background: #dc2626;
      animation: pulse 1.4s ease-in-out infinite;
    }

    @keyframes pulse { 0%, 100% { opacity: 1 } 50% { opacity: .25 } }

    /* ── Tabelle ── */
    .table-wrap { overflow-x: auto; }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: .85rem;
      white-space: nowrap;
    }

    th, td {
      text-align: right;
      padding: .45rem .6rem;
      border-bottom: 1px solid #e2e8f0;
    }

    th:first-child, td:first-child { text-align: left; }

    th {
      font-size: .7rem;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #94a3b8;
      font-weight: 600;
    }

    tbody tr:hover { background: #f8fafc; }
    tbody tr.selected { background: #e0f2fe; }
    td.name { cursor: pointer; color: #0284c7; font-weight: 600; }

    .badge {
      display: inline-block;
      padding: .1rem .45rem;
      border-radius: .35rem;
      font-size: .72rem;
      font-weight: 700;
    }

    .badge.good { background: #dcfce7; color: #15803d; }
    .badge.mid  { background: #fef9c3; color: #a16207; }
    .badge.bad  { background: #fee2e2; color: #b91c1c; }

    .chart-box { position: relative; height: 260px; }
    .empty { color: #94a3b8; font-size: .85rem; padding: .5rem 0; }
    .error { color: #b91c1c; font-size: .85rem; margin-bottom: .75rem; }

    .best {
      display: flex;
      align-items: baseline;
      gap: .6rem;
      flex-wrap: wrap;
      font-size: 1rem;
      margin-bottom: .8rem;
    }

    .best strong { font-size: 1.6rem; color: #16a34a; }

    label.inline {
      font-size: .8rem;
      color: #475569;
      display: inline-flex;
      align-items: center;
      gap: .35rem;
    }

    @media (max-width: 640px) {
      body { padding: .9rem; }
      input[type=text], select { min-width: 0; flex: 1 1 100%; }
    }
  </style>
</head>

<body>
  <header>
    <h1>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
        stroke-linejoin="round" aria-hidden="true">
        <path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z" />
      </svg>
      Carichi — profili di consumo
    </h1>
    <div class="header-meta">
      <a class="header-link" href="index.php" title="Dashboard stazione meteo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round" aria-hidden="true">
          <path d="M3 12 12 3l9 9" />
          <path d="M5 10v10h14V10" />
        </svg>
        Dashboard
      </a>
      <a class="header-link" href="batteria.php" title="Batteria Marstek Venus E">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round" aria-hidden="true">
          <rect x="2" y="7" width="16" height="10" rx="2" />
          <line x1="22" y1="11" x2="22" y2="13" />
        </svg>
        Batteria
      </a>
      <span id="last-update">—</span>
    </div>
  </header>

  <?php if ($bootError !== ''): ?>
    <section>
      <p class="error">Database non apribile (<?= htmlspecialchars(CARICHI_DB) ?>): <?= htmlspecialchars($bootError) ?></p>
      <p class="hint">Il file viene creato al primo avvio e deve essere scrivibile dall&rsquo;utente del web server:
        <code>sudo touch <?= htmlspecialchars(CARICHI_DB) ?> &amp;&amp; sudo chown www-data:www-data <?= htmlspecialchars(CARICHI_DB) ?></code>
      </p>
    </section>
  <?php endif; ?>

  <!-- ── Registrazione ── -->
  <section>
    <h2>Registrazione <span id="rec-state"></span></h2>
    <p class="hint">
      Scrivi il nome del carico e premi <strong>Avvia</strong> nel momento in cui lo accendi,
      <strong>Ferma</strong> quando ha finito. La finestra viene ritagliata dal contatore Shelly
      (una lettura al minuto): puoi chiudere la pagina, la registrazione continua lo stesso.
      Il consumo del carico è la differenza rispetto al fondo casa misurato nei
      <?= BASELINE_WINDOW_MIN ?> minuti precedenti l&rsquo;avvio, quindi conviene non accendere
      altro nel frattempo.
    </p>

    <div class="cards">
      <div class="card"><span class="label">Consumo casa</span><span class="value" id="live-casa">—<span class="unit"> W</span></span></div>
      <div class="card"><span class="label">Produzione FV</span><span class="value" id="live-pv">—<span class="unit"> W</span></span></div>
      <div class="card"><span class="label">Scambio rete</span><span class="value" id="live-grid">—<span class="unit"> W</span></span></div>
      <div class="card"><span class="label">Batteria SoC</span><span class="value" id="live-soc">—<span class="unit"> %</span></span></div>
    </div>

    <div class="controls">
      <input type="text" id="load-name" list="load-names" placeholder="Nome del carico (es. Lavastoviglie)" maxlength="60">
      <datalist id="load-names"></datalist>
      <button class="start" id="btn-start">Avvia registrazione</button>
      <button class="stop" id="btn-stop" hidden>Ferma registrazione</button>
      <button class="ghost" id="btn-cancel" hidden>Annulla</button>
    </div>
    <p class="error" id="rec-error" hidden></p>

    <div id="live-panel" hidden>
      <div class="cards" style="margin-top:1rem">
        <div class="card"><span class="label">Durata</span><span class="value" id="live-dur">—</span></div>
        <div class="card"><span class="label">Energia carico</span><span class="value" id="live-energy">—<span class="unit"> Wh</span></span></div>
        <div class="card"><span class="label">Picco</span><span class="value" id="live-peak">—<span class="unit"> W</span></span></div>
        <div class="card"><span class="label">Fondo casa</span><span class="value" id="live-base">—<span class="unit"> W</span></span></div>
      </div>
      <div class="chart-box"><canvas id="chart-live"></canvas></div>
    </div>
  </section>

  <!-- ── Registrazioni ── -->
  <section>
    <h2>Registrazioni</h2>
    <p class="hint">
      <em>Energia</em>, <em>picco</em> e <em>media</em> sono al netto del fondo casa, quindi sono
      del solo carico. <em>Da rete</em> e <em>autoconsumo</em> riguardano invece tutta la casa
      durante quella finestra: dicono com&rsquo;è andato quel ciclo in quel momento della giornata,
      ed è esattamente il numero che l&rsquo;ottimizzatore qui sotto cerca di migliorare.
    </p>
    <div class="table-wrap">
      <table id="sessions-table">
        <thead>
          <tr>
            <th>Carico</th>
            <th>Avvio</th>
            <th>Durata</th>
            <th>Energia</th>
            <th>Picco</th>
            <th>Media</th>
            <th>Da rete</th>
            <th>Autoconsumo</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
    <p class="empty" id="sessions-empty">Nessuna registrazione completata.</p>
  </section>

  <!-- ── Dettaglio ── -->
  <section id="detail-section" hidden>
    <h2><span id="detail-title">Dettaglio</span></h2>
    <div class="chart-box"><canvas id="chart-detail"></canvas></div>
  </section>

  <!-- ── Analisi e ottimizzazione ── -->
  <section>
    <h2>
      <span>Profilo medio e orario ottimale</span>
      <span class="controls" style="gap:.5rem">
        <select id="analysis-load"></select>
        <label class="inline"><input type="checkbox" id="use-battery" checked> considera la batteria</label>
        <button class="ghost" id="btn-del-load" title="Elimina il profilo: tutte le registrazioni di questo carico">
          Elimina profilo
        </button>
      </span>
    </h2>
    <p class="hint">
      Il profilo medio di tutti i cicli registrati per quel carico viene fatto scorrere sulla
      giornata fotovoltaica tipica (media dei giorni raccolti finora, a quarti d&rsquo;ora) per
      trovare l&rsquo;ora di avvio che lascia alla rete la fetta più piccola. Lo storico FV si
      accumula da solo ogni volta che questa pagina viene aperta.
    </p>
    <div id="analysis-body">
      <p class="empty">Registra almeno un ciclo per vedere l&rsquo;analisi.</p>
    </div>
  </section>

  <script>
    // ── Utilities ─────────────────────────────────────────────────────────────
    const $ = (id) => document.getElementById(id);

    const fmtW = (v) => v === null || v === undefined ? '—' : Math.round(v).toLocaleString('it-IT');
    const fmtWh = (v) => v === null || v === undefined ? '—'
      : (Math.abs(v) >= 1000 ? (v / 1000).toFixed(2) + ' kWh' : Math.round(v) + ' Wh');

    function fmtDur(sec) {
      if (sec === null || sec === undefined) return '—';
      const h = Math.floor(sec / 3600), m = Math.round((sec % 3600) / 60);
      return h > 0 ? h + 'h ' + String(m).padStart(2, '0') + 'm' : m + 'm';
    }

    // Gli istanti arrivano in UTC (…Z): il browser li mostra in ora locale.
    const fmtTime = (iso) => new Date(iso).toLocaleString('it-IT',
      { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

    function badge(score) {
      if (score === null || score === undefined) return '—';
      const cls = score >= 70 ? 'good' : score >= 40 ? 'mid' : 'bad';
      return '<span class="badge ' + cls + '">' + score + '%</span>';
    }

    async function call(api, opts = {}) {
      const res = await fetch('carichi.php?api=' + api + (opts.query || ''), {
        method: opts.body ? 'POST' : 'GET',
        body: opts.body,
      });
      const data = await res.json();
      if (data.error) throw new Error(data.error);
      return data;
    }

    function showError(msg) {
      const el = $('rec-error');
      el.textContent = msg;
      el.hidden = !msg;
    }

    // ── Grafici ───────────────────────────────────────────────────────────────
    const CHART_BASE = {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      elements: { point: { radius: 0 } },
      plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } },
    };

    let liveChart = null, detailChart = null, analysisChart = null;

    function powerChart(canvasId, existing, labels, datasets, xTitle) {
      if (existing) {
        existing.data.labels = labels;
        existing.data.datasets = datasets;
        existing.update('none');
        return existing;
      }
      return new Chart($(canvasId), {
        type: 'line',
        data: { labels, datasets },
        options: {
          ...CHART_BASE,
          scales: {
            x: { title: { display: true, text: xTitle, font: { size: 10 } },
                 ticks: { maxTicksLimit: 12, font: { size: 10 } } },
            y: { title: { display: true, text: 'W', font: { size: 10 } },
                 ticks: { font: { size: 10 } } },
          },
        },
      });
    }

    const SERIES = {
      net:  { label: 'Carico (netto)', borderColor: '#0284c7', backgroundColor: 'rgba(2,132,199,.15)',
              borderWidth: 2, fill: true, tension: .25 },
      casa: { label: 'Consumo casa', borderColor: '#f97316', borderWidth: 1.2, tension: .25 },
      pv:   { label: 'Produzione FV', borderColor: '#16a34a', borderWidth: 1.2, tension: .25 },
    };

    // ── Stato ─────────────────────────────────────────────────────────────────
    let state = null;
    let selectedSession = null;

    function renderLive(d) {
      const e = d.energy || {};
      $('live-casa').innerHTML = fmtW(e.casa_power) + '<span class="unit"> W</span>';
      $('live-pv').innerHTML   = fmtW(e.pv_power) + '<span class="unit"> W</span>';
      $('live-grid').innerHTML = fmtW(e.grid_power) + '<span class="unit"> W</span>';
      $('live-soc').innerHTML  = (d.battery && d.battery.soc !== null && d.battery.soc !== undefined
        ? Math.round(d.battery.soc) : '—') + '<span class="unit"> %</span>';

      const rec = d.live;
      $('btn-start').hidden  = !!rec;
      $('btn-stop').hidden   = !rec;
      $('btn-cancel').hidden = !rec;
      $('load-name').disabled = !!rec;
      $('live-panel').hidden = !rec;
      $('rec-state').innerHTML = rec
        ? '<span class="rec-badge">in registrazione: ' + rec.name + '</span>' : '';

      if (!rec) { $('last-update').textContent = 'aggiornato ' + new Date().toLocaleTimeString('it-IT'); return; }

      const s = rec.stats;
      $('load-name').value = rec.name;
      // La durata segue l'orologio, non l'ultimo campione: appena premuto Avvia
      // la tabella non ha ancora righe e il riquadro resterebbe a zero.
      const elapsed = Math.max(0, (Date.parse(d.now) - Date.parse(rec.started_at)) / 1000);
      $('live-dur').textContent = fmtDur(elapsed);
      $('live-energy').innerHTML = fmtWh(s.energy_wh);
      $('live-peak').innerHTML   = fmtW(s.peak_w) + '<span class="unit"> W</span>';
      $('live-base').innerHTML   = fmtW(s.baseline_w) + '<span class="unit"> W</span>';

      const labels = s.points.map(p => (p.t / 60).toFixed(0) + '′');
      liveChart = powerChart('chart-live', liveChart, labels, [
        { ...SERIES.net,  data: s.points.map(p => p.net) },
        { ...SERIES.casa, data: s.points.map(p => p.casa) },
        { ...SERIES.pv,   data: s.points.map(p => p.pv) },
      ], 'minuti dall’avvio');

      $('last-update').textContent = 'aggiornato ' + new Date().toLocaleTimeString('it-IT');
    }

    function renderSessions(list) {
      const tb = $('sessions-table').querySelector('tbody');
      tb.innerHTML = '';
      $('sessions-empty').hidden = list.length > 0;
      $('sessions-table').hidden = list.length === 0;

      for (const s of list) {
        const st = s.stats;
        const tr = document.createElement('tr');
        if (selectedSession === s.id) tr.className = 'selected';
        tr.innerHTML =
          '<td class="name">' + s.name + '</td>' +
          '<td>' + fmtTime(s.started_at) + '</td>' +
          '<td>' + fmtDur(st.duration_s) + '</td>' +
          '<td>' + fmtWh(st.energy_wh) + '</td>' +
          '<td>' + fmtW(st.peak_w) + ' W</td>' +
          '<td>' + fmtW(st.avg_w) + ' W</td>' +
          '<td>' + fmtWh(st.grid_wh) + '</td>' +
          '<td>' + badge(st.pv_share) + '</td>' +
          '<td><button class="ghost" title="Elimina">✕</button></td>';

        tr.querySelector('td.name').onclick = () => openSession(s.id, s.name);
        tr.querySelector('button').onclick = async () => {
          if (!confirm('Eliminare la registrazione di ' + s.name + '?')) return;
          const body = new FormData(); body.append('id', s.id);
          await call('delete', { body });
          if (selectedSession === s.id) { selectedSession = null; $('detail-section').hidden = true; }
          loop();
        };
        tb.appendChild(tr);
      }
    }

    function renderNames(names) {
      $('load-names').innerHTML = names.map(n => '<option value="' + n + '">').join('');

      const sel = $('analysis-load');
      const keep = sel.value;
      sel.innerHTML = names.map(n => '<option>' + n + '</option>').join('');
      if (names.includes(keep)) sel.value = keep;
      if (!sel.value && names.length) { sel.value = names[0]; }
      if (names.length && sel.value !== keep) loadAnalysis();
    }

    async function openSession(id, name) {
      selectedSession = id;
      const d = await call('session', { query: '&id=' + id });
      const s = d.stats;
      $('detail-section').hidden = false;
      $('detail-title').textContent =
        name + ' — ' + fmtTime(d.session.started_at) + ' · ' + fmtDur(s.duration_s) +
        ' · ' + fmtWh(s.energy_wh) + ' · picco ' + fmtW(s.peak_w) + ' W';

      const labels = s.points.map(p => (p.t / 60).toFixed(0) + '′');
      detailChart = powerChart('chart-detail', detailChart, labels, [
        { ...SERIES.net,  data: s.points.map(p => p.net) },
        { ...SERIES.casa, data: s.points.map(p => p.casa) },
        { ...SERIES.pv,   data: s.points.map(p => p.pv) },
      ], 'minuti dall’avvio');
      renderSessions(state.sessions);
      $('detail-section').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ── Analisi ───────────────────────────────────────────────────────────────
    async function loadAnalysis() {
      const name = $('analysis-load').value;
      const body = $('analysis-body');
      if (!name) { body.innerHTML = '<p class="empty">Registra almeno un ciclo per vedere l’analisi.</p>'; return; }

      let d;
      try {
        d = await call('analysis', {
          query: '&load=' + encodeURIComponent(name) + '&battery=' + ($('use-battery').checked ? '1' : '0'),
        });
      } catch (err) {
        body.innerHTML = '<p class="error">' + err.message + '</p>';
        return;
      }

      const p = d.profile;
      if (!p.sessions) {
        body.innerHTML = '<p class="empty">Nessun ciclo completo per «' + name + '».</p>';
        return;
      }

      const slots = d.optimize.slots;
      const best = slots[0];
      const days = d.optimize.days;

      let html = '<div class="cards">' +
        card('Cicli registrati', p.sessions, '') +
        card('Durata media', fmtDur(p.duration_min * 60), '') +
        card('Energia media', fmtWh(p.energy_wh), '') +
        card('Picco medio', fmtW(p.peak_w), ' W') +
        '</div>';

      if (!best) {
        html += '<p class="empty">Storico fotovoltaico insufficiente per il calcolo' +
          (days ? ' (' + days + ' giorn' + (days === 1 ? 'o' : 'i') + ' raccolt' + (days === 1 ? 'o' : 'i') + ').' : '.') +
          ' Lascia la pagina aperta qualche giorno: il profilo FV si costruisce da solo.</p>';
      } else {
        html += '<p class="best">Avvio consigliato: <strong>' + best.time + '</strong>' +
          ' — ' + best.score + '% da sole e batteria, ' + fmtWh(best.grid_wh) + ' dalla rete' +
          '<span class="hint" style="margin:0">su ' + days + ' giorn' + (days === 1 ? 'o' : 'i') +
          ' di storico FV' + (d.soc !== null && d.soc !== undefined && $('use-battery').checked
            ? ', batteria al ' + Math.round(d.soc) + '%' : '') + '</span></p>';

        html += '<div class="table-wrap"><table><thead><tr>' +
          '<th>Avvio</th><th>Da FV</th><th>Da batteria</th><th>Da rete</th><th>Autoconsumo</th>' +
          '</tr></thead><tbody>' +
          slots.slice(0, 8).map(s =>
            '<tr><td>' + s.time + '</td><td>' + fmtWh(s.pv_wh) + '</td><td>' + fmtWh(s.batt_wh) +
            '</td><td>' + fmtWh(s.grid_wh) + '</td><td>' + badge(s.score) + '</td></tr>').join('') +
          '</tbody></table></div>';
      }

      html += '<div class="chart-box" style="margin-top:1rem"><canvas id="chart-analysis"></canvas></div>';
      // Il canvas precedente sparisce con l'innerHTML: il grafico va chiuso prima,
      // altrimenti Chart.js resta agganciato a un elemento staccato dal DOM.
      if (analysisChart) { analysisChart.destroy(); analysisChart = null; }
      body.innerHTML = html;

      // Profilo medio del carico contro il surplus fotovoltaico tipico, entrambi
      // sull'asse delle ore del giorno a partire dall'orario consigliato.
      const startSlot = best ? best.slot : 0;
      const labels = [], loadW = [], surplus = [];
      const nSlots = Math.max(1, Math.ceil(p.duration_min / <?= SLOT_MIN ?>));
      // Due ore di contorno prima e dopo, per vedere dove cade la finestra.
      const pad = 8;   // due ore di contorno, a quarti d'ora
      for (let i = -pad; i < nSlots + pad; i++) {
        const slot = ((startSlot + i) % <?= SLOTS_PER_DAY ?> + <?= SLOTS_PER_DAY ?>) % <?= SLOTS_PER_DAY ?>;
        labels.push(String(Math.floor(slot * <?= SLOT_MIN ?> / 60)).padStart(2, '0') + ':' +
                    String((slot * <?= SLOT_MIN ?>) % 60).padStart(2, '0'));
        const pv = d.typical.pv[slot], base = d.typical.base[slot];
        surplus.push(pv === null ? null : Math.max(0, pv - (base || 0)));

        if (i < 0 || i >= nSlots) { loadW.push(null); continue; }
        const from = i * <?= SLOT_MIN ?>, to = from + <?= SLOT_MIN ?>;
        const vals = p.minutes.filter(m => m.m >= from && m.m < to).map(m => m.w);
        loadW.push(vals.length ? vals.reduce((a, b) => a + b, 0) / vals.length : 0);
      }

      analysisChart = powerChart('chart-analysis', null, labels, [
        { label: 'Profilo del carico', data: loadW, borderColor: '#0284c7',
          backgroundColor: 'rgba(2,132,199,.18)', borderWidth: 2, fill: true, tension: .25, spanGaps: false },
        { label: 'Surplus FV tipico', data: surplus, borderColor: '#16a34a',
          backgroundColor: 'rgba(22,163,74,.12)', borderWidth: 1.5, fill: true, tension: .3 },
      ], 'ora del giorno');
    }

    const card = (label, value, unit) =>
      '<div class="card"><span class="label">' + label + '</span><span class="value">' +
      value + '<span class="unit">' + unit + '</span></span></div>';

    // ── Azioni ────────────────────────────────────────────────────────────────
    $('btn-start').onclick = async () => {
      const name = $('load-name').value.trim();
      if (!name) { showError('Scrivi il nome del carico prima di avviare.'); return; }
      showError('');
      const body = new FormData(); body.append('name', name);
      try { await call('start', { body }); } catch (e) { showError(e.message); return; }
      loop();
    };

    $('btn-stop').onclick = async () => {
      showError('');
      try { await call('stop', { body: new FormData() }); } catch (e) { showError(e.message); return; }
      $('load-name').value = '';
      await loop();
      loadAnalysis();
    };

    $('btn-cancel').onclick = async () => {
      if (!confirm('Annullare la registrazione in corso? I campioni raccolti vengono buttati.')) return;
      try { await call('cancel', { body: new FormData() }); } catch (e) { showError(e.message); return; }
      $('load-name').value = '';
      loop();
    };

    $('btn-del-load').onclick = async () => {
      const name = $('analysis-load').value;
      if (!name) return;
      // Il conteggio arriva dall'elenco già in pagina: chi cancella deve sapere
      // quante registrazioni sta buttando, non solo il nome del carico.
      const n = state.sessions.filter(s => s.name === name).length;
      if (!confirm('Eliminare il profilo «' + name + '» e le sue ' + n +
                   ' registrazioni? L’operazione non è reversibile.')) return;
      const body = new FormData(); body.append('name', name);
      try { await call('delete_load', { body }); } catch (e) { showError(e.message); return; }
      selectedSession = null;
      $('detail-section').hidden = true;
      await loop();
      loadAnalysis();
    };

    $('analysis-load').onchange = loadAnalysis;
    $('use-battery').onchange = loadAnalysis;

    // ── Loop ──────────────────────────────────────────────────────────────────
    async function refresh() {
      try {
        state = await call('state');
      } catch (e) {
        $('last-update').textContent = 'errore: ' + e.message;
        return;
      }
      renderLive(state);
      renderSessions(state.sessions);
      renderNames(state.names);
    }

    // Mentre registra si guarda ogni 5 s, per seguire il ciclo e ritagliare i
    // campioni via via; a riposo ogni 30 s basta e avanza. Il giro si ripianifica
    // da solo, così premere Avvia o Ferma cambia subito anche la cadenza.
    let timer = null;
    async function loop() {
      await refresh();
      clearTimeout(timer);
      timer = setTimeout(loop, state && state.live ? 5000 : 30000);
    }

    loop();   // il primo giro popola l'elenco dei carichi e fa partire l'analisi
  </script>
</body>

</html>
