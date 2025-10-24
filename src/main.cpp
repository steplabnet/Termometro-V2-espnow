// src/main.cpp — Wemos D1 mini (ESP8266)
// Fixed setpoint thermostat + modern Wi-Fi setup page (scan, select, save)
// - Thermostat UI at "/" (blue/green theme) with presets and +/-
// - Wi-Fi setup at "/wifi": scans nearby SSIDs, allows selection + password
// - Credentials stored in LittleFS (/wifi.json). After saving, device reboots.
// - Uses fixed setpoint for control logic (temp < setpoint => action=1)

#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <espnow.h>
#include <ArduinoJson.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <LittleFS.h>
#include <ESP8266WebServer.h>
#include <ESP8266mDNS.h>
#include <time.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>

extern "C"
{
#include "user_interface.h"
}

// ===== Default Wi-Fi credentials (fallback only) =====
static const char *WIFI_SSID_DEFAULT = "NETGEAR11";
static const char *WIFI_PASS_DEFAULT = "breezypiano838";
static const char *HOSTNAME = "esp-thermo";

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

// ===== ESP-NOW target (broadcast by default) =====
static uint8_t TARGET[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};

// ===== Fixed setpoint state (persisted) =====
static float g_fixedSetpoint = 19.0f; // default: "on" preset
static String g_fixedPreset = "on";   // "off" | "on" | "away" | "custom"
static bool g_fixedEnabled = true;    // always use fixed setpoint for control

// ===== Live telemetry for web UI =====
volatile float g_lastTempC = NAN;
volatile uint8_t g_lastAction = 0; // 1=heat ON, 0=OFF

// ===== Remote "cesana" reporting (HTTPS GET) =====
static uint32_t g_lastHttpMs = 0;
static const uint32_t HTTP_MIN_INTERVAL_MS = 1500;
static bool g_remoteOk = false;
static float g_remoteSetpoint = NAN;
static String g_remoteMode = "";
static float g_remoteActual = NAN;
static bool g_remoteHeating = false;
static float g_remoteDelta = NAN;

// ===== Web server =====
ESP8266WebServer server(80);

// Pending reboot after saving Wi-Fi
static bool g_pendingRestart = false;
static uint32_t g_restartAtMs = 0;

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

