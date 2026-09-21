<?php
declare(strict_types=1);

/**
 * Builds the dashboard payload: every number, label, colour and icon shown on
 * index.php, computed once and rendered-ready.
 *
 * This used to live inline at the top of index.php. It moved here so the exact
 * same values feed three consumers:
 *   - index.php       -> first paint
 *   - carica_dati.php -> writes the snapshot to /dev/shm on every Pi upload
 *   - live.php        -> long-poll endpoint the browser patches the DOM from
 * One builder means the AJAX refresh can never drift from the server render.
 */

require_once __DIR__ . '/instant_store.php';
require_once __DIR__ . '/trend_store.php';

/** PV production under this many watts is noise, not production. */
// define() rather than const: grafico.php declares the same constant, and the
// two files must stay includable together.
defined('PV_ZERO_THRESHOLD') || define('PV_ZERO_THRESHOLD', 10.0);

/** Battery power under this many watts is the pack feeding its own
 * electronics, not charging or discharging. Same deadband as IDLE_W in the
 * Pi's batteria.php, so the two dashboards never disagree about "in attesa". */
defined('BATT_IDLE_W') || define('BATT_IDLE_W', 15.0);

/** A battery reading older than this is not used: see battTs in store_lib.php. */
defined('BATT_MAX_AGE') || define('BATT_MAX_AGE', 300);

/**
 * Battery diagnostics: three temperatures, four voltages, a current, plus the
 * AC power the derived AC current is computed from.
 *
 * These started as live-row-only fields -- read by one card, charted by
 * nothing. They are now written to `dati_meteo` as well, because every one of
 * them is plottable from the diagnostics card and a chart needs a history to
 * draw. Rows logged before that are simply NULL here and show as gaps.
 *
 * Defined here rather than in store_lib.php because both files need it and
 * only this one is included by index.php.
 */
defined('BATTERY_DIAG_FIELDS') || define('BATTERY_DIAG_FIELDS', [
  'battTempMin',
  'battTempInt',
  'battTempMos1',
  'battTempMos2',
  'battVolt',
  'battCurr',
  'battCellVMax',
  'battCellVMin',
  'battAcV',
  'battAcHz',
  'battAcW',
]);

/**
 * The Venus E's own energy counters, kWh, 0.01 kWh steps: lifetime (registers
 * 33000 / 33002), today (33004 / 33006) and this month (33008 / 33010), all
 * reset by the battery itself -- plus its BMS cycle count (34003).
 *
 * Kept apart from BATTERY_DIAG_FIELDS because they are not a reading of the
 * pack's condition and the diagnostics card has nothing to do with them. They
 * go to both tables: the Energia card needs the value at midnight to say how
 * much moved today, and that value lives in `dati_meteo`.
 */
defined('BATTERY_ENERGY_FIELDS') || define('BATTERY_ENERGY_FIELDS', [
  'battChgTot',
  'battDisTot',
  'battChgDay',
  'battDisDay',
  'battChgMon',
  'battDisMon',
  'battCycles',
]);

/**
 * Shelly Pro EM-50 detail: what the two clamps report besides active power.
 *
 * Every message already carries voltage, current and power factor per clamp
 * plus the mains frequency -- they were stored on the Raspberry and thrown
 * away on the way here. They feed the "Quadro principale" card and nothing
 * else, so they live in the live row only, like the battery diagnostics did
 * before they became plottable.
 *
 * The two voltages are the same line measured twice (one meter, two CTs on a
 * single-phase supply): they differ by sampling noise, not by circuit. The
 * currents are genuinely different -- one is what the inverter pushes, the
 * other what crosses the meter -- but both are UNSIGNED: direction lives only
 * in the sign of gridPower.
 */
defined('EM_DETAIL_FIELDS') || define('EM_DETAIL_FIELDS', [
  'emPvV',
  'emPvA',
  'emPvPf',
  'emGridV',
  'emGridA',
  'emGridPf',
  'emHz',
]);

/** Usable capacity of the pack, kWh — only to turn SoC into a "residuo".
 * 5.12 kWh is what the Venus E 3.0 reports as its rated capacity. */
defined('BATT_CAPACITY_KWH') || define('BATT_CAPACITY_KWH', 5.12);

/** Where the autonomy estimate stops counting: the SoC the pack is expected to
 * stop discharging at, not 0. The Venus reserves the bottom of the pack, and a
 * "quanto manca" measured to an empty it never reaches would always be wrong
 * by that reserve. */
defined('BATT_RESERVE_SOC') || define('BATT_RESERVE_SOC', 12.0);

/** Where the charge estimate stops counting. The Venus has no reachable
 * charge-ceiling register on the v3 map, so it fills to 100 and this is simply
 * full. */
defined('BATT_FULL_SOC') || define('BATT_FULL_SOC', 100.0);

/** Produzione sotto la quale il fotovoltaico e' considerato spento, in watt.
 * Sotto questi watt e' notte (o quasi) e la scarica della batteria conta come
 * copertura totale a prescindere dal contatore: non c'e' altra sorgente che
 * possa reggere la casa al posto suo. */
defined('BATT_PV_OFF_W') || define('BATT_PV_OFF_W', 10.0);

/** Soglia sotto la quale una sorgente conta come spenta, in watt. Vale sia per
 * la rete sia per il fotovoltaico: se nessuna delle due supera questi watt, la
 * casa la sta tenendo su la batteria da sola. Non e' zero perche' i contatori
 * non stanno mai esattamente a zero. */
defined('BATT_COVER_W') || define('BATT_COVER_W', 30.0);

/** How far back the autonomy estimate looks. Long enough that a kettle does
 * not set the slope, short enough to follow the evening as it changes. */
defined('BATT_ETA_WINDOW') || define('BATT_ETA_WINDOW', 1800);

/** ---------- FORMATTING HELPERS ---------- */

function fmt($val, $decimals = 1, $default = '--')
{
  return (isset($val) && $val !== null && $val !== '' && (float) $val > -99) ? round((float) $val, $decimals) : $default;
}

/** Format a wattage as W below 1 kW and kW above, so cards stay readable. */
function fmtW($val, $default = '--')
{
  if ($val === null) {
    return $default;
  }
  $val = (float) $val;
  return (abs($val) >= 1000) ? number_format($val / 1000, 2) . ' kW' : round($val) . ' W';
}

function calculateTrend($current, $old)
{
  if ($current === null || $old === null || $current <= -50)
    return null;
  $val = ($current - (float) $old) * 2;
  return (abs($val) > 20) ? null : $val;
}

function getFreezingTime($currentTemp, $trendPerHour)
{
  if ($currentTemp === null || $trendPerHour === null || $currentTemp <= -50)
    return null;
  if ($currentTemp > 0 && $trendPerHour < -0.1) {
    $hoursToZero = $currentTemp / abs((float) $trendPerHour);
    $targetTime = time() + (int) ($hoursToZero * 3600);
    $limitTime = strtotime('tomorrow 06:00');
    if ($targetTime < $limitTime)
      return date('H:i', $targetTime);
  }
  return null;
}

function getTrendHtml($val, $unit = '°C/h', $icePrediction = null)
{
  if ($val === null)
    return '<span class="trend-neutral">--</span>';
  $rounded = round($val, 2);
  $sign = ($rounded > 0) ? '+' : '';
  $colorClass = ($rounded > 0) ? 'trend-up' : (($rounded < 0) ? 'trend-down' : 'trend-neutral');
  $arrow = ($rounded > 0) ? '&#8593;' : (($rounded < 0) ? '&#8595;' : '&nbsp;');

  $html = "<span class=\"{$colorClass}\" style=\"display:block; font-weight:700;\">{$arrow} {$sign}{$rounded} <small>{$unit}</small></span>";
  if ($icePrediction) {
    $html .= "<div class=\"ice-prediction\">&#10052; 0°C alle {$icePrediction}</div>";
  }
  return $html;
}

/**
 * Estimate WHEN the forecast change is expected, from the rate of the
 * 3h pressure trend. A steeper drop/rise means the system is closer.
 * Returns '' for stable conditions (nothing to time).
 */
function getForecastTiming(array $forecast, float $presTrend3h): string
{
  $worsening = ['snow', 'snow_storm', 'storm', 'sleet', 'rain'];

  if (in_array($forecast['icon'], $worsening, true)) {
    $drop = abs($presTrend3h);
    if ($drop >= 1.5) {
      $lo = 1;
      $hi = 2;
    }   // very rapid -> imminent
    elseif ($drop >= 0.8) {
      $lo = 2;
      $hi = 4;
    } elseif ($drop >= 0.5) {
      $lo = 4;
      $hi = 8;
    } else {
      $lo = 8;
      $hi = 14;
    }   // slow drift
    $eta = date('H:i', time() + (int) ((($lo + $hi) / 2) * 3600));
    return "tra ~{$lo}-{$hi} h (verso le {$eta})";
  }

  // Clouds building on a falling barometer -> time the expected worsening.
  if ($forecast['text'] === 'Nuvoloso' && $presTrend3h <= -0.3) {
    $drop = abs($presTrend3h);
    if ($drop >= 1.5) {
      $lo = 1;
      $hi = 2;
    } elseif ($drop >= 0.8) {
      $lo = 2;
      $hi = 4;
    } elseif ($drop >= 0.5) {
      $lo = 4;
      $hi = 8;
    } else {
      $lo = 6;
      $hi = 12;
    }
    $eta = date('H:i', time() + (int) ((($lo + $hi) / 2) * 3600));
    return "peggioramento tra ~{$lo}-{$hi} h (verso le {$eta})";
  }

  if ($forecast['text'] === 'In Miglioramento') {
    $rise = abs($presTrend3h);
    if ($rise >= 1.0) {
      $lo = 2;
      $hi = 4;
    } else {
      $lo = 4;
      $hi = 8;
    }
    $eta = date('H:i', time() + (int) ((($lo + $hi) / 2) * 3600));
    return "schiarite tra ~{$lo}-{$hi} h (verso le {$eta})";
  }

  return '';
}

/**
 * How long the pack takes to reach $target, at the rate it has actually been
 * moving. Answers both directions: $target below the current SoC is the
 * autonomy question (how long until the reserve), above it the charge question
 * (how long until full).
 *
 * $rows are the last BATT_ETA_WINDOW seconds of history, oldest first, each
 * ['data' => unix ts, 'soc' => %, 'power' => signed W or null].
 *
 * The primary estimate is a least-squares fit of SoC against time: it measures
 * the pack actually filling or emptying, which already contains the conversion
 * losses and the real usable capacity, so it needs neither. The mean power is
 * the fallback, for the case the fit cannot answer -- too few rows, or a SoC
 * that has not moved a whole reported step yet, which is common at low power
 * because the battery reports SoC in tenths and half an hour at 150 W barely
 * shifts it.
 *
 * The sample thresholds are low because the fallback source, `dati_meteo`,
 * holds ONE ROW PER TEN MINUTES: half an hour of it is three or four rows and
 * no more, so a fit that insisted on more would never run when the RAM trend
 * window is empty. The span check is what keeps the fit honest instead --
 * three points inside a couple of minutes say nothing, three across twenty
 * minutes do. Fed from trend_store.php the same fit gets a sample every
 * twenty seconds or so and is simply better conditioned.
 *
 * Both estimates are LINEAR, which is the honest reading of the last half hour
 * and nothing more. It holds well while discharging; on charge it runs
 * optimistic near the top, where the Venus tapers into constant voltage and
 * the last few percent take longer than the fit expects.
 *
 * Returns ['hours' => float, 'watts' => float, 'slope' => float,
 * 'basis' => 'soc'|'power'] or null when neither method has anything honest to
 * say. `watts` and `slope` are the same rate in the two units the card shows
 * it in -- power, and SoC per hour, both as positive numbers whichever way the
 * pack is going -- so it can show the figures behind the estimate rather than
 * only its result. Each branch measures one of the two and converts to the
 * other across the pack capacity: on the SoC branch the slope is the
 * measurement and the watts are derived, on the power branch it is the other
 * way round. Neither is the instantaneous reading, which is what the pack
 * happens to be doing this second.
 */
function battSocEtaHours(array $rows, ?float $soc, float $target): ?array
{
  if ($soc === null) {
    return null;
  }
  // Which way the pack has to move to get there, as +1 or -1. A pack already
  // at its target has no estimate to give.
  $dir = ($target > $soc) ? 1.0 : (($target < $soc) ? -1.0 : 0.0);
  if ($dir === 0.0) {
    return null;
  }
  $toGo = abs($target - $soc);              // % still to cover

  // --- least squares on SoC(t), t in hours from the first sample ---
  $n = 0;
  $sx = $sy = $sxx = $sxy = 0.0;
  $t0 = null;
  $tLast = null;
  $pSum = 0.0;
  $pN = 0;
  foreach ($rows as $r) {
    if ($r['soc'] !== null) {
      $t0 = $t0 ?? (float) $r['data'];
      $tLast = (float) $r['data'];
      $x = ((float) $r['data'] - $t0) / 3600.0;
      $y = (float) $r['soc'];
      $n++;
      $sx += $x;
      $sy += $y;
      $sxx += $x * $x;
      $sxy += $x * $y;
    }
    if ($r['power'] !== null) {
      $pSum += (float) $r['power'];
      $pN++;
    }
  }

  $span = ($t0 !== null && $tLast !== null) ? ($tLast - $t0) : 0.0;
  if ($n >= 3 && $span >= 600) {
    $den = ($n * $sxx) - ($sx * $sx);
    if ($den > 0) {
      // %/h, signed: negative while emptying, positive while filling.
      $slope = (($n * $sxy) - ($sx * $sy)) / $den;
      $toward = $slope * $dir;              // progress toward the target
      // Anything shallower than this is noise on a 0.1 % reading, not a trend:
      // it would divide out to a "duration" of days.
      if ($toward >= 0.5) {
        return [
          'hours' => $toGo / $toward,
          'watts' => $toward / 100.0 * BATT_CAPACITY_KWH * 1000.0,
          'slope' => $toward,
          'basis' => 'soc',
        ];
      }
      // Only a pack visibly moving the WRONG way is refused outright. The band
      // between the two thresholds -- moving too slowly to fit, or flat
      // because SoC has not ticked a whole tenth yet -- falls through to the
      // power fallback, which is the case that branch was written for.
      if ($toward <= -0.5) {
        return null;
      }
    }
  }

  // --- fallback: mean power over the same window ---
  if ($pN >= 2) {
    $meanW = $pSum / $pN;                    // signed the house way: > 0 charging
    $towardW = $meanW * $dir;                // power spent going where we asked
    if ($towardW > BATT_IDLE_W) {
      $kwh = $toGo / 100.0 * BATT_CAPACITY_KWH;
      return [
        'hours' => $kwh / ($towardW / 1000.0),
        'watts' => $towardW,
        'slope' => ($towardW / 1000.0) / BATT_CAPACITY_KWH * 100.0,
        'basis' => 'power',
      ];
    }
  }

  return null;
}

