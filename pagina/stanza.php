<?php
// stanza.php — Single-file dashboard for ESP32-C3 Thermostat (BME280 Updated)

header('X-Content-Type-Options: nosniff');
$action = $_GET['action'] ?? '';

/** ---------- CONFIGURATION: STORAGE PATHS ---------- */
$DATA_DIR = '/dev/shm/thermo_data';

if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0775, true);
}

// Define file paths
$STATE_FILE = $DATA_DIR . '/state.json';
$SCHEDULE_FILE = $DATA_DIR . '/schedule.json';
$PRESETS_FILE = $DATA_DIR . '/presets.json';
$HISTORY_FILE = $DATA_DIR . '/temp_history.csv';
$HUMI_HISTORY_FILE = $DATA_DIR . '/humi_history.csv';
$PRES_HISTORY_FILE = $DATA_DIR . '/pres_history.csv';
$PHONE_HISTORY_FILE = $DATA_DIR . '/phone_history.csv';

/** 
 * Generic History Loader for CSV files
 * Returns last 24h as array of [ [t_iso, value], ... ] 
 */
function history_load_generic(string $path, int $bucket = 1200): array
{
    $now = time();
    $cutoff = $now - 86400;
    $out = [];
    if (!is_readable($path)) return $out;
    $rows = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($rows === false) return $out;

    $seen = [];
    foreach ($rows as $r) {
        [$tsStr, $valStr] = array_map('trim', explode(',', $r, 2) + ['', '']);
        if (!is_numeric($tsStr) || !is_numeric($valStr)) continue;
        $ts = (int) $tsStr;
        if ($ts < $cutoff) continue;
        
        // Downsample to avoid overwhelming the browser
        $b = intdiv($ts, $bucket) * $bucket;
        $seen[$b] = floatval($valStr);
    }
    ksort($seen);
    foreach ($seen as $ts => $val) {
        $out[] = [gmdate('c', $ts), $val];
    }
    return $out;
}

function write_json_atomic(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0664);
    return @rename($tmp, $path);
}

/** ---------- API ENDPOINTS ---------- */

