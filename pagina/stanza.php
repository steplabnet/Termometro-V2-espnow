<?php
// stanza.php — single-file PHP dashboard for a chronothermostat
// Backend endpoints: load/save schedule + load/save state (mode, manualSetpoint, actualTemp)
// NOW also: load/save presets (OFF/LOW/NORMAL/HIGH...) in presets.json
// Frontend: modern light UI, OFF/ON/AUTO, weekly chrono table, active setpoint highlight,
// polling actual temperature from state.json every 10s.

header('X-Content-Type-Options: nosniff');
$action = $_GET['action'] ?? '';

/** ---------- helpers for state in /dev/shm ---------- */
$RAM_DIR = '/dev/shm';
$STATE_FILENAME = 'state.json';

/** ---------- temperature history (CSV in /dev/shm) ---------- */
$HISTORY_FILENAME = 'temp_history.csv';
$HISTORY_FILE = resolve_state_path($RAM_DIR, $HISTORY_FILENAME);

/** ---------- presets file (in script dir) ---------- */
$PRESETS_FILE = __DIR__ . '/presets.json';

/**
 * Append one row "unix_ts,temperature" if last sample is older than $minDeltaSec.
 * Also prunes rows older than $keepSec.
 */
function history_maybe_append(string $path, float $temp, int $minDeltaSec = 1200, int $keepSec = 172800): void
{
    $now = time();
    @mkdir(dirname($path), 0775, true);

    $lastTs = null;
    if (is_readable($path)) {
        $fh = @fopen($path, 'r');
        if ($fh) {
            fseek($fh, -1, SEEK_END);
            $pos = ftell($fh);
            while ($pos > 0) {
                $c = fgetc($fh);
                if ($c === "\n")
                    break;
                fseek($fh, --$pos, SEEK_SET);
            }
            $lastLine = fgets($fh);
            fclose($fh);
            if ($lastLine) {
                [$tsStr] = array_map('trim', explode(',', $lastLine, 2));
                if (is_numeric($tsStr))
                    $lastTs = (int) $tsStr;
            }
        }
    }

    if ($lastTs === null || ($now - $lastTs) >= $minDeltaSec) {
        @file_put_contents($path, $now . ',' . number_format($temp, 2, '.', '') . "\n", FILE_APPEND);
    }

    if (is_readable($path)) {
        $cutoff = $now - $keepSec;
        $rows = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($rows !== false) {
            $kept = [];
            foreach ($rows as $r) {
                $parts = explode(',', $r, 2);
                if (!count($parts))
                    continue;
                $ts = (int) $parts[0];
                if ($ts >= $cutoff)
                    $kept[] = $r;
            }
            if (!empty($kept)) {
                $tmp = $path . '.tmp';
                if (@file_put_contents($tmp, implode("\n", $kept) . "\n") !== false) {
                    @rename($tmp, $path);
                }
            }
        }
    }
}

/** Return last 24h as array of [ [t_iso, temp], ... ]  */
function history_load_last24(string $path): array
{
    $now = time();
    $cutoff = $now - 86400;
    $out = [];
    if (!is_readable($path))
        return $out;
    $rows = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($rows === false)
        return $out;

    $bucket = 1200;
    $seen = [];
    foreach ($rows as $r) {
        [$tsStr, $tempStr] = array_map('trim', explode(',', $r, 2) + ['', '']);
        if (!is_numeric($tsStr) || !is_numeric($tempStr))
            continue;
        $ts = (int) $tsStr;
        if ($ts < $cutoff)
            continue;
        $b = intdiv($ts, $bucket) * $bucket;
        $seen[$b] = floatval($tempStr);
    }
    ksort($seen);
    foreach ($seen as $ts => $temp) {
        $out[] = [gmdate('c', $ts), $temp];
    }
    return $out;
}


function resolve_state_path(string $ramDir, string $filename): string
{
    if (is_dir($ramDir) && is_writable($ramDir)) {
        return rtrim($ramDir, '/') . '/' . $filename;
    }
    return __DIR__ . '/' . $filename;
}

function write_json_atomic(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir))
        return false;
    $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false)
        return false;

    if (@file_put_contents($tmp, $json, LOCK_EX) === false)
        return false;
    @chmod($tmp, 0664);
    return @rename($tmp, $path);
}

$STATE_FILE = resolve_state_path($RAM_DIR, $STATE_FILENAME);