/** A duration in hours as the card says it: "3h 20m" above the hour, plain
 * minutes below it. */
function fmtDuration(float $hours): string
{
  $mins = (int) round($hours * 60);
  if ($mins < 60) {
    return max(1, $mins) . ' min';
  }
  return intdiv($mins, 60) . 'h ' . str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT) . 'm';
}

/* Durata in secondi come hh:mm:ss. Le ore non rientrano nelle 24: il totale
 * di vita del pacco e' fatto per crescere oltre il giorno, e "312:40:05" dice
 * quello che "0d 12h" nasconderebbe. */
function fmtHms(int $secs): string
{
  $secs = max(0, $secs);
  return sprintf('%d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
}

/* Prossima occorrenza di un'ora del giorno a partire da $now: quella di oggi se
 * deve ancora arrivare, altrimenti quella di domani.
 *
 * Le soglie della batteria sono ancorate alla notte in corso e non al
 * calendario: chi guarda la dashboard alle 2 ha davanti la mattina fra sei ore,
 * non quella del giorno dopo, e con 'tomorrow 08:00' venti ore di autonomia
 * finirebbero colorate come un'emergenza.
 */
function nextClock(string $hhmm, int $now): int
{
  $today = strtotime("today $hhmm", $now);
  if ($today !== false && $today > $now) {
    return $today;
  }
  $tomorrow = strtotime("tomorrow $hhmm", $now);
  return $tomorrow === false ? $now : $tomorrow;
}

function getTempClass($val)
{
  return ($val < 1 && $val > -99) ? 'freezing' : '';
}

function getPowerClass($val)
{
  return ($val <= 0) ? 'night-mode' : '';
}

/** Open a connection on demand (live.php must not hold one while long-polling). */
function meteo_db(): ?mysqli
{
  require __DIR__ . '/connessione.php'; // defines $link
  return ($link instanceof mysqli) ? $link : null;
}

/**
 * ---------- THE BUILDER ----------
 * Runs every query the dashboard needs and returns a render-ready tree.
 * Everything the browser has to patch on refresh is already a string in here.
 */
/** Quanto a lungo il sole deve stare sopra il consumo prima di crederci (s). */
defined('PV_COVER_SUSTAIN') || define('PV_COVER_SUSTAIN', 600);
/** Buco nei dati oltre il quale una copertura in corso non e' piu' continua (s). */
defined('PV_COVER_GAP') || define('PV_COVER_GAP', 1800);
/** Ritardo del sole sulla riserva oltre il quale il pallino diventa rosso (s). */
defined('ANA_LATE_RED') || define('ANA_LATE_RED', 7200);
/** Finestra su cui la card Statistiche conta, in giorni. */
defined('STATS_DAYS') || define('STATS_DAYS', 30);

/* ---------- PROFILO MEDIO DEL FOTOVOLTAICO ----------
 * La stima di "pieno alle" e' una retta: prende il ritmo dell'ultima mezz'ora
 * e lo prolunga. Al mattino regge, dal primo pomeriggio no -- da li' in poi il
 * sole cala fino a spegnersi, e una retta tirata sul ritmo delle 13 promette
 * una carica che alle 18 non c'e' piu'. Il profilo medio degli ultimi giorni
 * dice COME cala, quarto d'ora per quarto d'ora, e serve a correggere quella
 * promessa.
 */
/** Giorni pieni di storico su cui si media la produzione. */
defined('PV_PROFILE_DAYS') || define('PV_PROFILE_DAYS', 7);
/** Larghezza di un intervallo del profilo, in secondi. `dati_meteo` tiene una
 * riga ogni dieci minuti: a un quarto d'ora ogni casella ha uno o due campioni
 * per giorno, abbastanza per una media e abbastanza fitto per il tramonto. */
defined('PV_PROFILE_BUCKET') || define('PV_PROFILE_BUCKET', 900);
/** Ora del giorno da cui la correzione entra in gioco. Prima di mezzogiorno il
 * sole sta ancora salendo e la retta, semmai, e' pessimista. */
defined('PV_PROFILE_FROM_HOUR') || define('PV_PROFILE_FROM_HOUR', 12);
/** Produzione media minima nell'intervallo corrente per potersi calibrare, in
 * watt. Sotto questa il rapporto fra oggi e la media e' una divisione per
 * rumore. */
defined('PV_PROFILE_MIN_W') || define('PV_PROFILE_MIN_W', 100.0);
/** Tetto al fattore di calibrazione fra oggi e la media. Oltre questo non e'
 * una giornata piu' bella della media: e' un profilo che non descrive oggi. */
defined('PV_PROFILE_MAX_K') || define('PV_PROFILE_MAX_K', 3.0);
/** Scarto oltre il quale le due stime non dicono la stessa cosa (s). Sotto i
 * venti minuti la correzione non aggiunge niente e la riga resta com'e'. */
defined('PV_PROFILE_TOLERANCE') || define('PV_PROFILE_TOLERANCE', 1200);
/** Giorni con almeno un campione richiesti perche' il profilo valga. */
defined('PV_PROFILE_MIN_DAYS') || define('PV_PROFILE_MIN_DAYS', 3);
/** Quanto lontano si puo' pescare la riga di ieri per il confronto alla stessa
 * ora, in secondi. `dati_meteo` ha una riga ogni dieci minuti e ieri non e'
 * campionato agli stessi secondi di oggi: un quarto d'ora di tolleranza trova
 * sempre qualcosa senza spostarsi a un'altra fase del sole. */
defined('PV_YESTERDAY_TOL') || define('PV_YESTERDAY_TOL', 900);

/* Le tre icone di stato della card "Inizio carica". Stanno qui e non nel
 * template perche' e' il payload a sceglierle: la pagina le riceve gia' pronte
 * e le sostituisce in diretta, senza doverne conoscere le regole. currentColor
 * lascia il colore al contenitore, cosi' icona e tinta cambiano insieme. */
defined('ANA_ICON_OK') || define(
  'ANA_ICON_OK',
  '<svg class="state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
  . ' stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/>'
  . '<path d="m8.5 12.5 2.5 2.5 4.5-5"/></svg>'
);
defined('ANA_ICON_WARN') || define(
  'ANA_ICON_WARN',
  '<svg class="state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
  . ' stroke-linecap="round" stroke-linejoin="round">'
  . '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'
  . '<path d="M12 9v4"/><path d="M12 17h.01"/></svg>'
);
defined('ANA_ICON_ALARM') || define(
  'ANA_ICON_ALARM',
  '<svg class="state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
  . ' stroke-linecap="round" stroke-linejoin="round">'
  . '<path d="M8.6 2h6.8L22 8.6v6.8L15.4 22H8.6L2 15.4V8.6z"/>'
  . '<path d="M12 7v6"/><path d="M12 17h.01"/></svg>'
);

/**
 * Produzione media del fotovoltaico per ora del giorno, sugli ultimi
 * PV_PROFILE_DAYS giorni PIENI (oggi escluso: e' in corso, e mediarlo con se
 * stesso non aggiunge niente).
 *
 * Torna [indice di intervallo => watt medi], dove l'indice e' il quarto d'ora
 * dalla mezzanotte locale: 48 = le 12:00. Gli intervalli senza campioni non ci
 * sono, e per chi legge valgono zero -- di notte e' esattamente giusto.
 *
 * Solo la FORMA conta. I watt assoluti di una settimana fa non sono quelli di
 * oggi; quello che si riusa e' il modo in cui la produzione cala dal
 * pomeriggio al tramonto, che dipende dal cielo del giorno molto meno di
 * quanto dipenda dalla stagione e dall'orientamento del tetto.
 *
 * Il risultato sta in cache per la giornata: gli ingressi sono giorni chiusi e
 * non cambiano piu' fino a domani, mentre il payload si ricostruisce ogni
 * minuto e senza cache si porterebbe dietro una query da un migliaio di righe
 * ogni volta. Anche un profilo vuoto viene scritto, altrimenti un impianto
 * senza storico rifarebbe quella query un minuto dopo l'altro per non trovare
 * mai niente.
 */
function pvDayProfile(mysqli $link, int $now): ?array
{
  $day = date('Y-m-d', $now);
  $cacheFile = (instant_ram_ready() ? INSTANT_RAM_DIR : __DIR__) . '/pv_profile.json';

  clearstatcache(true, $cacheFile);
  if (is_readable($cacheFile)) {
    $cached = json_decode((string) @file_get_contents($cacheFile), true);
    if (is_array($cached) && ($cached['day'] ?? null) === $day
      && isset($cached['profile']) && is_array($cached['profile'])) {
      $profile = [];
      foreach ($cached['profile'] as $idx => $w) {
        $profile[(int) $idx] = (float) $w;
      }
      return $profile === [] ? null : $profile;
    }
  }

  $todayStart = strtotime('today 00:00', $now);
  if ($todayStart === false) {
    return null;
  }
  $from = $todayStart - (PV_PROFILE_DAYS * 86400);

  $sum = [];      // indice => watt sommati
  $cnt = [];      // indice => campioni
  $seenDays = []; // giorni con almeno un campione: uno solo non e' una media
  try {
    $res = $link->query(
      "SELECT `data`, pvPower + 0 AS pv
       FROM dati_meteo
       WHERE `data` >= {$from} AND `data` < {$todayStart} AND pvPower IS NOT NULL
       ORDER BY `data` ASC"
    );
    if ($res instanceof mysqli_result) {
      while ($row = $res->fetch_assoc()) {
        if (!is_numeric($row['pv'])) {
          continue;
        }
        $ts = (int) $row['data'];
        // Secondi dalla mezzanotte LOCALE, non dall'epoca: e' l'ora del giorno
        // che deve allinearsi fra giorni diversi, e con l'ora legale in mezzo
        // le due cose non coincidono.
        $secOfDay = ((int) date('G', $ts)) * 3600 + ((int) date('i', $ts)) * 60
          + (int) date('s', $ts);
        $idx = intdiv($secOfDay, PV_PROFILE_BUCKET);
        $sum[$idx] = ($sum[$idx] ?? 0.0) + max(0.0, (float) $row['pv']);
        $cnt[$idx] = ($cnt[$idx] ?? 0) + 1;
        $seenDays[date('Y-m-d', $ts)] = true;
      }
    }
  } catch (Throwable $e) {
    return null;   // niente storico: la correzione semplicemente non si fa
  }

  $profile = [];
  if (count($seenDays) >= PV_PROFILE_MIN_DAYS) {
    foreach ($sum as $idx => $tot) {
      $profile[$idx] = $tot / $cnt[$idx];
    }
    ksort($profile);
  }

  @instant_write_atomic(
    $cacheFile,
    (string) json_encode(['day' => $day, 'built_at' => $now, 'profile' => $profile])
  );

  return $profile === [] ? null : $profile;
}

/**
 * Dove arriva la carica di oggi seguendo il sole invece di una retta.
 *
 * $chargeW sono i watt con cui il pacco sta caricando adesso, gli stessi su
 * cui la stima lineare si basa. Il profilo dice quanto vale, in media, questo
 * momento della giornata e quanto varranno quelli dopo: il rapporto fra i due
 * calibra oggi sulla media -- sereno k > 1, coperto k < 1 -- e da li' in poi la
 * potenza di carica segue la stessa discesa che il sole ha in media, fino a
 * spegnersi la sera.
 *
 * L'integrazione si ferma a mezzanotte, e in pratica molto prima: una carica
 * fotovoltaica non puo' finire il giorno dopo, e una stima che dicesse "pieno
 * alle 02:40" starebbe promettendo un sole che di notte non c'e'.
 *
 * Torna ['full' => ts|null, 'soc' => %, 'endTs' => ts, 'k' => float]:
 *   full  = quando il pacco tocca $target, oppure null se oggi non ci arriva;
 *   soc   = il SoC a fine giornata, cioe' la carica massima raggiungibile;
 *   endTs = l'ultimo istante in cui il sole carica ancora qualcosa.
 * null quando la calibrazione non e' possibile -- sole gia' basso o assente in
 * questo momento: meglio nessuna correzione che una divisione per rumore.
 */
function battChargeSunEta(array $profile, int $now, float $soc, float $target, float $chargeW): ?array
{
  if ($chargeW <= 0 || $soc >= $target) {
    return null;
  }
  $secOfDay = ((int) date('G', $now)) * 3600 + ((int) date('i', $now)) * 60 + (int) date('s', $now);
  $dayStart = $now - $secOfDay;
  $nowIdx = intdiv($secOfDay, PV_PROFILE_BUCKET);

  $avgNow = $profile[$nowIdx] ?? 0.0;
  if ($avgNow < PV_PROFILE_MIN_W) {
    return null;
  }
  $k = min($chargeW / $avgNow, PV_PROFILE_MAX_K);

  $lastIdx = intdiv(86400, PV_PROFILE_BUCKET) - 1;
  $endTs = $now;
  for ($i = $nowIdx; $i <= $lastIdx; $i++) {
    $bucketStart = $dayStart + ($i * PV_PROFILE_BUCKET);
    $slotFrom = ($i === $nowIdx) ? $now : $bucketStart;
    $slotTo = $bucketStart + PV_PROFILE_BUCKET;
    $dt = $slotTo - $slotFrom;
    if ($dt <= 0) {
      continue;
    }
    $watts = $k * ($profile[$i] ?? 0.0);
    if ($watts < BATT_IDLE_W) {
      continue;   // sotto questa soglia il pacco non carica: e' sera
    }
    $endTs = $slotTo;
    $dSoc = ($watts * ($dt / 3600.0)) / 1000.0 / BATT_CAPACITY_KWH * 100.0;
    if ($soc + $dSoc >= $target) {
      $frac = ($target - $soc) / $dSoc;
      return [
        'full' => (int) round($slotFrom + ($frac * $dt)),
        'soc' => $target,
        'endTs' => $slotTo,
        'k' => $k,
      ];
    }
    $soc += $dSoc;
  }

  return ['full' => null, 'soc' => $soc, 'endTs' => $endTs, 'k' => $k];
}

/* Primo momento, nella finestra [$from, $to), in cui il fotovoltaico arriva a
 * $threshold watt e ci resta per PV_COVER_SUSTAIN secondi.
 *
 * La soglia e' la potenza che la batteria sta erogando adesso: quando il sole
 * la raggiunge, quei watt li produce il tetto e il pacco puo' smettere di
 * scaricarsi. Si guarda la produzione contro il prelievo vero del pacco e non
 * contro il consumo di casa perche' e' il pacco che deve fermarsi, ed e' l'unica
 * grandezza che si misura in questo istante invece di ricostruirla da somme e
 * differenze.
 *
 * La condizione deve reggere per qualche minuto: una nuvola che si apre non e'
 * l'inizio della carica.
 *
 * Torna anche `drop`: se DOPO il sorpasso, e PRIMA del massimo di produzione
 * della giornata, il sole e' ricaduto sotto la soglia. Il limite del picco non
 * e' un dettaglio -- dopo il picco la produzione cala sempre, e' il tramonto, e
 * senza quel paletto ogni sera risulterebbe "con un calo". Prima del picco
 * invece il sole dovrebbe salire: se scende sono nuvole, e l'ora del sorpasso
 * regge molto meno.
 */
function pvCoverCrossing(mysqli $link, int $from, int $to, float $threshold): ?array
{
  try {
    $res = $link->query(
      "SELECT `data`, pvPower + 0 AS pv
       FROM dati_meteo
       WHERE `data` >= {$from} AND `data` < {$to} AND pvPower IS NOT NULL
       ORDER BY `data` ASC"
    );
  } catch (Throwable $e) {
    return null;
  }
  if (!($res instanceof mysqli_result)) {
    return null;
  }

  $rows = [];
  while ($row = $res->fetch_assoc()) {
    if (is_numeric($row['pv'])) {
      $rows[] = [(int) $row['data'], (float) $row['pv']];
    }
  }
  if ($rows === []) {
    return null;
  }

  // Primo sorpasso che tiene.
  $runStart = null;
  $runPv = null;
  $prevTs = null;
  $crossIdx = null;

  foreach ($rows as $i => [$ts, $pv]) {
    // Un buco lungo nei dati spezza la continuita': non si puo' dire che il
    // sole abbia tenuto la soglia per dieci minuti se per dieci minuti non si
    // e' guardato.
    if ($prevTs !== null && $ts - $prevTs > PV_COVER_GAP) {
      $runStart = null;
    }
    $prevTs = $ts;

    if ($pv > PV_ZERO_THRESHOLD && $pv >= $threshold) {
      if ($runStart === null) {
        $runStart = $ts;
        $runPv = $pv;
        $crossIdx = $i;
      }
      if ($ts - $runStart >= PV_COVER_SUSTAIN) {
        break;
      }
    } else {
      $runStart = null;   // nuvola, o soglia salita: si ricomincia
      $crossIdx = null;
    }
  }
  if ($crossIdx === null || $runStart === null || $prevTs - $runStart < PV_COVER_SUSTAIN) {
    return null;
  }

  // Indice del massimo di produzione: oltre quello si sta calando verso sera.
  $peakIdx = 0;
  foreach ($rows as $i => [, $pv]) {
    if ($pv > $rows[$peakIdx][1]) {
      $peakIdx = $i;
    }
  }

  $drop = false;
  for ($i = $crossIdx + 1; $i <= $peakIdx; $i++) {
    if ($rows[$i][1] < $threshold) {
      $drop = true;
      break;
    }
  }

  return ['ts' => $runStart, 'pv' => $runPv, 'drop' => $drop];
}

/* Quante volte, negli ultimi $days giorni, il pacco e' sceso fino alla riserva
 * e si e' fermato li'.
 *
 * Si conta un giorno per volta -- un giorno il cui SoC minimo ha toccato
 * BATT_RESERVE_SOC vale un evento -- invece di contare i singoli campioni sotto
 * soglia, che sarebbero decine per ogni singolo svuotamento. Contare per giorni
 * ha senso perche' per arrivare due volte alla riserva nello stesso giorno il
 * pacco dovrebbe anche ricaricarsi del tutto in mezzo, cosa che non succede.
 *
 * Il conto lo fa il database: tornare 4000 righe di SoC a ogni aggiornamento
 * della dashboard per contarle in PHP sarebbe lo stesso numero pagato molto piu'
 * caro.
 */
function battReserveHits(mysqli $link, int $now, int $days): array
{
  $since = $now - $days * 86400;
  $reserve = BATT_RESERVE_SOC;
  $out = ['hits' => null, 'last' => null, 'observed' => null];

  try {
    $res = $link->query(
      "SELECT COUNT(*) AS hits, MAX(d) AS last_day, MIN(d) AS first_day
         FROM (SELECT DATE(FROM_UNIXTIME(`data`)) AS d, MIN(battSoc + 0) AS soc_min
                 FROM dati_meteo
                WHERE `data` >= {$since} AND battSoc IS NOT NULL
                GROUP BY d
               HAVING soc_min <= {$reserve}) AS giorni"
    );
    if ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
      $out['hits'] = (int) $row['hits'];
      $out['last'] = $row['last_day'];
    }

    // Giorni davvero osservati: senza, "3 volte" non si sa se sia su un mese o
    // su una settimana di dati.
    $res2 = $link->query(
      "SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(`data`))) AS n
         FROM dati_meteo
        WHERE `data` >= {$since} AND battSoc IS NOT NULL"
    );
    if ($res2 instanceof mysqli_result && ($row2 = $res2->fetch_assoc())) {
      $out['observed'] = (int) $row2['n'];
    }
  } catch (Throwable $e) {
    // Colonna assente o query rifiutata: la card mostra "--" e non si inventa
    // un conteggio.
  }
  return $out;
}

