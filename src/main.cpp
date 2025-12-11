#include <Arduino.h>
#include <WiFi.h>
#include <esp_now.h>
#include <ArduinoJson.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <LittleFS.h>
#include <WebServer.h>
#include <ESPmDNS.h>
#include <ArduinoOTA.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <Ticker.h>

// BLE Headers
#include <BLEDevice.h>
#include <BLEUtils.h>
#include <BLEScan.h>
#include <BLEAdvertisedDevice.h>

// ===== CONFIGURATION =====
static const char *WIFI_SSID_DEFAULT = "NETGEAR11";
static const char *WIFI_PASS_DEFAULT = "breezypiano838";

// Emergency Access Point
static const char *AP_SSID = "termometroUff";
static const char *AP_PASS = "12345678";

static const char *HOSTNAME = "esp32-thermo";

// ===== PINS (SuperMini C3) =====
#define ONE_WIRE_BUS 3 // GPIO 3 (D3)
#define LED_PIN 8      // GPIO 8 (Onboard LED)

// ===== BLE CONFIG =====
const int BLE_RSSI_THRESHOLD = -75;
const int BLE_SCAN_TIME = 2;
bool g_phoneDetected = false;
int g_maxRssi = -100;

// ===== Telemetry Intervals =====
static const uint32_t INTERVAL_ACTIVE_MS = 2000;
static const uint32_t INTERVAL_IDLE_MS = 2000;

// ===== Objects =====
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);
WebServer server(80);
BLEScan *pBLEScan;

// ===== DS18B20 State =====
DeviceAddress g_dsAddr{};
bool g_haveSensor = false;

// ===== ESP-NOW target =====
// BROADCAST ADDRESS (Sends to everyone, ensures D1 Mini hears it if on same channel)
uint8_t TARGET[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
esp_now_peer_info_t peerInfo;

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
    digitalWrite(LED_PIN, LOW); // LED ON
  else
    digitalWrite(LED_PIN, HIGH); // LED OFF

  pBLEScan->clearResults();
}

// ================= ESP-NOW CALLBACKS =================
void OnDataSent(const uint8_t *mac_addr, esp_now_send_status_t status) {}

void OnDataRecv(const esp_now_recv_info_t *info, const uint8_t *incomingData, int len)
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

// ================= HTML =================
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
    g_fixedPreset = doc["preset"] | "on";
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

// ===== HTTPS TELEMETRY =====
// ===== REPLACE YOUR OLD cesanaReportAndFetch WITH THIS =====
static bool cesanaReportAndFetch(float tempC, bool heating) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println(">>> [DEBUG] WiFi disconnected, cannot call URL.");
    return false;
  }
  
  WiFiClientSecure client;
  client.setInsecure(); // Skip certificate check
  client.setTimeout(5000); // 5s timeout

  HTTPClient https;
  
  // 1. BUILD THE URL
  String url = "https://cesana.steplab.net/get_setpoint.php?temp=" + String(tempC, 1) + 
               "&cald=" + (heating?"1":"0") + 
               "&phone=" + (g_phoneDetected?"1":"0");
  
  // 2. PRINT THE URL TO SERIAL
  Serial.println("\n--------------------------------------------------");
  Serial.print(">>> [DEBUG] CALLING URL: ");
  Serial.println(url);
  
  if (https.begin(client, url)) {
    int httpCode = https.GET();
    
    // 3. CHECK RESULT
    if (httpCode > 0) {
      Serial.printf(">>> [DEBUG] HTTP CODE: %d\n", httpCode);

      if (httpCode == HTTP_CODE_OK) {
        String payload = https.getString();
        
        // 4. PRINT THE SERVER ANSWER
        Serial.print(">>> [DEBUG] SERVER ANSWER: ");
        Serial.println(payload);
        Serial.println("--------------------------------------------------\n");

        // Process the answer
        JsonDocument doc;
        if (!deserializeJson(doc, payload)) {
           float remoteSp = doc["setpoint"] | -1.0;
           if (remoteSp > 5.0 && remoteSp < 35.0 && abs(remoteSp - g_fixedSetpoint) > 0.1) {
              g_fixedSetpoint = remoteSp;
              g_fixedPreset = "remote";
              saveFixedSetpoint();
              Serial.println(">>> [DEBUG] Setpoint updated from server!");
           }
           https.end();
           return true;
        }
      }
    } else {
      Serial.printf(">>> [DEBUG] FAILED. Error: %s\n", https.errorToString(httpCode).c_str());
    }
    https.end();
  } else {
    Serial.println(">>> [DEBUG] Connection failed (Host unreachable)");
  }
  return false;
}

