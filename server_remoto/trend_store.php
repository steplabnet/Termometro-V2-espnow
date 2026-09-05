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