/* Energia di oggi, in kWh, integrata sulle righe di `dati_meteo` da $since.
 *
 * Stessa regola dei riquadri kWh di grafico.php: trapezi sui timestamp veri,
 * e un buco di piu' di un'ora non viene colmato -- un logger fermo non deve
 * inventare energia. Due differenze volute:
 *   - prelievo e immissione, carica e scarica sono separati campione per
 *     campione (max(0, x)) e non dal segno del trapezio, cosi' un passaggio da
 *     prelievo a immissione dentro un intervallo non si annulla da solo;
 *   - $live, la lettura di adesso, chiude la serie: le righe storiche sono una
 *     ogni dieci minuti, e senza l'ultimo tratto la card resterebbe ferma
 *     fino alla riga successiva.
 *
 * Le righe partono dieci minuti prima di $since, ma si integra solo la parte
 * dopo: servono a trovare il valore dei contatori del Venus a mezzanotte, che
 * torna in chgTot0 / disTot0 (null se le colonne o i valori non ci sono).
 */
function energyToday(mysqli $link, int $since, int $now, array $live): array
{
  $out = [
    'pv' => null, 'casa' => null, 'import' => null, 'export' => null,
    'chg' => null, 'dis' => null, 'chgTot0' => null, 'disTot0' => null,
  ];
  $from = $since - 600;
  $base = 'data, pvPower, gridPower, battPower';

  // Prima con i contatori, poi senza: su un database che non li ha ancora
  // mysqli lancia sulla colonna sconosciuta, e il resto va mostrato lo stesso.
  $rows = null;
  foreach (["$base, battChgTot, battDisTot", $base] as $sel) {
    try {
      $res = $link->query(
        "SELECT $sel FROM dati_meteo WHERE data >= {$from} AND data <= {$now} ORDER BY data ASC"
      );
      if ($res instanceof mysqli_result) {
        $rows = [];
        while ($r = $res->fetch_assoc()) {
          $rows[] = $r;
        }
        break;
      }
    } catch (Throwable $e) {
      // Colonna mancante: si riprova con la select ridotta.
    }
  }
  if ($rows === null) {
    return $out;
  }

  foreach ($rows as $r) {
    if ($out['chgTot0'] === null && is_numeric($r['battChgTot'] ?? null)) {
      $out['chgTot0'] = (float) $r['battChgTot'];
    }
    if ($out['disTot0'] === null && is_numeric($r['battDisTot'] ?? null)) {
      $out['disTot0'] = (float) $r['battDisTot'];
    }
  }

  $lastTs = $rows ? (int) end($rows)['data'] : 0;
  if ($now > $lastTs) {
    $rows[] = $live + ['data' => $now];
  }

  $acc = [];
  $prev = [];
  foreach ($rows as $r) {
    $t = (int) $r['data'];
    $num = static fn($k) => is_numeric($r[$k] ?? null) ? (float) $r[$k] : null;
    $pv = $num('pvPower');
    if ($pv !== null && abs($pv) < PV_ZERO_THRESHOLD) {
      $pv = 0.0;
    }
    $grid = $num('gridPower');
    $batt = $num('battPower');

    // Casa al netto della batteria, come la card Consumo Casa; una riga di
    // prima della batteria ha battPower NULL e tiene la somma semplice.
    $v = [
      'pv' => $pv,
      'import' => ($grid === null) ? null : max(0.0, $grid),
      'export' => ($grid === null) ? null : max(0.0, -$grid),
      'casa' => ($pv === null || $grid === null) ? null : $pv + $grid - ($batt ?? 0.0),
      'chg' => ($batt === null) ? null : max(0.0, $batt),
      'dis' => ($batt === null) ? null : max(0.0, -$batt),
    ];

    foreach ($v as $ch => $w) {
      if ($w === null) {
        continue;
      }
      $acc[$ch] = $acc[$ch] ?? 0.0;
      if (isset($prev[$ch]) && $t > $since && $t - $prev[$ch][0] <= 3600) {
        $dt = $t - max($prev[$ch][0], $since);
        $acc[$ch] += ($w + $prev[$ch][1]) / 2.0 * $dt / 3600.0;
      }
      $prev[$ch] = [$t, $w];
    }
  }

  foreach ($acc as $ch => $wh) {
    $out[$ch] = $wh / 1000.0;
  }
  return $out;
}

