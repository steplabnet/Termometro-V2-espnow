// src/main.cpp — Wemos D1 mini (ESP8266)
// Wi‑Fi + ESP‑NOW JSON sender + DS18B20 + Modern Local Web UI for 7×24 setpoints
// - Connects to your Wi‑Fi (NETGEAR11)
// - Locks ESP‑NOW to AP channel
// - Reads DS18B20 on D5 (GPIO14)
// - Serves a modern local webpage to edit per‑hour setpoints for each weekday
// - Buttons: Set entire day's temperature, Copy a day's settings to another
// - Shows live device date/time on page
// - Persists setpoints to LittleFS (/setpoints.json)
// - Sends ESP‑NOW JSON with "action" = 1 if temp < setpoint (at current local hour/day), else 0

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

extern "C"
{
#include "user_interface.h"
}

// ===== Wi‑Fi credentials (change if needed) =====
static const char *WIFI_SSID = "NETGEAR11";
static const char *WIFI_PASS = "breezypiano838";
// Hostname for DHCP + mDNS (.local)
static const char *HOSTNAME = "esp-thermo";

// ===== Timezone / NTP =====
// Europe/Rome (CET/CEST): TZ string below handles DST automatically
static const char *TZ_INFO = "CET-1CEST,M3.5.0,M10.5.0/3"; // IANA-like TZ rule
static const char *NTP_1 = "pool.ntp.org";
static const char *NTP_2 = "time.google.com";

// ===== DS18B20 on D4 (GPIO14) =====
#define ONE_WIRE_BUS D4 // termometro
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);

