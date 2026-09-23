<?php
declare(strict_types=1);

/**
 * RAM-backed snapshot of the "instant" dashboard payload.
 *
 * carica_dati.php (the Raspberry hits it every ~60 s) builds the payload once
 * and drops it here; index.php and live.php then read it straight out of RAM
 * instead of re-running the whole set of MySQL queries on every page load and
 * on every poll.
 *
 * /dev/shm is a tmpfs: it is wiped on reboot and may not exist at all on some
 * hosts, so every write is mirrored to a disk copy next to the PHP files and
 * restored from there when the RAM copy is missing. Same disk-backup scheme
 * get_setpoint.php already uses for the thermostat state.
 */

const INSTANT_RAM_DIR = '/dev/shm/thermo_data';
const INSTANT_RAM_FILE = INSTANT_RAM_DIR . '/instant.json';
const INSTANT_DISK_FILE = __DIR__ . '/instant.json';

/** Pressure/forecast inputs live here and change independently of the Pi. */
const INSTANT_STATE_FILE = INSTANT_RAM_DIR . '/state.json';
const INSTANT_PRES_HISTORY_FILE = INSTANT_RAM_DIR . '/pres_history.csv';

/**
 * Rebuild anyway once the snapshot is older than this (seconds). The Pi uploads
 * every ~60 s, so in normal operation this never fires; it is what keeps the
 * page alive (and time-dependent values like the trend ETA moving) when the
 * uploads stop.
 */
const INSTANT_MAX_AGE = 90;

/** How often the disk mirror of the snapshot is refreshed (seconds). */
const INSTANT_DISK_MIRROR_INTERVAL = 60;

/** Ensure the tmpfs directory exists; false when /dev/shm is unusable. */
function instant_ram_ready(): bool
{
    if (is_dir(INSTANT_RAM_DIR)) {
        return true;
    }
    return @mkdir(INSTANT_RAM_DIR, 0775, true) && is_dir(INSTANT_RAM_DIR);
}

/** Write $json to $path via a temp file + rename, so readers never see a half file. */
function instant_write_atomic(string $path, string $json): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return false;
    }
    $tmp = $dir . '/.' . basename($path) . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0664);
    return true;
}

/** Decode a snapshot file, or null when missing / unreadable / corrupt. */
function instant_decode(string $path): ?array
{
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Read the snapshot: RAM first, disk backup otherwise (and repopulate RAM, so
 * the first request after a reboot pays the copy and the rest hit tmpfs).
 */
function instant_read(): ?array
{
    $ram = instant_ram_ready() ? instant_decode(INSTANT_RAM_FILE) : null;
    if ($ram !== null) {
        return $ram;
    }
    $disk = instant_decode(INSTANT_DISK_FILE);
    if ($disk !== null && instant_ram_ready()) {
        instant_write_atomic(INSTANT_RAM_FILE, (string) json_encode($disk));
    }
    return $disk;
}

/**
 * Store a payload, stamping it with `built_at` and a revision that only ever
 * grows. Clients poll on `rev`, so it must change on every single write.
 */
function instant_write(array $payload): array
{
    $prev = instant_read();
    $payload['rev'] = ((int) ($prev['rev'] ?? 0)) + 1;
    $payload['built_at'] = time();

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return $payload;
    }
    $inRam = instant_ram_ready() && instant_write_atomic(INSTANT_RAM_FILE, $json);

    // Disk mirror: survives the tmpfs being wiped on reboot. It only has to be
    // roughly current for that one purpose, and the MQTT feed rewrites this
    // several times a minute, so it runs on a slower clock than the RAM copy.
    clearstatcache(true, INSTANT_DISK_FILE);
    $mirrorAge = is_readable(INSTANT_DISK_FILE)
        ? time() - (int) @filemtime(INSTANT_DISK_FILE)
        : PHP_INT_MAX;
    if (!$inRam || $mirrorAge >= INSTANT_DISK_MIRROR_INTERVAL) {
        instant_write_atomic(INSTANT_DISK_FILE, $json);
    }

    return $payload;
}

/** Newest mtime among the inputs the payload is derived from, 0 if none exist. */
function instant_sources_mtime(): int
{
    $m = 0;
    foreach ([INSTANT_STATE_FILE, INSTANT_PRES_HISTORY_FILE] as $f) {
        if (is_readable($f)) {
            $m = max($m, (int) @filemtime($f));
        }
    }
    return $m;
}