/** ---------- API: Load 24h history (READ-ONLY) ---------- */
if ($action === 'load_history') {
    header('Content-Type: application/json; charset=utf-8');
    $csv = __DIR__ . '/temp_history.csv';
    if (!is_readable($csv)) {
        $csv = $GLOBALS['HISTORY_FILE'];
    }
    $points = history_load_last24($csv);
    echo json_encode(['ok' => true, 'points' => $points], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** ---------- API: Load schedule ---------- */
if ($action === 'load_schedule') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $file = __DIR__ . '/schedule.json';
    if (is_readable($file)) {
        $raw = file_get_contents($file);
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $out = [
                "ok" => true,
                "version" => isset($j['version']) ? (int) $j['version'] : null,
                "saved_at" => $j['saved_at'] ?? null,
                "schedule" => $j['schedule'] ?? null,
            ];
            echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            echo json_encode(["ok" => true, "version" => null, "saved_at" => null, "schedule" => null]);
        }
    } else {
        echo json_encode(["ok" => true, "version" => null, "saved_at" => null, "schedule" => null]);
    }
    exit;
}

/** ---------- API: Save schedule ---------- */
if ($action === 'save_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $file = __DIR__ . '/schedule.json';
    $body = file_get_contents('php://input');
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['schedule']) || !is_array($decoded['schedule'])) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Invalid payload"]);
        exit;
    }

    $prevVersion = 0;
    if (is_readable($file)) {
        $prev = json_decode(@file_get_contents($file), true);
        if (is_array($prev) && isset($prev['version'])) {
            $prevVersion = (int) $prev['version'];
        }
    }

    $version = $prevVersion + 1;
    $saved_at = gmdate('c');

    $payloadToSave = [
        "version" => $version,
        "saved_at" => $saved_at,
        "schedule" => $decoded['schedule'],
    ];

    $wrote = false;
    if (is_writable(dirname($file))) {
        $wrote = write_json_atomic($file, $payloadToSave);
    }
    if (!$wrote) {
        $ok = @file_put_contents($file, json_encode($payloadToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $wrote = $ok !== false;
    }

    if (!$wrote) {
        echo json_encode(["ok" => false, "error" => "Could not write schedule.json. Check file permissions."]);
    } else {
        echo json_encode(["ok" => true, "saved_to" => basename($file), "version" => $version, "saved_at" => $saved_at]);
    }
    exit;
}

/** ---------- API: Save state ---------- */
if ($action === 'save_state' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $body = file_get_contents('php://input');
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['mode']) || !isset($decoded['manualSetpoint'])) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Invalid payload"]);
        exit;
    }
    $mode = in_array($decoded['mode'], ['OFF', 'ON', 'AUTO'], true) ? $decoded['mode'] : 'AUTO';
    $manual = floatval($decoded['manualSetpoint']);
    $state = ['mode' => $mode, 'manualSetpoint' => $manual];

    if (isset($decoded['actualTemp'])) {
        $state['actualTemp'] = floatval($decoded['actualTemp']);
    }
    if (isset($decoded['cald'])) {
        $state['cald'] = (int) $decoded['cald'];
    }

    if (!write_json_atomic($GLOBALS['STATE_FILE'], $state)) {
        echo json_encode([
            "ok" => false,
            "error" => "Could not write " . basename($GLOBALS['STATE_FILE']) . ". Check permissions or /dev/shm availability."
        ]);
    } else {
        echo json_encode(["ok" => true, "saved_to" => $GLOBALS['STATE_FILE']]);
    }
    exit;
}

/** ---------- API: Load state ---------- */
if ($action === 'load_state') {
    header('Content-Type: application/json; charset=utf-8');
    $file = $GLOBALS['STATE_FILE'];
    if (is_readable($file)) {
        $raw = file_get_contents($file);
        $j = json_decode($raw, true);
        if (!is_array($j))
            $j = [];
        echo json_encode([
            'ok' => true,
            'mode' => $j['mode'] ?? null,
            'manualSetpoint' => isset($j['manualSetpoint']) ? (float) $j['manualSetpoint'] : null,
            'actualTemp' => isset($j['actualTemp']) ? (float) $j['actualTemp'] : null,
            'cald' => isset($j['cald']) ? (int) $j['cald'] : 0
        ]);
    } else {
        echo json_encode(['ok' => true, 'mode' => null, 'manualSetpoint' => null, 'actualTemp' => null, 'cald' => 0]);
    }
    exit;
}