// ===== ESP‑NOW target
static uint8_t TARGET[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
// After receiver works, change to unicast:
// static uint8_t TARGET[6] = { 0x84,0xF3,0xEB,0xAA,0xBB,0xCC };

// ===== 7×24 setpoints (°C). Index: day [0..6]=Sun..Sat, hour [0..23]
float setpoints[7][24];

// ===== Web server
ESP8266WebServer server(80);

// ===== Utilities =====
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

// ===== Modern HTML UI (embedded) =====
const char INDEX_HTML[] PROGMEM = R"HTML(
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>ESP8266 Scheduler</title>
<style>
:root{ --bg:#f6f9ff; --card:#ffffff; --muted:#3c5a82; --accent:#2d6cdf; --accent-2:#4e89ff; --border:#d8e3f8; --ok:#1e9e4a; --err:#c62828; }
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:#0b274d;font:14px ui-sans-serif,system-ui,Segoe UI,Roboto,Arial}
.header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);position:sticky;top:0;background:linear-gradient(180deg,#f6f9ff,#eef4ff)}
.hleft{display:flex;gap:12px;align-items:center}
.title{font-size:18px;font-weight:700;color:#0b274d}
.time{font:12px/1.2 ui-monospace,Consolas;color:#476ea6}
.toolbar{display:flex;gap:8px;flex-wrap:wrap}
.btn{border:1px solid var(--border);background:var(--card);color:#0b274d;padding:8px 12px;border-radius:10px;cursor:pointer;box-shadow:0 1px 1px rgba(45,108,223,.08)}
.btn:hover{border-color:var(--accent)}
.btn.primary{background:linear-gradient(180deg,#e6efff,#d8e6ff);border-color:#b8cffb}
.btn.ghost{background:transparent}
.status{margin-left:8px;font-weight:600}
.container{padding:16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:12px}
.tablewrap{overflow:auto;max-height:70vh}
 table{border-collapse:collapse;width:100%;min-width:980px}
 th,td{border:1px solid var(--border);padding:6px;text-align:center}
 th{position:sticky;top:0;background:#eaf1ff}
 th.day{position:sticky;left:0;background:#eaf1ff;z-index:1}
 input[type=number]{width:5em;background:#f5f9ff;border:1px solid var(--border);color:#0b274d;border-radius:8px;padding:6px;text-align:right}
 td.now{background:#daf5e3 !important; box-shadow: inset 0 0 0 2px #25a244}
 .rowtools{display:flex;gap:6px;justify-content:center}
 .hint{color:#476ea6;font-size:12px;margin-top:8px}
</style>
</head>
<body>
  <div class="header">
    <div class="hleft">
      <div class="title">ESP8266 Weekly Setpoints</div>
      <div class="time"><span id="now">--</span></div>
    </div>
    <div class="toolbar">
      <button class="btn" id="load">Load</button>
      <button class="btn primary" id="save">Save</button>
      <button class="btn" id="setDay">Set whole day…</button>
      <button class="btn" id="copyDay">Copy day → day…</button>
      <span id="msg" class="status"></span>
    </div>
  </div>

  <div class="container">
    <div class="card">
      <div class="tablewrap"><table id="grid"></table></div>
      <div class="hint">Tip: Click “Set whole day…” to assign one temperature to all 24 hours of a chosen day. Use “Copy day → day…” to duplicate a day’s schedule.</div>
    </div>
  </div>
<script>
const days=["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];
const hours=Array.from({length:24},(_,h)=>h);
const grid=document.getElementById('grid');
const msgEl=document.getElementById('msg');

function setMessage(text,ok){msgEl.textContent=text; msgEl.style.color= ok? 'var(--ok)':'var(--err)';}

function buildTable(){
  const thead=document.createElement('thead');
  const trH=document.createElement('tr');
  trH.appendChild(document.createElement('th')).textContent='Hour/Day';
  // Days as columns
  days.forEach(d=>{const th=document.createElement('th'); th.textContent=d; trH.appendChild(th)});
  thead.appendChild(trH);

  const tbody=document.createElement('tbody');
  // Hours as rows
  hours.forEach((h)=>{
    const tr=document.createElement('tr');
    const th=document.createElement('th'); th.className='hour'; th.textContent=h; tr.appendChild(th);
    // For each day column create an input cell
    days.forEach((d,di)=>{
      const td=document.createElement('td');
      const inp=document.createElement('input');
      inp.type='number'; inp.step='0.1'; inp.min='5'; inp.max='35'; inp.value='21.0';
      inp.dataset.day=di; inp.dataset.hour=h;
      td.appendChild(inp); tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });

  grid.innerHTML=''; grid.appendChild(thead); grid.appendChild(tbody);
}

async function load(){
  try{const r=await fetch('/api/setpoints'); if(!r.ok) throw new Error('HTTP '+r.status);
    const j=await r.json();
    (j.grid||[]).forEach((row,di)=> row.forEach((v,hi)=>{
      const inp=document.querySelector(`input[data-day="${di}"][data-hour="${hi}"]`);
      if(inp) inp.value=Number(v).toFixed(1);
    }));
    setMessage('Loaded',true);
  }catch(e){setMessage('Load failed: '+e.message,false)}
}

async function save(){
  const gridData=[];
  for(let di=0;di<7;di++){
    const row=[]; for(let hi=0;hi<24;hi++){
      const inp=document.querySelector(`input[data-day="${di}"][data-hour="${hi}"]`);
      row.push(parseFloat(inp.value));
    } gridData.push(row);
  }
  try{
    const r=await fetch('/api/setpoints',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({grid:gridData})});
    if(!r.ok) throw new Error('HTTP '+r.status);
    setMessage('Saved',true);
  }catch(e){setMessage('Save failed: '+e.message,false)}
}

function setWholeDay(){
  const d=prompt('Which day index? 0=Sun .. 6=Sat'); if(d===null) return; const di=parseInt(d);
  if(!(di>=0&&di<7)) return alert('Day must be 0..6');
  const t=prompt('Temperature (°C) for all 24 hours', '21.0'); if(t===null) return; const val=parseFloat(t);
  for(let hi=0;hi<24;hi++){
    const inp=document.querySelector(`input[data-day="${di}"][data-hour="${hi}"]`);
    if(inp) inp.value=val.toFixed(1);
  }
}

function copyDay(){
  const from=prompt('Copy FROM day index? 0=Sun .. 6=Sat'); if(from===null) return; const a=parseInt(from);
  const to=prompt('Copy TO day index? 0=Sun .. 6=Sat'); if(to===null) return; const b=parseInt(to);
  if(!(a>=0&&a<7&&b>=0&&b<7)) return alert('Indices must be 0..6');
  for(let hi=0;hi<24;hi++){
    const src=document.querySelector(`input[data-day="${a}"][data-hour="${hi}"]`);
    const dst=document.querySelector(`input[data-day="${b}"][data-hour="${hi}"]`);
    if(src&&dst) dst.value=src.value;
  }
}

async function tickTime(){
  try{
    const r=await fetch('/api/time'); if(!r.ok) throw new Error(); const j=await r.json();
    const d=new Date(j.epoch*1000);
    document.getElementById('now').textContent=d.toLocaleString();
    // Highlight current day/hour cell
    const day=d.getDay();
    const hour=d.getHours();
    document.querySelectorAll('td.now').forEach(td=>td.classList.remove('now'));
    const inp=document.querySelector(`input[data-day="${day}"][data-hour="${hour}"]`);
    if(inp && inp.parentElement) inp.parentElement.classList.add('now');
  }catch(e){ document.getElementById('now').textContent='--'; }
}

// init
buildTable();
load();
setInterval(tickTime, 1000);

document.getElementById('load').onclick=load;
document.getElementById('save').onclick=save;
document.getElementById('setDay').onclick=setWholeDay;
document.getElementById('copyDay').onclick=copyDay;
</script>

</body></html>
)HTML";

// ===== Setpoints persistence =====
void loadSetpoints()
{
  for (int d = 0; d < 7; ++d)
    for (int h = 0; h < 24; ++h)
      setpoints[d][h] = 21.0f;
  if (!LittleFS.exists("/setpoints.json"))
    return;
  File f = LittleFS.open("/setpoints.json", "r");
  if (!f)
    return;
  JsonDocument doc;
  DeserializationError e = deserializeJson(doc, f);
  f.close();
  if (e)
  {
    Serial.print("[FS] JSON load error: ");
    Serial.println(e.c_str());
    return;
  }
  JsonArray outer = doc["grid"].as<JsonArray>();
  if (outer.size() != 7)
    return;
  for (int d = 0; d < 7; ++d)
  {
    JsonArray row = outer[d].as<JsonArray>();
    if (row.size() != 24)
      continue;
    for (int h = 0; h < 24; ++h)
      setpoints[d][h] = row[h].as<float>();
  }
  Serial.println("[FS] Setpoints loaded");
}

bool saveSetpoints()
{
  JsonDocument doc;
  JsonArray outer = doc["grid"].to<JsonArray>();
  for (int d = 0; d < 7; ++d)
  {
    JsonArray row = outer.add<JsonArray>();
    for (int h = 0; h < 24; ++h)
      row.add(setpoints[d][h]);
  }
  File f = LittleFS.open("/setpoints.json", "w");
  if (!f)
  {
    Serial.println("[FS] open write failed");
    return false;
  }
  bool ok = (serializeJson(doc, f) > 0);
  f.close();
  Serial.println(ok ? "[FS] Setpoints saved" : "[FS] Save failed");
  return ok;
}

// ===== Web handlers =====
void handleIndex() { server.send_P(200, "text/html", INDEX_HTML); }

void handleGetSetpoints()
{
  JsonDocument doc;
  JsonArray outer = doc["grid"].to<JsonArray>();
  for (int d = 0; d < 7; ++d)
  {
    JsonArray row = outer.add<JsonArray>();
    for (int h = 0; h < 24; ++h)
      row.add(setpoints[d][h]);
  }
  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}

void handlePostSetpoints()
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
  JsonArray outer = doc["grid"].as<JsonArray>();
  if (outer.size() != 7)
  {
    server.send(422, "text/plain", "grid must be 7 arrays");
    return;
  }
  for (int d = 0; d < 7; ++d)
  {
    JsonArray row = outer[d].as<JsonArray>();
    if (row.size() != 24)
    {
      server.send(422, "text/plain", "each day needs 24 values");
      return;
    }
    for (int h = 0; h < 24; ++h)
      setpoints[d][h] = row[h].as<float>();
  }
  bool ok = saveSetpoints();
  server.send(ok ? 200 : 500, "application/json", ok ? "{\"ok\":true}" : "{\"ok\":false}");
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

// ===== Wi‑Fi / NTP / mDNS =====
static void connectWiFi()
{
  Serial.print("[TX] Connecting to ");
  Serial.print(WIFI_SSID);
  Serial.println(" ...");
  WiFi.mode(WIFI_STA);
  WiFi.persistent(false);
  WiFi.disconnect(true);
  delay(100);
  // Set hostname before connecting
  WiFi.hostname(HOSTNAME);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  uint32_t t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 15000)
  {
    Serial.print(".");
    delay(500);
  }
  Serial.println();
  if (WiFi.status() == WL_CONNECTED)
  {
    Serial.print("[TX] WiFi OK. IP=");
    Serial.print(WiFi.localIP());
    Serial.print("  RSSI=");
    Serial.print(WiFi.RSSI());
    Serial.print(" dBm  CH=");
    Serial.println(WiFi.channel());
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
  { // up to ~30s
    time_t now = time(nullptr);
    if (now > 1700000000)
    { // sanity (2023+)
      Serial.print("[TIME] Synced: ");
      Serial.println((unsigned long)now);
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

  // Try a few times in case Wi-Fi just came up
  for (int i = 0; i < 5; i++)
  {
    if (MDNS.begin(HOSTNAME))
    {
      MDNS.addService("http", "tcp", 80);
      Serial.print("[MDNS] Started: http://");
      Serial.print(HOSTNAME);
      Serial.println(".local/");
      return;
    }
    delay(500);
  }
  Serial.println("[MDNS] Failed to start mDNS");
}

// ===== Helpers =====
static float getActiveSetpoint()
{
  time_t now = time(nullptr);
  struct tm lt;
  localtime_r(&now, &lt);
  int d = lt.tm_wday; // 0..6 (Sun..Sat)
  int h = lt.tm_hour; // 0..23
  if (d < 0 || d > 6 || h < 0 || h > 23)
    return 21.0f;
  return setpoints[d][h];
}

void setup()
{
  Serial.begin(115200);
  delay(200);

  // Start FS
  if (!LittleFS.begin())
  {
    Serial.println("[FS] LittleFS mount failed, formatting...");
    LittleFS.format();
    LittleFS.begin();
  }
  loadSetpoints();

  // DS18B20 init
  sensors.begin();
  sensors.setResolution(12);
  Serial.println("[DS18B20] Started on D5");

  // Wi‑Fi + Time + mDNS
  connectWiFi();
  setupTimeNTP();
  setupMDNS();

  // Web server routes
  server.on("/", HTTP_GET, handleIndex);
  server.on("/api/setpoints", HTTP_GET, handleGetSetpoints);
  server.on("/api/setpoints", HTTP_POST, handlePostSetpoints);
  server.on("/api/time", HTTP_GET, handleTime);
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
  Serial.print("[TX] Locked radio to channel ");
  Serial.println(channel);
  int rc = esp_now_init();
  Serial.print("[TX] esp_now_init -> ");
  Serial.println(rc);
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

  Serial.print("[TX] STA MAC: ");
  Serial.println(WiFi.macAddress());
  Serial.print("[TX] Ready. Open http://");
  Serial.print(HOSTNAME);
  Serial.print(".local or http://");
  Serial.println(WiFi.localIP());
}

void loop()
{
  MDNS.update();
  static uint32_t counter = 0;
  server.handleClient(); // service HTTP

  // Read temperature periodically
  static uint32_t tRead = 0;
  if (millis() - tRead > 1000)
  {
    tRead = millis();
    sensors.requestTemperatures();
  }
  float tempC = sensors.getTempCByIndex(0);
  bool valid = (tempC != DEVICE_DISCONNECTED_C && tempC > -100 && tempC < 125);

  // Determine active setpoint based on local day/hour
  float sp = getActiveSetpoint();
  uint8_t action = 0; // default off
  if (valid)
    action = (tempC < sp) ? 1 : 0;

  // JSON telemetry (every 1s)
  static uint32_t tSend = 0;
  if (millis() - tSend > 1000)
  {
    tSend = millis();
    JsonDocument doc;
    doc["type"] = "telemetry";
    doc["count"] = counter;
    if (valid)
      doc["temp"] = tempC;
    else
      doc["temp"] = nullptr;
    doc["setpoint"] = sp;
    doc["action"] = action; // 1 if temp < setpoint (heat on), else 0
    doc["note"] = "d1mini-ds18b20@D5";

    char buf[256];
    size_t n = serializeJson(doc, buf, sizeof(buf));
    uint8_t rc = esp_now_send(TARGET, (uint8_t *)buf, (int)n);
    Serial.print("[TX] send -> ");
    if (rc == 0)
      Serial.println("OK");
    else
      Serial.println(rc);
    counter++;
  }
}