function meteo_build_payload(mysqli $link): array
{
  $now = time();

  /** ---------- 1. CALCULATE MIN / MAX (Last 24H) ---------- */
  $time24hAgo = $now - 86400;

  $queryMinMax = "SELECT
    MAX(CASE WHEN (temperatura + 0) > -50 THEN (temperatura + 0) END) as max_temp,
    MIN(CASE WHEN (temperatura + 0) > -50 THEN (temperatura + 0) END) as min_temp,
    MAX(CASE WHEN (tombra + 0) > -50 THEN (tombra + 0) END) as max_tombra,
    MIN(CASE WHEN (tombra + 0) > -50 THEN (tombra + 0) END) as min_tombra,
    MAX(CASE WHEN (tMobile + 0) > -50 THEN (tMobile + 0) END) as max_tMobile,
    MIN(CASE WHEN (tMobile + 0) > -50 THEN (tMobile + 0) END) as min_tMobile,
    MAX(hombra + 0) as max_humi,
    MIN(hombra + 0) as min_humi,
    MAX(power + 0) as max_power,
    MAX(portata + 0) as max_portata,
    MIN(portata + 0) as min_portata
    FROM dati_meteo
    WHERE data >= {$time24hAgo}";

  $resMinMax = $link->query($queryMinMax);
  $mm = ($resMinMax && $rowMM = $resMinMax->fetch_assoc()) ? $rowMM : [];

  $mm_min_temp = fmt($mm['min_temp'] ?? null);
  $mm_max_temp = fmt($mm['max_temp'] ?? null);
  $mm_min_tombra = fmt($mm['min_tombra'] ?? null);
  $mm_max_tombra = fmt($mm['max_tombra'] ?? null);
  $mm_min_tMobile = fmt($mm['min_tMobile'] ?? null);
  $mm_max_tMobile = fmt($mm['max_tMobile'] ?? null);
  $mm_min_humi = fmt($mm['min_humi'] ?? null, 0);
  $mm_max_humi = fmt($mm['max_humi'] ?? null, 0);
  $mm_max_power = fmt($mm['max_power'] ?? null, 0);

  /** ---------- 2. LATEST DATA ---------- */
  $result = $link->query("SELECT `temperatura`,`data`,`tombra`,`hombra`,`power`,`tMobile`,`portata` FROM `dati_instant` WHERE `id` = 1");
  $row = ($result instanceof mysqli_result) ? $result->fetch_assoc() : null;

  $safeTemp0 = (isset($row['temperatura']) && $row['temperatura'] !== null && (float) $row['temperatura'] > -50) ? (float) $row['temperatura'] : -100.0;
  $safeTombra0 = (isset($row['tombra']) && $row['tombra'] !== null && (float) $row['tombra'] > -50) ? (float) $row['tombra'] : -100.0;
  $safeTMobile0 = (isset($row['tMobile']) && $row['tMobile'] !== null && (float) $row['tMobile'] > -50) ? (float) $row['tMobile'] : -100.0;
  $safeHombra0 = $row['hombra'] ?? '0';
  $safePower0 = (float) ($row['power'] ?? 0);
  $safeData0 = (int) ($row['data'] ?? $now);
  $safePortata0 = (float) ($row['portata'] ?? 0);

  /** ---------- 2b. SHELLY PRO EM-50 (PV PRODUCTION + GRID EXCHANGE) ----------
   * Queried apart from the main SELECTs so the dashboard still renders on a DB
   * where carica_dati.php has not yet added the two columns.
   *   pvPower   = photovoltaic production, W
   *   gridPower = grid exchange, W  (> 0 prelievo / import, < 0 immissione / export)
   *   casaPower = house load = pvPower + gridPower
   */
  $safePv0 = null;
  $safeGrid0 = null;

  // mysqli throws on an unknown column (PHP 8.1+ reports errors as exceptions),
  // so this has to be caught, not silenced with @.
  try {
    $resEm = $link->query("SELECT `pvPower`,`gridPower` FROM `dati_instant` WHERE `id` = 1");
    if ($resEm instanceof mysqli_result && $rowEm = $resEm->fetch_assoc()) {
      $safePv0 = is_numeric($rowEm['pvPower']) ? (float) $rowEm['pvPower'] : null;
      // Under 10 W the panels are not producing; show a clean 0 W.
      if ($safePv0 !== null && abs($safePv0) < PV_ZERO_THRESHOLD) {
        $safePv0 = 0.0;
      }
      $safeGrid0 = is_numeric($rowEm['gridPower']) ? (float) $rowEm['gridPower'] : null;
    }
  } catch (Throwable $e) {
    // Columns not created yet (carica_dati.php adds them on its first run).
    $safePv0 = null;
    $safeGrid0 = null;
  }

  // The meter cards are shown only when a real reading is stored. The columns
  // existing is not enough: they are NULL until meteo.py forwards the first
  // pvPower/gridPower, and empty cards look broken.
  $emAvailable = ($safePv0 !== null || $safeGrid0 !== null);

  // Clamp detail (V / A / pf / Hz) for the "Quadro principale" card. Its own
  // query and its own try/catch, exactly like the battery diagnostics: these
  // columns are newer than pvPower/gridPower and must not be able to take the
  // two meter cards down with them on a database that predates them.
  $emDetail = [];
  if ($emAvailable) {
    try {
      $cols = implode(',', array_map(static fn($c) => "`$c`", EM_DETAIL_FIELDS));
      $resEd = $link->query("SELECT $cols FROM `dati_instant` WHERE `id` = 1");
      if ($resEd instanceof mysqli_result && $rowEd = $resEd->fetch_assoc()) {
        foreach ($rowEd as $k => $v) {
          if (is_numeric($v)) {
            $emDetail[$k] = (float) $v;
          }
        }
      }
    } catch (Throwable $e) {
      // Columns not created yet: the card stays hidden.
    }
  }

  /** ---------- 2c. MARSTEK VENUS E (HOME BATTERY) ----------
   * battPower is signed the house way: > 0 charging, < 0 discharging.
   * battTs is when the BATTERY was read, which is not when this page is being
   * built: meteo.py keeps sending the rest of the reading while the battery
   * bridge is down, and the stored figures would otherwise stay in the house
   * load for ever. Past BATT_MAX_AGE the battery is simply treated as absent.
   */
  $safeBattPower0 = null;
  $safeBattSoc0 = null;
  $safeBattTemp0 = null;
  // Diagnostics: field => value, only the ones actually stored. Kept as an
  // array so a column the bridge does not publish yet simply does not appear,
  // and the card shows a dash for it instead of a zero.
  $battDiag = [];
  // Lifetime counters (BATTERY_ENERGY_FIELDS), same rule: only what is stored.
  $battEnergy = [];
  $battAge = null;
  try {
    $resB = $link->query("SELECT `battPower`,`battSoc`,`battTemp`,`battTs` FROM `dati_instant` WHERE `id` = 1");
    if ($resB instanceof mysqli_result && $rowB = $resB->fetch_assoc()) {
      $battTs = is_numeric($rowB['battTs']) ? (int) $rowB['battTs'] : null;
      $battAge = ($battTs !== null) ? $now - $battTs : null;
      if ($battAge !== null && $battAge <= BATT_MAX_AGE) {
        $safeBattPower0 = is_numeric($rowB['battPower']) ? (float) $rowB['battPower'] : null;
        $safeBattSoc0 = is_numeric($rowB['battSoc']) ? (float) $rowB['battSoc'] : null;
        // Hottest cell (the pack), not the electronics -- meteo.py picks it.
        $safeBattTemp0 = is_numeric($rowB['battTemp']) ? (float) $rowB['battTemp'] : null;

        // A second query, its own try/catch: these columns arrive with the
        // diagnostics card and must not be able to take the three above down
        // with them on a database that predates them.
        try {
          $cols = implode(',', array_map(
            static fn($c) => "`$c`",
            BATTERY_DIAG_FIELDS
          ));
          $resD = $link->query("SELECT $cols FROM `dati_instant` WHERE `id` = 1");
          if ($resD instanceof mysqli_result && $rowD = $resD->fetch_assoc()) {
            foreach ($rowD as $k => $v) {
              if (is_numeric($v)) {
                $battDiag[$k] = (float) $v;
              }
            }
          }
        } catch (Throwable $e) {
          // Diagnostics columns not created yet: the card shows dashes.
        }

        // Lifetime kWh counters, for the Energia card. Their own query for
        // the same reason: a Pi that does not forward them yet must not cost
        // the diagnostics above.
        try {
          $cols = implode(',', array_map(
            static fn($c) => "`$c`", BATTERY_ENERGY_FIELDS));
          $resE = $link->query("SELECT $cols FROM `dati_instant` WHERE `id` = 1");
          if ($resE instanceof mysqli_result && $rowE = $resE->fetch_assoc()) {
            foreach ($rowE as $k => $v) {
              if (is_numeric($v)) {
                $battEnergy[$k] = (float) $v;
              }
            }
          }
        } catch (Throwable $e) {
          // Counter columns not created yet: the card omits the totals.
        }
      }
    }
  } catch (Throwable $e) {
    // Columns not created yet (store_lib.php adds them on its first history row).
  }

  $battAvailable = ($safeBattPower0 !== null || $safeBattSoc0 !== null
    || $safeBattTemp0 !== null);

  /**
   * House load, net of the battery.
   *
   * The Venus sits behind the grid meter, on the house side, so the Shelly
   * cannot tell it apart from a dishwasher: pvPower + gridPower counts a pack
   * charging at 800 W as 800 W of consumption, and hides the same amount while
   * it discharges. Subtracting battPower leaves what the house is actually
   * using. Checked against the meters at 09:57 on 2026-09-04: PV 1871 W,
   * export 877 W, battery charging 770 W -> 224 W of real load, and
   * 224 + 770 + 877 = 1871 balances exactly.
   *
   * With no fresh battery reading this falls back to the old sum, which is the
   * right answer for a house that has no battery and the best available one
   * for a house whose bridge is down.
   */
  $safeCasa0 = ($safePv0 !== null && $safeGrid0 !== null) ? $safePv0 + $safeGrid0 : null;
  if ($safeCasa0 !== null && $safeBattPower0 !== null) {
    $safeCasa0 -= $safeBattPower0;
  }

  // 24h extremes for the same two channels (again isolated from the main query).
  $em_max_pv = null;
  $em_max_grid = null;
  $em_min_grid = null;
  $em_max_casa = null;
  if ($emAvailable) {
    try {
      $resEmMM = $link->query(
        "SELECT MAX(pvPower + 0) AS max_pv,
                MAX(gridPower + 0) AS max_grid,
                MIN(gridPower + 0) AS min_grid,
                MAX(pvPower + gridPower) AS max_casa
         FROM dati_meteo
         WHERE data >= {$time24hAgo} AND pvPower IS NOT NULL"
      );
      if ($resEmMM instanceof mysqli_result && $rowEmMM = $resEmMM->fetch_assoc()) {
        $em_max_pv = is_numeric($rowEmMM['max_pv']) ? (float) $rowEmMM['max_pv'] : null;
        $em_max_grid = is_numeric($rowEmMM['max_grid']) ? (float) $rowEmMM['max_grid'] : null;
        $em_min_grid = is_numeric($rowEmMM['min_grid']) ? (float) $rowEmMM['min_grid'] : null;
        $em_max_casa = is_numeric($rowEmMM['max_casa']) ? (float) $rowEmMM['max_casa'] : null;
      }
    } catch (Throwable $e) {
      // dati_instant has the columns but dati_meteo does not (yet): no extremes.
    }

    // The house peak again, this time net of the battery. Deliberately a
    // SECOND query rather than one more column in the one above: battPower
    // reaches dati_meteo only when the ten-minute path adds it, and while it
    // is missing mysqli throws -- which, from inside the same try, would take
    // the PV and grid extremes down with it. They are unrelated to the
    // battery and must not depend on it.
    //
    // COALESCE, not a bare subtraction: a row logged before the battery
    // existed has battPower NULL, and NULL would swallow the whole expression
    // and lose that row's peak.
    $em_max_casa_batt = null;
    try {
      // Two peaks in one pass: the house alone, and the house plus whatever
      // the battery was drawing. LEAST(...,0) is the algebra for "count the
      // battery only while it charges" -- casa + max(0,b) is the same as
      // pv + grid - min(b,0), and this way it stays one SQL expression.
      $resCasaMM = $link->query(
        "SELECT MAX(pvPower + gridPower - COALESCE(battPower, 0)) AS max_casa,
                MAX(pvPower + gridPower - LEAST(COALESCE(battPower, 0), 0)) AS max_casa_batt
         FROM dati_meteo
         WHERE data >= {$time24hAgo} AND pvPower IS NOT NULL"
      );
      if ($resCasaMM instanceof mysqli_result && $rowCasaMM = $resCasaMM->fetch_assoc()) {
        if (is_numeric($rowCasaMM['max_casa'])) {
          $em_max_casa = (float) $rowCasaMM['max_casa'];
        }
        if (is_numeric($rowCasaMM['max_casa_batt'])) {
          $em_max_casa_batt = (float) $rowCasaMM['max_casa_batt'];
        }
      }
    } catch (Throwable $e) {
      // No battPower column yet: keep the gross peak from the query above.
    }
  }

  // 24h extremes for the battery itself: how full it got, how empty, and the
  // hardest it pushed each way.
  $batt_min_soc = null;
  $batt_max_soc = null;
  $batt_max_charge = null;
  $batt_max_discharge = null;
  $batt_min_temp = null;
  $batt_max_temp = null;
  if ($battAvailable) {
    try {
      $resBMM = $link->query(
        "SELECT MIN(battSoc + 0) AS min_soc,
                MAX(battSoc + 0) AS max_soc,
                MAX(battPower + 0) AS max_charge,
                MIN(battPower + 0) AS min_charge,
                MIN(battTemp + 0) AS min_temp,
                MAX(battTemp + 0) AS max_temp
         FROM dati_meteo
         WHERE data >= {$time24hAgo} AND battSoc IS NOT NULL"
      );
      if ($resBMM instanceof mysqli_result && $rowBMM = $resBMM->fetch_assoc()) {
        $batt_min_soc = is_numeric($rowBMM['min_soc']) ? (float) $rowBMM['min_soc'] : null;
        $batt_max_soc = is_numeric($rowBMM['max_soc']) ? (float) $rowBMM['max_soc'] : null;
        $batt_max_charge = is_numeric($rowBMM['max_charge']) ? (float) $rowBMM['max_charge'] : null;
        $batt_max_discharge = is_numeric($rowBMM['min_charge']) ? (float) $rowBMM['min_charge'] : null;
        $batt_min_temp = is_numeric($rowBMM['min_temp']) ? (float) $rowBMM['min_temp'] : null;
        $batt_max_temp = is_numeric($rowBMM['max_temp']) ? (float) $rowBMM['max_temp'] : null;
      }
    } catch (Throwable $e) {
      // No battery history yet: the card shows its live figures only.
    }
  }

  /** ---------- 3. PRESSURE & FORECAST (WITH SNOW; USING TEMPERATURA SOLE) ---------- */
  $stateFile = INSTANT_STATE_FILE;
  $presHistoryFile = INSTANT_PRES_HISTORY_FILE;
  $safePres0 = 1013.0;
  $presTrend3h = 0.0;
  $presTrendValid = false;   // true only when both endpoints are trustworthy

  // Displayed "current" pressure (from the live state file).
  if (file_exists($stateFile)) {
    $stateJson = json_decode((string) file_get_contents($stateFile), true);
    if (isset($stateJson['pres']))
      $safePres0 = (float) $stateJson['pres'];
  }

  // --- Robust 3h trend -------------------------------------------------------
  // Parse the history CSV ("timestamp,pressure") into a clean list.
  $pHist = [];
  if (file_exists($presHistoryFile)) {
    foreach (file($presHistoryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $pr) {
      $pParts = explode(',', $pr);
      if (count($pParts) >= 2 && is_numeric($pParts[0]) && is_numeric($pParts[1])) {
        $pHist[] = [(int) $pParts[0], (float) $pParts[1]];
      }
    }
  }

  // Median pressure of all samples within +/- $halfWin seconds of $centerTs.
  // Median (not mean) rejects single spikes; the window averages out sensor jitter.
  $presMedianAround = function (int $centerTs, int $halfWin) use ($pHist) {
    $vals = [];
    foreach ($pHist as $r) {
      if (abs($r[0] - $centerTs) <= $halfWin)
        $vals[] = $r[1];
    }
    if (!$vals)
      return null;
    sort($vals);
    $n = count($vals);
    return ($n % 2) ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2.0;
  };

  if ($pHist) {
    // Both endpoints come from the SAME series (shared calibration).
    // Logger cadence is ~10 min, so windows are sized to hold a few samples and
    // tolerate one missed tick:
    //   "now": median of the last ~20 min -> also rejects a stale/dead logger.
    //   "3h ago": median within +/- 30 min of the 3h mark, else we don't trust it.
    $pNow = $presMedianAround($now, 1200);
    $pOld = $presMedianAround($now - 10800, 1800);
    if ($pNow !== null && $pOld !== null) {
      $presTrend3h = $pNow - $pOld;
      $presTrendValid = true;
    }
  }

  $mslp = $safePres0 + 31.8 - 2.5;

  // >>> USE TEMPERATURA OMBRA (shade air temp) as reference; fall back to sun sensor <<<
  // The shade sensor is the true air temperature; the sun-exposed probe over-reads.
  // Shade air temp lives in the `tombra` column ($safeTombra0); `tMobile` is the
  // indoor sensor.
  $outTemp = ($safeTombra0 > -99) ? $safeTombra0 : $safeTemp0;

  // humidity numeric
  $humi = is_numeric($safeHombra0) ? (float) $safeHombra0 : 0.0;
  $humiValid = ($humi > 0);

  // Humidity gating: precipitation needs moisture in the air.
  // If the sensor is invalid we don't block (treat as "moist enough").
  $moistAir = (!$humiValid || $humi >= 70);
  $dryAir = ($humiValid && $humi < 55);

  // default
  $forecast = ['icon' => 'cloud', 'text' => 'Variabile', 'color' => 'var(--text-muted)'];

  // snow heuristics (shade temp + falling pressure + high humidity)
  $snowLikely = false;
  if ($outTemp > -99) {
    if ($outTemp <= 1.5 && $presTrend3h <= -0.3 && $humi >= 75)
      $snowLikely = true;
    if ($outTemp <= 0.0 && $presTrend3h <= -0.2 && $humi >= 65)
      $snowLikely = true;
  }
  // A storm needs both a sharp pressure drop AND moisture.
  $stormLikely = ($presTrend3h <= -1.5 && $moistAir);

  if ($snowLikely) {
    $forecast = ['icon' => 'snow', 'text' => 'Neve', 'color' => 'var(--accent-ice)'];
  } elseif ($stormLikely) {
    if ($outTemp <= 1.0) {
      $forecast = ['icon' => 'snow_storm', 'text' => 'Bufera', 'color' => 'var(--accent-ice)'];
    } else {
      $forecast = ['icon' => 'storm', 'text' => 'Temporale', 'color' => 'var(--accent-red)'];
    }
  } elseif ($presTrend3h <= -0.5 && $moistAir) {
    if ($outTemp <= 1.0) {
      $forecast = ['icon' => 'sleet', 'text' => 'Nevischio', 'color' => 'var(--accent-ice)'];
    } else {
      $forecast = ['icon' => 'rain', 'text' => 'Pioggia', 'color' => 'var(--accent-blue)'];
    }
  } elseif ($presTrend3h <= -0.5 && $dryAir) {
    // Pressure dropping but the air is dry -> clouds building, not rain yet.
    $forecast = ['icon' => 'cloud', 'text' => 'Nuvoloso', 'color' => 'var(--text-muted)'];
  } elseif ($humiValid && $humi >= 95 && abs($presTrend3h) < 0.4 && $mslp <= 1022 && $outTemp <= 8.0) {
    // Saturated, calm and cool -> fog / mist.
    $forecast = ['icon' => 'cloud', 'text' => 'Nebbia', 'color' => 'var(--text-muted)'];
  } elseif ($mslp > 1022 && $dryAir) {
    // High pressure + dry air -> confidently clear.
    $forecast = ['icon' => 'sun', 'text' => 'Sereno', 'color' => 'var(--accent-orange)'];
  } elseif ($mslp > 1022) {
    // High pressure but humid -> hazy / veiled sun.
    $forecast = ['icon' => 'partly_cloudy', 'text' => 'Velato', 'color' => 'var(--accent-orange)'];
  } elseif ($mslp > 1016 && $presTrend3h > -0.2) {
    $forecast = ['icon' => 'partly_cloudy', 'text' => 'Poco Nuvoloso', 'color' => 'var(--accent-orange)'];
  } elseif ($presTrend3h >= 0.5) {
    $forecast = ['icon' => 'partly_cloudy', 'text' => 'In Miglioramento', 'color' => 'var(--accent-orange)'];
  } elseif ($mslp < 1008) {
    if (!$moistAir) {
      // Low pressure but dry -> unsettled/cloudy rather than wet.
      $forecast = ['icon' => 'cloud', 'text' => 'Nuvoloso', 'color' => 'var(--text-muted)'];
    } elseif ($outTemp <= 1.0) {
      $forecast = ['icon' => 'sleet', 'text' => 'Instabile (freddo)', 'color' => 'var(--accent-ice)'];
    } else {
      $forecast = ['icon' => 'rain', 'text' => 'Instabile', 'color' => 'var(--accent-blue)'];
    }
  }

  /** ---------- PV AS SOLAR-IRRADIANCE / SKY-NOW PROXY ---------- */
  // Photovoltaic output is a direct read of how much sun is hitting the panel.
  // To judge cloud cover we compare it against the CLEAR-SKY output expected for
  // THIS time of day, estimated from the best output seen at the same clock hour
  // over the past 7 days. This normalises for sun elevation, so a clear morning
  // or evening is no longer mistaken for cloud, and night falls out for free
  // (no historical output at this hour -> no reference -> no classification).
  $pvNow = $safePower0;

  $hourWindows = [];
  for ($k = 1; $k <= 7; $k++) {
    $lo = $now - ($k * 86400) - 1800;   // same clock time k days ago, +/- 30 min
    $hi = $now - ($k * 86400) + 1800;
    $hourWindows[] = "(data BETWEEN {$lo} AND {$hi})";
  }
  $qHourPeak = "SELECT MAX(power + 0) AS hour_peak FROM dati_meteo WHERE (" . implode(' OR ', $hourWindows) . ")";
  $resHP = $link->query($qHourPeak);
  $hourPeak = ($resHP && $rHP = $resHP->fetch_assoc()) ? (float) ($rHP['hour_peak'] ?? 0) : 0.0;

  // Fraction of the clear-sky reference we are actually producing right now.
  // Need a meaningful reference (>20 W) or we can't tell (deep night / no data).
  $sunFrac = ($hourPeak > 20 && $pvNow >= 0) ? min(1.0, $pvNow / $hourPeak) : null;

  $skyNow = null; // ['text' => ..., 'color' => ...]
  if ($sunFrac !== null) {
    if ($sunFrac >= 0.70) {
      $skyNow = ['text' => 'Sereno', 'color' => 'var(--accent-orange)'];
    } elseif ($sunFrac >= 0.40) {
      $skyNow = ['text' => 'Poco Nuvoloso', 'color' => 'var(--accent-orange)'];
    } elseif ($sunFrac >= 0.15) {
      $skyNow = ['text' => 'Nuvoloso', 'color' => 'var(--text-muted)'];
    } else {
      $skyNow = ['text' => 'Coperto', 'color' => 'var(--text-muted)'];
    }
  }

  $iceWarning = ($outTemp > -99 && $outTemp <= 0.0);
  $forecastTiming = getForecastTiming($forecast, $presTrend3h);

  /** ---------- 4. TRENDS & FILTERS ---------- */
  $time30mAgo = $now - 1800;
  $queryTrend = "SELECT `temperatura`, `tombra`, `tMobile`, `portata` FROM `dati_meteo` WHERE `data` <= {$time30mAgo} ORDER BY `data` DESC LIMIT 1";
  $resTrend = $link->query($queryTrend);
  $trendData = ($resTrend && $resTrend->num_rows > 0) ? $resTrend->fetch_assoc() : null;

  $trend_temp1 = calculateTrend($safeTemp0, $trendData['temperatura'] ?? null);
  $trend_tombra = calculateTrend($safeTombra0, $trendData['tombra'] ?? null);
  $trend_tMobile = calculateTrend($safeTMobile0, $trendData['tMobile'] ?? null);
  $trend_piave = calculateTrend($safePortata0, $trendData['portata'] ?? null);

  $iceTime_temp1 = getFreezingTime($safeTemp0, $trend_temp1);
  $iceTime_tombra = getFreezingTime($safeTombra0, $trend_tombra);
  $iceTime_tMobile = getFreezingTime($safeTMobile0, $trend_tMobile);

  /** ---------- 5. RIVER STATUS ---------- */
  $waterStatus = 'normal';
  if ($safePortata0 > 100.0)
    $waterStatus = 'increasing';
  elseif ($safePortata0 <= 17.0)
    $waterStatus = 'dry';

  /** ---------- 6. PV CARD (metered, with ADC fallback) ---------- */
  // Prefer the metered production; fall back to the ADC estimate while the
  // meter (or its DB columns) is not available, so the card is never empty.
  $pvMetered = ($safePv0 !== null);
  $pvValue = $pvMetered ? round($safePv0) : $safePower0;
  // Below 10 W there is no real production (clamp/inverter noise) -> show 0.
  // mqtt_receiver.py already stores it that way; this also covers rows logged
  // before that rule existed and the ADC fallback above.
  if ((float) $pvValue < PV_ZERO_THRESHOLD) {
    $pvValue = 0;
  }
  $pvPeak = ($em_max_pv !== null) ? fmtW($em_max_pv) : ($mm_max_power . ' W');
  $pvLink = $pvMetered ? 'grafico.php?var=pvPower' : 'grafico.php?var=power';

  /* La produzione di ieri alla stessa ora. Dice, in un numero solo, se oggi il
   * tetto sta andando meglio o peggio -- confronto che l'irraggiamento in
   * percentuale non faceva: quello misurava il cielo contro il massimo della
   * settimana, non contro la giornata precedente, ed era la stessa cosa che la
   * riga "Sereno / Nuvoloso" qui sopra dice gia' a parole.
   *
   * Si legge la stessa colonna che la card sta mostrando adesso -- il contatore
   * quando c'e', la stima ADC altrimenti -- perche' due sorgenti diverse non si
   * confrontano fra loro. La riga piu' vicina alle 24 ore esatte entro
   * PV_YESTERDAY_TOL, e nessuna se ieri a quest'ora non si e' registrato niente
   * (impianto nuovo, o un buco nei dati).
   */
  $pvYesterday = null;
  $pvCol = $pvMetered ? 'pvPower' : 'power';
  $pvRef = $now - 86400;
  $pvYFrom = $pvRef - PV_YESTERDAY_TOL;
  $pvYTo = $pvRef + PV_YESTERDAY_TOL;
  try {
    $resY = $link->query(
      "SELECT `{$pvCol}` + 0 AS pv
       FROM dati_meteo
       WHERE `data` BETWEEN {$pvYFrom} AND {$pvYTo} AND `{$pvCol}` IS NOT NULL
       ORDER BY ABS(`data` - {$pvRef}) ASC
       LIMIT 1"
    );
    if ($resY instanceof mysqli_result) {
      $rowY = $resY->fetch_assoc();
      if ($rowY !== null && is_numeric($rowY['pv'])) {
        $pvYesterday = max(0.0, (float) $rowY['pv']);
        if ($pvYesterday < PV_ZERO_THRESHOLD) {
          $pvYesterday = 0.0;   // stessa soglia del valore di oggi
        }
      }
    }
  } catch (Throwable $e) {
    // Colonna assente o storico vuoto: la voce mostra '--'.
  }

  /** ---------- 7. GRID DIRECTION ---------- */
  $exporting = ($safeGrid0 !== null && $safeGrid0 < 0);
  if ($safeGrid0 === null) {
    $gridFlow = '--';
  } elseif ($exporting) {
    $gridFlow = '&#8593; Immissione';
  } elseif ($safeGrid0 > 5) {
    $gridFlow = '&#8595; Prelievo';
  } else {
    $gridFlow = 'Equilibrio';
  }

  /** ---------- 7b. BATTERY STATE ----------
   * Derived from the power, not read: `state` on the Venus says what it has
   * been TOLD to do, not what it is doing. BATT_IDLE_W keeps a pack running
   * its own electronics from reading as a discharge.
   */
  $battCharging = ($safeBattPower0 !== null && $safeBattPower0 > BATT_IDLE_W);
  $battDischarging = ($safeBattPower0 !== null && $safeBattPower0 < -BATT_IDLE_W);
  if ($safeBattPower0 === null) {
    $battFlow = '--';
    $battColor = 'var(--text-muted)';
  } elseif ($battCharging) {
    $battFlow = '&#8595; In carica';
    $battColor = 'var(--accent-green)';
  } elseif ($battDischarging) {
    $battFlow = '&#8593; In scarica';
    $battColor = 'var(--accent-orange)';
  } else {
    $battFlow = 'In attesa';
    $battColor = 'var(--text-muted)';
  }

  /* Inizio carica: l'ora in cui il sole si prende il carico e la batteria
   * smette di scaricarsi per cominciare a riempirsi.
   *
   * E' una misura, non una previsione meteo: si legge sul fotovoltaico di OGGI,
   * il dato piu' fresco che questa dashboard abbia. Prima che il sorpasso di
   * oggi sia avvenuto pero' non c'e' niente da leggere -- ed e' proprio la
   * situazione in cui la card si vede, cioe' di notte a batteria in scarica --
   * quindi in quel caso si ricade sull'ultima mattina osservata davvero, quella
   * di ieri. Meglio l'ora dell'ultima alba vera che nessuna ora.
   */
  $anaCrossTs = null;   // istante del sorpasso, usato anche dalla stima batteria
  $anaVal = '--';
  $anaPv = '--';        // quanto produceva il sole in quel momento
  $anaDrop = false;     // dopo il sorpasso il sole e' ricaduto sotto la soglia

  // Soglia da raggiungere: i watt che il pacco sta erogando adesso. Si muove
  // con la casa -- accendi il forno e l'ora si sposta in avanti, perche' al
  // sole serve piu' tempo per arrivare a coprire un prelievo piu' grosso.
  $anaThreshold = ($battDischarging && $safeBattPower0 !== null)
    ? abs($safeBattPower0) : null;

  if ($battAvailable && $emAvailable && $anaThreshold !== null) {
    $tStart = strtotime('today 00:00', $now);
    $cross = ($tStart === false)
      ? null : pvCoverCrossing($link, $tStart, $now, $anaThreshold);

    if ($cross === null) {
      $yStart = strtotime('yesterday 00:00', $now);
      $yEnd = strtotime('today 00:00', $now);
      $cross = ($yStart === false || $yEnd === false)
        ? null : pvCoverCrossing($link, $yStart, $yEnd, $anaThreshold);
    }

    if ($cross !== null) {
      $anaCrossTs = (int) $cross['ts'];
      $anaVal = date('H:i', $anaCrossTs);
      // La potenza del sorpasso dice quanto sole ci vuole, in casa, perche' la
      // batteria smetta di lavorare: e' la soglia da tenere d'occhio nei giorni
      // coperti, quando l'ora da sola non basta a capire se ce la fara'.
      $anaPv = fmtW($cross['pv']);
      $anaDrop = (bool) $cross['drop'];
    }
  }

  /* Autonomy while discharging, time-to-full while charging: how long the
   * pack takes to get where it is going and at what time it arrives, at the
   * rate of the last half hour rather than the rate of this instant -- the
   * instantaneous power swings with every appliance and every passing cloud,
   * and would make the figure jump around uselessly.
   *
   * Discharging it counts down to BATT_RESERVE_SOC, not to zero, because that
   * is where the pack actually stops; charging it counts up to BATT_FULL_SOC.
   * Nothing is shown unless the window says something: a battery that just
   * changed direction gets no number rather than a wrong one.
   */
  $battEtaText = '';
  $battEtaClock = '';
  $battEtaShow = false;
  $battEtaColor = 'var(--accent-orange)';
  // Colore della barra imposto dalla stima invece che dal SoC: vale solo in
  // scarica, quando la carica finisce entro stanotte o entro domattina presto.
  // Resta null quando la stima non dice niente di urgente e la barra torna a
  // colorarsi in base alla percentuale.
  $battFillUrgency = null;
  // Istanti su cui il pallino della card "Inizio carica" da' il suo giudizio:
  // quando il pacco tocca la riserva e quando il sole se lo riprende.
  $battEtaEnd = null;
  $battSunTakeover = null;
  // Correzione della stima di carica col profilo medio del sole: l'ora di pieno
  // rifatta seguendo la discesa del pomeriggio, e -- quando quella discesa non
  // basta ad arrivare al 100% -- il massimo che oggi si puo' raggiungere.
  $sunFullTs = null;
  $sunMaxSoc = null;
  $sunMaxTs = null;
  if (($battDischarging || $battCharging) && $safeBattSoc0 !== null) {
    /* Samples come from the RAM window first: it holds every reading of the
     * last half hour, where `dati_meteo` holds three or four of them. The DB
     * is the fallback for the case the buffer has nothing to say yet -- after
     * a reboot, or on a host with no usable /dev/shm -- and gives the coarser
     * answer it always did rather than none.
     */
    $rowsEta = [];
    foreach (trend_window(BATT_ETA_WINDOW) as $t) {
      if (!isset($t['battSoc'])) {
        continue;
      }
      $rowsEta[] = [
        'data' => (int) $t['t'],
        'soc' => (float) $t['battSoc'],
        'power' => isset($t['battPower']) ? (float) $t['battPower'] : null,
      ];
    }

    if (count($rowsEta) < 3) {
      $rowsEta = [];
      try {
        $sinceEta = $now - BATT_ETA_WINDOW;
        $resEta = $link->query(
          "SELECT `data`, battSoc + 0 AS soc, battPower + 0 AS power
           FROM dati_meteo
           WHERE `data` >= {$sinceEta} AND battSoc IS NOT NULL
           ORDER BY `data` ASC"
        );
        if ($resEta instanceof mysqli_result) {
          while ($rowEta = $resEta->fetch_assoc()) {
            $rowsEta[] = [
              'data' => (int) $rowEta['data'],
              'soc' => is_numeric($rowEta['soc']) ? (float) $rowEta['soc'] : null,
              'power' => is_numeric($rowEta['power']) ? (float) $rowEta['power'] : null,
            ];
          }
        }
      } catch (Throwable $e) {
        // No battery history either: the card simply omits the estimate.
      }
    }

    $target = $battCharging ? BATT_FULL_SOC : BATT_RESERVE_SOC;
    $eta = battSocEtaHours($rowsEta, $safeBattSoc0, $target);
    if ($eta !== null) {
      $battEtaShow = true;
      // Green filling, orange emptying: the same colour the flow state uses,
      // so the two lines of the card never disagree about which way it is going.
      $battEtaColor = $battCharging ? 'var(--accent-green)' : 'var(--accent-orange)';
      $etaEnd = $now + (int) ($eta['hours'] * 3600);

      // Prossima volta che il sole si riprende il carico, all'ora che la card
      // "Inizio carica" ha misurato. nextClock la porta avanti alla giornata
      // giusta: guardando la dashboard di notte il sorpasso e' quello di fra
      // poche ore, non quello del giorno dopo.
      $sunTakeover = ($anaCrossTs === null)
        ? null : nextClock(date('H:i', $anaCrossTs), $now);

      // Il pacco arriva al sorpasso: alla riserva non ci arriva mai, perche' da
      // quell'ora il carico se lo prende il fotovoltaico e la scarica si ferma.
      // Durata e ora di fine descriverebbero qualcosa che non succedera', quindi
      // resta il solo ritmo in %/h, che e' l'unica cosa davvero misurata.
      $etaRescuedBySun = !$battCharging && $sunTakeover !== null && $etaEnd >= $sunTakeover;

      $battEtaEnd = $etaEnd;
      $battSunTakeover = $sunTakeover;

      // Oltre la mattina dopo la retta non descrive più niente: il sole avrà
      // rimesso dentro corrente molto prima. Da lì in poi cadono l'ora di fine
      // e i watt, che prometterebbero una precisione che la stima non ha.
      $etaBeyondMorning = !$battCharging && $etaEnd > nextClock('09:00', $now);

      /* In carica, dal primo pomeriggio, la retta va confrontata col sole che
       * c'e' davvero. Il ritmo dell'ultima mezz'ora e' quello di ADESSO: se
       * sono le 14 e il pacco prende 2 kW, prolungarlo dice "pieno alle 17"
       * ignorando che alle 17 il tetto fara' un quinto di quei watt. Il
       * profilo medio degli ultimi PV_PROFILE_DAYS giorni rifa' il conto
       * seguendo quella discesa.
       *
       * Se le due stime cadono a meno di PV_PROFILE_TOLERANCE l'una dall'altra
       * sono d'accordo e la riga resta com'e'. Altrimenti vince il profilo:
       * o l'ora corretta, o -- quando il sole finisce prima del 100% -- la
       * carica massima che la giornata permette.
       *
       * Da qui viene anche il paletto della mezzanotte: una carica solare non
       * puo' finire domani, e senza questo controllo una giornata coperta
       * produce senza fatica un "pieno alle 03:20".
       */
      if ($battCharging && (int) date('G', $now) >= PV_PROFILE_FROM_HOUR) {
        $pvProfile = pvDayProfile($link, $now);
        $sun = ($pvProfile === null) ? null
          : battChargeSunEta($pvProfile, $now, $safeBattSoc0, $target, $eta['watts']);
        if ($sun !== null) {
          if ($sun['full'] === null) {
            $sunMaxSoc = $sun['soc'];
            $sunMaxTs = (int) $sun['endTs'];
          } elseif (abs((int) $sun['full'] - $etaEnd) > PV_PROFILE_TOLERANCE) {
            $sunFullTs = (int) $sun['full'];
          }
        }
      }

      // Colore della barra, letto sulla stessa scadenza che la riga racconta:
      //   verde  = ce la fa fino al sorpasso del sole, non si svuota;
      //   rosso  = la riserva arriva entro oggi;
      //   giallo = ci arriva durante la notte, prima che il sole la salvi.
      // Senza il dato di ieri (giornata coperta, storico assente) il sorpasso
      // non esiste: resta il solo rosso per "finisce oggi" e per il resto la
      // barra torna a colorarsi in base alla percentuale.
      if (!$battCharging) {
        if ($etaRescuedBySun) {
          $battFillUrgency = 'var(--accent-green)';
        } elseif ($etaEnd <= nextClock('00:00', $now)) {
          $battFillUrgency = 'var(--accent-red)';
        } elseif ($sunTakeover !== null) {
          $battFillUrgency = 'var(--accent-yellow)';
        }
      }

      if ($etaRescuedBySun) {
        $battEtaText = number_format($eta['slope'], 1) . ' %/h';
        $battEtaClock = '';
      } else {
        // The rate the estimate rests on, in both its units: a duration is only
        // as good as the rate under it, and this is that rate.
        $battEtaClock = $etaBeyondMorning ? '' : ' &#183; ' . fmtW($eta['watts']);
        $battEtaClock .= ' &#183; ' . number_format($eta['slope'], 1) . ' %/h';
        if ($eta['hours'] > 24) {
          // Past a day the linear extrapolation is fiction: the house will have
          // charged and discharged again long before then.
          $battEtaText = 'oltre 24 h';
        } else {
          $battEtaText = fmtDuration($eta['hours']);
          if (!$etaBeyondMorning) {
            $battEtaClock .= ' &#183; ' . ($battCharging ? 'pieno alle ' : 'fino alle ')
              . date('H:i', $etaEnd);
          }
        }
      }

      /* La correzione col sole riscrive la riga, invece di aggiungersi in
       * coda: due orari di pieno diversi sulla stessa card non si commentano a
       * vicenda, si contraddicono. Restano i watt e i %/h, che sono misurati e
       * valgono comunque, e resta detto da dove viene la correzione.
       */
      if ($sunMaxSoc !== null) {
        // Oggi il 100% non arriva: quello che conta non e' piu' "quando", e'
        // "fin dove". Giallo perche' e' una carica che finisce a meta'.
        $battEtaText = 'max ' . round($sunMaxSoc) . '%';
        $battEtaClock = ' &#183; ' . fmtW($eta['watts'])
          . ' &#183; ' . number_format($eta['slope'], 1) . ' %/h'
          . ' &#183; sole fino alle ' . date('H:i', $sunMaxTs)
          . ' (media ' . PV_PROFILE_DAYS . ' gg)';
        $battEtaColor = 'var(--accent-yellow)';
        $battEtaEnd = $sunMaxTs;
      } elseif ($sunFullTs !== null) {
        $battEtaText = fmtDuration(max(0, $sunFullTs - $now) / 3600.0);
        $battEtaClock = ' &#183; ' . fmtW($eta['watts'])
          . ' &#183; ' . number_format($eta['slope'], 1) . ' %/h'
          . ' &#183; pieno alle ' . date('H:i', $sunFullTs)
          . ' (media ' . PV_PROFILE_DAYS . ' gg)';
        $battEtaEnd = $sunFullTs;
      } elseif ($battCharging && $etaEnd >= nextClock('00:00', $now)) {
        // Senza profilo utilizzabile resta almeno il paletto: il sole non
        // carica di notte, quindi l'ora di pieno di domani non si stampa.
        $battEtaClock = str_replace(' &#183; pieno alle ' . date('H:i', $etaEnd), '', $battEtaClock);
        $battEtaClock .= ' &#183; non entro oggi';
      }
      // Which of the two methods answered: a duration read off the SoC curve
      // and one read off the mean power are not the same claim, and the card
      // should not present them as if they were.
      if ($eta['basis'] === 'power') {
        $battEtaText .= ' (stima da potenza)';
      }
    }
  }

  /* Icona sotto l'ora di "Inizio carica": dice se quell'ora arriva in tempo
   * rispetto a quando il pacco tocca la riserva.
   *
   *   spunta         = il sole arriva prima che la batteria si svuoti, e da li'
   *                    in poi non e' piu' ricaduto sotto la soglia;
   *   triangolo      = l'ora ci starebbe, ma quel giorno dopo il sorpasso il
   *                    sole e' ricaduto (nuvole: l'ora regge poco); oppure
   *                    arriva in ritardo, ma per meno di due ore;
   *   ottagono       = arriva con piu' di due ore di ritardo: quel buco lo paga
   *                    la rete.
   *
   * Senza una delle due scadenze non si giudica: niente icona, che vuol dire
   * "non lo so" invece di una spunta ottimista.
   */
  $anaIcon = '';
  $anaIconColor = 'var(--text-muted)';
  if ($battSunTakeover !== null && $battEtaEnd !== null) {
    if ($battSunTakeover > $battEtaEnd + ANA_LATE_RED) {
      $anaIcon = ANA_ICON_ALARM;
      $anaIconColor = 'var(--accent-red)';
    } elseif ($battSunTakeover > $battEtaEnd || $anaDrop) {
      $anaIcon = ANA_ICON_WARN;
      $anaIconColor = 'var(--accent-yellow)';
    } else {
      $anaIcon = ANA_ICON_OK;
      $anaIconColor = 'var(--accent-green)';
    }
  }

  /* Da dove arriva, in questo istante, la corrente che casa sta usando. Le tre
   * sorgenti non si misurano una per una: si ricavano dai segni.
   *
   *   rete       = quota importata dello scambio (gridPower > 0);
   *   batteria   = quanto il pacco sta erogando (battPower < 0);
   *   fotovoltaico = quello che resta del consumo, cioe' la parte di produzione
   *                  che non e' finita ne' in rete ne' nel pacco.
   *
   * Il fotovoltaico per differenza e non come pvPower: quando l'impianto
   * esporta o carica la batteria, pvPower e' piu' grande del contributo che i
   * pannelli danno DAVVERO alle prese di casa, e le tre barre non tornerebbero.
   */
  $mix = [
    'show' => false,
    'pv' => '0%',
    'batt' => '0%',
    'grid' => '0%',
    'pvW' => '--',
    'battW' => '--',
    'gridW' => '--'
  ];
  $mixGridW = null;
  $mixPvW = null;
  if ($safeCasa0 !== null && $safeCasa0 > 0 && $safeGrid0 !== null) {
    $fromGrid = min(max(0.0, $safeGrid0), $safeCasa0);
    $fromBatt = ($safeBattPower0 !== null) ? max(0.0, -$safeBattPower0) : 0.0;
    $fromBatt = min($fromBatt, max(0.0, $safeCasa0 - $fromGrid));
    $fromPv = max(0.0, $safeCasa0 - $fromGrid - $fromBatt);
    // I due contributi in watt servono anche al contatore di scarica, che su
    // questi decide se la batteria stava reggendo la casa da sola.
    $mixGridW = $fromGrid;
    $mixPvW = $fromPv;
    $sum = $fromPv + $fromBatt + $fromGrid;
    if ($sum > 0) {
      // La rete prende il resto invece del suo arrotondamento: cosi' le tre
      // larghezze fanno esattamente 100% e la barra non lascia un filo vuoto.
      $pctPv = (int) round($fromPv / $sum * 100);
      $pctBatt = (int) round($fromBatt / $sum * 100);
      $pctGrid = max(0, 100 - $pctPv - $pctBatt);
      $mix = [
        'show' => true,
        'pv' => $pctPv . '%',
        'batt' => $pctBatt . '%',
        'grid' => $pctGrid . '%',
        'pvW' => fmtW($fromPv),
        'battW' => fmtW($fromBatt),
        'gridW' => fmtW($fromGrid),
      ];
    }
  }

  /* Statistiche: per ora una sola voce, quante volte il pacco e' finito sulla
   * riserva. La card e' pensata per crescere, quindi il calcolo sta qui e non
   * dentro il blocco della batteria.
   */
  $statsHits = '--';
  $statsLast = '--';
  $statsWindow = STATS_DAYS . ' giorni';

  /* Quanto la batteria ha davvero retto la casa. Il contatore avanza qui
   * perche' qui si sa se il pacco sta scaricando, e questa funzione gira a
   * ogni upload del Pi (~60 s): l'intervallo fra due passaggi e' l'unita' di
   * misura. Il giorno in corso sta in /dev/shm, ieri e il totale su disco --
   * vedi batt_runtime_accumulate().
   *
   * Copertura totale in due casi.
   *
   * Il primo e' il fotovoltaico spento (produzione sotto BATT_PV_OFF_W): di
   * notte non c'e' nient'altro in campo, la batteria sta reggendo la casa e
   * quelle ore contano intere, anche se il contatore intanto tira qualche
   * watt -- quella e' la parte di carico che il pacco non riesce a seguire,
   * non una seconda sorgente.
   *
   * Il secondo e' di giorno: nessuna delle due sorgenti supera BATT_COVER_W,
   * quindi ne' la rete ne' i pannelli stanno dando qualcosa alle prese.
   *
   * Senza le letture non si puo' dire, e quel tempo finisce fra i parziali:
   * meglio sottostimare le ore "da sola" che regalarle. */
  $battNightPv = ($safePv0 !== null && $safePv0 < BATT_PV_OFF_W);
  $battFullCover = ($battDischarging && (
    // Impianto fermo: la giornata e' finita e quello che tiene su la casa e'
    // il pacco, punto. Quel poco che eventualmente entra dalla rete e' la
    // coda che la batteria non ce la fa a seguire, non un'altra sorgente.
    $battNightPv
    || ($mixGridW !== null && $mixPvW !== null
      && $mixGridW <= BATT_COVER_W && $mixPvW <= BATT_COVER_W)
  ));
  $rt = batt_runtime_accumulate(
    $battAvailable && $battDischarging,
    $battAvailable && $battFullCover,
    $battAvailable && $battCharging,
    $now
  );

  $statsToday = fmtHms((int) $rt['day_secs']);
  $statsTotal = fmtHms((int) $rt['total_secs'] + (int) $rt['day_secs']);
  $statsTodayFull = fmtHms((int) $rt['day_full']);
  $statsTodayPart = fmtHms((int) $rt['day_secs'] - (int) $rt['day_full']);
  /* "Ieri" e' il ciclo precedente, non il giorno di calendario: la giornata
   * qui va da quando il pacco inizia a caricare la mattina a quando ricomincia
   * la mattina dopo (vedi batt_runtime_accumulate). Vale solo se quel ciclo e'
   * ancora vicino a questo -- con il sito fermo a lungo `prev_*` resta su
   * qualcosa di molto piu' vecchio, e chiamarlo "ieri" sarebbe una bugia. */
  $prevGap = ((int) $rt['prev_start'] > 0)
    ? (int) $rt['cycle_start'] - (int) $rt['prev_start'] : null;
  $hasPrev = ($prevGap !== null && $prevGap > 0 && $prevGap <= BATT_PREV_MAX);
  $statsYestFull = $hasPrev ? fmtHms((int) $rt['prev_full']) : '--';
  $statsYestPart = $hasPrev
    ? fmtHms((int) $rt['prev_secs'] - (int) $rt['prev_full']) : '--';
  $statsTotFull = fmtHms((int) $rt['total_full'] + (int) $rt['day_full']);
  $statsTotPart = fmtHms(
    ((int) $rt['total_secs'] + (int) $rt['day_secs'])
    - ((int) $rt['total_full'] + (int) $rt['day_full'])
  );

  if ($battAvailable) {
    $st = battReserveHits($link, $now, STATS_DAYS);
    if ($st['hits'] !== null) {
      $statsHits = (string) $st['hits'];
      $statsLast = ($st['last'] === null) ? 'mai' : date('d/m', (int) strtotime((string) $st['last']));
    }
    if ($st['observed'] !== null) {
      $statsWindow = $st['observed'] . ' giorni osservati';
    }
  }

  /* ---------- 7e. ENERGIA (KWH OGGI + CONTATORI DEL VENUS) ----------
   * Oggi: integrato dalle righe storiche piu' la lettura di adesso (vedi
   * energyToday). Per la batteria c'e' di meglio: i contatori che il Venus
   * tiene da se'. Quello che e' entrato e uscito oggi e' la differenza fra il
   * valore di adesso e quello di mezzanotte, cioe' la misura del pacco e non
   * un campione ogni dieci minuti. Si torna all'integrale se i contatori
   * mancano o danno un numero impossibile (azzerati da un aggiornamento
   * firmware, o una lettura fuori mappa).
   */
  $midnight = mktime(0, 0, 0, (int) date('n'), (int) date('j'), (int) date('Y'));
  $en = ($emAvailable || $battAvailable)
    ? energyToday($link, $midnight, $now, [
      'pvPower' => $safePv0,
      'gridPower' => $safeGrid0,
      'battPower' => $safeBattPower0,
    ])
    : null;

  $chgTot = $battEnergy['battChgTot'] ?? null;
  $disTot = $battEnergy['battDisTot'] ?? null;
  $counterDelta = static function (?float $nowVal, ?float $base): ?float {
    if ($nowVal === null || $base === null) {
      return null;
    }
    $d = $nowVal - $base;
    // Piu' di quattro pieni in un giorno non e' fisica, e' un contatore rotto.
    return ($d < 0 || $d > 4 * BATT_CAPACITY_KWH) ? null : $d;
  };
  // Tre fonti per "Batteria oggi", dalla migliore: i totali giornalieri che
  // il Venus tiene da se' (33004 / 33006, azzerati da lui a mezzanotte), la
  // differenza dei contatori di sempre da mezzanotte, l'integrale dei campioni.
  $chgToday = $battEnergy['battChgDay'] ?? null;
  $disToday = $battEnergy['battDisDay'] ?? null;
  $battFromCounters = ($chgToday !== null && $disToday !== null);
  if (!$battFromCounters) {
    $chgToday = $counterDelta($chgTot, $en['chgTot0'] ?? null);
    $disToday = $counterDelta($disTot, $en['disTot0'] ?? null);
    $battFromCounters = ($chgToday !== null && $disToday !== null);
  }
  if (!$battFromCounters) {
    $chgToday = $en['chg'] ?? null;
    $disToday = $en['dis'] ?? null;
  }
  $chgMonth = $battEnergy['battChgMon'] ?? null;
  $disMonth = $battEnergy['battDisMon'] ?? null;

  $kwh = static fn(?float $v) => ($v === null) ? '--'
    : number_format($v, ($v >= 100) ? 1 : 2) . ' kWh';
  $pct = static fn(?float $num, ?float $den) => ($num === null || $den === null || $den < 0.05)
    ? '--' : (int) round(min(100, max(0, $num / $den * 100))) . '%';

  // Autoconsumo: la parte della produzione usata in casa (o messa nel pacco)
  // invece che regalata alla rete. Autosufficienza: la parte del consumo che
  // non si e' comprata. Due domande diverse, e ognuna nasconde l'altra.
  $enPv = $en['pv'] ?? null;
  $enCasa = $en['casa'] ?? null;
  $enImport = $en['import'] ?? null;
  $enExport = $en['export'] ?? null;
  $selfUse = $pct(($enPv !== null && $enExport !== null) ? $enPv - $enExport : null, $enPv);
  $selfSuff = $pct(($enCasa !== null && $enImport !== null) ? $enCasa - $enImport : null, $enCasa);

  // Cicli: il contatore del BMS (34003) quando c'e', altrimenti quelli
  // equivalenti -- quanti pacchi interi ha restituito da quando esiste. E' il
  // numero con cui si misura l'usura (il Venus E e' garantito a cicli).
  // Il rendimento e' scaricato / caricato: approssimato, perche' l'energia
  // ancora dentro il pacco e' contata fra le cariche ma non fra le scariche.
  $bmsCycles = $battEnergy['battCycles'] ?? null;
  $battCycles = ($bmsCycles !== null) ? (string) (int) round($bmsCycles)
    : (($disTot === null) ? '--' : number_format($disTot / BATT_CAPACITY_KWH, 1));
  $battYield = ($chgTot !== null && $disTot !== null && $chgTot >= 1.0)
    ? $pct($disTot, $chgTot) : '--';

  /* Cell temperature, coloured by what it means for the pack rather than by
   * how warm it sounds. Below 0 °C the BMS refuses to CHARGE (discharge is
   * fine down to -20), which is normal on a winter night and worth flagging
   * as a state, not an alarm; above 45 °C it starts derating. */
  $battTempColor = 'var(--text-muted)';
  $battTempNote = '';
  if ($safeBattTemp0 !== null) {
    if ($safeBattTemp0 < 0) {
      $battTempColor = 'var(--accent-ice)';
      $battTempNote = ' carica bloccata dal BMS';
    } elseif ($safeBattTemp0 >= 45) {
      $battTempColor = 'var(--accent-red)';
      $battTempNote = ' in derating';
    } elseif ($safeBattTemp0 >= 35) {
      $battTempColor = 'var(--accent-orange)';
    } else {
      $battTempColor = 'var(--accent-teal)';
    }
  }

  /* ---------- 7c. PACK DIAGNOSTICS ----------
   * One card, three families: temperatures, voltages, currents. The two
   * spreads are the interesting derived numbers -- a pack whose cells sit
   * within a few mV and a couple of degrees of each other is balanced, and
   * both go wrong long before either extreme looks alarming on its own.
   */
  $d = static fn(string $k) => $battDiag[$k] ?? null;
  $fmtNum = static fn($v, int $dec, string $unit)
    => ($v === null) ? '--' : number_format((float) $v, $dec) . ' ' . $unit;

  $cellTempSpread = ($safeBattTemp0 !== null && $d('battTempMin') !== null)
    ? $safeBattTemp0 - $d('battTempMin') : null;
  $cellVoltSpread = ($d('battCellVMax') !== null && $d('battCellVMin') !== null)
    ? ($d('battCellVMax') - $d('battCellVMin')) * 1000 : null;   // in mV

  /* AC current is DERIVED, not read. Register 37004 is `ac_current` in the
   * community register map, but on this firmware it returns the AC power --
   * the same word as 30006, checked over three samples. |W| / V is the honest
   * substitute, and the card says so rather than implying a measurement. */
  $acCurrent = ($d('battAcW') !== null && $d('battAcV') !== null && $d('battAcV') > 50)
    ? abs($d('battAcW')) / $d('battAcV') : null;

  /* Casa + batteria: what the house and the pack are drawing together.
   *
   * Only a CHARGING battery is added. A discharging one is not consumption --
   * it is where part of the consumption is coming from, and adding it with its
   * own sign would subtract, making the total smaller than the house alone.
   */
  $battDraw = ($safeBattPower0 !== null) ? max(0.0, $safeBattPower0) : null;
  $safeCasaBatt0 = ($safeCasa0 !== null)
    ? $safeCasa0 + ($battDraw ?? 0.0)
    : null;

  /* Le due quote della card "Casa + Batteria": quanto di quel totale va alle
   * prese e quanto finisce nel pacco. Qui non c'e' niente da ricavare dai
   * segni -- sono i due addendi della somma appena fatta -- ma le larghezze si
   * preparano lo stesso nel payload, cosi' la pagina non fa conti. */
  $cbMix = [
    'show' => false,
    'casa' => '0%',
    'batt' => '0%',
    'casaW' => '--',
    'battW' => '--'
  ];
  if (
    $safeCasaBatt0 !== null && $safeCasaBatt0 > 0 && $battDraw !== null
    && $battDraw > BATT_IDLE_W
  ) {
    $pctCasa = (int) round(max(0.0, $safeCasa0) / $safeCasaBatt0 * 100);
    $cbMix = [
      'show' => true,
      'casa' => $pctCasa . '%',
      // La batteria prende il resto: due larghezze che fanno esattamente 100%.
      'batt' => max(0, 100 - $pctCasa) . '%',
      'casaW' => fmtW(max(0.0, $safeCasa0)),
      'battW' => fmtW($battDraw),
    ];
  }

  // How much of the load is NOT bought from the grid -- covered by the panels
  // directly or by the battery giving back. Computed from the import rather
  // than from pvPower, because with a battery in the middle the PV figure on
  // its own no longer says what the house consumed.
  $prelievo0 = ($safeGrid0 === null) ? null : max(0.0, $safeGrid0);
  $selfShare = null;
  if ($safeCasa0 !== null && $prelievo0 !== null && $safeCasa0 > 0) {
    $selfShare = (int) round(min(100, max(0, ($safeCasa0 - $prelievo0) / $safeCasa0 * 100)));
  }
  $selfLabel = $battAvailable ? '% da FV e batteria' : '% da fotovoltaico';

  /* ---------- 7d. QUADRO PRINCIPALE (SHELLY CLAMP DETAIL) ----------
   * The electrical picture behind the two power cards: line voltage and
   * frequency once, then current and power factor per clamp. The power factor
   * has no unit, so this formatter drops the trailing space $fmtNum would add.
   */
  $e = static fn(string $k) => $emDetail[$k] ?? null;
  $e_fmt = static fn($v, int $dec, string $unit)
    => ($v === null) ? '--'
    : number_format((float) $v, $dec) . ($unit === '' ? '' : ' ' . $unit);

  /* The clamps report |current|: only the sign of gridPower says which way it
   * is flowing, so the direction is spelled out next to the ampere figure. */
  $gridDir = '';
  if ($safeGrid0 !== null) {
    $gridDir = ($safeGrid0 > 0) ? 'prelievo'
      : (($safeGrid0 < 0) ? 'immissione' : 'in pareggio');
  }

  /** ---------- 8. RENDER-READY PAYLOAD ---------- */
  return [
    'ts' => $safeData0,
    // Seconds matter now that readings arrive on change rather than once a
    // minute: without them two consecutive updates look identically timed.
    'headerTime' => date('d-m-y H:i:s', $safeData0),
    'emAvailable' => $emAvailable,
    // Like emAvailable: the card only exists in the markup while the battery
    // is reporting, so the browser reloads rather than patching when it flips.
    'battAvailable' => $battAvailable,

    'tempSole' => [
      'val' => ($safeTemp0 <= -99 ? '--' : (string) $safeTemp0),
      'freezing' => (getTempClass($safeTemp0) !== ''),
      'min' => (string) $mm_min_temp,
      'max' => (string) $mm_max_temp,
      'trend' => getTrendHtml($trend_temp1, '°C/h', $iceTime_temp1),
    ],
    'tempOmbra' => [
      'val' => ($safeTombra0 <= -99 ? '--' : (string) $safeTombra0),
      'freezing' => (getTempClass($safeTombra0) !== ''),
      'min' => (string) $mm_min_tombra,
      'max' => (string) $mm_max_tombra,
      'trend' => getTrendHtml($trend_tombra, '°C/h', $iceTime_tombra),
    ],
    'tempInterno' => [
      'val' => ($safeTMobile0 <= -99 ? '--' : (string) $safeTMobile0),
      'freezing' => (getTempClass($safeTMobile0) !== ''),
      'min' => (string) $mm_min_tMobile,
      'max' => (string) $mm_max_tMobile,
      'trend' => getTrendHtml($trend_tMobile, '°C/h', $iceTime_tMobile),
    ],

    'pres' => [
      'val' => number_format($safePres0, 1),
      'icon' => $forecast['icon'],
      'text' => $forecast['text'],
      'color' => $forecast['color'],
      'timing' => $forecastTiming,
      'ice' => $iceWarning,
      'mslp' => number_format($mslp, 1),
      'trendText' => $presTrendValid ? (($presTrend3h > 0 ? '+' : '') . number_format($presTrend3h, 1)) : 'n/d',
      'trendClass' => $presTrendValid ? ($presTrend3h < 0 ? 'trend-down' : 'trend-up') : '',
    ],

    'pv' => [
      'val' => (string) $pvValue,
      'night' => (getPowerClass((float) $pvValue) !== ''),
      'skyText' => ($skyNow !== null) ? $skyNow['text'] : '',
      'skyColor' => ($skyNow !== null) ? $skyNow['color'] : 'var(--text-muted)',
      'peak' => (string) $pvPeak,
      'yesterday' => ($pvYesterday === null ? '--' : fmtW($pvYesterday)),
      'link' => $pvLink,
    ],

    'humi' => [
      'val' => (string) $safeHombra0,
      'range' => $mm_min_humi . '% - ' . $mm_max_humi . '%',
    ],

    'piave' => [
      'val' => number_format($safePortata0, 2),
      'status' => $waterStatus,
      'statusText' => ($waterStatus == 'dry' ? 'Secca' : ($waterStatus == 'increasing' ? 'In Piena' : 'Normale')),
      'statusColor' => ($waterStatus == 'dry' ? 'var(--accent-dry)' : ($waterStatus == 'increasing' ? 'var(--accent-red)' : 'var(--accent-blue)')),
      'rising' => ($waterStatus == 'increasing' && $trend_piave !== null && $trend_piave > 1.5),
      'trend' => getTrendHtml($trend_piave, 'm³/s/h'),
    ],

    'grid' => [
      'val' => ($safeGrid0 === null ? '--' : (string) round(abs($safeGrid0))),
      'exporting' => $exporting,
      'flow' => $gridFlow,
      'flowColor' => $exporting ? 'var(--accent-green)' : 'var(--accent-blue)',
      'maxIn' => ($em_max_grid === null ? '--' : fmtW(max(0, $em_max_grid))),
      'maxOut' => ($em_min_grid === null ? '--' : fmtW(abs(min(0, $em_min_grid)))),
    ],

    // Prelievo: what the house actually buys from the grid. Zero whenever the
    // PV covers the whole consumption (grid flow <= 0, i.e. balance or export);
    // otherwise it is simply the imported side of the exchange.
    'prelievo' => [
      'val' => ($safeGrid0 === null ? '--' : (string) round(max(0, $safeGrid0))),
      'drawing' => ($safeGrid0 !== null && $safeGrid0 > 5),
      'stateText' => ($safeGrid0 === null ? '--'
        : (($safeGrid0 > 5) ? '&#8595; Dalla rete' : '100% da fotovoltaico')),
      'stateColor' => ($safeGrid0 !== null && $safeGrid0 > 5)
        ? 'var(--accent-red)' : 'var(--accent-green)',
      'shareShow' => ($safeGrid0 !== null && $safeCasa0 !== null && $safeCasa0 > 0),
      'share' => ($safeGrid0 !== null && $safeCasa0 !== null && $safeCasa0 > 0)
        ? round(min(100, (max(0, $safeGrid0) / $safeCasa0) * 100)) . '% da rete'
        : '',
      'max' => ($em_max_grid === null ? '--' : fmtW(max(0, $em_max_grid))),
    ],

    // Consumo casa is net of the battery (see the derivation above), so it is
    // what the appliances are drawing rather than what the meters see.
    'casa' => [
      'val' => ($safeCasa0 === null ? '--' : (string) round($safeCasa0)),
      'shareShow' => ($selfShare !== null),
      'share' => ($selfShare !== null) ? $selfShare . $selfLabel : '',
      'peak' => fmtW($em_max_casa),
      // Le tre sorgenti del consumo, gia' in percentuale (larghezze pronte per
      // la barra) e in watt (la legenda sotto).
      'mix' => $mix,
    ],

    /* Casa + batteria. Deliberately its own card rather than a second figure
     * on the one above: the two answer different questions -- what the house
     * is using, and what the whole installation is pulling while it also
     * fills the battery. */
    'casaBatt' => [
      'val' => ($safeCasaBatt0 === null ? '--' : (string) round($safeCasaBatt0)),
      'charging' => ($battDraw !== null && $battDraw > BATT_IDLE_W),
      // The split, so the number is never a mystery: house plus battery. The
      // "solo casa" branch is what the card would say while the battery is
      // idle -- index.php hides the whole card in that case, and the string is
      // kept because live.php serves this payload to other consumers too.
      'breakdown' => ($safeCasa0 === null) ? '--'
        : (($battDraw !== null && $battDraw > BATT_IDLE_W)
          ? fmtW($safeCasa0) . ' casa + ' . fmtW($battDraw) . ' batteria'
          : 'solo casa'),
      'breakdownColor' => ($battDraw !== null && $battDraw > BATT_IDLE_W)
        ? 'var(--accent-green)' : 'var(--text-muted)',
      // Le due quote della somma, gia' in percentuale (la barra) e in watt
      // (la legenda sotto).
      'mix' => $cbMix,
      'peak' => fmtW($em_max_casa_batt ?? $em_max_casa),
    ],

    /* Quadro principale: what the two Shelly clamps measure besides power.
     * Same treatment as battDiag -- plain strings with their units, read and
     * not charted. The currents are unsigned, so the grid one carries the
     * direction as a word taken from the sign of gridPower. */
    'quadro' => [
      'show' => ($emDetail !== []),
      'vLine' => $e_fmt($e('emGridV') ?? $e('emPvV'), 1, 'V'),
      'hz' => $e_fmt($e('emHz'), 2, 'Hz'),
      'vGrid' => $e_fmt($e('emGridV'), 1, 'V'),
      'iGrid' => $e_fmt($e('emGridA'), 2, 'A'),
      'pfGrid' => $e_fmt($e('emGridPf'), 2, ''),
      'wGrid' => fmtW($safeGrid0),
      'dirGrid' => $gridDir,
      'vPv' => $e_fmt($e('emPvV'), 1, 'V'),
      'iPv' => $e_fmt($e('emPvA'), 2, 'A'),
      'pfPv' => $e_fmt($e('emPvPf'), 2, ''),
      'wPv' => fmtW($safePv0),
    ],

    /* Everything the pack reports about itself. Rendered as plain strings
     * with their units: nothing here is charted or compared, it is read. */
    // Statistiche sul pacco: quante volte e' arrivato alla riserva e quando.
    'stats' => [
      'show' => $battAvailable,
      'hits' => $statsHits,
      'last' => $statsLast,
      'window' => $statsWindow,
      // Ore di scarica in hh:mm:ss. `today`/`total` sono il tempo di scarica
      // comunque sia andata; le sei voci sotto lo dividono fra le ore in cui
      // il pacco ha retto la casa da solo e quelle in cui la rete dava una
      // mano, per oggi, ieri e sempre.
      'today' => $statsToday,
      'total' => $statsTotal,
      'todayFull' => $statsTodayFull,
      'todayPart' => $statsTodayPart,
      'yestFull' => $statsYestFull,
      'yestPart' => $statsYestPart,
      'totFull' => $statsTotFull,
      'totPart' => $statsTotPart,
    ],

    // Energia: kWh di oggi (impianto e batteria) e i totali di sempre che il
    // Venus conta da se'. Tre gruppi, ognuno visibile solo se ha una fonte.
    'energia' => [
      'show' => ($emAvailable || $battAvailable),
      'emShow' => $emAvailable,
      'pv' => $kwh($enPv),
      'casa' => $kwh($enCasa),
      'import' => $kwh($enImport),
      'export' => $kwh($enExport),
      'selfUse' => $selfUse,
      'selfSuff' => $selfSuff,
      'battShow' => $battAvailable,
      'chg' => $kwh($chgToday),
      'dis' => $kwh($disToday),
      'battSrc' => $battFromCounters ? 'dai contatori del Venus' : 'stimata dai campioni',
      'monShow' => ($battAvailable && ($chgMonth !== null || $disMonth !== null)),
      'chgMon' => $kwh($chgMonth),
      'disMon' => $kwh($disMonth),
      'totShow' => ($battAvailable && ($chgTot !== null || $disTot !== null)),
      'chgTot' => $kwh($chgTot),
      'disTot' => $kwh($disTot),
      'cycles' => $battCycles,
      'cyclesLabel' => ($bmsCycles !== null) ? 'Cicli (BMS)' : 'Cicli equivalenti',
      'yield' => $battYield,
    ],

    'battDiag' => [
      'show' => ($battDiag !== []),
      'tCellMax' => $fmtNum($safeBattTemp0, 1, '°C'),
      'tCellMin' => $fmtNum($d('battTempMin'), 1, '°C'),
      'tSpread' => $fmtNum($cellTempSpread, 1, '°C'),
      'tSpreadWarn' => ($cellTempSpread !== null && $cellTempSpread > 5),
      'tInt' => $fmtNum($d('battTempInt'), 1, '°C'),
      'tMos1' => $fmtNum($d('battTempMos1'), 1, '°C'),
      'tMos2' => $fmtNum($d('battTempMos2'), 1, '°C'),
      'vPack' => $fmtNum($d('battVolt'), 2, 'V'),
      'vCellMax' => $fmtNum($d('battCellVMax'), 3, 'V'),
      'vCellMin' => $fmtNum($d('battCellVMin'), 3, 'V'),
      // In mV: the digit that matters here is the one three decimals of a
      // volt hide.
      'vSpread' => ($cellVoltSpread === null) ? '--'
        : round($cellVoltSpread) . ' mV',
      'vSpreadWarn' => ($cellVoltSpread !== null && $cellVoltSpread > 50),
      'vAc' => $fmtNum($d('battAcV'), 1, 'V'),
      'hz' => $fmtNum($d('battAcHz'), 2, 'Hz'),
      'iPack' => $fmtNum($d('battCurr'), 1, 'A'),
      'iAc' => $fmtNum($acCurrent, 1, 'A'),
    ],

    // Inizio carica: l'ora in cui il sole si prende il carico e la batteria
    // smette di scaricarsi. Solo l'ora, ed e' l'unico numero su cui si decide.
    // Si vede solo mentre il pacco scarica: fermo o in carica non serve.
    'inizioCarica' => [
      'show' => ($battAvailable && $emAvailable && $battDischarging),
      'val' => $anaVal,
      'pv' => $anaPv,
      'icon' => $anaIcon,
      'iconColor' => $anaIconColor,
    ],

    'batteria' => [
      'val' => ($safeBattSoc0 === null ? '--' : (string) round($safeBattSoc0)),
      // Width of the fill bar, as a CSS length the poller can drop straight in.
      'fill' => ($safeBattSoc0 === null ? '0%'
        : max(0, min(100, round($safeBattSoc0))) . '%'),
      // L'urgenza della stima batte la percentuale: un pacco al 60% che si
      // svuota entro stanotte non è una situazione verde.
      'fillColor' => ($safeBattSoc0 === null ? 'var(--text-muted)'
        : ($battFillUrgency ?? ($safeBattSoc0 <= 15 ? 'var(--accent-red)'
          : ($safeBattSoc0 <= 35 ? 'var(--accent-orange)' : 'var(--accent-green)')))),
      'charging' => $battCharging,
      'discharging' => $battDischarging,
      'flow' => $battFlow,
      'flowColor' => $battColor,
      // Signed watts as the battery reports them, so the card says how hard it
      // is working as well as which way.
      'power' => ($safeBattPower0 === null ? '--' : fmtW(abs($safeBattPower0))),
      'residuo' => ($safeBattSoc0 === null ? '--'
        : number_format($safeBattSoc0 / 100 * BATT_CAPACITY_KWH, 2) . ' kWh'),
      'range' => ($batt_min_soc === null || $batt_max_soc === null) ? '--'
        : round($batt_min_soc) . '% - ' . round($batt_max_soc) . '%',
      // Autonomy: shown only while discharging, and only when the last half
      // hour is enough to say something. See BATT_RESERVE_SOC for the floor.
      'etaShow' => $battEtaShow,
      'eta' => $battEtaText,
      'etaClock' => $battEtaClock,
      'etaColor' => $battEtaColor,
      // Cella piu' calda: e' quella su cui lavorano i limiti del BMS, mentre
      // `temperature` sulla batteria e' l'elettronica e corre 6 gradi sopra.
      'temp' => ($safeBattTemp0 === null ? '--' : number_format($safeBattTemp0, 1) . ' °C'),
      'tempShow' => ($safeBattTemp0 !== null),
      'tempColor' => $battTempColor,
      'tempNote' => $battTempNote,
      'tempRange' => ($batt_min_temp === null || $batt_max_temp === null) ? '--'
        : round($batt_min_temp) . '° - ' . round($batt_max_temp) . '°',
      'maxCharge' => ($batt_max_charge === null) ? '--' : fmtW(max(0, $batt_max_charge)),
      'maxDischarge' => ($batt_max_discharge === null) ? '--' : fmtW(abs(min(0, $batt_max_discharge))),
    ],
  ];
}

/**
 * Build the payload and park it in /dev/shm. Returns the stored version
 * (i.e. with `rev` and `built_at` filled in), or null if the DB is unreachable.
 */
function meteo_refresh_payload(?mysqli $link = null): ?array
{
  $link = $link ?? meteo_db();
  if (!$link instanceof mysqli) {
    return null;
  }
  return instant_write(meteo_build_payload($link));
}

/**
 * The snapshot every reader should use: the RAM copy while it is fresh, a
 * rebuild (which refreshes the RAM copy) otherwise. Falls back to a stale
 * snapshot rather than nothing when the DB is down.
 */
function meteo_payload_get(?mysqli $link = null): ?array
{
  $cached = instant_read();
  if (instant_is_fresh($cached)) {
    return $cached;
  }
  $fresh = meteo_refresh_payload($link);
  return $fresh ?? $cached;
}