/** ---------- API: Load presets (new) ---------- */
if ($action === 'load_presets') {
    header('Content-Type: application/json; charset=utf-8');

    // default presets if file missing/corrupt
    $default = [
        "ok" => true,
        "presets" => [
            "order" => ["OFF", "LOW", "NORMAL", "HIGH"],
            "map" => ["OFF" => 10, "LOW" => 15, "NORMAL" => 19, "HIGH" => 20]
        ]
    ];

    if (!is_readable($PRESETS_FILE)) {
        echo json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $raw = file_get_contents($PRESETS_FILE);
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['order']) || !isset($j['map'])) {
        echo json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(["ok" => true, "presets" => $j], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/** ---------- API: Save presets (new) ---------- */
if ($action === 'save_presets' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $body = file_get_contents('php://input');
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['order']) || !isset($decoded['map'])) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Invalid presets payload"]);
        exit;
    }

    // sanitize a bit
    $order = array_values(array_filter($decoded['order'], 'is_string'));
    $map = [];
    foreach ($decoded['map'] as $k => $v) {
        $map[$k] = (float) $v;
    }
    $payload = ["order" => $order, "map" => $map];

    $wrote = false;
    if (is_writable(dirname($PRESETS_FILE))) {
        $wrote = write_json_atomic($PRESETS_FILE, $payload);
    }
    if (!$wrote) {
        $ok = @file_put_contents($PRESETS_FILE, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $wrote = $ok !== false;
    }

    if (!$wrote) {
        echo json_encode(["ok" => false, "error" => "Could not write presets.json. Check file permissions."]);
    } else {
        echo json_encode(["ok" => true]);
    }
    exit;
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stanza · Chronothermostat</title>
    <style>
        :root {
            --bg: #f7f7fb;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --brand: #2563eb;
            --brand-weak: #dbeafe;
            --ring: #93c5fd;
            --danger: #ef4444;
            --ok: #16a34a;
            --border: #e5e7eb;
            --chip: #eef2ff;
            --active-chip: #2563eb;
            --active-text: #ffffff;
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Inter, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text)
        }

        .container {
            max-width: 1100px;
            margin: 24px auto;
            padding: 0 16px
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px
        }

        .title {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: .2px
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 16px
        }

        .card {
            grid-column: span 12;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 4px 14px rgba(31, 41, 55, .06)
        }

        .card.pad {
            padding: 16px
        }

        @media(min-width:820px) {
            .span4 {
                grid-column: span 4
            }

            .span8 {
                grid-column: span 8
            }

            .span6 {
                grid-column: span 6
            }
        }

        .row {
            display: flex;
            gap: 16px;
            align-items: center
        }

        .temp {
            font-size: 48px;
            font-weight: 700
        }

        .unit {
            font-size: 18px;
            color: var(--muted)
        }

        .subtitle {
            color: var(--muted);
            font-size: 13px
        }

        .btns {
            display: flex;
            gap: 8px;
            flex-wrap: wrap
        }

        .btn {
            appearance: none;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            padding: 10px 14px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: .18s ease
        }

        .btn:hover {
            transform: translateY(-1px)
        }

        .btn.active {
            border-color: var(--brand);
            background: var(--brand-weak);
            color: #0b3ea6;
            box-shadow: 0 0 0 4px var(--ring)
        }

        .btn.danger {
            border-color: #fecaca;
            background: #fef2f2;
            color: #991b1b
        }

        input[type="number"],
        input[type="time"],
        input[type="text"] {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #fff;
            font: inherit
        }

        input[disabled] {
            background: #f3f4f6;
            color: #9ca3af
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px
        }

        .day {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px;
            background: #fff
        }

        .day h3 {
            margin: 0 0 8px 0;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: #374151
        }

        .slots {
            display: flex;
            flex-wrap: wrap;
            gap: 8px
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            background: var(--chip);
            border-radius: 999px
        }

        .chip .x {
            border: none;
            background: transparent;
            cursor: pointer;
            padding: 0 4px;
            font-weight: 700;
            color: #6b7280
        }

        .chip.active {
            background: var(--active-chip);
            color: var(--active-text);
            border-color: var(--active-chip)
        }

        .add-row {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap
        }

        .toolbar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center
        }

        .save-note {
            font-size: 12px;
            color: var(--muted)
        }

        .footer {
            margin-top: 16px;
            color: var(--muted);
            font-size: 12px
        }

        .divider {
            height: 1px;
            background: var(--border);
            margin: 12px 0
        }

        .pill {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
            gap: 8px;
            background: #fff
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: var(--ok)
        }

        /* --- Presets modal --- */
        .modalOverlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .18);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 50;
        }

        .modal {
            width: 560px;
            max-width: 92vw;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .12);
            padding: 16px;
        }

        .modal h3 {
            margin: 0 0 12px 0;
            font-size: 16px
        }

        .presetRow {
            display: grid;
            grid-template-columns: 1fr 120px 40px;
            gap: 8px;
            margin-bottom: 8px
        }

        .presetRow input[type="text"] {
            text-transform: uppercase
        }

        .dragHint {
            font-size: 12px;
            color: var(--muted);
            margin-top: 6px
        }

        .modal .rowEnd {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 10px
        }

        #tempChart {
            height: 100px !important;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>


</head>

