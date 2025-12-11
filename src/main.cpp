#include <Arduino.h>
#include <WiFi.h> // Changed from ESP8266WiFi
#include <esp_now.h>
#include <ArduinoJson.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <LittleFS.h>
#include <WebServer.h> // Changed from ESP8266WebServer
#include <ESPmDNS.h>   // Changed from ESP8266mDNS
#include <ArduinoOTA.h>
#include <time.h>
#include <HTTPClient.h>       // Changed from ESP8266HTTPClient
#include <WiFiClientSecure.h> // Native ESP32 Secure Client
#include <Ticker.h>

// BLE Headers
#include <BLEDevice.h>
#include <BLEUtils.h>
#include <BLEScan.h>
#include <BLEAdvertisedDevice.h>

// ===== CONFIGURATION =====
static const char *WIFI_SSID_DEFAULT = "zelja_RPT";
static const char *WIFI_PASS_DEFAULT = "pikolejla";
static const char *HOSTNAME = "esp32-thermo"; // Updated hostname

// ===== PINS (SuperMini C3) =====
#define ONE_WIRE_BUS 3 // GPIO 3 (D3)
#define LED_PIN 8      // GPIO 8 (Onboard LED)

// ===== BLE CONFIG =====
const int BLE_RSSI_THRESHOLD = -75; // Adjust sensitivity
const int BLE_SCAN_TIME = 2;        // Seconds to scan
bool g_phoneDetected = false;
int g_maxRssi = -100;

// ===== Telemetry Intervals =====
static const uint32_t INTERVAL_ACTIVE_MS = 2000; // Increased slightly for BLE time
static const uint32_t INTERVAL_IDLE_MS = 600000;

// ===== Objects =====
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);
WebServer server(80);
BLEScan *pBLEScan;

// Mutable Wi-Fi creds
static String g_wifiSsid;
static String g_wifiPass;

// ===== DS18B20 State =====
DeviceAddress g_dsAddr{};
bool g_haveSensor = false;
bool g_haveAddress = false;
static uint32_t g_dsReqAt = 0;
static bool g_dsPending = false;