// ======== Thermostat HTML (blue/green theme) at "/" ========
const char INDEX_HTML[] PROGMEM = R"HTML(
<!doctype html><html lang="en"><head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>ESP8266 Thermostat</title>
<style>
:root{
  --bg:#f4fbfd; --card:#ffffff; --ink:#0b3440; --muted:#4d7580;
  --accent:#1aa6b7; --accent-2:#36d1b1; --border:#d7eef2;
  --ok:#1b9e77; --warn:#ffb703; --err:#c1121f;
}
*{box-sizing:border-box} html,body{height:100%}
body{margin:0;background:linear-gradient(180deg,#f4fbfd 0%,#e8f7fa 100%);
  color:var(--ink); font:16px ui-sans-serif,system-ui,Segoe UI,Roboto,Arial; display:grid; place-items:center; padding:18px;}
.app{width:min(680px,100%); background:var(--card); border:1px solid var(--border);
  border-radius:18px; box-shadow:0 8px 30px rgba(26,166,183,.15); overflow:hidden;}
.header{display:flex;justify-content:space-between;align-items:center; padding:14px 16px; background:
  linear-gradient(180deg,#e9fbff,#d9f5f7); border-bottom:1px solid var(--border)}
.title{font-weight:800; letter-spacing:.2px}
.badges{display:flex; gap:8px; flex-wrap:wrap}
.badge{font:12px/1 ui-monospace,Consolas; color:var(--ink); background:#eefbfd; border:1px solid var(--border);
  padding:6px 8px; border-radius:999px}
.nav{display:flex; gap:8px}
.nav a{color:#055968; text-decoration:none; font-weight:800; padding:6px 10px; border:1px solid var(--border); border-radius:10px; background:#f1fdff}
.content{padding:18px; display:grid; gap:14px}
.card{border:1px solid var(--border); border-radius:14px; padding:16px; background:linear-gradient(180deg,#ffffff,#f7fffe)}
.row{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap}
.kpi{display:flex; align-items:baseline; gap:10px}
.kpi .label{color:var(--muted); font-size:14px}
.kpi .value{font-size:40px; font-weight:900}
.controls{display:flex; align-items:center; gap:10px}
.btn{border:1px solid var(--border); background:linear-gradient(180deg,#faffff,#e9fffb);
  color:var(--ink); padding:10px 14px; border-radius:12px; cursor:pointer; font-weight:700; min-width:44px;
  transition:transform .05s ease, box-shadow .15s ease}
.btn:hover{box-shadow:0 3px 10px rgba(54,209,177,.15)}
.btn:active{transform:translateY(1px)}
.btn.primary{background:linear-gradient(180deg,#bff6ec,#8df0dc); border-color:#8de9d8}
.btn.pill{border-radius:999px}
.presetbar{display:flex; gap:10px; flex-wrap:wrap}
.preset{padding:10px 14px; border-radius:999px; border:1px solid var(--border); background:#f7fffe; cursor:pointer; font-weight:700}
.preset.active{outline:2px solid var(--accent); box-shadow:0 0 0 3px rgba(26,166,183,.15) inset}
.hint{font-size:13px; color:var(--muted)}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px; vertical-align:middle}
.on{background:var(--ok)} .off{background:#9aaeb5}
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
      <div class="badge" id="time">--</div>
    </div>
  </div>
  <div class="content">
    <div class="card row">
      <div class="kpi"><div class="label">Actual</div><div class="value" id="actual">--.-°C</div></div>
      <div class="kpi"><div class="label">Setpoint</div><div class="value" id="sp">--.-°C</div></div>
    </div>

    <div class="card row">
      <div class="controls">
        <button class="btn pill" id="minus">−</button>
        <button class="btn pill" id="plus">+</button>
        <button class="btn primary pill" id="save">Save</button>
      </div>
      <div class="presetbar">
        <button class="preset" data-name="off"  data-val="10">Off · 10°C</button>
        <button class="preset" data-name="on"   data-val="19">On · 19°C</button>
        <button class="preset" data-name="away" data-val="15">Away · 15°C</button>
        <span class="hint" id="state">—</span>
      </div>
    </div>
  </div>
</div>

<script>
let sp = 19.0;
let preset = 'on';

function fmt(v){ return Number(v).toFixed(1) + '°C'; }
function setActivePreset(name){
  document.querySelectorAll('.preset').forEach(b=>{
    b.classList.toggle('active', b.dataset.name===name);
  });
}

async function loadFixed(){
  try{
    const r = await fetch('/api/fixed');
    const j = await r.json();
    sp = typeof j.setpoint === 'number' ? j.setpoint : 19.0;
    preset = j.preset || 'custom';
    document.getElementById('sp').textContent = fmt(sp);
    setActivePreset(preset);
    document.getElementById('state').textContent = 'Preset: ' + preset;
  }catch(e){}
}

async function saveFixed(newPreset){
  try{
    const body = newPreset ? { preset:newPreset } : { setpoint: sp, preset:'custom' };
    const r = await fetch('/api/fixed',{method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
    const j = await r.json();
    sp = j.setpoint; preset = j.preset || 'custom';
    document.getElementById('sp').textContent = fmt(sp);
    setActivePreset(preset);
    document.getElementById('state').textContent = 'Saved · Preset: ' + preset;
  }catch(e){
    document.getElementById('state').textContent = 'Save failed';
  }
}

async function tick(){
  try{
    const r = await fetch('/api/status');
    const j = await r.json();
    if (typeof j.temp === 'number') document.getElementById('actual').textContent = fmt(j.temp);
    if (typeof j.setpoint === 'number'){
      sp = j.setpoint;
      document.getElementById('sp').textContent = fmt(sp);
    }
    const on = j.action===1;
    document.getElementById('heatText').textContent = 'Heat: ' + (on?'ON':'OFF');
    document.getElementById('heatDot').className = 'dot ' + (on?'on':'off');
    if (typeof j.epoch === 'number'){
      const d=new Date(j.epoch*1000);
      document.getElementById('time').textContent=d.toLocaleString();
    }
  }catch(e){}
}

document.getElementById('minus').onclick = ()=>{ sp = Math.max(5, Math.round((sp-0.5)*10)/10); document.getElementById('sp').textContent = fmt(sp); setActivePreset('custom'); };
document.getElementById('plus').onclick  = ()=>{ sp = Math.min(35, Math.round((sp+0.5)*10)/10); document.getElementById('sp').textContent = fmt(sp); setActivePreset('custom'); };
document.getElementById('save').onclick  = ()=> saveFixed(null);
document.querySelectorAll('.preset').forEach(b=>{
  b.onclick = ()=> saveFixed(b.dataset.name);
});

loadFixed();
tick();
setInterval(tick, 1000);
</script>
</body></html>
)HTML";

// ======== Wi-Fi Setup HTML at "/wifi" ========
const char WIFI_HTML[] PROGMEM = R"HTML(
<!doctype html><html lang="en"><head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Wi-Fi Setup</title>
<style>
:root{
  --bg:#f4fbfd; --card:#ffffff; --ink:#0b3440; --muted:#4d7580;
  --accent:#1aa6b7; --accent-2:#36d1b1; --border:#d7eef2; --ok:#1b9e77; --err:#c1121f;
}
*{box-sizing:border-box} body{margin:0;background:linear-gradient(180deg,#f4fbfd 0%,#e8f7fa 100%);color:var(--ink);
  font:16px ui-sans-serif,system-ui,Segoe UI,Roboto,Arial;display:grid;place-items:center;min-height:100vh;padding:18px}
.app{width:min(720px,100%); background:var(--card); border:1px solid var(--border); border-radius:18px;
  box-shadow:0 8px 30px rgba(26,166,183,.15); overflow:hidden}
.header{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;background:linear-gradient(180deg,#e9fbff,#d9f5f7); border-bottom:1px solid var(--border)}
.title{font-weight:800}
.nav a{color:#055968;text-decoration:none;font-weight:800;padding:6px 10px;border:1px solid var(--border);border-radius:10px;background:#f1fdff}
.content{padding:18px; display:grid; gap:16px}
.card{border:1px solid var(--border); border-radius:14px; padding:16px; background:linear-gradient(180deg,#ffffff,#f7fffe)}
.row{display:grid; grid-template-columns:140px 1fr; gap:12px; align-items:center}
select,input{border:1px solid var(--border); border-radius:12px; padding:10px; background:#fbffff}
.btn{border:1px solid var(--border); background:linear-gradient(180deg,#faffff,#e9fffb); color:var(--ink); padding:10px 14px; border-radius:12px; cursor:pointer; font-weight:800}
.btn.primary{background:linear-gradient(180deg,#bff6ec,#8df0dc); border-color:#8de9d8}
.kv{display:flex; gap:8px; flex-wrap:wrap; color:var(--muted); font-size:14px}
.badge{border:1px solid var(--border); border-radius:999px; padding:6px 10px; background:#eefbfd}
.msg{font-size:14px}
.ok{color:var(--ok)} .err{color:var(--err)}
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
        <div style="display:flex;gap:8px;align-items:center">
          <select id="ssid" style="min-width:260px"></select>
          <button class="btn" id="refresh">Refresh</button>
        </div>
      </div>
      <div class="row">
        <label for="pass">Password</label>
        <input id="pass" type="password" placeholder="Enter Wi-Fi password"/>
      </div>
      <div class="row">
        <div></div>
        <div style="display:flex;gap:8px;align-items:center">
          <button class="btn primary" id="save">Save & Reboot</button>
          <span class="msg" id="msg"></span>
        </div>
      </div>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center">
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
  }catch(e){
    sel.innerHTML='<option>Scan failed</option>';
  }
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
  }catch(e){
    m.textContent='Save failed'; m.className='msg err';
  }
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
float setpoints[7][24]; // retained for compatibility; not used for control now
static void initLegacySchedule()
{
  for (int d = 0; d < 7; ++d)
    for (int h = 0; h < 24; ++h)
      setpoints[d][h] = g_fixedSetpoint;
}

// ===== HTTPS GET to cesana.steplab.net =====
static bool cesanaReportAndFetch(float tempC, bool heating)
{
  if (WiFi.status() != WL_CONNECTED)
  {
    Serial.println("[HTTP] Skipped: WiFi not connected");
    return false;
  }
  String url = "https://cesana.steplab.net/get_setpoint.php?temp=";
  url += String(tempC, 1);
  url += "&cald=";
  url += heating ? "1" : "0";

  std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
  client->setInsecure();

  HTTPClient https;
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
                g_remoteOk ? "true" : "false", g_remoteMode.c_str(),
                g_remoteSetpoint, g_remoteActual,
                g_remoteHeating ? "ON" : "OFF",
                (isnan(g_remoteDelta) ? NAN : g_remoteDelta));
  return g_remoteOk;
}

// ===== Web handlers =====
void handleIndex() { server.send_P(200, "text/html", INDEX_HTML); }
void handleWifiPage() { server.send_P(200, "text/html", WIFI_HTML); }

// Fixed setpoint APIs
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

  JsonDocument in; // <-- rename to avoid redeclaration
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
      g_fixedSetpoint = 10.0f;
    else if (p == "on")
      g_fixedSetpoint = 19.0f;
    else if (p == "away")
      g_fixedSetpoint = 15.0f;
    else
      return false;
    g_fixedPreset = p;
    g_fixedEnabled = true;
    return true;
  };

  if (preset.length() && preset != "custom")
  {
    changed = applyPreset(preset);
  }
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

  JsonDocument out; // <-- declare this 'out'
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

  JsonDocument doc;
  doc["epoch"] = (uint32_t)now;
  if (isnan(g_lastTempC))
    doc["temp"] = nullptr;
  else
    doc["temp"] = g_lastTempC;
  doc["setpoint"] = sp;
  doc["preset"] = g_fixedPreset;
  doc["action"] = g_lastAction;

  // Remote info
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
  JsonArray arr = doc["devices"].to<JsonArray>(); // v7 style
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
    {
      arr.add(nullptr);
    }
  }
  doc["parasite"] = sensors.isParasitePowerMode();
  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}

// ===== Wi-Fi API handlers =====
void handleWifiScan()
{
  int n = WiFi.scanNetworks(/*async=*/false, /*hidden=*/true);

  JsonDocument doc;
  JsonArray arr = doc["networks"].to<JsonArray>(); // v7 style
  for (int i = 0; i < n; ++i)
  {
    JsonObject o = arr.add<JsonObject>(); // v7 style
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

// ===== Wi-Fi / NTP / mDNS =====
static void connectWiFi()
{
  Serial.printf("[TX] Connecting to SSID='%s' ...\n", g_wifiSsid.c_str());
  WiFi.mode(WIFI_STA);
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
    Serial.printf("[TX] WiFi OK. IP=%s  RSSI=%d dBm  CH=%d\n",
                  WiFi.localIP().toString().c_str(), WiFi.RSSI(), WiFi.channel());
  }
  else
  {
    Serial.println("[TX] WiFi timeout; UI will be unreachable until connected.");
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

// ===== Control helper =====
static float getActiveSetpoint() { return g_fixedEnabled ? g_fixedSetpoint : 19.0f; }

// ======== DS18B20 Robust Bring-Up (BEFORE WiFi) ========
static uint8_t rom[8];
static bool onewire_find_any()
{
  oneWire.reset_search();
  if (!oneWire.search(rom))
    return false;
  return OneWire::crc8(rom, 7) == rom[7];
}

static void ds_init_bus_and_probe_pre_wifi()
{
  pinMode(ONE_WIRE_BUS, INPUT_PULLUP);
  delay(200);
  sensors.begin();
  sensors.setWaitForConversion(true);
  sensors.setResolution(12);
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

static float ds_read_c()
{
  sensors.requestTemperatures();
  float t = g_haveAddress ? sensors.getTempC(g_dsAddr) : sensors.getTempCByIndex(0);
  if (t == DEVICE_DISCONNECTED_C || t < -55 || t > 125)
    return NAN;
  return t;
}

// ===================== SETUP =====================
void setup()
{
  Serial.begin(115200);
  delay(200);

  // FS
  if (!LittleFS.begin())
  {
    Serial.println("[FS] LittleFS mount failed, formatting...");
    LittleFS.format();
    LittleFS.begin();
  }

  // Load fixed setpoint + Wi-Fi creds + init legacy schedule
  loadFixedSetpoint();
  loadWifiCreds();
  initLegacySchedule();

  // Probe 1-Wire BEFORE WiFi
  ds_init_bus_and_probe_pre_wifi();

  // Wi-Fi + Time + mDNS
  connectWiFi();
  setupTimeNTP();
  setupMDNS();

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
  rc = esp_now_add_peer(TARGET, ESP_NOW_ROLE_COMBO, channel, NULL, 0);
  Serial.print("[TX] add_peer(");
  printMac(TARGET);
  Serial.print(") -> ");
  Serial.println(rc);

  Serial.printf("[TX] STA MAC: %s\n", WiFi.macAddress().c_str());
  Serial.printf("[TX] Ready. Open http://%s.local or http://%s\n", HOSTNAME, WiFi.localIP().toString().c_str());
}

// ===================== LOOP =====================
void loop()
{
  MDNS.update();
  server.handleClient();

  // Handle deferred reboot after saving Wi-Fi
  if (g_pendingRestart && (int32_t)(millis() - g_restartAtMs) >= 0)
  {
    Serial.println("[SYS] Rebooting to apply new Wi-Fi credentials...");
    delay(100);
    ESP.restart();
  }

  static uint32_t counter = 0;

  // --- Temperature read cycle (every ~1s)
  static uint32_t tRead = 0;
  if (millis() - tRead > 1000)
  {
    tRead = millis();

    float tempC = NAN;

    if (!g_haveSensor)
    {
      if (!ds_try_hotplug())
      {
        Serial.println("[DS18B20] Still no sensor on bus.");
      }
    }

    if (g_haveSensor)
    {
      tempC = ds_read_c();
      if (isnan(tempC))
        Serial.println("[DS18B20] Read failed (disconnected/out of range).");
    }

    bool valid = !isnan(tempC);

    // Determine active setpoint (fixed)
    float sp = getActiveSetpoint();
    uint8_t action = 0;
    if (valid)
      action = (tempC < sp) ? 1 : 0;

    // Keep latest for web UI
    if (valid)
      g_lastTempC = tempC;
    g_lastAction = action;

    // Report to remote (rate-limited) if we have valid temp
    if (valid && (millis() - g_lastHttpMs >= HTTP_MIN_INTERVAL_MS))
    {
      cesanaReportAndFetch(tempC, action == 1);
      g_lastHttpMs = millis();
    }

    // ESP-NOW telemetry (every ~1s)
    // ...
    JsonDocument doc;
    doc["type"] = "telemetry";
    doc["count"] = counter++;
    if (valid)
      doc["temp"] = tempC;
    else
      doc["temp"] = nullptr;
    doc["setpoint"] = sp;
    doc["action"] = action;
    doc["preset"] = g_fixedPreset;
    doc["note"] = "d1mini-ds18b20@D4";
    // ...

    char buf[256];
    size_t n = serializeJson(doc, buf, sizeof(buf));
    uint8_t rc = esp_now_send(TARGET, (uint8_t *)buf, (int)n);
    Serial.print("[TX] send -> ");
    Serial.println(rc == 0 ? "OK" : String(rc));
  }
}
