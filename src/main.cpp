/*
 * ======================================================================================
 * PROJECT: ESP32-C3 Smart Office Thermostat (NimBLE + OTA + Telnet Debug)
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
#include <Adafruit_NeoPixel.h> 

// OTA LIBRARY
#include <ArduinoOTA.h>

// NIMBLE LIBRARY
#include <NimBLEDevice.h>

// TELNET STREAM (IP Monitor)
#include <TelnetStream.h>

// ======================================================================================
// LOGGING MACROS (Sends to BOTH Serial and Telnet)
// ======================================================================================
#define LOG_PRINT(...)   { Serial.print(__VA_ARGS__); TelnetStream.print(__VA_ARGS__); }
#define LOG_PRINTLN(...) { Serial.println(__VA_ARGS__); TelnetStream.println(__VA_ARGS__); }
#define LOG_PRINTF(...)  { Serial.printf(__VA_ARGS__); TelnetStream.printf(__VA_ARGS__); }

// ======================================================================================
// CONFIGURATION
// ======================================================================================

static const char *WIFI_SSID_DEFAULT = "NETGEAR11";
static const char *WIFI_PASS_DEFAULT = "breezypiano838";

static const char *AP_SSID = "termometroUff";
static const char *AP_PASS = "12345678";

static const char *HOSTNAME = "esp32-thermo";

// NTP Server Settings
const char *ntpServer = "pool.ntp.org";
const long gmtOffset_sec = 3600;     // GMT+1 (Italy/CET)
const int daylightOffset_sec = 3600; // +1 hour for DST

// Hardware Pins (Official ESP32-C3 DevKitM-1)
#define ONE_WIRE_BUS 3 // GPIO 3 (D3)
#define RGB_PIN 8      // GPIO 8 (Onboard RGB LED)

// BLE Settings
const int BLE_RSSI_THRESHOLD = -75;
const int BLE_SCAN_TIME = 1; // 1 Second Scan

// Telemetry Timing
static const uint32_t INTERVAL_ACTIVE_MS = 2000;
static const uint32_t INTERVAL_IDLE_MS = 3000;

// ======================================================================================
// PRESENCE FILTER SETTINGS
// ======================================================================================
#define REQUIRED_HITS 10    // 10 Hits required
#define HIT_WINDOW_MS 60000 // 1 Minute

unsigned long g_detectionHistory[REQUIRED_HITS] = {0};
int g_historyIndex = 0;
int g_currentValidHits = 0;

// --- AI CONFIG ---
#define WINDOW_CHECK_INTERVAL 60000 // Check every minute
float g_prevTemp = 0.0;
int g_dropCount = 0;

// ======================================================================================
// GLOBAL OBJECTS & VARIABLES
// ======================================================================================
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);
WebServer server(80);

NimBLEScan *pBLEScan;

Ticker g_wdtTicker;
Ticker ledBlinker;

// RGB LED Object
Adafruit_NeoPixel pixels(1, RGB_PIN, NEO_GRB + NEO_KHZ800);

// ESP-NOW Broadcast Address
uint8_t TARGET[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
esp_now_peer_info_t peerInfo;

// State Variables
volatile float g_lastTempC = NAN;
volatile uint8_t g_lastAction = 0;
bool g_phoneDetected = false;
int g_maxRssi = -100;
volatile bool g_wdtFed = false;
bool g_otaInProgress = false; 

// LED State Variables
bool g_ledState = false;
uint32_t g_blinkColor = 0; 

// Settings
static float g_fixedSetpoint = 19.0f;
static String g_fixedPreset = "on";
static bool g_fixedEnabled = true;
static const float HYST_BAND_C = 0.5f;

// Timer for Phone Presence
static uint32_t g_lastPhoneSeenMs = 0;
const uint32_t PHONE_ABSENCE_TIMEOUT_MS = 600000; // 10 Minutes (Eco limit)

// Dynamic Timeouts based on Heater State
const uint32_t TIMEOUT_HEATER_ON = 300000; // 5 Minutes
const uint32_t TIMEOUT_HEATER_OFF = 60000; // 1 Minute

// --- HEATER MALFUNCTION CHECK ---

#define HEATER_CHECK_INTERVAL_MS 1800000 // 30 Minutes
#define MIN_REQUIRED_RISE 0.5            // 0.5°C

unsigned long g_heatStartTime = 0;
float g_heatStartTemp = 0.0;
float g_alarmTriggerTemp = 0.0; 
bool g_heaterMonitorActive = false;
bool g_malfunctionState = false; 

Ticker fastBlueTicker; 

// ======================================================================================
// HELPERS
// ======================================================================================

void IRAM_ATTR wdtCallback()
{
  if (g_otaInProgress) {
    g_wdtFed = true; 
    return;
  }

  if (g_wdtFed)
    g_wdtFed = false;
  else
  {
    // ets_printf is ISR safe, do not change to LOG_PRINTF
    ets_printf("\n[WDT] System hung. Resetting...\n");
    ESP.restart();
  }
}

// RGB Toggle Function
void toggleLed()
{
  if (g_otaInProgress) return; 

  g_ledState = !g_ledState;
  if (g_ledState)
  {
    pixels.setPixelColor(0, g_blinkColor);
  }
  else
  {
    pixels.setPixelColor(0, 0);
  }
  pixels.show();
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

void checkWindowOpenAnomaly(float currentTemp, bool isHeaterOn)
{
  static unsigned long lastCheck = 0;
  if (millis() - lastCheck < WINDOW_CHECK_INTERVAL) return;
  lastCheck = millis();

  if (g_prevTemp == 0.0) {
    g_prevTemp = currentTemp;
    return;
  }

  float diff = currentTemp - g_prevTemp;
  if (isHeaterOn && diff < -0.2) { 
    g_dropCount++;
    LOG_PRINTF(">>> [AI] Temp drop detected (%.2f -> %.2f). Count: %d\n", g_prevTemp, currentTemp, g_dropCount);
  } else {
    g_dropCount = 0; 
  }

  if (g_dropCount >= 3) { 
    LOG_PRINTLN(">>> [AI] WINDOW OPEN DETECTED! Forcing Heater OFF.");
    g_fixedPreset = "off"; 
    g_fixedSetpoint = 10.0;
    saveFixedSetpoint();
    g_dropCount = 0;
  }

  g_prevTemp = currentTemp;
}

void runBleScan()
{
  // Safety: Do not scan if OTA is running
  if(g_otaInProgress) return;

  NimBLEScanResults foundDevices = pBLEScan->start(BLE_SCAN_TIME, false);

  int nearbyCount = 0;
  int strongest = -120;
  int count = foundDevices.getCount();

  for (int i = 0; i < count; i++)
  {
    NimBLEAdvertisedDevice device = foundDevices.getDevice(i);
    int rssi = device.getRSSI();

    if (rssi > BLE_RSSI_THRESHOLD)
    {
      nearbyCount++;
      if (rssi > strongest)
        strongest = rssi;
    }
  }

  if (nearbyCount > 0)
  {
    g_detectionHistory[g_historyIndex] = millis();
    g_historyIndex = (g_historyIndex + 1) % REQUIRED_HITS;
    g_maxRssi = strongest;
  }

  int validHits = 0;
  unsigned long now = millis();
  for (int i = 0; i < REQUIRED_HITS; i++)
  {
    if (g_detectionHistory[i] != 0 && (now - g_detectionHistory[i] <= HIT_WINDOW_MS))
    {
      validHits++;
    }
  }
  g_currentValidHits = validHits;

  if (nearbyCount > 0)
  {
    LOG_PRINTF(">>> [BLE] Phone Found! RSSI: %d dBm | Hits: %d/%d\n", strongest, validHits, REQUIRED_HITS);
  }

  bool stablePresence = (validHits >= REQUIRED_HITS);
  g_phoneDetected = stablePresence;

  if (stablePresence)
  {
    g_lastPhoneSeenMs = millis();
  }

  // LED Logic
  if (g_malfunctionState)
  {
    pBLEScan->clearResults(); 
    return;
  }

  static bool isBlinking = false;
  if (validHits > 0)
  {
    if (validHits < REQUIRED_HITS) g_blinkColor = pixels.Color(0, 0, 10); // Blue
    else g_blinkColor = pixels.Color(0, 10, 0); // Green

    if (!isBlinking)
    {
      ledBlinker.attach(0.5, toggleLed);
      isBlinking = true;
      LOG_PRINTLN(">>> [LED] Blink START");
    }
  }
  else
  {
    if (isBlinking)
    {
      ledBlinker.detach();
      pixels.clear();
      pixels.show();
      isBlinking = false;
      g_ledState = false;
      LOG_PRINTLN(">>> [LED] Blink STOP");
    }
  }

  pBLEScan->clearResults(); 
}

static bool cesanaReportAndFetch(float tempC, bool heating, float realSp)
{
  if (WiFi.status() != WL_CONNECTED)
    return false;

  WiFiClientSecure client;
  client.setInsecure();
  client.setTimeout(5000);
  HTTPClient https;

  uint32_t presenceTimeout = heating ? TIMEOUT_HEATER_ON : TIMEOUT_HEATER_OFF;
  bool logicPhonePresent = (millis() - g_lastPhoneSeenMs < presenceTimeout);

  String url = "https://cesana.steplab.net/get_setpoint.php?temp=" + String(tempC, 1) +
               "&cald=" + (heating ? "1" : "0") +
               "&phone=" + (logicPhonePresent ? "1" : "0") +
               "&real=" + String(realSp, 1);
  
  // Note: Printing URLs to logs can be spammy, but useful for debug
  LOG_PRINT(">>> [HTTP] Calling: ");
  LOG_PRINTLN(url);

  if (https.begin(client, url))
  {
    int code = https.GET();
    if (code == HTTP_CODE_OK)
    {
      String payload = https.getString();
      // LOG_PRINT(">>> [HTTP] Reply: ");
      // LOG_PRINTLN(payload);

      JsonDocument doc;
      if (!deserializeJson(doc, payload))
      {
        float remoteSp = doc["setpoint"] | -1.0;
        if (remoteSp > 5.0 && remoteSp < 35.0 && abs(remoteSp - g_fixedSetpoint) > 0.1)
        {
          g_fixedSetpoint = remoteSp;
          g_fixedPreset = "remote";
          saveFixedSetpoint();
          LOG_PRINTLN(">>> [HTTP] Setpoint updated via Remote!");
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
// ESP-NOW & WEB
// ======================================================================================

void OnDataRecv(const esp_now_recv_info_t *info, const uint8_t *incomingData, int len) {}
void OnDataSent(const uint8_t *mac_addr, esp_now_send_status_t status) {}

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
    w["ssid"] = WiFi.SSID();
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
void toggleFastBlue()
{
  if(g_otaInProgress) return;
  static bool state = false;
  state = !state;
  pixels.setPixelColor(0, state ? pixels.Color(0, 0, 255) : 0);
  pixels.show();
}

// ======================================================================================
// OTA SETUP FUNCTION
// ======================================================================================
void setupOTA() {
  ArduinoOTA.setHostname(HOSTNAME);

  ArduinoOTA.onStart([]() {
    g_otaInProgress = true;
    String type = (ArduinoOTA.getCommand() == U_FLASH) ? "sketch" : "filesystem";
    
    // We can't really use Telnet reliably here, it might cut off
    Serial.println("Start updating " + type);
    
    // CRITICAL: Stop BLE Scan to prevent radio conflicts
    if(pBLEScan) pBLEScan->stop();
    
    // Disable other tickers
    ledBlinker.detach();
    fastBlueTicker.detach();

    // Visual Indicator: Magenta
    pixels.setPixelColor(0, pixels.Color(255, 0, 255));
    pixels.show();
  });

  ArduinoOTA.onEnd([]() {
    Serial.println("\nEnd");
    g_otaInProgress = false;
    pixels.clear();
    pixels.show();
  });

  ArduinoOTA.onProgress([](unsigned int progress, unsigned int total) {
    if (progress % 10 == 0) {
        pixels.setPixelColor(0, pixels.Color(50, 0, 50)); 
        pixels.show();
    } else if (progress % 5 == 0) {
        pixels.setPixelColor(0, pixels.Color(0, 0, 0));
        pixels.show();
    }
  });

  ArduinoOTA.onError([](ota_error_t error) {
    g_otaInProgress = false;
    Serial.printf("Error[%u]: ", error);
    if (error == OTA_AUTH_ERROR) Serial.println("Auth Failed");
    else if (error == OTA_BEGIN_ERROR) Serial.println("Begin Failed");
    else if (error == OTA_CONNECT_ERROR) Serial.println("Connect Failed");
    else if (error == OTA_RECEIVE_ERROR) Serial.println("Receive Failed");
    else if (error == OTA_END_ERROR) Serial.println("End Failed");
    ESP.restart(); 
  });

  ArduinoOTA.begin();
}

// ======================================================================================
// MAIN SETUP
// ======================================================================================
void setup()
{
  Serial.begin(115200);
  delay(3000);
  Serial.println("\n--- Starting ESP32-C3 Thermostat (Official DevKit RGB) ---");

  if (!LittleFS.begin(true))
    Serial.println("LittleFS Fail");
  loadFixedSetpoint();

  pixels.begin();
  pixels.clear();
  pixels.show();

  g_lastPhoneSeenMs = millis();

  // Sensors
  oneWire.begin(ONE_WIRE_BUS);
  sensors.begin();
  sensors.setResolution(12);

  // NimBLE Init
  NimBLEDevice::init("ESP32-Thermo");
  pBLEScan = NimBLEDevice::getScan();
  pBLEScan->setActiveScan(true);
  pBLEScan->setInterval(100);
  pBLEScan->setWindow(99);

  // WiFi
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
    
    // --- START TELNET ---
    TelnetStream.begin();
    Serial.println(">>> Telnet Server Started on Port 23");
    
    setupOTA();
  }
  else
  {
    Serial.println(">>> TIMEOUT: Running in AP Mode.");
  }

  // ESP-NOW
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

  // Web
  server.on("/", handleIndex);
  server.on("/api/status", handleStatus);
  server.on("/api/fixed", HTTP_POST, handlePostFixed);
  server.begin();

  // Watchdog
  g_wdtTicker.attach(20.0, wdtCallback);
}

void checkHeaterMalfunction(float currentTemp, bool isHeaterOn)
{
  if (g_otaInProgress) return; 

  // 1. RECOVERY CHECK (If already in Alarm)
  if (g_malfunctionState)
  {
    if (currentTemp >= (g_alarmTriggerTemp + MIN_REQUIRED_RISE))
    {
      LOG_PRINTLN(">>> [ALARM] Recovery! Temp rose 0.5C. Exiting Malfunction State.");

      g_malfunctionState = false;
      fastBlueTicker.detach(); 
      pixels.clear();
      pixels.show();
    }
    return; 
  }

  // 2. MONITORING LOGIC
  if (!isHeaterOn)
  {
    g_heaterMonitorActive = false;
    return;
  }

  if (!g_heaterMonitorActive)
  {
    g_heaterMonitorActive = true;
    g_heatStartTime = millis();
    g_heatStartTemp = currentTemp;
    LOG_PRINTF(">>> [MONITOR] Heater Started at %.2f C. Timer: 30 mins.\n", currentTemp);
    return;
  }

  if (millis() - g_heatStartTime >= HEATER_CHECK_INTERVAL_MS)
  {
    float diff = currentTemp - g_heatStartTemp;

    if (diff < MIN_REQUIRED_RISE)
    {
      LOG_PRINTF(">>> [ALARM] FAIL! 30 mins elapsed. Rise: %.2f (Req: %.2f). Stopping Heater.\n", diff, MIN_REQUIRED_RISE);

      g_malfunctionState = true;
      g_alarmTriggerTemp = currentTemp; 

      g_fixedSetpoint = 10.0;
      g_fixedPreset = "off";
      saveFixedSetpoint();

      ledBlinker.detach();
      fastBlueTicker.attach_ms(100, toggleFastBlue);

      g_heaterMonitorActive = false;
    }
  }
}

// ======================================================================================
// MAIN LOOP
// ======================================================================================
void loop()
{
  // 0. OTA HANDLE - MUST BE FIRST
  ArduinoOTA.handle();

  // If OTA is running, skip everything else to prevent crashes
  if(g_otaInProgress) return;

  g_wdtFed = true;
  server.handleClient();

  // 1. FAST LOOP: BLE Scan
  runBleScan();

  // 2. SLOW LOOP: Temperature, Logic, WiFi (Every 2 seconds)
  static uint32_t lastSlowLoop = 0;
  if (millis() - lastSlowLoop > 2000)
  {
    lastSlowLoop = millis();

    // A. Read Temperature
    sensors.requestTemperatures();
    float t = sensors.getTempCByIndex(0);
    if (t != DEVICE_DISCONNECTED_C && t > -50 && t < 100)
      g_lastTempC = t;

    // B. Safety Checks
    checkWindowOpenAnomaly(g_lastTempC, (g_lastAction == 1));
    checkHeaterMalfunction(g_lastTempC, (g_lastAction == 1));

    // C. Phone Presence & Time Logic
    uint32_t msSincePhone = millis() - g_lastPhoneSeenMs;
    struct tm timeinfo;
    bool timeKnown = getLocalTime(&timeinfo);

    if (!g_malfunctionState)
    {
      uint32_t activePresenceTimeout = (g_lastAction == 1) ? TIMEOUT_HEATER_ON : TIMEOUT_HEATER_OFF;

      if (msSincePhone < activePresenceTimeout)
      {
        if (g_fixedSetpoint < 18.0)
        {
          LOG_PRINTLN(">>> [LOGIC] Stable Presence. Forcing Min 18.0 C");
          g_fixedSetpoint = 18.0;
          g_fixedPreset = "auto_comfort";
          saveFixedSetpoint();
        }
      }
      else if (timeKnown && timeinfo.tm_hour >= 10 && msSincePhone > PHONE_ABSENCE_TIMEOUT_MS)
      {
        if (g_fixedSetpoint > 15.0)
        {
          LOG_PRINTLN(">>> [LOGIC] Time >= 10 & Phone absent > 10min. Capping at 15.0 C");
          g_fixedSetpoint = 15.0;
          g_fixedPreset = "auto_eco_15";
          saveFixedSetpoint();
        }
      }
    }

    // D. Thermostat Hysteresis Control
    float sp = g_fixedEnabled ? g_fixedSetpoint : 19.0;

    if (g_malfunctionState)
    {
      g_lastAction = 0; 
    }
    else
    {
      if (g_lastTempC < (sp - HYST_BAND_C / 2))
        g_lastAction = 1;
      else if (g_lastTempC > (sp + HYST_BAND_C / 2))
        g_lastAction = 0;
    }

    // E. ESP-NOW Broadcast
    JsonDocument jtx;
    jtx["heater"] = (g_lastAction == 1) ? "ON" : "OFF";
    jtx["temp"] = g_lastTempC;
    jtx["phone"] = g_phoneDetected;
    jtx["alarm"] = g_malfunctionState; 
    jtx["id"] = 12;
    char buf[128];
    serializeJson(jtx, buf);
    esp_now_send(TARGET, (uint8_t *)buf, strlen(buf));

    // F. Debug Logs (Telnet + Serial)
    LOG_PRINTF("[STATUS] Temp: %.2f | Set: %.1f | Heat: %s | Phone: %s | Alarm: %s\n",
                  g_lastTempC, sp, (g_lastAction == 1) ? "ON" : "OFF",
                  g_phoneDetected ? "YES" : "NO",
                  g_malfunctionState ? "YES (BLINKING)" : "NO");

    // G. HTTP Telemetry
    if (WiFi.status() == WL_CONNECTED)
    {
      static uint32_t lastHttp = 0;
      uint32_t interval = (sp <= 10.0) ? INTERVAL_IDLE_MS : INTERVAL_ACTIVE_MS;
      if (millis() - lastHttp > interval)
      {
        cesanaReportAndFetch(g_lastTempC, g_lastAction, sp);
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