// ===== ESP-NOW target =====
// Broadcast to everyone (or set specific MAC)
uint8_t TARGET[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
esp_now_peer_info_t peerInfo; // ESP32 requires this struct

// ===== State Variables =====
static float g_fixedSetpoint = 19.0f;
static String g_fixedPreset = "on";
static bool g_fixedEnabled = true;
volatile float g_lastTempC = NAN;
volatile uint8_t g_lastAction = 0;

// ACK State
static bool g_haveAck = false;
static bool g_ackRelayOn = false;
static uint32_t g_ackLastMs = 0;

// Hysteresis
static const float HYST_BAND_C = 0.5f;

// Remote Reporting
static uint32_t g_lastHttpMs = 0;
static bool g_remoteOk = false;
static float g_remoteSetpoint = NAN;
static String g_remoteMode = "";
static float g_remoteActual = NAN;
static bool g_remoteHeating = false;
static float g_remoteDelta = NAN;
static bool g_apActive = false;

// Reboot / Persistence
static bool g_pendingRestart = false;
static uint32_t g_restartAtMs = 0;
static float g_lastSavedSetpoint = NAN;
static uint32_t g_lastFsWriteMs = 0;
static const uint32_t FS_WRITE_MIN_GAP_MS = 30000;
static const float SP_EPS = 0.05f;

// Watchdog
Ticker g_wdtTicker;
volatile bool g_wdtFed = false;

// ================= WATCHDOG =================
void IRAM_ATTR wdtCallback()
{
  if (g_wdtFed)
  {
    g_wdtFed = false;
  }
  else
  {
    ets_printf("\n[WDT] System hung. Resetting...\n");
    ESP.restart();
  }
}

// ================= BLE SCANNER =================
void runBleScan()
{
  // BLE and WiFi share the radio. We scan briefly.
  BLEScanResults *foundDevices = pBLEScan->start(BLE_SCAN_TIME, false);

  int nearbyCount = 0;
  int strongest = -120;
  int count = foundDevices->getCount();

  for (int i = 0; i < count; i++)
  {
    BLEAdvertisedDevice device = foundDevices->getDevice(i);
    int rssi = device.getRSSI();
    if (rssi > BLE_RSSI_THRESHOLD)
    {
      nearbyCount++;
      if (rssi > strongest)
        strongest = rssi;
    }
  }

  g_phoneDetected = (nearbyCount > 0);
  g_maxRssi = strongest;

  // Visual indication
  if (g_phoneDetected)
    digitalWrite(LED_PIN, LOW); // LED ON
  else
    digitalWrite(LED_PIN, HIGH); // LED OFF

  pBLEScan->clearResults(); // Important to clear RAM
}

// ================= ESP-NOW CALLBACKS =================
void OnDataSent(const uint8_t *mac_addr, esp_now_send_status_t status)
{
  // Serial.print("[TX] Status: "); Serial.println(status == ESP_NOW_SEND_SUCCESS ? "OK" : "FAIL");
}

void OnDataRecv(const uint8_t *mac, const uint8_t *incomingData, int len)
{
  JsonDocument doc;
  DeserializationError e = deserializeJson(doc, incomingData, len);
  if (e)
    return;

  const char *ack = doc["ack"] | nullptr;
  int relay = doc["relay"] | -1;
  bool ok = doc["ok"] | false;
  if (!ok || relay < 0)
    return;

  g_haveAck = true;
  g_ackRelayOn = (relay == 1) || (ack && strcmp(ack, "ON") == 0);
  g_ackLastMs = millis();
}

// ================= AP FALLBACK =================
static void startApFallback()
{
  if (g_apActive)
    return;
  WiFi.mode(WIFI_AP_STA);
  // wifi_set_sleep_type(NONE_SLEEP_T); // Not needed on ESP32 in the same way
  const char *apSsid = "Termometro_C3";
  const char *apPass = "12345678";
  bool ok = WiFi.softAP(apSsid, apPass, 1);
  g_apActive = ok;
  Serial.printf("[WiFi] AP fallback %s (IP=%s)\n", ok ? "started" : "FAILED", WiFi.softAPIP().toString().c_str());
}

// ================= HTML (Updated with Phone Icon) =================
const char INDEX_HTML[] PROGMEM = R"HTML(
<!doctype html><html lang="en"><head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>ESP32 Thermostat</title>
<style>
:root{ --bg:#f4fbfd; --ink:#0b3440; --ok:#1b9e77; --err:#c1121f; --font:16px system-ui,sans-serif; }
body{ margin:0; background:var(--bg); color:var(--ink); font:var(--font); padding:1rem; max-width:600px; margin:0 auto; }
.card{ background:#fff; padding:1.5rem; border-radius:16px; box-shadow:0 4px 12px rgba(0,0,0,0.1); margin-bottom:1rem; }
.row{ display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem; }
.val{ font-size:2.5rem; font-weight:bold; }
.btn{ padding:10px 20px; border-radius:12px; border:1px solid #ccc; background:#e0f7fa; font-weight:bold; cursor:pointer; }
.active{ background:#00bcd4; color:#fff; border-color:#00bcd4; }
.badge{ padding:4px 8px; border-radius:99px; font-size:0.8rem; border:1px solid #ddd; display:inline-flex; gap:5px; align-items:center; }
.dot{ width:10px; height:10px; border-radius:50%; background:#ccc; }
.on{ background:var(--ok); }
</style>
</head><body>
<h2>Thermostat C3</h2>
<div class="card">
  <div class="row">
    <div>Actual <div class="val" id="actual">--.-°C</div></div>
    <div>Target <div class="val" id="sp">--.-°C</div></div>
  </div>
  <div class="row" style="gap:10px; justify-content:start;">
     <button class="btn" id="minus">-</button>
     <button class="btn" id="plus">+</button>
     <button class="btn" id="save">Save</button>
  </div>
  <div class="row" style="margin-top:10px">
    <button class="preset" data-name="off">Off (10°)</button>
    <button class="preset" data-name="on">On (19°)</button>
    <button class="preset" data-name="away">Away (15°)</button>
  </div>
  <div id="state" style="color:#666; font-size:0.9rem; margin-top:5px;">Loading...</div>
</div>
<div class="card">
  <div class="row"><div class="badge"><span class="dot" id="heatDot"></span>Heat</div> <div id="heatText">--</div></div>
  <div class="row"><div class="badge"><span class="dot" id="phoneDot"></span>Phone</div> <div id="phoneText">--</div></div>
  <div class="row"><div class="badge"><span class="dot" id="wifiDot"></span>WiFi</div> <div id="wifiText">--</div></div>
</div>
<script>
let sp=19.0;
const el = (id) => document.getElementById(id);
const fmt = (v) => Number(v).toFixed(1)+'°C';
async function tick(){
  try {
    const r = await fetch('/api/status');
    const j = await r.json();
    if(j.temp) el('actual').textContent = fmt(j.temp);
    el('heatDot').className = 'dot ' + (j.action===1 ? 'on':'');
    el('heatText').textContent = j.action===1 ? 'ON' : 'OFF';
    el('phoneDot').className = 'dot ' + (j.phone ? 'on':'');
    el('phoneText').textContent = j.phone ? ('Yes ('+j.rssi+'dB)') : 'No';
    el('wifiDot').className = 'dot ' + (j.wifi.connected ? 'on':'');
    el('wifiText').textContent = j.wifi.ssid || 'Offline';
    if(document.activeElement.tagName !== 'BUTTON') { // Don't overwrite if user clicking
       el('sp').textContent = fmt(j.setpoint);
       sp = j.setpoint;
    }
  } catch(e){}
}
el('minus').onclick = () => { sp-=0.5; el('sp').textContent=fmt(sp); };
el('plus').onclick = () => { sp+=0.5; el('sp').textContent=fmt(sp); };
el('save').onclick = async () => {
    await fetch('/api/fixed', {method:'POST', body:JSON.stringify({setpoint:sp})});
    el('state').textContent = 'Saved custom.';
};
document.querySelectorAll('.preset').forEach(b => b.onclick = async () => {
    await fetch('/api/fixed', {method:'POST', body:JSON.stringify({preset:b.dataset.name})});
    el('state').textContent = 'Saved ' + b.dataset.name;
});
setInterval(tick, 2000);
tick();
</script></body></html>
)HTML";

// ===== FILESYSTEM HELPERS =====
static void loadFixedSetpoint()
{
  if (!LittleFS.exists("/fixed.json"))
    return;
  File f = LittleFS.open("/fixed.json", "r");
  JsonDocument doc;
  if (!deserializeJson(doc, f))
  {
    g_fixedSetpoint = doc["setpoint"] | 19.0;
    g_fixedPreset = (const char *)doc["preset"] | "on";
  }
  f.close();
}

static bool saveFixedSetpoint()
{
  JsonDocument doc;
  doc["setpoint"] = g_fixedSetpoint;
  doc["preset"] = g_fixedPreset;
  File f = LittleFS.open("/fixed.json", "w");
  serializeJson(doc, f);
  f.close();
  return true;
}

// ===== WEB HANDLERS =====
void handleIndex() { server.send(200, "text/html", INDEX_HTML); }
void handleStatus()
{
  JsonDocument doc;
  doc["temp"] = isnan(g_lastTempC) ? 0.0 : g_lastTempC;
  doc["setpoint"] = g_fixedEnabled ? g_fixedSetpoint : 19.0f;
  doc["action"] = g_lastAction;
  doc["phone"] = g_phoneDetected;
  doc["rssi"] = g_maxRssi;

  JsonObject w = doc["wifi"].to<JsonObject>();
  w["connected"] = (WiFi.status() == WL_CONNECTED);
  w["ssid"] = WiFi.SSID();

  String out;
  serializeJson(doc, out);
  server.send(200, "application/json", out);
}
void handlePostFixed()
{
  if (!server.hasArg("plain"))
    return server.send(400);
  JsonDocument doc;
  deserializeJson(doc, server.arg("plain"));

  String pr = doc["preset"] | "";
  if (pr == "off")
  {
    g_fixedSetpoint = 10.0;
    g_fixedPreset = "off";
  }
  else if (pr == "on")
  {
    g_fixedSetpoint = 19.0;
    g_fixedPreset = "on";
  }
  else if (pr == "away")
  {
    g_fixedSetpoint = 15.0;
    g_fixedPreset = "away";
  }
  else
  {
    g_fixedSetpoint = doc["setpoint"] | g_fixedSetpoint;
    g_fixedPreset = "custom";
  }

  g_fixedEnabled = true;
  saveFixedSetpoint();
  server.send(200, "application/json", "{\"ok\":true}");
}

// ===== HTTPS TELEMETRY =====
static bool cesanaReportAndFetch(float tempC, bool heating)
{
  if (WiFi.status() != WL_CONNECTED)
    return false;

  WiFiClientSecure client;
  client.setInsecure(); // Skip certificate validation (required for self-signed or simple setups)
  HTTPClient https;

  String url = "https://cesana.steplab.net/get_setpoint.php?temp=" + String(tempC, 1) + "&cald=" + (heating ? "1" : "0") + "&phone=" + (g_phoneDetected ? "1" : "0");

  if (https.begin(client, url))
  {
    int code = https.GET();
    if (code == HTTP_CODE_OK)
    {
      String payload = https.getString();
      JsonDocument doc;
      if (!deserializeJson(doc, payload))
      {
        float remoteSp = doc["setpoint"] | -1.0;
        if (remoteSp > 5.0 && remoteSp < 35.0 && abs(remoteSp - g_fixedSetpoint) > 0.1)
        {
          g_fixedSetpoint = remoteSp;
          g_fixedPreset = "remote";
          saveFixedSetpoint();
        }
        return true;
      }
    }
    https.end();
  }
  return false;
}

// ===== SETUP =====
void setup()
{
  Serial.begin(115200);
  delay(3000); // Wait for USB
  Serial.println("Starting ESP32-C3 Thermostat...");

  if (!LittleFS.begin(true))
    Serial.println("LittleFS Fail"); // true = format if fail
  loadFixedSetpoint();

  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, HIGH); // LED OFF

  // 1. Setup Sensors
  oneWire.begin(ONE_WIRE_BUS);
  sensors.begin();
  sensors.setResolution(12);

  // 2. Setup BLE
  BLEDevice::init("ESP32-Thermo");
  pBLEScan = BLEDevice::getScan();
  pBLEScan->setActiveScan(true);
  pBLEScan->setInterval(100);
  pBLEScan->setWindow(99);

  // 3. Setup WiFi
  WiFi.mode(WIFI_AP_STA); // AP_STA needed for ESP-NOW + WiFi
  WiFi.hostname(HOSTNAME);
  WiFi.begin(WIFI_SSID_DEFAULT, WIFI_PASS_DEFAULT);

  // 4. Setup ESP-NOW
  if (esp_now_init() != ESP_OK)
  {
    Serial.println("ESP-NOW Init Failed");
    ESP.restart();
  }
  esp_now_register_send_cb(OnDataSent);
  esp_now_register_recv_cb(OnDataRecv);

  // Add Peer (ESP32 style)
  memcpy(peerInfo.peer_addr, TARGET, 6);
  peerInfo.channel = 1; // Must match WiFi channel (usually 1 if not connected, or AP channel)
  peerInfo.encrypt = false;
  if (esp_now_add_peer(&peerInfo) != ESP_OK)
  {
    Serial.println("Failed to add peer");
  }

  // 5. Setup Web
  server.on("/", handleIndex);
  server.on("/api/status", handleStatus);
  server.on("/api/fixed", HTTP_POST, handlePostFixed);
  server.begin();

  // 6. Watchdog
  g_wdtTicker.attach(20.0, wdtCallback);
}

// ===== LOOP =====
void loop()
{
  g_wdtFed = true; // Feed Watchdog
  server.handleClient();

  static uint32_t lastLoop = 0;
  if (millis() - lastLoop > 2000)
  {
    lastLoop = millis();

    // 1. Read Temp
    sensors.requestTemperatures();
    float t = sensors.getTempCByIndex(0);
    if (t != DEVICE_DISCONNECTED_C && t > -50 && t < 100)
      g_lastTempC = t;

    // 2. Hysteresis Logic
    float sp = g_fixedEnabled ? g_fixedSetpoint : 19.0;
    if (g_lastTempC < (sp - HYST_BAND_C / 2))
      g_lastAction = 1;
    else if (g_lastTempC > (sp + HYST_BAND_C / 2))
      g_lastAction = 0;

    // 3. BLE Scan (This takes 2 seconds!)
    // Note: This pauses the WebServer for 2 seconds.
    // If that's annoying, reduce BLE_SCAN_TIME to 1.
    runBleScan();

    // 4. Send ESP-NOW
    JsonDocument jtx;
    jtx["heater"] = (g_lastAction == 1) ? "ON" : "OFF";
    jtx["temp"] = g_lastTempC;
    jtx["phone"] = g_phoneDetected; // Added Phone status
    char buf[128];
    serializeJson(jtx, buf);
    esp_now_send(TARGET, (uint8_t *)buf, strlen(buf));

    // 5. Telemetry (HTTP)
    // Only send if connected
    if (WiFi.status() == WL_CONNECTED)
    {
      static uint32_t lastHttp = 0;
      uint32_t interval = (sp <= 10.0) ? INTERVAL_IDLE_MS : INTERVAL_ACTIVE_MS;
      if (millis() - lastHttp > interval)
      {
        cesanaReportAndFetch(g_lastTempC, g_lastAction);
        lastHttp = millis();
      }
    }
    else
    {
      // Reconnect logic
      WiFi.reconnect();
    }
  }
}