/*
 * ======================================================================================
 * PROJECT: ESP32-C3 Smart Office Thermostat
 * ======================================================================================
 *
 * AUTOMATION LOGIC & PRIORITIES:
 *
 * 1. PRIORITY 1: COMFORT ENFORCEMENT (Presence Detected)
 *    - Trigger:   BLE Device (Phone) seen within the LAST 5 MINUTES.
 *    - Behavior:  Enforces a MINIMUM floor of 18.0°C.
 *                 (If setpoint is < 18.0, it is raised to 18.0.
 *                  If setpoint is already higher, e.g. 21.0, it remains unchanged).
 *
 * 2. PRIORITY 2: ECO / AWAY ENFORCEMENT (Absence Detected)
 *    - Trigger:   Phone NOT seen for > 10 MINUTES
 *                 AND Current Time is >= 10:00 AM.
 *    - Behavior:  Enforces a MAXIMUM cap of 15.0°C.
 *                 (If setpoint is > 15.0, it is lowered to 15.0).
 *
 * 3. PRIORITY 3: MORNING / REMOTE / MANUAL DEFAULT
 *    - Trigger:   Occurs when neither Priority 1 nor Priority 2 are active.
 *                 (Specifically: Before 10:00 AM OR during the 5-10m buffer window).
 *    - Behavior:  The thermostat follows the last setpoint received from:
 *                 a) The Web Interface (Manual)
 *                 b) The Remote Cloud API (HTTPS)
 *    - Note:      This allows pre-heating in the morning (00:00 - 09:59) without
 *                 needing a phone present.
 *
 * ======================================================================================
 */

#include <Arduino.h>
#include <WiFi.h>
#include <esp_now.h>
#include <ArduinoJson.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <LittleFS.h>
#include <WebServer.h>
#include <ESPmDNS.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <Ticker.h>
#include <time.h>

// BLE Headers
#include <BLEDevice.h>
#include <BLEUtils.h>
#include <BLEScan.h>
#include <BLEAdvertisedDevice.h>

// ======================================================================================
// CONFIGURATION
// ======================================================================================

// UPDATED WI-FI CREDENTIALS
static const char *WIFI_SSID_DEFAULT = "NETGEAR11";
static const char *WIFI_PASS_DEFAULT = "breezypiano838";

// Emergency Access Point (If WiFi fails)
static const char *AP_SSID = "termometroUff";
static const char *AP_PASS = "12345678";

static const char *HOSTNAME = "esp32-thermo";

// NTP Server Settings
const char *ntpServer = "pool.ntp.org";
const long gmtOffset_sec = 3600;     // GMT+1 (Italy/CET)
const int daylightOffset_sec = 3600; // +1 hour for DST

// Hardware Pins (ESP32-C3 SuperMini)
#define ONE_WIRE_BUS 3 // GPIO 3 (D3) - Requires 4.7k Resistor to 3.3V!
#define LED_PIN 8      // GPIO 8 (Onboard LED, Active Low)

// BLE Settings
const int BLE_RSSI_THRESHOLD = -75; // Sensitivity (-60 close, -90 far)
const int BLE_SCAN_TIME = 2;        // Seconds to scan per loop

// Telemetry Timing
static const uint32_t INTERVAL_ACTIVE_MS = 2000;
static const uint32_t INTERVAL_IDLE_MS = 3000; // 10 mins

// ======================================================================================
// GLOBAL OBJECTS & VARIABLES
// ======================================================================================
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);
WebServer server(80);
BLEScan *pBLEScan;
Ticker g_wdtTicker;

