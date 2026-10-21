<?php
declare(strict_types=1);

/**
 * RAM-only ring buffer of the last TREND_WINDOW seconds of incoming readings.
 *
 * Trends need a SERIES, not a snapshot: how fast the battery is emptying, how
 * fast the pressure is moving. Until now the only series on this host was
 * `dati_meteo`, which is a disk table written once every ten minutes -- so a
 * half-hour trend was three rows, and asking it for anything finer would have
 * meant writing history rows at the MQTT rate and wearing the disk out for
 * data nobody keeps.
 *
 * This is the answer to that: every reading is appended here as it arrives,
 * the window is pruned on the way in, and nothing else is ever kept. It lives
 * in the same tmpfs as the instant snapshot.
 *
 * Deliberately NOT mirrored to disk, unlike instant.json. The whole point is
 * that trend inputs never touch the platters, and losing the buffer costs
 * nothing: it is thirty minutes of samples that the DB already has the
 * ten-minute version of, and it refills within one window. Every reader must
 * therefore cope with an empty buffer -- after a reboot, after a tmpfs wipe,
 * or on a host with no usable /dev/shm -- by falling back to the database.
 *
 * The storage rules for the real data are untouched: dati_instant, dati_meteo
 * and the snapshot all still work exactly as before. This is an addition, not
 * a replacement.
 */

require_once __DIR__ . '/instant_store.php';   // INSTANT_RAM_DIR, instant_ram_ready()

/** The buffer itself. One JSON array, oldest sample first. */
const TREND_RAM_FILE = INSTANT_RAM_DIR . '/trend_window.json';

/** How much history the buffer holds (seconds). */
const TREND_WINDOW = 1800;

/**
 * One sample per this many seconds of WALL CLOCK. The MQTT feed can deliver a
 * reading every couple of seconds, which would be a thousand samples per
 * window for no gain in a trend measured in %/h -- and the file is rewritten
 * whole on every push, so its size is the cost of every write.
 *
 * Readings landing in a bucket already occupied replace what is in it, so the
 * newest values win. The bucket comes from the clock and NOT from the previous
 * sample's own timestamp: anchoring it on the stored row would move the anchor
 * forward on every replacement, the gap would never be reached, and the buffer
 * would sit at one sample for ever (which is exactly what it did).
 */
const TREND_MIN_GAP = 20;

/** Hard ceiling on stored samples, whatever the timing says. */
const TREND_MAX_ROWS = 200;

/**
 * Append one reading. Only numeric fields are kept, under the names they
 * arrive with (battSoc, battPower, pres, temp ...), plus `t`, the moment it
 * was stored.
 *
 * Returns false when there is no usable tmpfs -- a caller must never treat
 * that as an error worth failing the reading over.
 */
function trend_push(array $sample, ?int $ts = null): bool
{
    if (!instant_ram_ready()) {
        return false;
    }
    $now = $ts ?? time();

    $fh = @fopen(TREND_RAM_FILE, 'c+');
    if ($fh === false) {
        return false;
    }
    // Exclusive for the whole read-modify-write: several PHP workers can be
    // storing readings at once, and an unlocked rewrite would lose samples.
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return false;
    }

    $raw = stream_get_contents($fh);
    $rows = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
    if (!is_array($rows)) {
        $rows = [];   // corrupt or half-written: start the window again
    }

    $row = ['t' => $now];
    foreach ($sample as $k => $v) {
        if ($k !== 't' && is_numeric($v)) {
            $row[$k] = (float) $v;
        }
    }

    // Same bucket as the last sample: replace it, keeping the newer values --
    // the newest sample is the one the fit extrapolates from. A new bucket
    // appends, which is what makes the window grow.
    $last = $rows === [] ? null : $rows[count($rows) - 1];
    if (is_array($last)
        && intdiv((int) ($last['t'] ?? 0), TREND_MIN_GAP) === intdiv($now, TREND_MIN_GAP)) {
        array_pop($rows);
    }
    $rows[] = $row;

    $cut = $now - TREND_WINDOW;
    $rows = array_values(array_filter(
        $rows,
        static fn($x) => is_array($x) && ((int) ($x['t'] ?? 0)) >= $cut
    ));
    if (count($rows) > TREND_MAX_ROWS) {
        $rows = array_slice($rows, -TREND_MAX_ROWS);
    }

    $json = json_encode($rows, JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json);
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $json !== false;
}

