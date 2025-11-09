// src/main.cpp — Wemos D1 mini (ESP8266)
// Thermostat + Mobile UI + Wi-Fi setup + 0.5°C hysteresis + Arduino OTA (PlatformIO espota)
// - UI at "/": presets & +/- (polling never overwrites editing)
// - Wi-Fi setup at "/wifi": scan/select/save (LittleFS /wifi.json). Reboots after saving.
// - Control logic: 0.5°C hysteresis (ON <= sp-0.25, OFF >= sp+0.25)
// - OTA: Upload via PlatformIO using mDNS (esp-thermo.local) or device IP.
// - Remote setpoint: fetch via get_setpoint.php; adopt & persist only if changed.
// - ESP-NOW payload: only {"heater":"ON"} or {"heater":"OFF"}
// - Use ACK from relay to show Heat ON/OFF in UI and to set cald=0/1 in HTTP
// - *** Performance: non-blocking DS18B20, skip HTTPS in AP mode, tight timeouts, AP keeps radio awake.

#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <espnow.h>
#include <ArduinoJson.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <LittleFS.h>
#include <ESP8266WebServer.h>
#include <ESP8266mDNS.h>
#include <ArduinoOTA.h>
#include <time.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <math.h>

extern "C"
{
#include "user_interface.h"
}

// ===== Default Wi-Fi credentials (fallback only) =====
static const char *WIFI_SSID_DEFAULT = "zelja_RPT";
static const char *WIFI_PASS_DEFAULT = "pikolejla";
static const char *HOSTNAME = "esp-thermo";

// ===== Power-saving (sleep mode) =====
static bool sleepModeActive = false;    // we're in "setpoint <= 10" mode
static bool sleepWaitingRemote = false; // we are waiting for a successful remote reply

// OPTIONAL: OTA password (set to non-empty to require it for uploads)
static const char *OTA_PASS = ""; // e.g. "mySecret123"

// Mutable Wi-Fi creds (loaded from /wifi.json or default)
static String g_wifiSsid;
static String g_wifiPass;

// ===== Timezone / NTP (Europe/Rome) =====
static const char *TZ_INFO = "CET-1CEST,M3.5.0,M10.5.0/3";
static const char *NTP_1 = "pool.ntp.org";
static const char *NTP_2 = "time.google.com";

// ===== DS18B20 on D4 =====
#define ONE_WIRE_BUS D4
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);
DeviceAddress g_dsAddr{};
bool g_haveSensor = false;  // any device present
bool g_haveAddress = false; // address for index 0 resolved

// --- DS18B20 async conversion state (non-blocking) ---
static uint32_t g_dsReqAt = 0;
static bool g_dsPending = false;

// ===== ESP-NOW target (broadcast by default) =====
static uint8_t TARGET[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};

// ===== Fixed setpoint state (persisted) =====
static float g_fixedSetpoint = 19.0f; // default: "on" preset
static String g_fixedPreset = "on";   // "off" | "on" | "away" | "custom" | "remote"
static bool g_fixedEnabled = true;    // always use fixed setpoint for control

// ===== Live telemetry for web UI =====
volatile float g_lastTempC = NAN;
volatile uint8_t g_lastAction = 0; // local decision: 1=heat ON, 0=OFF

// ===== ACK state from relay (drives UI + HTTP cald) =====
static bool g_haveAck = false;
static bool g_ackRelayOn = false;
static uint32_t g_ackLastMs = 0;

// ===== Hysteresis (°C, total band) =====
static const float HYST_BAND_C = 0.5f; // +/- 0.25°C around setpoint

// ===== Remote "cesana" reporting (HTTPS GET) =====
static uint32_t g_lastHttpMs = 0;
static const uint32_t HTTP_MIN_INTERVAL_MS = 1500;
static bool g_remoteOk = false;
static float g_remoteSetpoint = NAN;
static String g_remoteMode = "";
static float g_remoteActual = NAN;
static bool g_remoteHeating = false;
static float g_remoteDelta = NAN;
static bool g_apActive = false;

// ===== Web server =====
ESP8266WebServer server(80);

// Pending reboot after saving Wi-Fi
static bool g_pendingRestart = false;
static uint32_t g_restartAtMs = 0;

// ===== Persist/write minimization for fixed setpoint =====
static float g_lastSavedSetpoint = NAN;
static uint32_t g_lastFsWriteMs = 0;
static const uint32_t FS_WRITE_MIN_GAP_MS = 30000; // 30s between FS writes
static const float SP_EPS = 0.05f;                 // consider same within ±0.05°C

static void startApFallback()
{
  if (g_apActive)
    return; // already running
  WiFi.mode(WIFI_AP_STA);
  wifi_set_sleep_type(NONE_SLEEP_T); // keep AP responsive
  const char *apSsid = "Termometro";
  const char *apPass = "12345678";
  bool ok = WiFi.softAP(apSsid, apPass, /*channel*/ 1);
  g_apActive = ok;
  Serial.printf("[WiFi] AP fallback %s (SSID=%s, ch=%d, IP=%s)\n",
                ok ? "started" : "FAILED", apSsid, 1, WiFi.softAPIP().toString().c_str());
  // Keep ESP-NOW on the same channel as AP
  wifi_set_channel(1);
}

// ===== Utils =====
static void printMac(const uint8_t *mac)
{
  for (int i = 0; i < 6; ++i)
  {
    if (i)
      Serial.print(":");
    char b[3];
    sprintf(b, "%02X", mac[i]);
    Serial.print(b);
  }
}

static void onDataSent(uint8_t *mac, uint8_t status)
{
  Serial.print("[TX] Sent to ");
  printMac(mac);
  Serial.print(" -> status=");
  Serial.println(status == 0 ? "OK" : "ERR");
}

// receive ACKs from relay
static void onDataRecv(uint8_t *mac, uint8_t *data, uint8_t len)
{
  Serial.print("[RX] from ");
  printMac(mac);
  Serial.printf(" len=%u: ", len);
  for (uint8_t i = 0; i < len; i++)
    Serial.write(data[i]);
  Serial.println();

  JsonDocument doc;
  DeserializationError e = deserializeJson(doc, data, len);
  if (e)
  {
    Serial.printf("[RX] JSON error: %s\n", e.c_str());
    return;
  }

  // Expected: {"ack":"ON"|"OFF","relay":0|1,"ok":true}
  const char *ack = doc["ack"] | nullptr;
  int relay = doc["relay"] | -1;
  bool ok = doc["ok"] | false;
  if (!ok || relay < 0)
  {
    Serial.println("[RX] Missing ok/relay in ACK");
    return;
  }

  g_haveAck = true;
  g_ackRelayOn = (relay == 1) || (ack && strcmp(ack, "ON") == 0);
  g_ackLastMs = millis();

  Serial.printf("[RX] ACK parsed -> relay=%d (%s)\n", relay, g_ackRelayOn ? "ON" : "OFF");
}