// ESP-NOW Broadcast Address
uint8_t TARGET[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
esp_now_peer_info_t peerInfo;

// State Variables
volatile float g_lastTempC = NAN;
volatile uint8_t g_lastAction = 0; // 1 = ON, 0 = OFF
bool g_phoneDetected = false;
int g_maxRssi = -100;
volatile bool g_wdtFed = false;

// Settings (Persisted)
static float g_fixedSetpoint = 19.0f;
static String g_fixedPreset = "on";
static bool g_fixedEnabled = true;
static const float HYST_BAND_C = 0.5f;

// Timer for Phone Presence
static uint32_t g_lastPhoneSeenMs = 0;
const uint32_t PHONE_ABSENCE_TIMEOUT_MS = 600000; // 10 Minutes (For Eco Mode)
const uint32_t PHONE_PRESENCE_WINDOW_MS = 300000; // 5 Minutes (For Comfort Mode)

// ======================================================================================
// HELPERS
// ======================================================================================

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

static void loadFixedSetpoint()
{
  if (!LittleFS.exists("/fixed.json"))
    return;
  File f = LittleFS.open("/fixed.json", "r");
  JsonDocument doc;
  if (!deserializeJson(doc, f))
  {
    g_fixedSetpoint = doc["setpoint"] | 19.0;
    g_fixedPreset = doc["preset"] | "on";
  }
  f.close();
}

void runBleScan()
{
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

  if (g_phoneDetected)
  {
    g_lastPhoneSeenMs = millis();
  }

  digitalWrite(LED_PIN, g_phoneDetected ? LOW : HIGH);
  pBLEScan->clearResults();
}

static bool cesanaReportAndFetch(float tempC, bool heating)
{
  if (WiFi.status() != WL_CONNECTED)
    return false;

  WiFiClientSecure client;
  client.setInsecure();
  client.setTimeout(5000);

  HTTPClient https;

  // Calculate if phone is considered present based on the 5-minute Logic Window
  // (Matches Priority 1 Logic in main loop)
  bool logicPhonePresent = (millis() - g_lastPhoneSeenMs < PHONE_PRESENCE_WINDOW_MS);

  String url = "https://cesana.steplab.net/get_setpoint.php?temp=" + String(tempC, 1) +
               "&cald=" + (heating ? "1" : "0") +
               "&phone=" + (logicPhonePresent ? "1" : "0");

  Serial.print(">>> [HTTP] Calling: ");
  Serial.println(url);

  if (https.begin(client, url))
  {
    int code = https.GET();
    if (code == HTTP_CODE_OK)
    {
      String payload = https.getString();
      Serial.print(">>> [HTTP] Reply: ");
      Serial.println(payload);

      JsonDocument doc;
      if (!deserializeJson(doc, payload))
      {
        float remoteSp = doc["setpoint"] | -1.0;
        // Only accept remote if reasonable
        if (remoteSp > 5.0 && remoteSp < 35.0 && abs(remoteSp - g_fixedSetpoint) > 0.1)
        {
          g_fixedSetpoint = remoteSp;
          g_fixedPreset = "remote";
          saveFixedSetpoint();
          Serial.println(">>> [HTTP] Setpoint updated via Remote!");
        }
      }
      https.end();
      return true;
    }
    https.end();
  }
  return false;
}

// ======================================================================================
// ESP-NOW SETUP
// ======================================================================================

void OnDataRecv(const esp_now_recv_info_t *info, const uint8_t *incomingData, int len) {}
void OnDataSent(const uint8_t *mac_addr, esp_now_send_status_t status) {}

// ======================================================================================
// WEB SERVER HANDLERS & HTML
// ======================================================================================

const char INDEX_HTML[] PROGMEM = R"HTML(
<!doctype html><html lang="en"><head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Termometro Uff</title>
<style>
:root{ --bg:#f4fbfd; --ink:#0b3440; --ok:#1b9e77; --err:#c1121f; --font:16px system-ui,sans-serif; }
body{ margin:0; background:var(--bg); color:var(--ink); font:var(--font); padding:1rem; max-width:600px; margin:0 auto; }
.card{ background:#fff; padding:1.5rem; border-radius:16px; box-shadow:0 4px 12px rgba(0,0,0,0.1); margin-bottom:1rem; }
.row{ display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem; }
.val{ font-size:2.5rem; font-weight:bold; }
.btn{ padding:10px 20px; border-radius:12px; border:1px solid #ccc; background:#e0f7fa; font-weight:bold; cursor:pointer; }
.badge{ padding:4px 8px; border-radius:99px; font-size:0.8rem; border:1px solid #ddd; display:inline-flex; gap:5px; align-items:center; }
.dot{ width:10px; height:10px; border-radius:50%; background:#ccc; }
.on{ background:var(--ok); }
.warn{ background:orange; }
</style>
</head><body>
<h2>Termometro Uff</h2>
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
  <div class="row"><div class="badge"><span class="dot" id="timeDot"></span>Time</div> <div id="timeText">--:--</div></div>
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
    if(j.wifi.connected) {
        el('wifiDot').className = 'dot on';
        el('wifiText').textContent = j.wifi.ssid;
    } else {
        el('wifiDot').className = 'dot warn';
        el('wifiText').textContent = "AP: " + j.wifi.ap_ssid;
    }
    if(j.time) el('timeText').textContent = j.time;
    if(document.activeElement.tagName !== 'BUTTON') { 
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

void handleIndex() { server.send(200, "text/html", INDEX_HTML); }

void handleStatus()
{
  JsonDocument doc;
  doc["temp"] = isnan(g_lastTempC) ? 0.0 : g_lastTempC;
  doc["setpoint"] = g_fixedEnabled ? g_fixedSetpoint : 19.0f;
  doc["action"] = g_lastAction;
  doc["phone"] = g_phoneDetected;
  doc["rssi"] = g_maxRssi;

  struct tm timeinfo;
  if (getLocalTime(&timeinfo))
  {
    char timeStr[6];
    strftime(timeStr, sizeof(timeStr), "%H:%M", &timeinfo);
    doc["time"] = timeStr;
  }
  else
  {
    doc["time"] = "--:--";
  }

  JsonObject w = doc["wifi"].to<JsonObject>();
  bool connected = (WiFi.status() == WL_CONNECTED);
  w["connected"] = connected;
  if (connected)
  {
    w["ssid"] = WiFi.SSID();
  }
  else
  {
    w["ssid"] = nullptr;
    w["ap_ssid"] = AP_SSID;
    w["ap_ip"] = WiFi.softAPIP().toString();
  }
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

// ======================================================================================
// MAIN SETUP
// ======================================================================================
void setup()
{
  Serial.begin(115200);
  delay(3000);
  Serial.println("\n--- Starting ESP32-C3 Thermostat ---");

  if (!LittleFS.begin(true))
    Serial.println("LittleFS Fail");
  loadFixedSetpoint();

  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, HIGH); // Off

  g_lastPhoneSeenMs = millis();

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

  // 3. Setup WiFi (AP + STA)
  WiFi.mode(WIFI_AP_STA);
  WiFi.softAP(AP_SSID, AP_PASS, 1);
  WiFi.hostname(HOSTNAME);
  WiFi.begin(WIFI_SSID_DEFAULT, WIFI_PASS_DEFAULT);

  Serial.print("[WiFi] Connecting to ");
  Serial.print(WIFI_SSID_DEFAULT);
  unsigned long startAttempt = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - startAttempt < 10000)
  {
    delay(500);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED)
  {
    Serial.print(">>> SUCCESS! IP: ");
    Serial.println(WiFi.localIP());
    configTime(gmtOffset_sec, daylightOffset_sec, ntpServer);
    Serial.println(">>> NTP Configured");
  }
  else
  {
    Serial.println(">>> TIMEOUT: Running in AP Mode.");
    Serial.print(">>> AP IP: ");
    Serial.println(WiFi.softAPIP());
  }

  // 4. Setup ESP-NOW
  if (esp_now_init() != ESP_OK)
  {
    Serial.println("ESP-NOW Fail");
    ESP.restart();
  }
  esp_now_register_send_cb(OnDataSent);
  esp_now_register_recv_cb(OnDataRecv);

  memcpy(peerInfo.peer_addr, TARGET, 6);
  peerInfo.channel = 0;
  peerInfo.encrypt = false;
  if (esp_now_add_peer(&peerInfo) != ESP_OK)
    Serial.println("Add Peer Fail");

  // 5. Setup Web
  server.on("/", handleIndex);
  server.on("/api/status", handleStatus);
  server.on("/api/fixed", HTTP_POST, handlePostFixed);
  server.begin();

  // 6. Watchdog
  g_wdtTicker.attach(20.0, wdtCallback);
}

// ======================================================================================
// MAIN LOOP
// ======================================================================================
void loop()
{
  g_wdtFed = true;
  server.handleClient();

  static uint32_t lastLoop = 0;
  if (millis() - lastLoop > 2000)
  {
    lastLoop = millis();

    // 1. Read Temp
    sensors.requestTemperatures();
    float t = sensors.getTempCByIndex(0);
    if (t != DEVICE_DISCONNECTED_C && t > -50 && t < 100)
    {
      g_lastTempC = t;
    }

    // 2. BLE Scan (Blocking)
    runBleScan();

    // 3. LOGIC CONTROLLER
    // ====================================================================
    uint32_t msSincePhone = millis() - g_lastPhoneSeenMs;
    struct tm timeinfo;
    bool timeKnown = getLocalTime(&timeinfo);

    // RULE A: PRESENCE (PRIORITY HIGH)
    // If phone seen within last 5 minutes, force Minimum Setpoint = 18.0
    if (msSincePhone < PHONE_PRESENCE_WINDOW_MS)
    {
      if (g_fixedSetpoint < 18.0)
      {
        Serial.println(">>> [LOGIC] Phone Present (<5min). Forcing Min 18.0 C");
        g_fixedSetpoint = 18.0;
        g_fixedPreset = "auto_comfort";
        saveFixedSetpoint();
      }
    }
    // RULE B: ABSENCE / ECO (PRIORITY LOW)
    // Triggers ONLY if:
    // 1. Time is known
    // 2. Hour is >= 10 (10:00 AM, 11:00 AM... etc)
    // 3. Phone absent > 10 min
    else if (timeKnown &&
             timeinfo.tm_hour >= 10 &&
             msSincePhone > PHONE_ABSENCE_TIMEOUT_MS)
    {
      // If time < 10, this block is SKIPPED, so it follows existing/remote setpoint.
      if (g_fixedSetpoint > 15.0)
      {
        Serial.println(">>> [LOGIC] Time >= 10 & Phone absent > 10min. Capping at 15.0 C");
        g_fixedSetpoint = 15.0;
        g_fixedPreset = "auto_eco_15";
        saveFixedSetpoint();
      }
    }
    // ====================================================================

    // 4. Hysteresis
    float sp = g_fixedEnabled ? g_fixedSetpoint : 19.0;
    if (g_lastTempC < (sp - HYST_BAND_C / 2))
      g_lastAction = 1;
    else if (g_lastTempC > (sp + HYST_BAND_C / 2))
      g_lastAction = 0;

    // 5. Send ESP-NOW
    JsonDocument jtx;
    jtx["heater"] = (g_lastAction == 1) ? "ON" : "OFF";
    jtx["temp"] = g_lastTempC;
    jtx["phone"] = g_phoneDetected;
    jtx["id"] = 12;
    char buf[128];
    serializeJson(jtx, buf);
    esp_now_send(TARGET, (uint8_t *)buf, strlen(buf));

    // 6. Serial Debug
    Serial.printf("[STATUS] Temp: %.2f | Set: %.1f | Heat: %s | Phone: %s | LastSeen: %ds ago\n",
                  g_lastTempC, sp, (g_lastAction == 1) ? "ON" : "OFF", g_phoneDetected ? "YES" : "NO", msSincePhone / 1000);

    // 7. HTTPS Telemetry
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
      static uint32_t lastReconnect = 0;
      if (millis() - lastReconnect > 30000)
      {
        WiFi.reconnect();
        lastReconnect = millis();
      }
    }
  }
}