/**
 * The buffer, oldest first, limited to the last $seconds (default: all of it).
 * An empty array is the normal answer on a host that has just booted; it is
 * never an error.
 */
function trend_window(?int $seconds = null): array
{
    if (!is_readable(TREND_RAM_FILE)) {
        return [];
    }
    $fh = @fopen(TREND_RAM_FILE, 'r');
    if ($fh === false) {
        return [];
    }
    // Shared lock: a reader must not see a file a writer is halfway through.
    if (!flock($fh, LOCK_SH)) {
        fclose($fh);
        return [];
    }
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    $rows = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
    if (!is_array($rows)) {
        return [];
    }
    if ($seconds === null) {
        return $rows;
    }
    $cut = time() - $seconds;
    return array_values(array_filter(
        $rows,
        static fn($x) => is_array($x) && ((int) ($x['t'] ?? 0)) >= $cut
    ));
}

/**
 * One field as a series, oldest first: [['data' => ts, 'value' => float], ...].
 * Samples where the field is missing are skipped, so a column that only some
 * readings carry does not produce holes a fit would have to guard against.
 */
function trend_series(string $field, ?int $seconds = null): array
{
    $out = [];
    foreach (trend_window($seconds) as $row) {
        if (isset($row[$field]) && is_numeric($row[$field])) {
            $out[] = ['data' => (int) $row['t'], 'value' => (float) $row[$field]];
        }
    }
    return $out;
}

/* ---------- Consumo casa: the last readings, one by one ----------
 *
 * The bar strip of the Consumo Casa card. Not taken from the trend window:
 * that keeps one sample per 20 s bucket, so readings in between were
 * overwritten and the strip missed most of what the card's number showed.
 * Here every reading is appended. RAM only, like the rest.
 */
const CASA_RAM_FILE = INSTANT_RAM_DIR . '/casa_last.json';
const CASA_SAMPLES = 10;
// Same strip on the Produzione Fotovoltaico card.
const PV_RAM_FILE = INSTANT_RAM_DIR . '/pv_last.json';

/** Append one house-load reading (W) taken at $ts. */
function casa_push(int $ts, float $w): bool
{
    return last_push(CASA_RAM_FILE, $ts, $w);
}

/** Append one PV production reading (W) taken at $ts. */
function pv_push(int $ts, float $w): bool
{
    return last_push(PV_RAM_FILE, $ts, $w);
}

/** Append one reading to a last-CASA_SAMPLES file in RAM. */
function last_push(string $file, int $ts, float $w): bool
{
    if (!instant_ram_ready()) {
        return false;
    }
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return false;
    }
    $raw = stream_get_contents($fh);
    $rows = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
    $rows = is_array($rows) ? $rows : [];
    $last = $rows === [] ? null : $rows[count($rows) - 1];
    // The bridge retrying the same message: once is enough.
    if (!(is_array($last) && (int) ($last['t'] ?? 0) === $ts && (float) ($last['w'] ?? -1) === round($w, 1))) {
        $rows[] = ['t' => $ts, 'w' => round($w, 1)];
        $rows = array_slice($rows, -CASA_SAMPLES);
        $json = json_encode($rows);
        if ($json !== false) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json);
            fflush($fh);
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

/** The last CASA_SAMPLES readings, oldest first: [['t' => ts, 'w' => W], ...]. */
function casa_samples(): array
{
    return last_samples(CASA_RAM_FILE);
}

/** The last CASA_SAMPLES PV readings, oldest first. */
function pv_samples(): array
{
    return last_samples(PV_RAM_FILE);
}