// ===== SETUP =====
void setup()
{
  Serial.begin(115200);
  delay(3000);
  Serial.println("Starting ESP32-C3 Thermostat...");

  if (!LittleFS.begin(true))
    Serial.println("LittleFS Fail");
  loadFixedSetpoint();

  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, HIGH);

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

  // 3. Setup WiFi (DUAL MODE)
  WiFi.mode(WIFI_AP_STA);

  // Setup AP
  WiFi.softAP(AP_SSID, AP_PASS);
  Serial.print("AP Started. IP: ");
  Serial.println(WiFi.softAPIP());

  // Connect to Router
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

  // FIX: Set channel to 0 so it follows the Router's channel
  memcpy(peerInfo.peer_addr, TARGET, 6);
  peerInfo.channel = 0; // <--- FIX: 0 = "Use current WiFi channel"
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
// ===== LOOP =====
void loop()
{
  g_wdtFed = true;       // Feed the Watchdog
  server.handleClient(); // Handle Web UI requests

  static uint32_t lastLoop = 0;

  // Run logic every 2 seconds
  if (millis() - lastLoop > 2000)
  {
    lastLoop = millis();

    // 1. Read Temperature
    sensors.requestTemperatures();
    float t = sensors.getTempCByIndex(0);

    // Validate reading (-127 is error, 85 is power-on default)
    if (t != DEVICE_DISCONNECTED_C && t > -50 && t < 100)
    {
      g_lastTempC = t;
    }
    else
    {
      Serial.println("[ERR] Sensor Read Failed! Check 4.7k Resistor & Wiring.");
    }

    // 2. Hysteresis Logic
    float sp = g_fixedEnabled ? g_fixedSetpoint : 19.0;
    if (g_lastTempC < (sp - HYST_BAND_C / 2))
      g_lastAction = 1;
    else if (g_lastTempC > (sp + HYST_BAND_C / 2))
      g_lastAction = 0;

    // 3. BLE Scan (Blocking for ~2 seconds)
    runBleScan();

    // ==========================================
    // ADDED: PRINT TO SERIAL MONITOR
    // ==========================================
    Serial.printf("[STATUS] Temp: %.2f C | Target: %.1f C | Heater: %s | Phone: %s (%d dBm) | WiFi: %s\n",
                  g_lastTempC,
                  sp,
                  (g_lastAction == 1) ? "ON" : "OFF",
                  g_phoneDetected ? "YES" : "NO",
                  g_maxRssi,
                  (WiFi.status() == WL_CONNECTED) ? "Conn" : "AP-Only");
    // ==========================================

    // 4. Send ESP-NOW
    JsonDocument jtx;
    jtx["heater"] = (g_lastAction == 1) ? "ON" : "OFF";
    jtx["temp"] = g_lastTempC;
    jtx["phone"] = g_phoneDetected;
    char buf[128];
    serializeJson(jtx, buf);

    // Send to peer (Target)
    esp_err_t result = esp_now_send(TARGET, (uint8_t *)buf, strlen(buf));
    if (result != ESP_OK)
    {
      Serial.println("[ESP-NOW] Send Error");
    }

    // 5. Telemetry & WiFi Reconnection
    if (WiFi.status() == WL_CONNECTED)
    {
      static uint32_t lastHttp = 0;
      uint32_t interval = (sp <= 10.0) ? INTERVAL_IDLE_MS : INTERVAL_ACTIVE_MS;

      if (millis() - lastHttp > interval)
      {
        Serial.print("[HTTP] Uploading... ");
        bool ok = cesanaReportAndFetch(g_lastTempC, g_lastAction);
        Serial.println(ok ? "OK" : "Fail");
        lastHttp = millis();
      }
    }
    else
    {
      // WiFi is down, retry occasionally
      static uint32_t lastReconnect = 0;
      if (millis() - lastReconnect > 30000)
      {
        Serial.println("[WiFi] Connection lost. Retrying...");
        WiFi.reconnect();
        lastReconnect = millis();
      }
    }
  }
}