/** mtime of the snapshot itself (0 when there is none). */
function instant_mtime(): int
{
    clearstatcache(true, INSTANT_RAM_FILE);
    if (is_readable(INSTANT_RAM_FILE)) {
        return (int) @filemtime(INSTANT_RAM_FILE);
    }
    clearstatcache(true, INSTANT_DISK_FILE);
    return is_readable(INSTANT_DISK_FILE) ? (int) @filemtime(INSTANT_DISK_FILE) : 0;
}

/**
 * A snapshot is usable while it is younger than INSTANT_MAX_AGE and no source
 * file (pressure state / history) has changed since it was built.
 */
function instant_is_fresh(?array $payload): bool
{
    if ($payload === null || !isset($payload['built_at'])) {
        return false;
    }
    $builtAt = (int) $payload['built_at'];
    if (time() - $builtAt > INSTANT_MAX_AGE) {
        return false;
    }
    return instant_sources_mtime() <= $builtAt;
}

/* ---------- BATTERY DISCHARGE RUNTIME ----------
 *
 * How long the pack has actually carried the house, counted in seconds, and
 * how much of that time it carried it ALONE: while the battery discharges the
 * grid or the panels can still be feeding the house, and an hour of full cover
 * is not the same claim as an hour of help.
 *
 *   *_secs = every second the pack was discharging;
 *   *_full = the subset of those with no other source in play.
 *   parziale = secs - full, computed where it is shown.
 *
 * THE "DAY" HERE IS NOT THE CALENDAR DAY. It runs from the morning the pack
 * starts charging to the next morning it does: that is the cycle the numbers
 * describe -- fill in the sun, empty over the evening and the night -- and
 * midnight falls in the middle of it, splitting one night's work in two. So
 * the counter rolls over on a charge START, not at 00:00.
 *
 * Two conditions pick the right transition out of the many charge starts a
 * day has. The charge must begin in the morning window (BATT_CHARGE_HOUR_FROM
 * to BATT_CHARGE_HOUR_TO): a pack that resumes charging at four in the
 * afternoon is finishing today's fill, not opening tomorrow. And it must
 * happen on a LATER calendar day than the one the open cycle started on, so
 * the stop and start of the charge as clouds pass leaves the cycle alone.
 *
 * There is no timeout: with no morning charge at all -- days of rain, or the
 * bridge down -- the cycle simply stays open until one arrives. That is the
 * rule taken literally, and it is the honest reading: those hours of discharge
 * really do belong to the last fill.
 *
 * The cycle in progress lives in tmpfs and is rewritten on every payload build
 * (a few times a minute); only when it closes is it moved to `prev_*`, added
 * to the running total and pushed to disk, so the disk sees one write a day
 * instead of thousands.
 *
 * The price of that is the cycle in progress: a reboot wipes /dev/shm and the
 * hours accumulated since the morning go with it. The previous cycle and the
 * total are on disk and survive.
 */
const BATT_RUNTIME_RAM_FILE = INSTANT_RAM_DIR . '/batt_runtime.json';
const BATT_RUNTIME_DISK_FILE = __DIR__ . '/batt_runtime.json';

/**
 * Longest gap between two samples that still counts as discharge time. The
 * payload is rebuilt every ~60 s; a hole bigger than this means the Pi, the
 * inverter or the whole host was down, and guessing what the battery did in
 * the meantime would inflate the counter.
 */
const BATT_RUNTIME_MAX_GAP = 300;

/**
 * The morning window, as local hours: only a charge starting inside it can
 * open a new day. From 05:00 to 12:00 -- before five it is still night (and a
 * charge there comes off the grid, not the sun), after noon the fill is
 * already under way.
 */
const BATT_CHARGE_HOUR_FROM = 5;
const BATT_CHARGE_HOUR_TO = 12;

/**
 * How far back "ieri" may reach before the card stops calling it that
 * (seconds). Nothing to do with the rollover: it only keeps a cycle from
 * weeks ago being presented as the previous day.
 */
const BATT_PREV_MAX = 345600;   // 4 giorni