// 1. History Endpoints
if ($action === 'load_history') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'points' => history_load_generic($HISTORY_FILE)]);
    exit;
}
if ($action === 'load_humi_history') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'points' => history_load_generic($HUMI_HISTORY_FILE)]);
    exit;
}
if ($action === 'load_pres_history') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'points' => history_load_generic($PRES_HISTORY_FILE)]);
    exit;
}
if ($action === 'load_phone_history') {
    header('Content-Type: application/json');
    $now = time(); $cutoff = $now - 86400; $out = [];
    if (is_readable($PHONE_HISTORY_FILE)) {
        $rows = @file($PHONE_HISTORY_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($rows) {
            foreach ($rows as $r) {
                [$tsStr, $valStr] = array_map('trim', explode(',', $r, 2));
                if ((int)$tsStr >= $cutoff) $out[] = [gmdate('c', (int)$tsStr), (int)$valStr];
            }
        }
    }
    echo json_encode(['ok' => true, 'points' => $out]);
    exit;
}

// 2. State Endpoints
if ($action === 'load_state') {
    header('Content-Type: application/json');
    if (is_readable($STATE_FILE)) {
        $j = json_decode(file_get_contents($STATE_FILE), true);
        echo json_encode([
            'ok' => true,
            'mode' => $j['mode'] ?? 'AUTO',
            'manualSetpoint' => (float)($j['manualSetpoint'] ?? 20),
            'actualTemp' => isset($j['actualTemp']) ? (float)$j['actualTemp'] : null,
            'humi' => isset($j['humi']) ? (float)$j['humi'] : null,
            'pres' => isset($j['pres']) ? (float)$j['pres'] : null,
            'real' => isset($j['real']) ? (float)$j['real'] : null,
            'cald' => (int)($j['cald'] ?? 0),
            'phone' => (int)($j['phone'] ?? 0)
        ]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($action === 'save_state' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (!is_array($decoded)) exit;
    $state = read_json_state_helper($STATE_FILE); // simplified logic
    $state['mode'] = $decoded['mode'] ?? $state['mode'];
    $state['manualSetpoint'] = $decoded['manualSetpoint'] ?? $state['manualSetpoint'];
    write_json_atomic($STATE_FILE, $state);
    echo json_encode(['ok' => true]);
    exit;
}

function read_json_state_helper($path) {
    if (!is_readable($path)) return ['mode'=>'AUTO','manualSetpoint'=>20];
    return json_decode(file_get_contents($path), true) ?: [];
}

// 3. Schedule & Presets (Keep existing logic)
if ($action === 'load_schedule') {
    header('Content-Type: application/json');
    if (is_readable($SCHEDULE_FILE)) echo file_get_contents($SCHEDULE_FILE);
    else echo json_encode(['ok'=>true, 'schedule'=>null]);
    exit;
}
if ($action === 'save_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $decoded = json_decode(file_get_contents('php://input'), true);
    write_json_atomic($SCHEDULE_FILE, ["version"=>time(), "schedule"=>$decoded['schedule']]);
    echo json_encode(['ok'=>true]);
    exit;
}
if ($action === 'load_presets') {
    header('Content-Type: application/json');
    $default = ["order"=>["OFF","LOW","NORMAL","HIGH"],"map"=>["OFF"=>10,"LOW"=>15,"NORMAL"=>19,"HIGH"=>20]];
    if (is_readable($PRESETS_FILE)) echo file_get_contents($PRESETS_FILE);
    else echo json_encode(["ok"=>true, "presets"=>$default]);
    exit;
}
if ($action === 'save_presets' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $decoded = json_decode(file_get_contents('php://input'), true);
    write_json_atomic($PRESETS_FILE, $decoded);
    echo json_encode(['ok'=>true]);
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
            --bg: #f7f7fb; --card: #ffffff; --text: #1f2937; --muted: #6b7280;
            --brand: #2563eb; --brand-weak: #dbeafe; --ring: #93c5fd;
            --danger: #ef4444; --ok: #16a34a; --border: #e5e7eb;
            --chip: #eef2ff; --active-chip: #2563eb; --active-text: #ffffff;
        }
        * { box-sizing: border-box }
        body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text) }
        .container { max-width: 1100px; margin: 24px auto; padding: 0 16px }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px }
        .title { font-size: 22px; font-weight: 700; }
        .cards { display: grid; grid-template-columns: repeat(12, 1fr); gap: 16px }
        .card { grid-column: span 12; background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 4px 14px rgba(31, 41, 55, .06) }
        .card.pad { padding: 16px }
        @media(min-width:820px) { .span4 { grid-column: span 4 } .span8 { grid-column: span 8 } .span6 { grid-column: span 6 } }
        .row { display: flex; gap: 16px; align-items: center }
        .temp { font-size: 40px; font-weight: 700 }
        .unit { font-size: 16px; color: var(--muted) }
        .subtitle { color: var(--muted); font-size: 13px; margin-bottom: 4px; }
        .btn { appearance: none; border: 1px solid var(--border); background: #fff; padding: 10px 14px; border-radius: 12px; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .btn:hover { background: #f9fafb; }
        .btn.active { border-color: var(--brand); background: var(--brand-weak); color: #0b3ea6; box-shadow: 0 0 0 3px var(--ring); }
        input[type="number"] { padding: 10px; border-radius: 10px; border: 1px solid var(--border); width: 80px; font: inherit; font-weight: 700; }
        .grid { display: grid; gap: 12px; }
        .day { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 12px; }
        canvas { width: 100% !important; }
        #tempChart { height: 130px !important; }
        #humiChart, #presChart { height: 100px !important; }
        #phoneChart { height: 60px !important; }
        .pill { display: inline-flex; align-items: center; padding: 6px 12px; border-radius: 999px; background: #fff; border: 1px solid var(--border); font-size: 12px; font-weight: 600; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>

<body>
    <div class="container">
        <div class="header">
            <div class="title">Studio · Chronothermostat</div>
            <div class="pill"><span id="statusText">System Running</span></div>
        </div>

        <div class="cards">
            <!-- 1. Temperature Card -->
            <div class="card pad span4">
                <div class="subtitle">Actual Temperature</div>
                <div class="row">
                    <div class="temp" id="actualTemp">--.-</div>
                    <div class="unit">°C</div>
                </div>
                <div id="realContainer" style="margin-top:8px; font-size:12px; color:var(--muted); display:none;">
                    Target: <strong id="realTemp" style="color:var(--text)">--.-</strong>°C
                </div>
            </div>

            <!-- 2. BME280 Extras Card -->
            <div class="card pad span4">
                <div class="row" style="justify-content: space-around;">
                    <div>
                        <div class="subtitle">Humidity</div>
                        <div class="row">
                            <div class="temp" style="font-size: 28px;" id="actualHumi">--</div>
                            <div class="unit">%</div>
                        </div>
                    </div>
                    <div style="border-left: 1px solid var(--border); padding-left: 16px;">
                        <div class="subtitle">Pressure</div>
                        <div class="row">
                            <div class="temp" style="font-size: 28px;" id="actualPres">----</div>
                            <div class="unit">hPa</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Heater & Phone Card -->
            <div class="card pad span4">
                <div class="subtitle">Heater Status</div>
                <div id="heaterStatus" style="font-size:18px; font-weight:700; color:var(--muted)">--</div>
                <div style="margin-top:10px; font-size:13px; color:var(--muted); border-top:1px solid var(--border); padding-top:8px;">
                    Phone: <span id="phoneStatus" style="font-weight:700">--</span>
                </div>
            </div>

            <!-- 4. Mode Selection -->
            <div class="card pad span6">
                <div class="subtitle">Operating Mode</div>
                <div class="row">
                    <button class="btn" id="btnOff">OFF</button>
                    <button class="btn" id="btnOn">MANUAL</button>
                    <button class="btn" id="btnAuto">AUTO</button>
                </div>
            </div>

            <!-- 5. Setpoint Selection -->
            <div class="card pad span6">
                <div class="subtitle">Manual Setpoint</div>
                <div class="row">
                    <input id="setpointInput" type="number" step="0.5" min="5" max="35" />
                    <div class="unit">°C</div>
                </div>
            </div>

            <!-- 6. Temperature History Chart (Full width) -->
            <div class="card pad span12">
                <div class="subtitle">Temperature Trend (Last 24h)</div>
                <canvas id="tempChart"></canvas>
            </div>

            <!-- 7. Humidity History Chart (Half width) -->
            <div class="card pad span6">
                <div class="subtitle">Humidity Trend</div>
                <canvas id="humiChart"></canvas>
            </div>

            <!-- 8. Pressure History Chart (Half width) -->
            <div class="card pad span6">
                <div class="subtitle">Atmospheric Pressure Trend</div>
                <canvas id="presChart"></canvas>
            </div>

            <!-- 9. Phone Presence Chart -->
            <div class="card pad span12">
                <div class="subtitle">Phone Detection History</div>
                <canvas id="phoneChart"></canvas>
            </div>

            <!-- 10. Weekly Table -->
            <div class="card pad span12">
                <div class="row" style="justify-content: space-between;">
                    <strong>Chrono Schedule</strong>
                    <div class="row">
                        <button class="btn" id="btnLoadServer">Load</button>
                        <button class="btn active" id="btnSaveServer">Save to Server</button>
                    </div>
                </div>
                <div id="daysGrid" class="grid" style="margin-top:16px;"></div>
            </div>
        </div>
    </div>

    <script>
        let charts = { temp: null, humi: null, pres: null, phone: null };

        // Helper to initialize or update a chart
        function updateChart(id, label, color, points, unit) {
            const ctx = document.getElementById(id).getContext('2d');
            const labels = points.map(p => {
                const d = new Date(p[0]);
                return d.getHours().toString().padStart(2,'0') + ":" + d.getMinutes().toString().padStart(2,'0');
            });
            const values = points.map(p => p[1]);

            if (charts[id]) charts[id].destroy();

            charts[id] = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: label,
                        data: values,
                        borderColor: color,
                        borderWidth: 2,
                        pointRadius: 0,
                        tension: 0.3,
                        fill: (id === 'phoneChart'),
                        backgroundColor: color + '22'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { maxTicksLimit: 12, font: { size: 10 } }, grid: { display: false } },
                        y: { ticks: { font: { size: 10 }, callback: v => v + unit } }
                    }
                }
            });
        }

        async function fetchState() {
            try {
                const res = await fetch('?action=load_state&_=' + Date.now());
                const j = await res.json();
                if (j.ok) {
                    document.getElementById('actualTemp').textContent = j.actualTemp?.toFixed(1) || '--.-';
                    document.getElementById('actualHumi').textContent = j.humi?.toFixed(0) || '--';
                    document.getElementById('actualPres').textContent = j.pres?.toFixed(0) || '----';
                    document.getElementById('phoneStatus').textContent = j.phone === 1 ? 'Present' : 'Absent';
                    document.getElementById('phoneStatus').style.color = j.phone === 1 ? 'var(--brand)' : 'var(--muted)';
                    
                    if(j.real) {
                        document.getElementById('realTemp').textContent = j.real.toFixed(1);
                        document.getElementById('realContainer').style.display = 'block';
                    }

                    const h = document.getElementById('heaterStatus');
                    h.textContent = j.cald === 1 ? 'ACTIVE' : 'INACTIVE';
                    h.style.color = j.cald === 1 ? 'var(--ok)' : 'var(--danger)';

                    document.getElementById('setpointInput').value = j.manualSetpoint;
                    
                    ['btnOff', 'btnOn', 'btnAuto'].forEach(id => document.getElementById(id).classList.remove('active'));
                    if (j.mode === 'OFF') document.getElementById('btnOff').classList.add('active');
                    if (j.mode === 'ON') document.getElementById('btnOn').classList.add('active');
                    if (j.mode === 'AUTO') document.getElementById('btnAuto').classList.add('active');
                }
            } catch (e) { console.error('State Error:', e); }
        }

        async function fetchHistory() {
            const get = url => fetch(url + '&_=' + Date.now()).then(r => r.json());
            try {
                const temp = await get('?action=load_history');
                if (temp.ok) updateChart('tempChart', 'Temp', '#2563eb', temp.points, '°');

                const humi = await get('?action=load_humi_history');
                if (humi.ok) updateChart('humiChart', 'Humi', '#16a34a', humi.points, '%');

                const pres = await get('?action=load_pres_history');
                if (pres.ok) updateChart('presChart', 'Pres', '#9333ea', pres.points, '');

                const phone = await get('?action=load_phone_history');
                if (phone.ok) updateChart('phoneChart', 'Presence', '#2563eb', phone.points, '');
            } catch (e) { console.error('History Error:', e); }
        }

        // Logic for setting state
        async function setMode(mode) {
            const sp = parseFloat(document.getElementById('setpointInput').value);
            await fetch('?action=save_state', {
                method: 'POST',
                body: JSON.stringify({ mode, manualSetpoint: sp })
            });
            fetchState();
        }

        document.getElementById('btnOff').onclick = () => setMode('OFF');
        document.getElementById('btnOn').onclick = () => setMode('ON');
        document.getElementById('btnAuto').onclick = () => setMode('AUTO');

        // Init
        fetchState();
        fetchHistory();
        setInterval(fetchState, 10000);
        setInterval(fetchHistory, 60000);
    </script>
</body>
</html>