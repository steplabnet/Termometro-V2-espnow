<?php
declare(strict_types=1);

/**
 * Storage logic for one incoming weather reading, shared by the two front doors:
 *   - ingest.php     (MQTT bridge, several messages per minute)
 *   - carica_dati.php (legacy HTTP GET, kept working)
 *
 * The cadences here are what keeps a fast MQTT feed cheap:
 *   dati_instant   -> every reading (the dashboard's live row)
 *   /dev/shm snapshot -> every reading (what live.php pushes to browsers)
 *   /dev/shm trend window -> every reading, last 30 min only, never to disk
 *   dati_meteo     -> one history row per 10 minutes, as it has always been
 *   ARPA portata   -> fetched at most every 10 minutes, cached in RAM
 *   minmax_24h.json -> only when a history row was written
 *   icache.html    -> at most once a minute
 */

require_once __DIR__ . '/instant_lib.php'; // payload builder + /dev/shm snapshot
require_once __DIR__ . '/trend_store.php'; // RAM-only 30 min window for trends

/** dati_meteo keeps one row per this many seconds. */
const METEO_HISTORY_INTERVAL = 600;
/** The Piave flow is read from ARPA Veneto at most this often. */
const PORTATA_TTL = 600;
/**
 * The static dashboard cache is rebuilt at most this often.
 *
 * Rebuilding means a loopback HTTP request, i.e. this script occupying one PHP
 * worker while a second one renders index.php. With live.php also holding
 * workers for its long polls, that must stay rare -- hence five minutes rather
 * than the one minute the old HTTP-per-reading flow could afford. Any real page
 * view rewrites icache.html anyway, so it is never far behind in practice.
 */
const ICACHE_INTERVAL = 300;


const ARPA_PORTATA_URL =
  'https://api.arpa.veneto.it/REST/v1/meteo_meteogrammi_tabella?codseqst=300001781&rnd=0.26636924190339917';

/**
 * Path for a small cache file: RAM when /dev/shm is usable, next to the PHP
 * files otherwise.
 */
function store_cache_path(string $name): string
{
  return instant_ram_ready() ? INSTANT_RAM_DIR . '/' . $name : __DIR__ . '/' . $name;
}

/** Quote a scalar for SQL, or the literal NULL when there is nothing to store. */
function store_sql(mysqli $link, $value): string
{
  if ($value === null || $value === '') {
    return 'NULL';
  }
  return "'" . $link->real_escape_string((string) $value) . "'";
}

/**
 * Add a column to a table if it is not there yet.
 * Lets the Shelly columns appear on first run without a manual migration.
 */
function ensure_column(mysqli $link, string $table, string $column, string $definition): void
{
  $res = $link->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  if ($res instanceof mysqli_result && $res->num_rows === 0) {
    $link->query("ALTER TABLE `$table` ADD `$column` $definition");
  }
}

/**
 * Current Piave flow, cached in RAM.
 *
 * The MQTT feed can deliver a reading every couple of seconds; ARPA must not be
 * called that often, and its value only moves on a scale of minutes anyway. On
 * a failed fetch the cached value is kept rather than replaced by an empty one.
 */
function store_portata(): string
{
  $cache = store_cache_path('portata.json');

  $cached = null;
  if (is_readable($cache)) {
    $decoded = json_decode((string) @file_get_contents($cache), true);
    if (is_array($decoded) && isset($decoded['fetched_at'])) {
      $cached = $decoded;
      if (time() - (int) $decoded['fetched_at'] < PORTATA_TTL) {
        return (string) ($decoded['portata'] ?? '');
      }
    }
  }

  $portata = '';
  try {
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $json = @file_get_contents(ARPA_PORTATA_URL, false, $ctx);
    $data = ($json === false) ? null : json_decode($json, true);
    foreach ($data['data'] ?? [] as $item) {
      // "PORT" is the flow-rate series; anything older than ~12 min is not the
      // current reading and is ignored.
      //
      // ARPA timestamps carry no zone and are UTC, so ' UTC' has to be spelled
      // out: this used to run before date_default_timezone_set('Europe/Rome')
      // and silently relied on the ambient default being UTC. Read as Rome
      // time every row looks two hours old and none of them qualify.
      if (($item['tipo'] ?? '') !== 'PORT') {
        continue;
      }
      if (strtotime(($item['dataora'] ?? '') . ' UTC') > time() - 700) {
        $portata = trim((string) $item['valore']);
      }
    }
  } catch (Throwable $e) {
    $portata = '';
  }

  if ($portata === '') {
    // ARPA is unreachable or has nothing fresh: keep serving the last value we
    // did get, and retry on the next call rather than storing a blank.
    return (string) ($cached['portata'] ?? '');
  }

  @file_put_contents(
    $cache,
    json_encode(['portata' => $portata, 'fetched_at' => time()]),
    LOCK_EX
  );
  return $portata;
}