function last_samples(string $file): array
{
    $raw = is_readable($file) ? @file_get_contents($file) : false;
    $rows = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
    return is_array($rows) ? $rows : [];
}

/* ---------- Inverter efficiency: the last battery readings, per direction ----------
 *
 * The efficiency cards average the last BATT_EFF_SAMPLES readings the battery
 * itself produced, not the 10-minute history rows and not the 20-second
 * buckets of the trend window above. The Pi forwards the same battery reading
 * in several messages, so a reading is identified by battTs (when the BATTERY
 * was read) and taken once.
 *
 * Charging and discharging keep separate lists, so each card still has its
 * last ten when the pack has switched direction. Idle readings (either power
 * inside the deadband) are not kept: their ratio is noise. Neither are
 * readings with an efficiency over 100 % (discharge with AC above DC, charge
 * with DC above AC): the two powers were not read at the same instant.
 *
 * RAM only, like the trend window: losing it costs ten readings.
 */
const BATT_EFF_RAM_FILE = INSTANT_RAM_DIR . '/batt_eff.json';
const BATT_EFF_SAMPLES = 10;
const BATT_EFF_IDLE_W = 15.0;

/** Store one battery reading. $dc is battPower (house sign, > 0 charging). */
function batt_eff_push(int $battTs, float $dc, float $ac): bool
{
    if (!instant_ram_ready()) {
        return false;
    }
    if (abs($dc) <= BATT_EFF_IDLE_W || abs($ac) <= BATT_EFF_IDLE_W) {
        return false;
    }
    if ($dc < 0 ? abs($ac) > abs($dc) : abs($dc) > abs($ac)) {
        return false;   // efficiency over 100 % (discharge AC / DC, charge DC / AC)
    }
    $fh = @fopen(BATT_EFF_RAM_FILE, 'c+');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return false;
    }
    $raw = stream_get_contents($fh);
    $data = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    $chg = is_array($data['chg'] ?? null) ? $data['chg'] : [];
    $dis = is_array($data['dis'] ?? null) ? $data['dis'] : [];

    // Already have this reading (same battTs in either list): nothing to do.
    $seen = false;
    foreach ([$chg, $dis] as $list) {
        $lastRow = $list === [] ? null : $list[count($list) - 1];
        if (is_array($lastRow) && (int) ($lastRow['t'] ?? 0) === $battTs) {
            $seen = true;
        }
    }
    $ok = true;
    if (!$seen) {
        $row = ['t' => $battTs, 'dc' => abs($dc), 'ac' => abs($ac)];
        if ($dc > 0) {
            $chg[] = $row;
            $chg = array_slice($chg, -BATT_EFF_SAMPLES);
        } else {
            $dis[] = $row;
            $dis = array_slice($dis, -BATT_EFF_SAMPLES);
        }
        // A new reading also updates its per-class table (see
        // batt_curve_update below). Here, inside the !$seen branch, so a
        // reading repeated by the Pi is counted once there too.
        batt_curves_add($dc, $ac);
        $json = json_encode(['chg' => $chg, 'dis' => $dis], JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json);
            fflush($fh);
        }
        $ok = ($json !== false);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/** ['chg' => [...], 'dis' => [...]], each oldest first; empty lists when unknown. */
function batt_eff_samples(): array
{
    $empty = ['chg' => [], 'dis' => []];
    if (!is_readable(BATT_EFF_RAM_FILE)) {
        return $empty;
    }
    $fh = @fopen(BATT_EFF_RAM_FILE, 'r');
    if ($fh === false) {
        return $empty;
    }
    if (!flock($fh, LOCK_SH)) {
        fclose($fh);
        return $empty;
    }
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = ($raw === false || $raw === '') ? null : json_decode($raw, true);
    if (!is_array($data)) {
        return $empty;
    }
    return [
        'chg' => is_array($data['chg'] ?? null) ? $data['chg'] : [],
        'dis' => is_array($data['dis'] ?? null) ? $data['dis'] : [],
    ];
}