// ======== Thermostat HTML (UI) ========
const char INDEX_HTML[] PROGMEM = R"HTML(
<!doctype html><html lang="en"><head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
<title>ESP8266 Thermostat</title>
<style>
:root{
  --bg:#f4fbfd; --card:#ffffff; --ink:#0b3440; --muted:#4d7580;
  --accent:#1aa6b7; --accent-2:#36d1b1; --border:#d7eef2;
  --ok:#1b9e77; --warn:#ffb703; --err:#c1121f;
  --radius:16px; --pad:clamp(12px,2.5vw,18px); --tap:48px;
  --font:16px ui-sans-serif,system-ui,"Segoe UI",Roboto,Arial;
}
*{box-sizing:border-box; -webkit-tap-highlight-color:transparent}
html,body{height:100%}
body{
  margin:0; background:linear-gradient(180deg,#f4fbfd 0%,#e8f7fa 100%);
  color:var(--ink); font:var(--font); display:grid; grid-template-rows:auto 1fr; gap:0; padding:0;
}
.app{ width:min(840px,100%); margin:0 auto; }
.header{
  position:sticky; top:0; z-index:10;
  display:flex; justify-content:space-between; align-items:center;
  padding:var(--pad); background:linear-gradient(180deg,#e9fbff,#d9f5f7);
  border-bottom:1px solid var(--border);
}
.title{font-weight:800; letter-spacing:.2px; font-size:clamp(16px,2.8vw,20px)}
.nav{display:flex; gap:8px}
.nav a{
  color:#055968; text-decoration:none; font-weight:800; padding:10px 12px; line-height:1;
  border:1px solid var(--border); border-radius:12px; background:#f1fdff; min-height:var(--tap);
  display:inline-flex; align-items:center; justify-content:center;
}
.badges{display:flex; gap:8px; flex-wrap:wrap; margin-left:auto}
.badge{
  font:12px/1 ui-monospace,Consolas; color:var(--ink); background:#eefbfd;
  border:1px solid var(--border); padding:8px 10px; border-radius:999px; min-height:var(--tap);
  display:inline-flex; align-items:center; gap:8px;
}

.content{ padding:var(--pad); display:grid; gap:12px }
.card{
  border:1px solid var(--border); border-radius:var(--radius); padding:var(--pad);
  background:linear-gradient(180deg,#ffffff,#f7fffe);
  box-shadow:0 8px 26px rgba(26,166,183,.12);
}
.row{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap}

.kpi{display:flex; align-items:baseline; gap:10px; min-height:var(--tap)}
.kpi .label{color:var(--muted); font-size:clamp(13px,2.2vw,14px)}
.kpi .value{font-size:clamp(28px,9vw,44px); font-weight:900}

.controls{display:flex; align-items:center; gap:10px; flex-wrap:wrap}
.btn{
  border:1px solid var(--border); background:linear-gradient(180deg,#faffff,#e9fffb);
  color:var(--ink); padding:12px 18px; border-radius:14px; cursor:pointer; font-weight:700; min-width:52px;
  min-height:var(--tap); line-height:1; user-select:none; touch-action:manipulation;
  transition:transform .05s ease, box-shadow .15s ease;
}
.btn:hover{box-shadow:0 3px 10px rgba(54,209,177,.15)}
.btn:active{transform:translateY(1px)}
.btn.primary{background:linear-gradient(180deg,#bff6ec,#8df0dc); border-color:#8de9d8}
.btn.pill{border-radius:999px}

.presetbar{
  display:flex; gap:10px; flex-wrap:nowrap; overflow-x:auto; padding-bottom:2px; margin:0 -4px;
  scrollbar-width:thin;
}
.presetbar::-webkit-scrollbar{height:6px}
.presetbar::-webkit-scrollbar-thumb{background:#bfeff3; border-radius:999px}
.preset{
  flex:0 0 auto; padding:10px 14px; border-radius:999px; border:1px solid var(--border);
  background:#f7fffe; cursor:pointer; font-weight:700; min-height:var(--tap);
}
.preset.active{outline:2px solid var(--accent); box-shadow:0 0 0 3px rgba(26,166,183,.15) inset}
.hint{font-size:clamp(12px,2.4vw,13px); color:var(--muted); min-height:var(--tap); display:flex; align-items:center}

.dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px; vertical-align:middle}
.on{background:var(--ok)} .off{background:#9aaeb5}

/* Responsive stack for small screens */
@media (max-width: 480px){
  .row{flex-direction:column; align-items:stretch}
  .controls{justify-content:space-between}
  .badges{width:100%; justify-content:flex-end}
  .nav{flex-wrap:wrap}
}
  /* --- Mobile optimizations ------------------------------------ */
.header, .content { padding-left: calc(var(--pad) + env(safe-area-inset-left)); padding-right: calc(var(--pad) + env(safe-area-inset-right)); }
.badges { flex: 1; justify-content: flex-end }
.btn.pill#minus, .btn.pill#plus { width: var(--tap); height: var(--tap); padding: 0; font-size: 24px; display: inline-flex; align-items: center; justify-content: center; }
#save { min-width: 110px }
.presetbar { scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; }
.preset { scroll-snap-align: start }
.presetbar::-webkit-scrollbar { height: 0 }
@media (max-width: 480px){ :root { --tap: 52px } .kpi .value { font-size: clamp(30px, 12vw, 44px) } .btn { padding: 12px 16px } .badge { font-size: 11px } .content { gap: 10px } }
@media (max-width: 360px){ :root { --tap: 56px } .title { font-size: 16px } .nav a { padding: 8px 10px; font-size: 13px } .btn { padding: 12px 14px; font-weight: 800 } .controls { gap: 8px } .preset { padding: 10px 12px; font-size: 14px } }
@media (prefers-reduced-motion: reduce){ .btn { transition: none } }
</style>
</head><body>
<div class="app">
   <div class="header">
    <div style="display:flex;gap:10px;align-items:center">
      <div class="title">ESP8266 Thermostat</div>
      <div class="nav">
        <a href="/">Thermostat</a>
        <a href="/wifi">Wi-Fi</a>
      </div>
    </div>
    <div class="badges">
      <div class="badge"><span class="dot" id="heatDot"></span><span id="heatText">Heat: --</span></div>
      <div class="badge"><span class="dot" id="calDot"></span><span id="calText">Caldaia: --</span></div>
      <div class="badge"><span class="dot" id="wifiDot"></span><span id="wifiText">Wi-Fi: --</span></div>
      <div class="badge" id="time">--</div>
    </div>
  </div>
  </div>
  <div class="content">
    <div class="card row">
      <div class="kpi"><div class="label">Actual</div><div class="value" id="actual">--.-°C</div></div>
      <div class="kpi"><div class="label">Setpoint</div><div class="value" id="sp">--.-°C</div></div>
    </div>

    <div class="card">
      <div class="row" style="gap:14px">
        <div class="controls">
          <button class="btn pill" id="minus" aria-label="Decrease setpoint">−</button>
          <button class="btn pill" id="plus"  aria-label="Increase setpoint">+</button>
          <button class="btn primary pill" id="save">Save</button>
        </div>
        <div class="presetbar" role="tablist" aria-label="Presets">
          <button class="preset" data-name="off"  data-val="10">Off · 10°C</button>
          <button class="preset" data-name="on"   data-val="19">On · 19°C</button>
          <button class="preset" data-name="away" data-val="15">Away · 15°C</button>
        </div>
        <div class="hint" id="state">—</div>
      </div>
    </div>
  </div>
</div>

<script>
// Poll only Actual/Heat/time; never overwrite setpoint/preset while editing.
let sp = 19.0;
let preset = 'on';
let saveTimer = null;
const SAVE_DEBOUNCE_MS = 350;

function fmt(v){ return Number(v).toFixed(1) + '°C'; }
function setActivePreset(name){ document.querySelectorAll('.preset').forEach(b=> b.classList.toggle('active', b.dataset.name===name)); }
function showState(msg){ document.getElementById('state').textContent = msg; }

async function loadFixed(){
  try{
    const r = await fetch('/api/fixed'); if (!r.ok) throw new Error('http');
    const j = await r.json();
    sp = (typeof j.setpoint === 'number' && Number.isFinite(j.setpoint)) ? j.setpoint : 19.0;
    preset = j.preset || 'custom';
    document.getElementById('sp').textContent = fmt(sp);
    setActivePreset(preset);
    showState('Preset: ' + preset);
  }catch(e){
    sp = 19.0; preset = 'custom';
    document.getElementById('sp').textContent = fmt(sp);
    setActivePreset(preset);
    showState('Preset: custom');
  }
}

async function savePreset(name){
  try{
    showState('Saving preset…');
    const r = await fetch('/api/fixed',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({preset:name}) });
    if (!r.ok){ showState('Save failed'); return; }
    const j = await r.json();
    if (typeof j.setpoint === 'number'){
      sp = j.setpoint; preset = j.preset || name;
      document.getElementById('sp').textContent = fmt(sp);
      setActivePreset(preset);
      showState('Saved · Preset: ' + preset);
    }else{ showState('Save failed'); }
  }catch(e){ showState('Save failed'); }
}

async function saveCustomNow(){
  try{
    const r = await fetch('/api/fixed',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ setpoint: sp }) });
    if (!r.ok){ showState('Save failed'); return; }
    const j = await r.json();
    if (typeof j.setpoint === 'number'){
      sp = j.setpoint; preset = j.preset || 'custom';
      document.getElementById('sp').textContent = fmt(sp);
      setActivePreset(preset);
      showState('Saved · Preset: ' + preset);
    }else{ showState('Save failed'); }
  }catch(e){ showState('Save failed'); }
}
function queueSaveCustom(){
  if (saveTimer) clearTimeout(saveTimer);
  showState('Saving…');
  saveTimer = setTimeout(saveCustomNow, SAVE_DEBOUNCE_MS);
}

// Poll status (uses j.action which is ACK-based when available)
async function tick(){
  try{
    const r = await fetch('/api/status'); if (!r.ok) return;
    const j = await r.json();
    if (typeof j.temp === 'number') document.getElementById('actual').textContent = fmt(j.temp);
    const on = j.action===1;
    document.getElementById('heatText').textContent = 'Heat: ' + (on?'ON':'OFF');
    document.getElementById('heatDot').className = 'dot ' + (on?'on':'off');
    if (typeof j.epoch === 'number'){
      const d=new Date(j.epoch*1000);
      document.getElementById('time').textContent=d.toLocaleString();
    }
    // Caldaia badge
    const hasAck = !!j.ackAvailable;
    let ackFresh = false;
    if (hasAck) {
      const age = (typeof j.ackAgeMs === 'number') ? j.ackAgeMs : 0;
      ackFresh = age <= 5000;
    }
    document.getElementById('calDot').className = 'dot ' + (ackFresh ? 'on' : 'off');
    document.getElementById('calText').textContent = ackFresh ? 'Caldaia: OK' : (hasAck ? 'Caldaia: stale' : 'Caldaia: —');

    // Wi-Fi badge
    const wb = j.wifi || {};
    const wifiOn = !!wb.connected;
    const apOn   = !!wb.ap;
    document.getElementById('wifiDot').className = 'dot ' + (wifiOn ? 'on' : 'off');
    let wifiLabel = 'Wi-Fi: --';
    if (wifiOn) {
      const ip = (typeof wb.ip === 'string' && wb.ip) ? ` (${wb.ip})` : '';
      wifiLabel = `Wi-Fi: ${wb.ssid||'—'}${ip}`;
    } else if (apOn) {
      wifiLabel = `AP: ${wb.ap_ip || '192.168.4.1'}`;
    } else {
      wifiLabel = 'Wi-Fi: offline';
    }
    document.getElementById('wifiText').textContent = wifiLabel;
  }catch(e){}
}

document.getElementById('minus').onclick = ()=>{
  if (!Number.isFinite(sp)) sp = 19.0;
  sp = Math.max(5, Math.round((sp - 0.5) * 10) / 10);
  document.getElementById('sp').textContent = fmt(sp);
  setActivePreset('custom');
  queueSaveCustom();
};
document.getElementById('plus').onclick  = ()=>{
  if (!Number.isFinite(sp)) sp = 19.0;
  sp = Math.min(35, Math.round((sp + 0.5) * 10) / 10);
  document.getElementById('sp').textContent = fmt(sp);
  setActivePreset('custom');
  queueSaveCustom();
};
document.getElementById('save').onclick  = ()=> saveCustomNow();
document.querySelectorAll('.preset').forEach(b=> b.onclick = ()=> savePreset(b.dataset.name));

loadFixed();
tick();
setInterval(tick, 1500);
</script>
</body></html>
)HTML";

// ======== Wi-Fi Setup HTML ========
const char WIFI_HTML[] PROGMEM = R"HTML(
<!doctype html><html lang="en"><head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
<title>Wi-Fi Setup</title>
<style>
:root{
  --bg:#f4fbfd; --card:#ffffff; --ink:#0b3440; --muted:#4d7580;
  --accent:#1aa6b7; --accent-2:#36d1b1; --border:#d7eef2; --ok:#1b9e77; --err:#c1121f;
  --radius:16px; --pad:clamp(12px,2.5vw,18px); --tap:48px;
  --font:16px ui-sans-serif,system-ui,"Segoe UI",Roboto,Arial;
}
*{box-sizing:border-box; -webkit-tap-highlight-color:transparent}
body{
  margin:0;background:linear-gradient(180deg,#f4fbfd 0%,#e8f7fa 100%);color:var(--ink);
  font:var(--font);display:grid;place-items:start;min-height:100vh;padding:0;
}
.app{width:min(840px,100%); margin:0 auto}
.header{
  position:sticky; top:0; z-index:10; padding:var(--pad);
  display:flex;justify-content:space-between;align-items:center;
  background:linear-gradient(180deg,#e9fbff,#d9f5f7);
  border-bottom:1px solid var(--border)
}
.title{font-weight:800; font-size:clamp(16px,2.8vw,20px)}
.nav a{
  color:#055968;text-decoration:none;font-weight:800;padding:10px 12px;border:1px solid var(--border);
  border-radius:12px;background:#f1fdff; min-height:var(--tap); display:inline-flex; align-items:center
}
.content{padding:var(--pad); display:grid; gap:12px}
.card{
  border:1px solid var(--border); border-radius:var(--radius); padding:var(--pad);
  background:linear-gradient(180deg,#ffffff,#f7fffe);
  box-shadow:0 8px 26px rgba(26,166,183,.12)
}
.row{display:grid; grid-template-columns:1fr; gap:10px; align-items:center}
@media (min-width:560px){ .row{ grid-template-columns:180px 1fr } }
select,input{
  border:1px solid var(--border); border-radius:12px; padding:12px; background:#fbffff; min-height:var(--tap); width:100%;
  font-size:16px;
}
.btn{
  border:1px solid var(--border); background:linear-gradient(180deg,#faffff,#e9fffb);
  color:var(--ink); padding:12px 18px; border-radius:12px; cursor:pointer; font-weight:800; min-height:var(--tap)
}
.btn.primary{background:linear-gradient(180deg,#bff6ec,#8df0dc); border-color:#8de9d8}
.kv{display:flex; gap:8px; flex-wrap:wrap; color:var(--muted); font-size:14px}
.badge{border:1px solid var(--border); border-radius:999px; padding:8px 10px; background:#eefbfd; min-height:var(--tap); display:inline-flex; align-items:center}
.msg{font-size:14px}
.ok{color:var(--ok)} .err{color:var(--err)}
.header, .content { padding-left: calc(var(--pad) + env(safe-area-inset-left)); padding-right: calc(var(--pad) + env(safe-area-inset-right)); }
select, input { font-size: 16px; min-height: calc(var(--tap) + 6px) }
.btn { min-height: calc(var(--tap) + 4px) }
#ssid { min-width: 100% }
@media (max-width: 480px){ .badge { font-size: 11px } .nav a { padding: 8px 10px; font-size: 13px } .row { gap: 8px } }
@media (prefers-reduced-motion: reduce){ .btn { transition: none } }
</style>
</head><body>
<div class="app">
  <div class="header">
    <div class="title">Wi-Fi Setup</div>
    <div class="nav">
      <a href="/">Thermostat</a>
      <a href="/wifi">Wi-Fi</a>
    </div>
  </div>
  <div class="content">
    <div class="card">
      <div class="row">
        <label for="ssid">Available Wi-Fi</label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <select id="ssid" style="min-width:min(260px,100%)"></select>
          <button class="btn" id="refresh">Refresh</button>
        </div>
      </div>
      <div class="row">
        <label for="pass">Password</label>
        <input id="pass" type="password" inputmode="text" autocomplete="current-password" placeholder="Enter Wi-Fi password"/>
      </div>
      <div class="row">
        <div></div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button class="btn primary" id="save">Save & Reboot</button>
          <span class="msg" id="msg" role="status" aria-live="polite"></span>
        </div>
      </div>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <div class="kv">
          <span class="badge" id="curSsid">SSID: --</span>
          <span class="badge" id="curIp">IP: --</span>
          <span class="badge" id="curRssi">RSSI: --</span>
        </div>
        <button class="btn" id="reloadCur">Reload</button>
      </div>
    </div>
  </div>
</div>
<script>
function securityLabel(enc){
  const map={ "7":"WPA3","5":"WEP","4":"AUTO","3":"WPA/WPA2","2":"WPA2","1":"WPA","0":"OPEN" };
  return map[String(enc)]||("ENC"+enc);
}
async function loadScan(){
  const sel = document.getElementById('ssid');
  sel.innerHTML = '<option>Scanning…</option>';
  try{
    const r = await fetch('/api/wifi/scan'); const j = await r.json();
    sel.innerHTML='';
    j.networks.forEach(n=>{
      const o=document.createElement('option');
      o.value=n.ssid; o.textContent = `${n.ssid}  ·  ${n.rssi} dBm  ·  ${securityLabel(n.enc)}  ·  ch${n.ch}`;
      sel.appendChild(o);
    });
    if (j.networks.length===0) sel.innerHTML='<option>No networks found</option>';
  }catch(e){ sel.innerHTML='<option>Scan failed</option>'; }
}
async function loadCurrent(){
  try{
    const r = await fetch('/api/wifi/current'); const j = await r.json();
    document.getElementById('curSsid').textContent = 'SSID: ' + (j.ssid||'--');
    document.getElementById('curIp').textContent   = 'IP: ' + (j.ip||'--');
    document.getElementById('curRssi').textContent = 'RSSI: ' + ((j.rssi!=null)?(j.rssi+' dBm'):'--');
  }catch(e){}
}
async function saveCreds(){
  const ssid = document.getElementById('ssid').value;
  const pass = document.getElementById('pass').value;
  const m = document.getElementById('msg');
  if (!ssid){ m.textContent='Select a network'; m.className='msg err'; return; }
  m.textContent='Saving…'; m.className='msg';
  try{
    const r = await fetch('/api/wifi/save',{method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ssid,pass})});
    if (!r.ok){ m.textContent='Save failed'; m.className='msg err'; return; }
    m.textContent='Saved. Rebooting…'; m.className='msg ok';
    setTimeout(()=>location.href='/', 7000);
  }catch(e){ m.textContent='Save failed'; m.className='msg err'; }
}
document.getElementById('refresh').onclick = loadScan;
document.getElementById('reloadCur').onclick = loadCurrent;
document.getElementById('save').onclick = saveCreds;
loadScan(); loadCurrent();
</script>
</body></html>
)HTML";

// ====== Persistence for fixed setpoint ======
static const char *FIXED_PATH = "/fixed_setpoint.json";
static const char *WIFI_PATH = "/wifi.json";

static void loadFixedSetpoint()
{
  g_fixedSetpoint = 19.0f;
  g_fixedPreset = "on";
  g_fixedEnabled = true;
  if (!LittleFS.exists(FIXED_PATH))
    return;
  File f = LittleFS.open(FIXED_PATH, "r");
  if (!f)
    return;
  JsonDocument doc;
  if (deserializeJson(doc, f) == DeserializationError::Ok)
  {
    float sp = doc["setpoint"] | g_fixedSetpoint;
    const char *pr = doc["preset"] | g_fixedPreset.c_str();
    bool en = doc["enabled"] | true;
    if (sp >= 5 && sp <= 35)
      g_fixedSetpoint = sp;
    g_fixedPreset = pr;
    g_fixedEnabled = en;
  }
  f.close();
  Serial.printf("[FS] Fixed setpoint loaded: %.1f (%s)\n", g_fixedSetpoint, g_fixedPreset.c_str());
}

static bool saveFixedSetpoint()
{
  JsonDocument doc;
  doc["setpoint"] = g_fixedSetpoint;
  doc["preset"] = g_fixedPreset;
  doc["enabled"] = g_fixedEnabled;
  File f = LittleFS.open(FIXED_PATH, "w");
  if (!f)
  {
    Serial.println("[FS] open write failed (fixed)");
    return false;
  }
  bool ok = (serializeJson(doc, f) > 0);
  f.close();
  Serial.println(ok ? "[FS] Fixed setpoint saved" : "[FS] Fixed save failed");
  return ok;
}

static bool saveFixedSetpointIfNeeded(bool force = false)
{
  if (!isnan(g_lastSavedSetpoint) && fabsf(g_fixedSetpoint - g_lastSavedSetpoint) < SP_EPS)
    return true;
  if (!force && (millis() - g_lastFsWriteMs < FS_WRITE_MIN_GAP_MS))
    return true;
  bool ok = saveFixedSetpoint();
  if (ok)
  {
    g_lastSavedSetpoint = g_fixedSetpoint;
    g_lastFsWriteMs = millis();
  }
  return ok;
}

// Wi-Fi credentials persistence
static void loadWifiCreds()
{
  g_wifiSsid = WIFI_SSID_DEFAULT;
  g_wifiPass = WIFI_PASS_DEFAULT;
  if (!LittleFS.exists(WIFI_PATH))
  {
    Serial.printf("[FS] No /wifi.json, using defaults SSID=%s\n", WIFI_SSID_DEFAULT);
    return;
  }
  File f = LittleFS.open(WIFI_PATH, "r");
  if (!f)
  {
    Serial.println("[FS] open /wifi.json failed");
    return;
  }
  JsonDocument doc;
  if (deserializeJson(doc, f) == DeserializationError::Ok)
  {
    const char *s = doc["ssid"] | WIFI_SSID_DEFAULT;
    const char *p = doc["pass"] | WIFI_PASS_DEFAULT;
    g_wifiSsid = s;
    g_wifiPass = p;
    Serial.printf("[FS] Wi-Fi loaded: SSID=%s\n", g_wifiSsid.c_str());
  }
  else
  {
    Serial.println("[FS] /wifi.json parse error; using defaults");
  }
  f.close();
}
static bool saveWifiCreds(const String &ssid, const String &pass)
{
  JsonDocument doc;
  doc["ssid"] = ssid;
  doc["pass"] = pass;
  File f = LittleFS.open(WIFI_PATH, "w");
  if (!f)
  {
    Serial.println("[FS] open write failed (/wifi.json)");
    return false;
  }
  bool ok = (serializeJson(doc, f) > 0);
  f.close();
  Serial.println(ok ? "[FS] Wi-Fi creds saved" : "[FS] Wi-Fi creds save failed");
  return ok;
}

// ====== (Legacy) 7×24 schedule kept but unused ======
float setpoints[7][24];
static void initLegacySchedule()
{
  for (int d = 0; d < 7; ++d)
    for (int h = 0; h < 24; ++h)
      setpoints[d][h] = g_fixedSetpoint;
}

// ===== HTTPS GET to cesana.steplab.net =====
static bool cesanaReportAndFetch(float tempC, bool heatingFromAck /* true=ON, false=OFF */)
{
  // Only report in STA mode, not in AP
  if (WiFi.status() != WL_CONNECTED || g_apActive)
  {
    return false;
  }

  String url = "https://cesana.steplab.net/get_setpoint.php?temp=";
  url += String(tempC, 1);
  url += "&cald=";
  url += (heatingFromAck ? "1" : "0");

  std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
  client->setInsecure();
  client->setTimeout(600); // tight socket timeout (ms)

  HTTPClient https;
  https.setTimeout(800); // total request timeout (ms)
  https.setReuse(false);

  Serial.printf("[HTTP] GET %s\n", url.c_str());
  if (!https.begin(*client, url))
  {
    Serial.println("[HTTP] begin() failed");
    return false;
  }
  int code = https.GET();
  if (code <= 0)
  {
    Serial.printf("[HTTP] GET failed: %s\n", https.errorToString(code).c_str());
    https.end();
    return false;
  }
  Serial.printf("[HTTP] Status: %d\n", code);
  if (code != HTTP_CODE_OK)
  {
    https.end();
    return false;
  }
  String payload = https.getString();
  https.end();

  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, payload);
  if (err)
  {
    Serial.printf("[JSON-HTTP] Parse error: %s\n", err.c_str());
    Serial.print("[JSON-HTTP] Raw: ");
    Serial.println(payload);
    return false;
  }
  g_remoteOk = doc["ok"] | false;
  g_remoteMode = (const char *)(doc["mode"] | "");
  g_remoteSetpoint = doc["setpoint"] | NAN;
  g_remoteActual = doc["actualTemp"] | NAN;
  if (!isnan(g_remoteSetpoint) && !isnan(g_remoteActual))
  {
    g_remoteHeating = (g_remoteActual < g_remoteSetpoint);
    g_remoteDelta = g_remoteActual - g_remoteSetpoint;
  }
  else
  {
    g_remoteHeating = false;
    g_remoteDelta = NAN;
  }
  Serial.printf("[HTTP] ok=%s mode=%s setpoint=%.1f actual=%.1f heat=%s Δ=%.1f\n",
                g_remoteOk ? "true" : "false", g_remoteMode.c_str(), g_remoteSetpoint, g_remoteActual,
                g_remoteHeating ? "ON" : "OFF", (isnan(g_remoteDelta) ? NAN : g_remoteDelta));

  // Apply remote setpoint if provided
  if (g_remoteOk && !isnan(g_remoteSetpoint) && g_remoteSetpoint >= 5.0f && g_remoteSetpoint <= 35.0f)
  {
    if (fabsf(g_remoteSetpoint - g_fixedSetpoint) >= SP_EPS)
    {
      g_fixedSetpoint = g_remoteSetpoint;
      g_fixedPreset = "remote";
      g_fixedEnabled = true;
      saveFixedSetpointIfNeeded(/*force=*/true);
      Serial.printf("[HTTP] Applied remote SP=%.1f and saved (preset=remote)\n", g_fixedSetpoint);
    }
  }
  return g_remoteOk;
}

// ===== Web handlers =====
void handleIndex()
{
  server.sendHeader("Cache-Control", "public,max-age=86400");
  server.send_P(200, "text/html", INDEX_HTML);
}
void handleWifiPage()
{
  server.sendHeader("Cache-Control", "public,max-age=86400");
  server.send_P(200, "text/html", WIFI_HTML);
}

// Fixed setpoint APIs (unchanged)
void handleGetFixed()
{
  JsonDocument doc;
  doc["setpoint"] = g_fixedSetpoint;
  doc["preset"] = g_fixedPreset;
  doc["enabled"] = g_fixedEnabled;
  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}

void handlePostFixed()
{
  if (server.method() != HTTP_POST)
  {
    server.send(405, "text/plain", "Method Not Allowed");
    return;
  }
  if (!server.hasArg("plain"))
  {
    server.send(400, "text/plain", "Missing body");
    return;
  }

  JsonDocument in;
  DeserializationError e = deserializeJson(in, server.arg("plain"));
  if (e)
  {
    server.send(400, "text/plain", String("JSON error: ") + e.c_str());
    return;
  }

  String preset = in["preset"] | "";
  bool changed = false;

  auto applyPreset = [&](const String &p)
  {
    if (p == "off")
    {
      g_fixedSetpoint = 10.0f;
      g_fixedPreset = "off";
      g_fixedEnabled = true;
      return true;
    }
    if (p == "on")
    {
      g_fixedSetpoint = 19.0f;
      g_fixedPreset = "on";
      g_fixedEnabled = true;
      return true;
    }
    if (p == "away")
    {
      g_fixedSetpoint = 15.0f;
      g_fixedPreset = "away";
      g_fixedEnabled = true;
      return true;
    }
    return false;
  };

  if (preset.length())
    changed = applyPreset(preset);
  else
  {
    float sp = in["setpoint"] | NAN;
    if (!isnan(sp) && sp >= 5.0f && sp <= 35.0f)
    {
      g_fixedSetpoint = sp;
      g_fixedPreset = "custom";
      g_fixedEnabled = true;
      changed = true;
    }
  }

  if (!changed)
  {
    server.send(422, "application/json", "{\"ok\":false}");
    return;
  }
  bool ok = saveFixedSetpoint();

  JsonDocument out;
  out["ok"] = ok;
  out["setpoint"] = g_fixedSetpoint;
  out["preset"] = g_fixedPreset;
  out["enabled"] = g_fixedEnabled;
  String s;
  serializeJson(out, s);
  server.send(ok ? 200 : 500, "application/json", s);
}

void handleTime()
{
  time_t now = time(nullptr);
  JsonDocument doc;
  doc["epoch"] = (uint32_t)now;
  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}

void handleStatus()
{
  time_t now = time(nullptr);
  float sp = g_fixedEnabled ? g_fixedSetpoint : 19.0f;

  // UI action: prefer ACK relay state when available, else local decision
  uint8_t actionForUi = g_haveAck ? (g_ackRelayOn ? 1 : 0) : g_lastAction;

  JsonDocument doc;
  doc["epoch"] = (uint32_t)now;
  if (isnan(g_lastTempC))
    doc["temp"] = nullptr;
  else
    doc["temp"] = g_lastTempC;
  doc["setpoint"] = sp;
  doc["preset"] = g_fixedPreset;
  doc["action"] = actionForUi; // drives Heat ON/OFF badge
  doc["hysteresis"] = HYST_BAND_C;

  // ACK/Caldaia info
  doc["ackAvailable"] = g_haveAck;
  if (g_haveAck)
    doc["ackAgeMs"] = (uint32_t)(millis() - g_ackLastMs);

  // Remote (unchanged)
  if (isnan(g_remoteSetpoint))
    doc["remoteSetpoint"] = nullptr;
  else
    doc["remoteSetpoint"] = g_remoteSetpoint;
  if (g_remoteMode.length())
    doc["remoteMode"] = g_remoteMode;
  if (isnan(g_remoteActual))
    doc["remoteActual"] = nullptr;
  else
    doc["remoteActual"] = g_remoteActual;
  doc["remoteHeating"] = g_remoteHeating;
  if (isnan(g_remoteDelta))
    doc["remoteDelta"] = nullptr;
  else
    doc["remoteDelta"] = g_remoteDelta;

  // Wi-Fi status + AP info
  JsonObject w = doc["wifi"].to<JsonObject>();
  bool staUp = (WiFi.status() == WL_CONNECTED);
  w["connected"] = staUp;
  w["ap"] = g_apActive; // true if AP is running
  if (staUp)
  {
    w["ssid"] = WiFi.SSID();
    w["ip"] = WiFi.localIP().toString();
    w["rssi"] = WiFi.RSSI();
  }
  else
  {
    w["ssid"] = nullptr;
    w["ip"] = nullptr;
    w["rssi"] = nullptr;
  }
  if (g_apActive)
    w["ap_ip"] = WiFi.softAPIP().toString();

  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}

// Quick 1-Wire bus inspection (debug)
void handleOwBus()
{
  sensors.requestTemperatures();
  delay(5);
  JsonDocument doc;
  JsonArray arr = doc["devices"].to<JsonArray>();
  uint8_t count = sensors.getDeviceCount();
  for (uint8_t i = 0; i < count; i++)
  {
    DeviceAddress a{};
    if (sensors.getAddress(a, i))
    {
      char s[24];
      int p = 0;
      for (int k = 0; k < 8; k++)
        p += sprintf(s + p, "%02X%s", a[k], (k < 7 ? ":" : ""));
      arr.add(s);
    }
    else
      arr.add(nullptr);
  }
  doc["parasite"] = sensors.isParasitePowerMode();
  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}

// ===== Wi-Fi API handlers =====
void handleWifiScan()
{
  int n = WiFi.scanNetworks(false, true);
  JsonDocument doc;
  JsonArray arr = doc["networks"].to<JsonArray>();
  for (int i = 0; i < n; ++i)
  {
    JsonObject o = arr.add<JsonObject>();
    o["ssid"] = WiFi.SSID(i);
    o["rssi"] = WiFi.RSSI(i);
    o["enc"] = (int)WiFi.encryptionType(i);
    o["ch"] = WiFi.channel(i);
  }
  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}
void handleWifiCurrent()
{
  JsonDocument doc;
  if (WiFi.status() == WL_CONNECTED)
  {
    doc["ssid"] = WiFi.SSID();
    doc["ip"] = WiFi.localIP().toString();
    doc["rssi"] = WiFi.RSSI();
  }
  else
  {
    doc["ssid"] = nullptr;
    doc["ip"] = nullptr;
    doc["rssi"] = nullptr;
  }
  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}
void handleWifiSave()
{
  if (server.method() != HTTP_POST)
  {
    server.send(405, "text/plain", "Method Not Allowed");
    return;
  }
  if (!server.hasArg("plain"))
  {
    server.send(400, "text/plain", "Missing body");
    return;
  }
  JsonDocument doc;
  DeserializationError e = deserializeJson(doc, server.arg("plain"));
  if (e)
  {
    server.send(400, "text/plain", String("JSON error: ") + e.c_str());
    return;
  }
  String ssid = doc["ssid"] | "";
  String pass = doc["pass"] | "";
  if (ssid.length() == 0)
  {
    server.send(422, "application/json", "{\"ok\":false,\"err\":\"ssid required\"}");
    return;
  }
  bool ok = saveWifiCreds(ssid, pass);
  if (ok)
  {
    server.send(200, "application/json", "{\"ok\":true,\"reboot\":true}");
    g_pendingRestart = true;
    g_restartAtMs = millis() + 1500;
  }
  else
  {
    server.send(500, "application/json", "{\"ok\":false}");
  }
}

// ===== Wi-Fi / NTP / mDNS / OTA =====

static void connectWiFi()
{
  Serial.printf("[TX] Connecting to SSID='%s' ...\n", g_wifiSsid.c_str());
  WiFi.mode(WIFI_STA);
  wifi_set_sleep_type(NONE_SLEEP_T); // keep radio responsive
  WiFi.persistent(false);
  WiFi.disconnect(true);
  delay(100);
  WiFi.hostname(HOSTNAME);
  WiFi.begin(g_wifiSsid.c_str(), g_wifiPass.c_str());

  uint32_t t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 15000)
  {
    Serial.print(".");
    delay(500);
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED)
  {
    g_apActive = false; // we’re on STA now
    Serial.printf("[TX] Wi-Fi OK. IP=%s  RSSI=%d dBm  CH=%d\n",
                  WiFi.localIP().toString().c_str(), WiFi.RSSI(), WiFi.channel());
  }
  else
  {
    Serial.println("[TX] Wi-Fi timeout; starting AP fallback so UI is reachable.");
    startApFallback(); // start AP right away
  }
}

static void setupTimeNTP()
{
  configTime(TZ_INFO, NTP_1, NTP_2);
  Serial.println("[TIME] Syncing NTP...");
  for (int i = 0; i < 30; i++)
  {
    time_t now = time(nullptr);
    if (now > 1700000000)
    {
      Serial.printf("[TIME] Synced: %lu\n", (unsigned long)now);
      return;
    }
    delay(500);
  }
  Serial.println("[TIME] NTP sync timeout; will continue without exact time.");
}

static void setupMDNS()
{
  if (WiFi.status() != WL_CONNECTED)
    return;
  for (int i = 0; i < 5; i++)
  {
    if (MDNS.begin(HOSTNAME))
    {
      MDNS.addService("http", "tcp", 80);
      Serial.printf("[MDNS] Started: http://%s.local/\n", HOSTNAME);
      return;
    }
    delay(500);
  }
  Serial.println("[MDNS] Failed to start mDNS");
}

static void setupOTA()
{
  if (WiFi.status() != WL_CONNECTED)
    return;
  ArduinoOTA.setHostname(HOSTNAME);
  if (OTA_PASS && OTA_PASS[0] != '\0')
    ArduinoOTA.setPassword(OTA_PASS);
  ArduinoOTA.onStart([]()
                     { String t = (ArduinoOTA.getCommand()==U_FLASH)?"sketch":"filesystem"; Serial.printf("[OTA] Start %s\n", t.c_str()); });
  ArduinoOTA.onEnd([]()
                   { Serial.println("\n[OTA] End"); });
  ArduinoOTA.onProgress([](unsigned int prog, unsigned int total)
                        { Serial.printf("[OTA] Progress: %u%%\n", (prog * 100) / total); });
  ArduinoOTA.onError([](ota_error_t err)
                     {
    Serial.printf("[OTA] Error[%u]: ", err);
    if (err==OTA_AUTH_ERROR) Serial.println("Auth Failed");
    else if (err==OTA_BEGIN_ERROR) Serial.println("Begin Failed");
    else if (err==OTA_CONNECT_ERROR) Serial.println("Connect Failed");
    else if (err==OTA_RECEIVE_ERROR) Serial.println("Receive Failed");
    else if (err==OTA_END_ERROR) Serial.println("End Failed"); });
  ArduinoOTA.begin();
  Serial.printf("[OTA] Ready: %s.local:8266 (auth:%s)\n", HOSTNAME, (OTA_PASS && OTA_PASS[0] ? "yes" : "no"));
}

// ===== Control helper =====
static float getActiveSetpoint() { return g_fixedEnabled ? g_fixedSetpoint : 19.0f; }

// ======== DS18B20 Robust Bring-Up (BEFORE Wi-Fi) ========
static uint8_t rom[8];
static bool onewire_find_any()
{
  oneWire.reset_search();
  if (!oneWire.search(rom))
    return false;
  return OneWire::crc8(rom, 7) == rom[7];
}

static uint16_t ds_tconv_ms()
{
  // 9/10/11/12-bit => ~94/188/375/750 ms
  uint8_t res = g_haveAddress ? sensors.getResolution(g_dsAddr) : sensors.getResolution();
  switch (res)
  {
  case 9:
    return 94;
  case 10:
    return 188;
  case 11:
    return 375;
  default:
    return 750;
  }
}

static void ds_init_bus_and_probe_pre_wifi()
{
  pinMode(ONE_WIRE_BUS, INPUT_PULLUP);
  delay(200);
  sensors.begin();
  sensors.setWaitForConversion(false); // <<< async conversions
  sensors.setResolution(12);           // (optional: 10 for faster)
  sensors.requestTemperatures();
  delay(10);
  bool found = onewire_find_any();
  if (!found)
  {
    Serial.println("[DS18B20] Raw search: no devices yet, retrying...");
    delay(200);
    found = onewire_find_any();
  }
  uint8_t count = sensors.getDeviceCount();
  Serial.printf("[DS18B20] Dallas count: %u  RawFound:%s\n", count, found ? "YES" : "NO");
  g_haveSensor = found || (count > 0);
  if (g_haveSensor)
  {
    g_haveAddress = sensors.getAddress(g_dsAddr, 0);
    if (g_haveAddress)
    {
      Serial.print("[DS18B20] Sensor[0] address: ");
      for (uint8_t i = 0; i < 8; i++)
        Serial.printf("%02X%s", g_dsAddr[i], (i < 7 ? ":" : ""));
      Serial.println();
      sensors.setResolution(g_dsAddr, 12);
    }
    else
    {
      Serial.println("[DS18B20] Using by-index mode until address resolves.");
    }
  }
  else
  {
    Serial.println("[DS18B20] No sensor found on D4. Will keep scanning in loop().");
  }
}

static bool ds_try_hotplug()
{
  sensors.requestTemperatures();
  delay(5);
  if (onewire_find_any())
  {
    g_haveSensor = true;
    g_haveAddress = sensors.getAddress(g_dsAddr, 0);
    if (g_haveAddress)
      sensors.setResolution(g_dsAddr, 12);
    Serial.println(g_haveAddress ? "[DS18B20] Sensor appeared — address mode." : "[DS18B20] Sensor appeared — index mode.");
    return true;
  }
  return false;
}

static bool ds_poll(float &outC)
{
  if (!g_haveSensor)
    return false;

  if (!g_dsPending)
  {
    sensors.requestTemperatures(); // start conversion
    g_dsReqAt = millis();
    g_dsPending = true;
    return false;
  }
  if ((uint32_t)(millis() - g_dsReqAt) < ds_tconv_ms())
    return false; // still converting (non-blocking)

  // ready to read without starting a new conversion
  float t = g_haveAddress ? sensors.getTempC(g_dsAddr) : sensors.getTempCByIndex(0);
  g_dsPending = false; // allow next kick
  if (t == DEVICE_DISCONNECTED_C || t < -55 || t > 125)
    return false;
  outC = t;
  return true;
}

// ===================== SETUP =====================
void setup()
{
  Serial.begin(115200);
  delay(200);
  if (!LittleFS.begin())
  {
    Serial.println("[FS] LittleFS mount failed, formatting...");
    LittleFS.format();
    LittleFS.begin();
  }
  loadFixedSetpoint();
  g_lastSavedSetpoint = g_fixedSetpoint;
  loadWifiCreds();
  initLegacySchedule();
  ds_init_bus_and_probe_pre_wifi();

  connectWiFi();
  setupTimeNTP();
  setupMDNS();
  setupOTA(); // enable OTA when Wi-Fi is ready

  // Web routes
  server.on("/", HTTP_GET, handleIndex);
  server.on("/wifi", HTTP_GET, handleWifiPage);
  server.on("/api/fixed", HTTP_GET, handleGetFixed);
  server.on("/api/fixed", HTTP_POST, handlePostFixed);
  server.on("/api/time", HTTP_GET, handleTime);
  server.on("/api/status", HTTP_GET, handleStatus);
  server.on("/api/owbus", HTTP_GET, handleOwBus);
  server.on("/api/wifi/scan", HTTP_GET, handleWifiScan);
  server.on("/api/wifi/current", HTTP_GET, handleWifiCurrent);
  server.on("/api/wifi/save", HTTP_POST, handleWifiSave);
  server.begin();
  Serial.println("[WEB] HTTP server started on port 80");

  // ESPNOW on AP channel
  int channel = WiFi.channel();
  if (channel <= 0)
  {
    channel = 1;
    Serial.println("[TX] Using fallback channel=1");
  }
  wifi_set_channel(channel);
  Serial.printf("[TX] Locked radio to channel %d\n", channel);
  int rc = esp_now_init();
  Serial.printf("[TX] esp_now_init -> %d\n", rc);
  if (rc != 0)
  {
    Serial.println("[TX] ESPNOW init failed; rebooting...");
    delay(1500);
    ESP.restart();
  }
  esp_now_set_self_role(ESP_NOW_ROLE_COMBO);
  esp_now_register_send_cb(onDataSent);
  esp_now_register_recv_cb(onDataRecv);

  rc = esp_now_add_peer(TARGET, ESP_NOW_ROLE_COMBO, channel, NULL, 0);
  Serial.print("[TX] add_peer(");
  printMac(TARGET);
  Serial.print(") -> ");
  Serial.println(rc);

  Serial.printf("[TX] STA MAC: %s\n", WiFi.macAddress().c_str());
  Serial.printf("[TX] Ready. Open http://%s.local or http://%s\n", HOSTNAME, WiFi.localIP().toString().c_str());
}

// ===================== LOOP =====================
// --- Strict 0.5 °C hysteresis (±0.25 °C): ON when temp < sp-0.25, OFF when temp > sp+0.25
static inline uint8_t apply_hysteresis(float temp, float sp, uint8_t prev)
{
  const float half = HYST_BAND_C * 0.5f; // 0.25
  const float on_th = sp - half;         // below => ON
  const float off_th = sp + half;        // above => OFF
  if (temp >= 19.8)
    return 0;
  if (temp < on_th)
    return 1; // strictly less
  if (temp > off_th)
    return 0; // strictly greater

  return prev; // inside band -> hold
}

void loop()
{
  // Service web server aggressively for snappy UI
  for (uint8_t i = 0; i < 3; ++i)
  {
    server.handleClient();
    yield();
  }

  // ===== Connectivity management (AP fallback + 2-minute STA retries) =====
  static bool prevSta = false;
  static uint32_t lastStaRetryMs = 0;

  bool sta = (WiFi.status() == WL_CONNECTED);

  // If STA just came up, (re)enable mDNS/OTA and mark AP inactive flag
  if (sta && !prevSta)
  {
    Serial.println("[WiFi] STA connected — re-initializing mDNS/OTA");
    setupMDNS();
    setupOTA();
    g_apActive = false; // flag only; you can WiFi.softAPdisconnect(true) if you want to shut AP
  }

  // If STA is down, ensure AP is available and retry STA every 120s (non-blocking)
  if (!sta)
  {
    if (!g_apActive)
    {
      Serial.println("[WiFi] STA down & AP not active -> starting AP fallback");
      startApFallback();
    }
    if (millis() - lastStaRetryMs >= 120000UL)
    { // every 2 minutes
      lastStaRetryMs = millis();
      Serial.println("[WiFi] STA down — retrying connection with saved credentials");
      WiFi.mode(WIFI_STA);
      wifi_set_sleep_type(NONE_SLEEP_T);
      WiFi.persistent(false);
      WiFi.disconnect(true); // clear old state
      delay(50);
      WiFi.hostname(HOSTNAME);
      WiFi.begin(g_wifiSsid.c_str(), g_wifiPass.c_str()); // async; no blocking wait
    }
  }

  // OTA & mDNS only when STA is up
  if (sta)
  {
    MDNS.update();
    ArduinoOTA.handle();
  }
  prevSta = sta;

  // Handle deferred reboot after saving Wi-Fi
  if (g_pendingRestart && (int32_t)(millis() - g_restartAtMs) >= 0)
  {
    Serial.println("[SYS] Rebooting to apply new Wi-Fi credentials...");
    delay(100);
    ESP.restart();
  }

  // ===== Sensor / Control / Reporting (non-blocking cadence) =====
  static uint32_t tCtl = 0;
  if (millis() - tCtl > 200)
  { // ~5 Hz
    tCtl = millis();

    // Hot-plug check
    if (!g_haveSensor)
    {
      (void)ds_try_hotplug();
    }

    // Non-blocking DS18B20 poll
    float freshC;
    bool gotFresh = ds_poll(freshC);
    if (gotFresh)
      g_lastTempC = freshC; // - 3.0f;

    // Decide action with strict hysteresis if we have any valid temperature
    const bool haveTemp = isfinite(g_lastTempC);
    float sp = getActiveSetpoint();
    uint8_t action = g_lastAction;

    if (haveTemp)
    {
      action = apply_hysteresis(g_lastTempC, sp, g_lastAction);
    }
    else
    {
      action = 0; // sensor invalid -> safe OFF
    }
    g_lastAction = action;

    // === ESP-NOW TX to relay: {"heater":"ON"/"OFF"} ===
    static long timerAction = millis();

    {
      static String azione = "OFF";
      static uint32_t onStartMs = 0;
      static bool forcedOff = false;
      static uint32_t forcedOffUntil = 0;

      if (millis() - timerAction > 60000)
      { // update every minute
        // --- Safety logic: auto OFF after 1 hour ON, cool down 30 minutes ---
        if (azione == "ON")
        {
          if (onStartMs == 0)
            onStartMs = millis(); // mark when ON started
          if (!forcedOff && millis() - onStartMs >= 3600000UL)
          { // 1 hour
            forcedOff = true;
            forcedOffUntil = millis() + 1800000UL; // 30 minutes OFF
            Serial.println("[SAFETY] Heater forced OFF for 30 minutes");
          }
        }
        else
        {
          onStartMs = 0; // reset ON timer when OFF
        }

        // If currently under forced OFF period
        if (forcedOff)
        {
          if (millis() >= forcedOffUntil)
          {
            forcedOff = false;
            Serial.println("[SAFETY] Forced OFF period ended, normal control resumed");
          }
          else
          {
            azione = "OFF"; // keep OFF during safety period
          }
        }
        else
        {
          azione = (action == 1) ? "ON" : "OFF";
        }

        timerAction = millis();
      }

      JsonDocument jtx;
      jtx["heater"] = azione;
      jtx["id"] = 12; // indirizzo della caldaia
      char buf[32];
      size_t n = serializeJson(jtx, buf, sizeof(buf));
      int rc = esp_now_send(TARGET, (uint8_t *)buf, (int)n);
      Serial.print("[TX] send -> ");
      Serial.println(rc == 0 ? "OK" : String(rc));
    }

    // === HTTPS report every 1.5s (min), use ACK if available ===
    if (haveTemp && (millis() - g_lastHttpMs >= HTTP_MIN_INTERVAL_MS))
    {
      bool heatingForReport = g_haveAck ? g_ackRelayOn : (action == 1);
      bool ok = cesanaReportAndFetch(g_lastTempC, heatingForReport); // <- capture result
      g_lastHttpMs = millis();

      // If we're in sleep mode and we were waiting for the remote -> we can sleep now
      if (sleepModeActive && sleepWaitingRemote && ok)
      {
        Serial.println("[SLEEP] Remote answered OK, going to deep sleep for 10 minutes...");
        ESP.deepSleep(1ULL * 60ULL * 1000000ULL); // 1 minutes
        delay(100);
      }
    }

    // Keep doing AP-availability check (cheap; pairs well with 2-min retry)
    static uint32_t lastApChk = 0;
    if (millis() - lastApChk > 2000)
    {
      lastApChk = millis();
      if (!sta && !g_apActive)
      {
        Serial.println("[WiFi] STA down & AP not active -> starting AP fallback (periodic check)");
        startApFallback();
      }
    }

    // ===== Power-saving mode based on setpoint =====
    float spNow = getActiveSetpoint();

    if (spNow <= 10.0f)
    {
      // enter / stay in sleep mode
      if (!sleepModeActive)
      {
        sleepModeActive = true;
        sleepWaitingRemote = true; // on this wake, wait for a good remote reply
        Serial.println("[SLEEP] Low setpoint -> wait for remote, then sleep 10 minutes");
      }
    }
    else
    {
      // leave sleep mode
      if (sleepModeActive)
      {
        Serial.println("[SLEEP] Setpoint > 10 -> staying active");
      }
      sleepModeActive = false;
      sleepWaitingRemote = false;
    }
  }
}