/** Read the counter: RAM first, disk (previous cycle + total) as the fallback. */
function batt_runtime_read(): array
{
    $st = (instant_ram_ready() ? instant_decode(BATT_RUNTIME_RAM_FILE) : null)
        ?? instant_decode(BATT_RUNTIME_DISK_FILE)
        ?? [];
    $int = static fn($v): int => max(0, (int) ($v ?? 0));
    return [
        // Calendar date the open cycle started on: only a label, used to say
        // which day the figures belong to.
        'day'         => (string) ($st['day'] ?? ''),
        'cycle_start' => $int($st['cycle_start'] ?? 0),
        'day_secs'    => $int($st['day_secs'] ?? 0),
        // The _full fields arrived after the plain ones: a file written by the
        // previous version has none, and its seconds stay where they are --
        // counted as discharge, attributed to "parziale" for want of a better
        // answer, rather than thrown away.
        'day_full'    => $int($st['day_full'] ?? 0),
        'prev_day'    => (string) ($st['prev_day'] ?? ''),
        'prev_start'  => $int($st['prev_start'] ?? 0),
        'prev_secs'   => $int($st['prev_secs'] ?? 0),
        'prev_full'   => $int($st['prev_full'] ?? 0),
        'total_secs'  => $int($st['total_secs'] ?? 0),
        'total_full'  => $int($st['total_full'] ?? 0),
        'last_ts'     => $int($st['last_ts'] ?? 0),
        // State the battery was in at last_ts: the interval between two samples
        // is credited to the state that held at its start (sample and hold),
        // not to the one that happens to hold now.
        'disch'       => !empty($st['disch']),
        'full'        => !empty($st['full']),
        'charging'    => !empty($st['charging']),
    ];
}

/**
 * Fold the time since the previous call into the counters and store them.
 *
 * $fullCover says whether, at this instant, the battery is covering the house
 * on its own; $charging is what opens the next cycle when it turns true after
 * being false. Returns the updated state: `day_*` is the open cycle, `prev_*`
 * the one before it, `total_*` every cycle already closed.
 */
function batt_runtime_accumulate(
    bool $discharging,
    bool $fullCover,
    bool $charging,
    int $now
): array {
    $st = batt_runtime_read();

    if ($st['cycle_start'] <= 0) {
        // First run, or a file from the version that counted calendar days:
        // the open cycle starts at midnight of the day it was labelled with,
        // which is exactly what those seconds were counted against.
        $midnight = ($st['day'] !== '') ? strtotime($st['day'] . ' 00:00:00') : false;
        $st['cycle_start'] = ($midnight === false) ? $now : (int) $midnight;
    }
    if ($st['day'] === '') {
        $st['day'] = date('Y-m-d', $st['cycle_start']);
    }

    // The new morning: a charge that begins inside the morning window, on a
    // later day than the one the open cycle started on.
    $hour = (int) date('G', $now);
    $chargeStart = ($charging && !$st['charging']);
    $rollover = $chargeStart
        && $hour >= BATT_CHARGE_HOUR_FROM
        && $hour < BATT_CHARGE_HOUR_TO
        && date('Y-m-d', $now) > $st['day'];

    if ($rollover) {
        $st['prev_day'] = $st['day'];
        $st['prev_start'] = $st['cycle_start'];
        $st['prev_secs'] = $st['day_secs'];
        $st['prev_full'] = $st['day_full'];
        $st['total_secs'] += $st['day_secs'];
        $st['total_full'] += $st['day_full'];
        $st['day_secs'] = 0;
        $st['day_full'] = 0;
        $st['cycle_start'] = $now;
        $st['day'] = date('Y-m-d', $now);
    }

    $delta = $now - $st['last_ts'];
    if ($st['last_ts'] > 0 && $delta > 0 && $delta <= BATT_RUNTIME_MAX_GAP && $st['disch']) {
        // A sample straddling the rollover is credited to the cycle that is now
        // open; splitting it would buy a couple of seconds of precision on a
        // counter that already loses whole gaps.
        $st['day_secs'] += $delta;
        if ($st['full']) {
            $st['day_full'] += $delta;
        }
    }
    $st['last_ts'] = $now;
    $st['disch'] = $discharging;
    $st['full'] = $discharging && $fullCover;
    $st['charging'] = $charging;

    $json = json_encode($st, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return $st;
    }
    $inRam = instant_ram_ready() && instant_write_atomic(BATT_RUNTIME_RAM_FILE, $json);
    // Disk gets the file when a cycle closes, when there is no RAM to write to,
    // and once to create it. Nothing else.
    if ($rollover || !$inRam || !is_readable(BATT_RUNTIME_DISK_FILE)) {
        instant_write_atomic(BATT_RUNTIME_DISK_FILE, $json);
    }

    return $st;
}