/* ---------- Per power class tables: efficiency ----------
 *
 * Two tables of power classes, one JSON file each in tmpfs:
 *
 *   eff_dis   discharge efficiency AC / DC, class by AC delivered,
 *             18 classes of 50 W, 0..900 W
 *   eff_chg   charge efficiency DC / AC,    class by AC drawn from the house,
 *             20 classes of 100 W, 0..2000 W
 *
 * Only the current value of each class is kept: a new reading replaces it
 * with (stored + latest) / 2, field by field, so the latest reading weighs
 * half and older ones fade. An empty class takes the first reading as it is.
 * A reading at or above the table's max in the class power is not kept.
 * A stored table with a different class count is dropped and starts again.
 *
 * RAM only, in the same tmpfs as the rest: a reboot starts the tables again.
 */
const BATT_CURVES = [
    'eff_dis' => ['file' => INSTANT_RAM_DIR . '/batt_eff_curve.json', 'step' => 50, 'max' => 900],
    'eff_chg' => ['file' => INSTANT_RAM_DIR . '/batt_eff_curve_chg.json', 'step' => 100, 'max' => 2000],
];

/** Route one reading ($dc house-signed, > 0 charging) to its table. */
function batt_curves_add(float $dc, float $ac): void
{
    $dc0 = abs($dc);
    $ac0 = abs($ac);
    if ($dc0 <= 0 || $ac0 <= 0) {
        return;
    }
    if ($dc > 0) {
        batt_curve_update('eff_chg', $ac0, ['eff' => $dc0 / $ac0 * 100]);
    } else {
        batt_curve_update('eff_dis', $ac0, ['eff' => $ac0 / $dc0 * 100]);
    }
}

/** One stored class as an array of fields. The first discharge efficiency
 * table stored bare numbers; read them as ['eff' => n]. */
function batt_curve_bin($v): ?array
{
    if (is_numeric($v)) {
        return ['eff' => (float) $v];
    }
    return is_array($v) ? $v : null;
}

/** Fold one reading into the class of $classW in table $name. */
function batt_curve_update(string $name, float $classW, array $vals): bool
{
    $cfg = BATT_CURVES[$name] ?? null;
    if ($cfg === null || $classW <= 0 || $classW >= $cfg['max'] || !instant_ram_ready()) {
        return false;
    }
    $file = $cfg['file'];
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return false;
    }
    $raw = stream_get_contents($fh);
    $data = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
    $n = intdiv($cfg['max'], $cfg['step']);
    $bins = (is_array($data) && is_array($data['bins'] ?? null) && count($data['bins']) === $n)
        ? array_map('batt_curve_bin', $data['bins']) : array_fill(0, $n, null);

    $i = (int) floor($classW / $cfg['step']);
    $old = $bins[$i];
    $new = [];
    foreach ($vals as $k => $v) {
        $new[$k] = round(is_array($old) && is_numeric($old[$k] ?? null)
            ? ((float) $old[$k] + $v) / 2 : $v, 2);
    }
    $bins[$i] = $new;

    $json = json_encode(['t' => time(), 'bins' => $bins]);
    if ($json !== false) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json);
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $json !== false;
}

/** The classes of table $name (null where empty), lowest first. */
function batt_curve(string $name): array
{
    $cfg = BATT_CURVES[$name] ?? null;
    if ($cfg === null) {
        return [];
    }
    $n = intdiv($cfg['max'], $cfg['step']);
    $empty = array_fill(0, $n, null);
    $file = $cfg['file'];
    if (!is_readable($file)) {
        return $empty;
    }
    $fh = @fopen($file, 'r');
    if ($fh === false) {
        return $empty;
    }
    if (!flock($fh, LOCK_SH)) {
        fclose($fh);
        return $empty;
    }
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = ($raw === false || $raw === '') ? null : json_decode($raw, true);
    return (is_array($data) && is_array($data['bins'] ?? null) && count($data['bins']) === $n)
        ? array_map('batt_curve_bin', $data['bins']) : $empty;
}