<body>
    <div class="container">
        <div class="header">
            <div class="title">Studio · Chronothermostat</div>
            <div class="pill"><span class="status-dot" id="statusDot"></span><span id="statusText">Running</span></div>
        </div>

        <div class="cards">
            <div class="card pad span4">
                <div class="subtitle">Actual temperature</div>
                <div class="row">
                    <div class="temp" id="actualTemp">--.-</div>
                    <div class="unit">°C</div>
                </div>
            </div>
            <div class="card pad span4">
                <div class="subtitle">Heater status</div>
                <div class="row">
                    <div id="heaterStatus" style="font-size:20px;font-weight:600;color:#6b7280">--</div>
                </div>
            </div>


            <div class="card pad span4">
                <div class="subtitle">Mode</div>
                <div class="btns">
                    <button class="btn" id="btnOff">OFF</button>
                    <button class="btn" id="btnOn">ON</button>
                    <button class="btn" id="btnAuto">AUTO</button>
                </div>
            </div>

            <div class="card pad span4">
                <div class="subtitle">Setpoint</div>
                <div class="row">
                    <input id="setpointInput" type="number" step="0.5" min="5" max="35" value="20" />
                    <div class="unit">°C</div>
                </div>
                <div class="subtitle" id="setpointHint">Manual setpoint</div>
            </div>
            <div class="card pad span12">
                <div class="subtitle">Temperature (last 24h)</div>
                <canvas id="tempChart" style="height:100px"></canvas>
            </div>



            <div class="card pad span12">
                <div class="toolbar">
                    <strong>Weekly chrono table</strong>
                    <span class="save-note">AUTO mode uses these time points. Each day can have multiple time→setpoint
                        entries.</span>
                    <span style="flex:1"></span>
                    <span id="serverVersion" class="save-note"></span>
                    <button class="btn" id="btnSaveServer" title="Save on server (schedule.json)">Save to
                        server</button>
                    <button class="btn" id="btnLoadServer" title="Load from server (schedule.json)">Load from
                        server</button>
                    <button class="btn" id="btnExport">Export JSON</button>
                    <label class="btn" for="importFile">Import JSON</label>
                    <input id="importFile" type="file" accept="application/json" style="display:none">
                    <button class="btn" id="btnPresets" title="Edit preset names & temperatures">Presets</button>
                </div>
                <div class="divider"></div>
                <div class="grid" id="daysGrid"></div>
                <div class="footer">Tip: In AUTO, the active setpoint is the last time point ≤ current time for that
                    day. If none exists, the first point of the day is used.</div>
            </div>
        </div>
    </div>

    <!-- Presets modal -->
    <div id="presetsModal" class="modalOverlay">
        <div class="modal">
            <h3>Edit presets</h3>
            <div id="presetRows"></div>
            <div class="row">
                <button class="btn" id="btnAddPreset">+ Add preset</button>
                <span class="dragHint">Tip: Use ↑/↓ to reorder. Names must be unique. Values in °C.</span>
            </div>
            <div class="rowEnd">
                <button class="btn" id="btnClosePresets">Cancel</button>
                <button class="btn" id="btnSavePresets">Save</button>
            </div>
        </div>
    </div>

    <script>
        let isEditing = false;

        // -------- presets now come from server, not localStorage --------
        const PRESETS_URL_LOAD = '?action=load_presets';
        const PRESETS_URL_SAVE = '?action=save_presets';

        function defaultPresets() {
            return { order: ["OFF", "LOW", "NORMAL", "HIGH"], map: { OFF: 10, LOW: 15, NORMAL: 19, HIGH: 20 } };
        }

        let PRESETS_OBJ = defaultPresets();

        function presetNameForValue(v) {
            for (const k of PRESETS_OBJ.order) {
                if (Math.abs(PRESETS_OBJ.map[k] - v) < 0.01) return k;
            }
            return null;
        }

        function makePresetSelect(currentValue, onChange) {
            const sel = document.createElement('select');
            sel.style.padding = '6px 10px';
            sel.style.borderRadius = '999px';
            sel.style.border = '1px solid var(--border)';
            sel.style.background = '#fff';
            sel.style.font = 'inherit';

            PRESETS_OBJ.order.forEach(k => {
                const opt = document.createElement('option');
                opt.value = k;
                opt.textContent = k;
                sel.appendChild(opt);
            });

            const current = presetNameForValue(currentValue) || (PRESETS_OBJ.order[0] || "NORMAL");
            sel.value = current;

            sel.addEventListener('change', () => onChange(sel.value));
            return sel;
        }

        (async function () {
            const days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
            const byId = id => document.getElementById(id);
            const actualTempEl = byId('actualTemp');
            const setpointInput = byId('setpointInput');
            const setpointHint = byId('setpointHint');
            const statusDot = byId('statusDot');
            const statusText = byId('statusText');
            const btnOff = byId('btnOff');
            const btnOn = byId('btnOn');
            const btnAuto = byId('btnAuto');
            const daysGrid = byId('daysGrid');

            // Presets modal elements
            const presetsModal = byId('presetsModal');
            const presetRows = byId('presetRows');
            const btnAddPreset = byId('btnAddPreset');
            const btnSavePresets = byId('btnSavePresets');
            const btnClosePresets = byId('btnClosePresets');
            const btnPresets = byId('btnPresets');

            const serverVersionEl = byId('serverVersion');

            const STORAGE_KEY = 'chrono.schedule.v1';
            const MODE_KEY = 'chrono.mode.v1';
            const MANUAL_SP_KEY = 'chrono.manual_sp.v1';
            const SERVER_STATE_URL_SAVE = '?action=save_state';
            const SERVER_STATE_URL_LOAD = '?action=load_state';
            const tempChartCanvas = document.getElementById('tempChart');
            let tempChart;

            // ---- load presets from server BEFORE rendering UI ----
            try {
                const res = await fetch(PRESETS_URL_LOAD + '&_=' + Date.now());
                if (res.ok) {
                    const j = await res.json();
                    if (j && j.ok && j.presets && Array.isArray(j.presets.order) && typeof j.presets.map === 'object') {
                        PRESETS_OBJ = j.presets;
                    }
                }
            } catch (e) {
                // stick to defaultPresets()
            }

            function buildTempChart(labels, data) {
                if (tempChart) {
                    tempChart.data.labels = labels;
                    tempChart.data.datasets[0].data = data;
                    tempChart.update();
                    return;
                }
                tempChart = new Chart(tempChartCanvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [{
                            label: '°C',
                            data,
                            tension: 0.25,
                            pointRadius: 0,
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => `${ctx.parsed.y.toFixed(1)} °C`
                                }
                            }
                        },
                        scales: {
                            x: {
                                ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 },
                                grid: { display: false }
                            },
                            y: {
                                beginAtZero: false,
                                ticks: { callback: v => v.toFixed ? v.toFixed(0) + '°' : v + '°' }
                            }
                        }
                    }
                });
            }

            async function fetchHistoryAndRender() {
                try {
                    const res = await fetch('?action=load_history&_=' + Date.now());
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const j = await res.json();
                    if (!j.ok || !Array.isArray(j.points)) return;

                    const labels = [];
                    const values = [];
                    for (const [iso, t] of j.points) {
                        const dt = new Date(iso);
                        const hh = String(dt.getHours()).padStart(2, '0');
                        const mm = String(dt.getMinutes()).padStart(2, '0');
                        labels.push(`${hh}:${mm}`);
                        values.push(Number(t));
                    }
                    buildTempChart(labels, values);
                } catch (e) {
                }
            }

            let state = {
                mode: localStorage.getItem(MODE_KEY) || 'AUTO',
                manualSetpoint: parseFloat(localStorage.getItem(MANUAL_SP_KEY) || '20'),
                schedule: loadLocalSchedule() || defaultSchedule(),
            };

            function defaultSchedule() {
                return days.map((d, i) => ({
                    day: d, slots: [
                        { time: '06:30', setpoint: 20 },
                        { time: '08:00', setpoint: 18.5 },
                        { time: '18:00', setpoint: 21 },
                        { time: '22:30', setpoint: 17.5 },
                    ]
                }));
            }
            function loadLocalSchedule() {
                try { const raw = localStorage.getItem(STORAGE_KEY); return raw ? JSON.parse(raw) : null; } catch (e) { return null; }
            }
            function saveLocalSchedule() { localStorage.setItem(STORAGE_KEY, JSON.stringify(state.schedule)); }
            function saveMode() { localStorage.setItem(MODE_KEY, state.mode); }
            function saveManual() { localStorage.setItem(MANUAL_SP_KEY, String(state.manualSetpoint)); }

            // UI build for days (rebuilt after presets change)
            function renderDays() {
                daysGrid.innerHTML = '';

                const toMin = (hm) => {
                    const [h, m] = hm.split(':').map(n => parseInt(n, 10));
                    return h * 60 + m;
                };

                function createAddChip(dayObj) {
                    const chip = document.createElement('div');
                    chip.className = 'chip';
                    chip.style.cursor = 'pointer';

                    const plus = document.createElement('strong');
                    plus.textContent = '+ Add';
                    chip.appendChild(plus);

                    function openEditor(ev) {
                        ev && ev.stopPropagation();
                        isEditing = true;
                        chip.innerHTML = '';

                        const time = document.createElement('input');
                        time.type = 'time';
                        time.value = '06:00';
                        time.style.padding = '6px 10px';
                        time.style.borderRadius = '999px';
                        time.style.border = '1px solid var(--border)';
                        time.style.background = '#fff';
                        time.style.font = 'inherit';

                        const presetSel = makePresetSelect(19, () => { });

                        const addBtn = document.createElement('button');
                        addBtn.className = 'btn';
                        addBtn.textContent = 'Add';
                        addBtn.style.padding = '6px 10px';

                        const cancelBtn = document.createElement('button');
                        cancelBtn.className = 'x';
                        cancelBtn.title = 'Cancel';
                        cancelBtn.textContent = '×';

                        [time, presetSel, addBtn, cancelBtn].forEach(el => {
                            el.addEventListener('click', e => e.stopPropagation());
                        });

                        addBtn.addEventListener('click', () => {
                            const t = time.value || '00:00';
                            const chosen = presetSel.value || (PRESETS_OBJ.order[0] || 'NORMAL');
                            dayObj.slots.push({ time: t, setpoint: PRESETS_OBJ.map[chosen] });
                            saveLocalSchedule();
                            isEditing = false;
                            renderDays();
                            updateSetpointFromMode();
                        });

                        cancelBtn.addEventListener('click', (ev) => {
                            ev.stopPropagation();
                            isEditing = false;
                            renderDays();
                        });

                        chip.appendChild(time);
                        chip.appendChild(presetSel);
                        chip.appendChild(addBtn);
                        chip.appendChild(cancelBtn);

                        setTimeout(() => {
                            try { time.focus(); time.showPicker && time.showPicker(); } catch { }
                        }, 0);
                    }

                    chip.addEventListener('click', openEditor);
                    return chip;
                }

                state.schedule.forEach((dayObj, idx) => {
                    const wrap = document.createElement('div');
                    wrap.className = 'day';

                    const headerRow = document.createElement('div');
                    headerRow.className = 'row';
                    headerRow.style.justifyContent = 'space-between';
                    headerRow.style.alignItems = 'center';

                    const h = document.createElement('h3');
                    h.textContent = dayObj.day;
                    h.style.marginBottom = '0';

                    const dupBtn = document.createElement('button');
                    dupBtn.className = 'btn';
                    dupBtn.textContent = 'Duplicate';
                    dupBtn.title = 'Duplicate this day into another day';
                    dupBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const targetName = prompt("Duplicate into which day? (Monday, Tuesday, ... Sunday)", "Monday");
                        if (!targetName) return;
                        const target = state.schedule.find(d => d.day.toLowerCase() === targetName.trim().toLowerCase());
                        if (!target) {
                            alert("No such day: " + targetName);
                            return;
                        }
                        target.slots = JSON.parse(JSON.stringify(dayObj.slots));
                        saveLocalSchedule();
                        renderDays();
                        updateSetpointFromMode();
                    });

                    headerRow.appendChild(h);
                    headerRow.appendChild(dupBtn);
                    wrap.appendChild(headerRow);

                    const slots = document.createElement('div');
                    slots.className = 'slots';

                    dayObj.slots.sort((a, b) => a.time.localeCompare(b.time));

                    const now = new Date();
                    const todayIndex = (now.getDay() + 6) % 7;
                    const nowMinutes = now.getHours() * 60 + now.getMinutes();
                    let activeIdx = -1;
                    if (state.mode === 'AUTO' && idx === todayIndex && dayObj.slots.length) {
                        let chosen = -1;
                        dayObj.slots.forEach((s, i) => { if (toMin(s.time) <= nowMinutes) chosen = i; });
                        activeIdx = chosen >= 0 ? chosen : 0;
                    }

                    dayObj.slots.forEach((s, sidx) => {
                        const chip = document.createElement('div');
                        chip.className = 'chip';
                        if (sidx === activeIdx) chip.classList.add('active');

                        const timeEl = document.createElement('strong');
                        timeEl.textContent = s.time;
                        chip.appendChild(timeEl);

                        const arrowEl = document.createElement('span');
                        arrowEl.textContent = ' → ';
                        chip.appendChild(arrowEl);

                        const presetSel = makePresetSelect(s.setpoint, (newPreset) => {
                            s.setpoint = PRESETS_OBJ.map[newPreset];
                            saveLocalSchedule();
                            updateSetpointFromMode();
                        });
                        presetSel.addEventListener('click', e => e.stopPropagation());
                        chip.appendChild(presetSel);

                        const del = document.createElement('button');
                        del.className = 'x';
                        del.title = 'Remove';
                        del.textContent = '×';
                        del.addEventListener('click', (e) => {
                            e.stopPropagation();
                            dayObj.slots.splice(sidx, 1);
                            saveLocalSchedule();
                            renderDays();
                            updateSetpointFromMode();
                        });
                        chip.appendChild(del);

                        slots.appendChild(chip);
                    });

                    slots.appendChild(createAddChip(dayObj));

                    wrap.appendChild(slots);
                    daysGrid.appendChild(wrap);
                });
            }

            function setMode(mode) {
                state.mode = mode; saveMode();
                [btnOff, btnOn, btnAuto].forEach(b => b.classList.remove('active'));
                if (mode === 'OFF') btnOff.classList.add('active');
                if (mode === 'ON') btnOn.classList.add('active');
                if (mode === 'AUTO') btnAuto.classList.add('active');
                setpointInput.disabled = (mode !== 'ON');
                setpointHint.textContent = mode === 'ON' ? 'Manual setpoint' : (mode === 'AUTO' ? 'AUTO from chrono table' : 'System is OFF');
                renderDays();
                updateSetpointFromMode();
                saveStateServer().catch(() => { });
            }

            function parseHM(hm) { const [h, m] = hm.split(':').map(n => parseInt(n, 10)); return h * 60 + m; }

            function computeAutoSetpoint(now = new Date()) {
                const dayIndex = (now.getDay() + 6) % 7;
                const day = state.schedule[dayIndex];
                if (!day || !day.slots.length) return state.manualSetpoint;
                const minutes = now.getHours() * 60 + now.getMinutes();
                const sorted = [...day.slots].sort((a, b) => a.time.localeCompare(b.time));
                let chosen = sorted[0];
                for (const s of sorted) { if (parseHM(s.time) <= minutes) chosen = s; }
                return chosen.setpoint;
            }

            function updateSetpointFromMode() {
                if (state.mode === 'OFF') {
                    setpointInput.value = '';
                    setpointInput.placeholder = '—';
                } else if (state.mode === 'ON') {
                    setpointInput.value = state.manualSetpoint.toFixed(1);
                    setpointInput.placeholder = '';
                } else {
                    const sp = computeAutoSetpoint();
                    setpointInput.value = sp.toFixed(1);
                    setpointInput.placeholder = '';
                }
            }

            btnOff.addEventListener('click', () => setMode('OFF'));
            btnOn.addEventListener('click', () => setMode('ON'));
            btnAuto.addEventListener('click', () => setMode('AUTO'));
            setpointInput.addEventListener('change', () => {
                if (state.mode === 'ON') {
                    const v = parseFloat(setpointInput.value);
                    if (!isNaN(v)) { state.manualSetpoint = v; saveManual(); saveStateServer().catch(() => { }); }
                }
            });

            const heaterStatusEl = document.getElementById('heaterStatus');

            async function fetchActualTemp() {
                try {
                    const res = await fetch(SERVER_STATE_URL_LOAD + '&_=' + Date.now());
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const j = await res.json();

                    if (j && typeof j.actualTemp === 'number') {
                        actualTempEl.textContent = j.actualTemp.toFixed(1);
                    } else {
                        actualTempEl.textContent = '--.-';
                    }

                    if (j && typeof j.cald === 'number') {
                        if (j.cald === 1) {
                            heaterStatusEl.textContent = 'Heater active';
                            heaterStatusEl.style.color = '#16a34a';
                        } else {
                            heaterStatusEl.textContent = 'Heater inactive';
                            heaterStatusEl.style.color = '#ef4444';
                        }
                    } else {
                        heaterStatusEl.textContent = '--';
                        heaterStatusEl.style.color = '#6b7280';
                    }
                } catch (e) {
                    actualTempEl.textContent = '--.-';
                    heaterStatusEl.textContent = '--';
                    heaterStatusEl.style.color = '#6b7280';
                }
            }

            setInterval(fetchActualTemp, 10000);
            fetchActualTemp();
            fetchHistoryAndRender();
            setInterval(fetchHistoryAndRender, 60000);

            setInterval(() => {
                if (state.mode === 'AUTO' && !isEditing) {
                    updateSetpointFromMode();
                    renderDays();
                }
            }, 30000);

            async function postJSON(url, data) {
                const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            }

            async function saveStateServer() {
                const payload = { mode: state.mode, manualSetpoint: state.manualSetpoint };
                try {
                    const j = await postJSON(SERVER_STATE_URL_SAVE, payload);
                    if (!j.ok) throw new Error(j.error || 'save_state failed');
                    flashStatus('State synced');
                } catch (e) { flashStatus('State sync failed', true); }
            }

            document.getElementById('btnSaveServer').addEventListener('click', async () => {
                try {
                    const payload = { schedule: state.schedule };
                    const j = await postJSON('?action=save_schedule', payload);
                    if (j.ok) {
                        if (j.version) {
                            serverVersionEl.textContent = `Server schedule v${j.version}` + (j.saved_at ? ` · ${j.saved_at}` : '');
                            flashStatus(`Saved on server (v${j.version})`);
                        } else {
                            serverVersionEl.textContent = '';
                            flashStatus('Saved on server');
                        }
                    } else {
                        flashStatus(j.error || 'Save failed', true);
                    }
                } catch (e) { flashStatus('Save failed', true); }
            });
            document.getElementById('btnLoadServer').addEventListener('click', async () => {
                try {
                    const res = await fetch('?action=load_schedule');
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const j = await res.json();
                    if (j && (j.schedule || j.schedule === null)) {
                        if (j.schedule) {
                            state.schedule = j.schedule;
                            saveLocalSchedule();
                            renderDays();
                            updateSetpointFromMode();
                            if (j.version) {
                                serverVersionEl.textContent = `Server schedule v${j.version}` + (j.saved_at ? ` · ${j.saved_at}` : '');
                                flashStatus(`Loaded from server (v${j.version})`);
                            } else {
                                serverVersionEl.textContent = '';
                                flashStatus('Loaded from server');
                            }
                        } else {
                            serverVersionEl.textContent = '';
                            flashStatus('No server schedule found');
                        }
                    } else { flashStatus('Load failed', true); }
                } catch (e) { flashStatus('Load failed', true); }
            });

            function flashStatus(text, isError = false) {
                statusText.textContent = text;
                statusDot.style.background = isError ? 'var(--danger)' : 'var(--ok)';
                setTimeout(() => { statusText.textContent = 'Running'; statusDot.style.background = 'var(--ok)'; }, 2500);
            }

            document.getElementById('btnExport').addEventListener('click', () => {
                const data = { schedule: state.schedule };
                const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'schedule-export.json';
                a.click();
                URL.revokeObjectURL(a.href);
            });
            document.getElementById('importFile').addEventListener('change', (ev) => {
                const f = ev.target.files[0]; if (!f) return;
                const reader = new FileReader();
                reader.onload = () => {
                    try {
                        const j = JSON.parse(reader.result);
                        if (j && Array.isArray(j.schedule)) {
                            state.schedule = j.schedule; saveLocalSchedule(); renderDays(); updateSetpointFromMode(); flashStatus('Imported JSON');
                        } else { flashStatus('Invalid JSON format', true); }
                    } catch (e) { flashStatus('Invalid JSON', true); }
                };
                reader.readAsText(f);
            });

            // -------- Presets editor modal logic --------
            function openPresetsEditor() {
                presetRows.innerHTML = '';
                PRESETS_OBJ.order.forEach((name, idx) => addPresetRow(name, PRESETS_OBJ.map[name], idx));
                presetsModal.style.display = 'flex';
            }

            function closePresetsEditor() {
                presetsModal.style.display = 'none';
            }

            function addPresetRow(name = '', value = 19, index = null) {
                const row = document.createElement('div');
                row.className = 'presetRow';

                const nameInput = document.createElement('input');
                nameInput.type = 'text';
                nameInput.placeholder = 'NAME';
                nameInput.value = name;

                const valInput = document.createElement('input');
                valInput.type = 'number';
                valInput.step = '0.5';
                valInput.min = '5';
                valInput.max = '35';
                valInput.value = String(value);

                const delBtn = document.createElement('button');
                delBtn.className = 'btn danger';
                delBtn.textContent = '×';

                nameInput.addEventListener('keydown', (e) => {
                    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                        e.preventDefault();
                        const sib = e.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;
                        if (sib) {
                            if (e.key === 'ArrowUp') presetRows.insertBefore(row, sib);
                            else presetRows.insertBefore(sib, row);
                        }
                    }
                });

                delBtn.addEventListener('click', () => row.remove());

                row.appendChild(nameInput);
                row.appendChild(valInput);
                row.appendChild(delBtn);

                if (index === null || index >= presetRows.children.length) presetRows.appendChild(row);
                else presetRows.insertBefore(row, presetRows.children[index]);
            }

            function collectPresetsFromUI() {
                const rows = Array.from(presetRows.querySelectorAll('.presetRow'));
                const order = [];
                const map = {};
                for (const r of rows) {
                    const name = r.querySelector('input[type="text"]').value.trim().toUpperCase();
                    const val = parseFloat(r.querySelector('input[type="number"]').value);
                    if (!name) continue;
                    if (isNaN(val)) continue;
                    if (order.includes(name)) continue;
                    order.push(name);
                    map[name] = Math.max(5, Math.min(35, val));
                }
                return { order, map };
            }

            btnPresets.addEventListener('click', openPresetsEditor);
            btnClosePresets.addEventListener('click', closePresetsEditor);
            presetsModal.addEventListener('click', (e) => { if (e.target === presetsModal) closePresetsEditor(); });
            btnAddPreset.addEventListener('click', () => addPresetRow('', 19));
            btnSavePresets.addEventListener('click', async () => {
                const next = collectPresetsFromUI();
                if (!next.order.length) { flashStatus('Need at least one preset', true); return; }
                // save to server
                try {
                    const resp = await fetch(PRESETS_URL_SAVE, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(next)
                    });
                    const jr = await resp.json();
                    if (!resp.ok || !jr.ok) {
                        flashStatus('Preset save failed', true);
                        return;
                    }
                    PRESETS_OBJ = next;
                    closePresetsEditor();
                    renderDays();
                    updateSetpointFromMode();
                    flashStatus('Presets saved to server');
                } catch (e) {
                    flashStatus('Preset save failed', true);
                }
            });

            // Initial render with server presets
            renderDays();
            setMode(state.mode);
        })();
    </script>
</body>

</html>