/**
 * Rebuild icache.html, at most once per ICACHE_INTERVAL.
 *
 * index.php writes that file as a side effect of rendering, so a loopback
 * request is all it takes. Throttled because the MQTT feed would otherwise
 * trigger a full page render several times a minute for a file almost nobody
 * reads (the live dashboard is served by index.php + live.php).
 */
function store_refresh_icache(): void
{
  $stamp = store_cache_path('icache_stamp');
  clearstatcache(true, $stamp);
  if (is_readable($stamp) && time() - (int) @filemtime($stamp) < ICACHE_INTERVAL) {
    return;
  }
  @touch($stamp);

  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'cesana.steplab.net';
  $ctx = stream_context_create(['http' => ['timeout' => 15]]);
  @file_get_contents("{$scheme}://{$host}/index.php", false, $ctx);
}

/** Recompute the 24h extremes file (only worth doing when history changed). */
function store_write_minmax(mysqli $link, int $now): void
{
  $time24hAgo = $now - 86400;
  $sqlStats = "SELECT
    MAX(temperatura + 0) as max_temp,
    MIN(temperatura + 0) as min_temp,
    MAX(tombra + 0) as max_tombra,
    MIN(tombra + 0) as min_tombra,
    MAX(humi + 0) as max_humi,
    MIN(humi + 0) as min_humi,
    MAX(press + 0) as max_press,
    MIN(press + 0) as min_press,
    MAX(wind + 0) as max_wind,
    MAX(gust + 0) as max_gust,
    MAX(power + 0) as max_power,
    MAX(pvPower + 0) as max_pvPower,
    MAX(gridPower + 0) as max_gridPower,
    MIN(gridPower + 0) as min_gridPower
    FROM dati_meteo
    WHERE data >= $time24hAgo";

  $res = $link->query($sqlStats);
  if (!$res instanceof mysqli_result) {
    return;
  }
  $stats = $res->fetch_assoc();
  if (!is_array($stats)) {
    return;
  }
  $stats['calculated_at'] = date('Y-m-d H:i:s', $now);
  $stats['timestamp'] = $now;
  @file_put_contents(__DIR__ . '/minmax_24h.json', json_encode($stats, JSON_PRETTY_PRINT));
}

/**
 * Store one reading.
 *
 * $r accepts the field names the station has always used:
 *   temp, humi, wind, rain, pres, chip, gust, tombra, hombra, tMobile,
 *   tempCpu, fan, power, pvPower, gridPower, battPower, battSoc, battTemp,
 *   battTs
 * Missing entries are stored as NULL in the history row and left untouched in
 * dati_instant, so a partial message never overwrites good data with zeroes.
 *
 * Returns ['history' => bool, 'rev' => int|null, 'portata' => string].
 */
