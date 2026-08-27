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