function meteo_store_reading(mysqli $link, array $r): array
{
  $now = time();
  $portata = store_portata();

  // Production under 10 W is noise (clamp leakage / inverter standby): store 0.
  $pvPower = isset($r['pvPower']) && is_numeric($r['pvPower']) ? (float) $r['pvPower'] : null;
  if ($pvPower !== null && abs($pvPower) < PV_ZERO_THRESHOLD) {
    $pvPower = 0.0;
  }
  $gridPower = isset($r['gridPower']) && is_numeric($r['gridPower']) ? (float) $r['gridPower'] : null;

  // What the two clamps measure besides power: voltage, current and power
  // factor each, plus the mains frequency. Live row only -- see
  // EM_DETAIL_FIELDS in instant_lib.php. A field the station does not send
  // simply stays out, so the stored value is left alone rather than blanked.
  $emDetail = [];
  foreach (EM_DETAIL_FIELDS as $f) {
    if (isset($r[$f]) && is_numeric($r[$f])) {
      $emDetail[$f] = (float) $r[$f];
    }
  }

  // Marstek Venus E. battPower is signed the house way (+ = charging) and is
  // subtracted from the house load downstream, so a value with no companion
  // battTs is refused: an undated power figure is exactly the one that would
  // go on being subtracted after the battery bridge died.
  $battPower = isset($r['battPower']) && is_numeric($r['battPower']) ? (float) $r['battPower'] : null;
  $battSoc = isset($r['battSoc']) && is_numeric($r['battSoc']) ? (float) $r['battSoc'] : null;
  // The hottest cell, not the electronics: see read_latest_battery() in meteo.py.
  $battTemp = isset($r['battTemp']) && is_numeric($r['battTemp']) ? (float) $r['battTemp'] : null;
  $battTs = isset($r['battTs']) && is_numeric($r['battTs']) ? (int) $r['battTs'] : null;

  // The diagnostics ride on the same timestamp: undated, they would sit on the
  // card looking live long after the bridge stopped. The history row needs no
  // such guard -- it is stamped by `data` like every other column in it.
  $battLive = [];
  if ($battTs !== null) {
    foreach (BATTERY_DIAG_FIELDS as $f) {
      if (isset($r[$f]) && is_numeric($r[$f])) {
        $battLive[$f] = (float) $r[$f];
      }
    }
  }

  if ($battTs === null) {
    $battPower = null;
    $battSoc = null;
    $battTemp = null;
  }

  /* Into the RAM trend window, before anything is written anywhere else.
   *
   * The battery fields go in already guarded: an undated battPower is refused
   * above, and a trend must not be fitted through the figures a dead bridge
   * left behind. The rest of the reading goes in as it arrived -- trend_push()
   * keeps the numeric fields and ignores everything else.
   *
   * Failure here is deliberately silent and costs nothing: no tmpfs means no
   * trend buffer, and every reader falls back to `dati_meteo`.
   */
  trend_push(array_merge($r, [
    'pvPower' => $pvPower,
    'gridPower' => $gridPower,
    'battPower' => $battPower,
    'battSoc' => $battSoc,
    'battTemp' => $battTemp,
  ]), $now);

  $get = static fn(string $k) => (isset($r[$k]) && $r[$k] !== '') ? $r[$k] : null;

  /** ---------- 1. HISTORY ROW, ONE PER 10 MINUTES ---------- */
  $res = $link->query("SELECT * FROM dati_meteo ORDER BY id DESC LIMIT 1");
  $last = ($res instanceof mysqli_result) ? $res->fetch_assoc() : null;
  $lastTs = (int) ($last['data'] ?? 0);
  // Align to the 10-minute grid the series has always used, so rows keep
  // landing on :00, :10, :20 ... rather than drifting with the message rate.
  $due = ($now >= $lastTs + METEO_HISTORY_INTERVAL - ($lastTs % METEO_HISTORY_INTERVAL));

  $tombra = $get('tombra');
  $tMobile = $get('tMobile');
  $wroteHistory = false;

  if ($due) {
    // Only on the slow path: four SHOW COLUMNS per reading would be wasteful at
    // MQTT rates, and a new column can wait ten minutes to appear.
    foreach (['dati_meteo', 'dati_instant'] as $t) {
      ensure_column($link, $t, 'pvPower', 'FLOAT NULL');
      ensure_column($link, $t, 'gridPower', 'FLOAT NULL');
      ensure_column($link, $t, 'battPower', 'FLOAT NULL');
      ensure_column($link, $t, 'battSoc', 'FLOAT NULL');
      ensure_column($link, $t, 'battTemp', 'FLOAT NULL');
    }
    // Only the live row carries the battery's own read time: a history row is
    // already stamped with `data`, and a NULL battPower there simply means the
    // battery said nothing in that ten-minute window.
    ensure_column($link, 'dati_instant', 'battTs', 'INT NULL');
    // Both tables: the diagnostics are charted from `dati_meteo` now.
    foreach (BATTERY_DIAG_FIELDS as $c) {
      ensure_column($link, 'dati_instant', $c, 'FLOAT NULL');
      ensure_column($link, 'dati_meteo', $c, 'FLOAT NULL');
    }
    // Live row only: the quadro card reads them, nothing charts them.
    foreach (EM_DETAIL_FIELDS as $c) {
      ensure_column($link, 'dati_instant', $c, 'FLOAT NULL');
    }

    // Validation to prevent bad sensor readings (-50)
    $histTombra = ($tombra !== null && (float) $tombra < -50) ? ($last['tombra'] ?? null) : $tombra;
    $histTMobile = ($tMobile !== null && (float) $tMobile < -50) ? ($last['tMobile'] ?? null) : $tMobile;

    $cols = [
      'temperatura' => $get('temp'),
      'humi' => $get('humi'),
      'wind' => $get('wind'),
      'rain' => $get('rain'),
      'press' => $get('pres'),
      'tombra' => $histTombra,
      'hombra' => $get('hombra'),
      'data' => $now,
      'chip' => $get('chip'),
      'gust' => $get('gust'),
      'power' => $get('power'),
      'cpuTemp' => $get('tempCpu'),
      'fanMode' => $get('fan'),
      'tMobile' => $histTMobile,
      'portata' => $portata,
      'pvPower' => $pvPower,
      'gridPower' => $gridPower,
      'battPower' => $battPower,
      'battSoc' => $battSoc,
      'battTemp' => $battTemp,
    ] + $battLive;

    $names = implode(', ', array_map(static fn($c) => "`$c`", array_keys($cols)));
    $values = implode(', ', array_map(static fn($v) => store_sql($link, $v), $cols));
    $wroteHistory = (bool) $link->query("INSERT INTO `dati_meteo` ($names) VALUES ($values)");
  }

  /** ---------- 2. LIVE ROW, EVERY READING ---------- */
  $instant = [
    'temperatura' => $get('temp'),
    'humi' => $get('humi'),
    'wind' => $get('wind'),
    'rain' => $get('rain'),
    'press' => $get('pres'),
    'tombra' => $tombra,
    'hombra' => $get('hombra'),
    'data' => $now,
    'gust' => $get('gust'),
    'power' => $get('power'),
    'cpuTemp' => $get('tempCpu'),
    'chip' => $get('chip'),
    'fan' => $get('fan'),
    'tMobile' => $tMobile,
    'portata' => $portata,
  ];
  // A meter that did not report keeps its previous value instead of being
  // zeroed, so the dashboard's PV/grid cards do not blink to 0 W.
  if ($pvPower !== null) {
    $instant['pvPower'] = $pvPower;
  }
  if ($gridPower !== null) {
    $instant['gridPower'] = $gridPower;
  }
  foreach ($emDetail as $f => $v) {
    $instant[$f] = $v;
  }
  // battTs goes with them: the three are written together or not at all, so
  // the stored age always belongs to the stored figures.
  if ($battPower !== null || $battSoc !== null || $battTemp !== null || $battLive) {
    if ($battPower !== null) {
      $instant['battPower'] = $battPower;
    }
    if ($battSoc !== null) {
      $instant['battSoc'] = $battSoc;
    }
    if ($battTemp !== null) {
      $instant['battTemp'] = $battTemp;
    }
    foreach ($battLive as $f => $v) {
      $instant[$f] = $v;
    }
    $instant['battTs'] = $battTs;
  }

  $sets = [];
  foreach ($instant as $col => $val) {
    if ($val === null) {
      continue; // nothing to say about this field: leave the stored value alone
    }
    $sets[] = "`$col` = " . store_sql($link, $val);
  }
  if ($sets) {
    $sql = "UPDATE `dati_instant` SET " . implode(', ', $sets) . " WHERE id = 1";
    try {
      $link->query($sql);
    } catch (Throwable $e) {
      // Almost always an unknown column: a field is being sent for the first
      // time and the ten-minute path that adds columns has not run yet. On
      // PHP 8.1+ mysqli raises that, which would fail the whole ingest and
      // lose a perfectly good reading, so the columns are created here and the
      // write is retried once. A second failure is a real error and is left to
      // the caller.
      foreach (array_merge(['pvPower', 'gridPower', 'battPower', 'battSoc', 'battTemp'],
                          BATTERY_DIAG_FIELDS, EM_DETAIL_FIELDS) as $c) {
        ensure_column($link, 'dati_instant', $c, 'FLOAT NULL');
      }
      ensure_column($link, 'dati_instant', 'battTs', 'INT NULL');
      $link->query($sql);
    }
  }

  /** ---------- 3. DERIVED FILES ---------- */
  if ($wroteHistory) {
    store_write_minmax($link, $now);
  }

  // Rebuild the RAM snapshot: this is what makes the new values reach every
  // open browser (live.php is holding their requests on the current `rev`).
  $rev = null;
  try {
    $payload = meteo_refresh_payload($link);
    $rev = $payload['rev'] ?? null;
  } catch (Throwable $e) {
    error_log('instant snapshot failed: ' . $e->getMessage());
  }

  store_refresh_icache();

  return ['history' => $wroteHistory, 'rev' => $rev, 'portata' => $portata];
}
