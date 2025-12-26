/*
 * ======================================================================================
 * PROJECT: ESP32-C3 Smart Office Thermostat (STABLE VERSION)
 * FIXES: Radio collision prevention, Modem Sleep disabled, Staggered Sync
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
#include <ArduinoOTA.h>
#include <NimBLEDevice.h>
#include <TelnetStream.h>
#include <esp_task_wdt.h>

// ======================================================================================
// CONFIGURATION & PINS
// ======================================================================================
#define WDT_TIMEOUT_MS 15000
#define ONE_WIRE_BUS 3
#define RGB_PIN 8

static const char *WIFI_SSID_DEFAULT = "NETGEAR11";
static const char *WIFI_PASS_DEFAULT = "breezypiano838";
static const char *AP_SSID = "termometroUff";
static const char *AP_PASS = "12345678";
static const char *HOSTNAME = "esp32-thermo";

// Intervals
const uint32_t HTTP_SYNC_INTERVAL = 30000; // 30 Seconds
const uint32_t BLE_SCAN_INTERVAL = 30000;  // 30 Seconds
const uint32_t LOGIC_INTERVAL = 2000;      // 2 Seconds

const char *ntpServer = "pool.ntp.org";
const long gmtOffset_sec = 3600;
const int daylightOffset_sec = 3600;

// BLE Settings
const int BLE_RSSI_THRESHOLD = -75;
const int BLE_SCAN_TIME = 1; // 1 second scan duration
#define REQUIRED_HITS 10
#define HIT_WINDOW_MS 60000

// Logic Settings
static float g_fixedSetpoint = 19.0f;
static String g_fixedPreset = "on";
static const float HYST_BAND_C = 0.5f;
const uint32_t PHONE_ABSENCE_TIMEOUT_MS = 600000;
const uint32_t TIMEOUT_HEATER_ON = 300000;
const uint32_t TIMEOUT_HEATER_OFF = 60000;

// ======================================================================================
// GLOBALS
// ======================================================================================
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);
WebServer server(80);
NimBLEScan *pBLEScan;
Adafruit_NeoPixel pixels(1, RGB_PIN, NEO_GRB + NEO_KHZ800);
Ticker ledBlinker;

uint8_t TARGET[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
esp_now_peer_info_t peerInfo;

volatile float g_lastTempC = NAN;
volatile uint8_t g_lastAction = 0;
bool g_phoneDetected = false;
bool g_otaInProgress = false;
bool g_ledState = false;
uint32_t g_blinkColor = 0;
uint32_t g_lastPhoneSeenMs = 0;
unsigned long g_detectionHistory[REQUIRED_HITS] = {0};
int g_historyIndex = 0;
bool g_malfunctionState = false;

esp_task_wdt_config_t twdt_config = {
    .timeout_ms = WDT_TIMEOUT_MS,
    .idle_core_mask = (1 << 0),
    .trigger_panic = true};

#define LOG_PRINTF(...)               \
  {                                   \
    Serial.printf(__VA_ARGS__);       \
    TelnetStream.printf(__VA_ARGS__); \
  }
#define LOG_PRINTLN(...)               \
  {                                    \
    Serial.println(__VA_ARGS__);       \
    TelnetStream.println(__VA_ARGS__); \
  }

// ======================================================================================
// HELPERS
// ======================================================================================

static void saveFixedSetpoint()
{
  JsonDocument doc;
  doc["setpoint"] = g_fixedSetpoint;
  doc["preset"] = g_fixedPreset;
  File f = LittleFS.open("/fixed.json", "w");
  serializeJson(doc, f);
  f.close();
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

void toggleLed()
{
  if (g_otaInProgress)
    return;
  g_ledState = !g_ledState;
  pixels.setPixelColor(0, g_ledState ? g_blinkColor : 0);
  pixels.show();
}

void runBleScan()
{
  if (g_otaInProgress)
    return;

  // Start scan (blocking for BLE_SCAN_TIME)
  NimBLEScanResults foundDevices = pBLEScan->start(BLE_SCAN_TIME, false);
  int nearbyCount = 0;

  for (int i = 0; i < foundDevices.getCount(); i++)
  {
    if (foundDevices.getDevice(i).getRSSI() > BLE_RSSI_THRESHOLD)
      nearbyCount++;
  }

  if (nearbyCount > 0)
  {
    g_detectionHistory[g_historyIndex] = millis();
    g_historyIndex = (g_historyIndex + 1) % REQUIRED_HITS;
  }

  int validHits = 0;
  unsigned long now = millis();
  for (int i = 0; i < REQUIRED_HITS; i++)
  {
    if (g_detectionHistory[i] != 0 && (now - g_detectionHistory[i] <= HIT_WINDOW_MS))
      validHits++;
  }

  g_phoneDetected = (validHits >= REQUIRED_HITS);
  if (g_phoneDetected)
    g_lastPhoneSeenMs = millis();

  static bool isBlinking = false;
  if (validHits > 0 && !g_malfunctionState)
  {
    g_blinkColor = (validHits < REQUIRED_HITS) ? pixels.Color(0, 0, 15) : pixels.Color(0, 15, 0);
    if (!isBlinking)
    {
      ledBlinker.attach(0.5, toggleLed);
      isBlinking = true;
    }
  }
  else if (isBlinking)
  {
    ledBlinker.detach();
    pixels.clear();
    pixels.show();
    isBlinking = false;
  }
  pBLEScan->clearResults();
}

static bool cesanaReportAndFetch(float tempC, bool heating, float realSp, bool isNightMode)
{
  if (WiFi.status() != WL_CONNECTED)
    return false;

  WiFiClientSecure client;
  client.setInsecure();
  client.setTimeout(5000); // 5 sec timeout to prevent WDT trigger
  HTTPClient https;

  String url = "https://cesana.steplab.net/get_setpoint.php?temp=" + String(tempC, 1) +
               "&cald=" + (heating ? "1" : "0") +
               "&phone=" + (g_phoneDetected ? "1" : "0") +
               "&real=" + String(realSp, 1);

  if (https.begin(client, url))
  {
    int code = https.GET();
    if (code == HTTP_CODE_OK)
    {
      JsonDocument doc;
      if (!deserializeJson(doc, https.getString()))
      {
        float remoteSp = doc["setpoint"] | -1.0;
        if (isNightMode || g_phoneDetected)
        {
          if (remoteSp > 5.0 && remoteSp < 35.0)
          {
            float targetSp = remoteSp;
            if (!isNightMode && g_phoneDetected && targetSp < 18.0)
              targetSp = 18.0;
            if (abs(targetSp - g_fixedSetpoint) > 0.1)
            {
              g_fixedSetpoint = targetSp;
              g_fixedPreset = "remote_sync";
              saveFixedSetpoint();
              LOG_PRINTF(">>> [HTTP] Remote Update: %.1f C\n", targetSp);
            }
          }
        }
      }
      https.end();
      return true;
    }
    https.end();
  }
  return false;
}

void setupOTA()
{
  ArduinoOTA.setHostname(HOSTNAME);
  ArduinoOTA.onStart([]()
                     {
    g_otaInProgress = true;
    esp_task_wdt_delete(NULL); 
    esp_task_wdt_deinit();
    if(pBLEScan) pBLEScan->stop();
    ledBlinker.detach();
    pixels.setPixelColor(0, pixels.Color(255, 0, 255)); pixels.show(); });
  ArduinoOTA.onEnd([]()
                   { ESP.restart(); });
  ArduinoOTA.begin();
}

void setup()
{
  Serial.begin(115200);
  LittleFS.begin(true);
  loadFixedSetpoint();
  pixels.begin();
  pixels.show();

  oneWire.begin(ONE_WIRE_BUS);
  sensors.begin();
  sensors.setResolution(12);

  NimBLEDevice::init(HOSTNAME);
  pBLEScan = NimBLEDevice::getScan();
  pBLEScan->setActiveScan(true);

  WiFi.mode(WIFI_AP_STA);
  WiFi.setSleep(false);
  WiFi.setAutoReconnect(true);
  WiFi.softAP(AP_SSID, AP_PASS);
  WiFi.begin(WIFI_SSID_DEFAULT, WIFI_PASS_DEFAULT);

  // Wait briefly for connection
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 8000)
    delay(500);

  if (WiFi.status() == WL_CONNECTED)
  {
    // REMOVED the "g_" prefix from these two variables:
    configTime(gmtOffset_sec, daylightOffset_sec, ntpServer);
    TelnetStream.begin();
    setupOTA();
  }

  esp_now_init();
  memcpy(peerInfo.peer_addr, TARGET, 6);
  peerInfo.channel = 0;
  peerInfo.encrypt = false;
  esp_now_add_peer(&peerInfo);

  server.on("/api/status", []()
            {
      JsonDocument doc;
      doc["temp"] = g_lastTempC;
      doc["setpoint"] = g_fixedSetpoint;
      doc["phone"] = g_phoneDetected;
      String out; serializeJson(doc, out);
      server.send(200, "application/json", out); });
  server.begin();

  esp_task_wdt_init(&twdt_config);
  esp_task_wdt_add(NULL);
  LOG_PRINTLN(">>> System Ready. Reachability Patches Applied.");
}

void loop()
{
  ArduinoOTA.handle();
  if (g_otaInProgress)
    return;

  // 1. FEED THE DOG & HANDLE CLIENTS (High Frequency)
  esp_task_wdt_reset();
  server.handleClient();

  uint32_t now = millis();

  // 2. THERMOSTAT LOGIC & ESP-NOW (Every 2 Seconds)
  static uint32_t lastLogic = 0;
  if (now - lastLogic > LOGIC_INTERVAL)
  {
    lastLogic = now;

    sensors.requestTemperatures();
    float t = sensors.getTempCByIndex(0);
    if (t > -50 && t < 100)
      g_lastTempC = t;

    struct tm timeinfo;
    bool timeKnown = getLocalTime(&timeinfo);
    bool isNightMode = (timeKnown && timeinfo.tm_hour >= 0 && timeinfo.tm_hour < 6);

    // Day Presence Logic
    if (!g_malfunctionState && !isNightMode)
    {
      uint32_t timeSinceSeen = now - g_lastPhoneSeenMs;
      uint32_t activeTimeout = (g_lastAction == 1) ? TIMEOUT_HEATER_ON : TIMEOUT_HEATER_OFF;

      if (g_lastPhoneSeenMs > 0 && timeSinceSeen < activeTimeout)
      {
        if (g_fixedSetpoint < 18.0)
        {
          g_fixedSetpoint = 18.0;
          g_fixedPreset = "auto_comfort";
          saveFixedSetpoint();
        }
      }
      else if (timeKnown && timeinfo.tm_hour >= 10)
      {
        if (g_lastPhoneSeenMs == 0 || timeSinceSeen > PHONE_ABSENCE_TIMEOUT_MS)
        {
          if (g_fixedSetpoint > 15.0)
          {
            g_fixedSetpoint = 15.0;
            g_fixedPreset = "auto_eco";
            saveFixedSetpoint();
          }
        }
      }
    }

    // Thermostat Hysteresis
    if (!g_malfunctionState)
    {
      if (g_lastTempC < (g_fixedSetpoint - HYST_BAND_C / 2))
        g_lastAction = 1;
      else if (g_lastTempC > (g_fixedSetpoint + HYST_BAND_C / 2))
        g_lastAction = 0;
    }

    // ESP-NOW Report
    JsonDocument jtx;
    jtx["heater"] = (g_lastAction == 1) ? "ON" : "OFF";
    jtx["temp"] = g_lastTempC;
    jtx["id"] = 12;
    char buf[128];
    serializeJson(jtx, buf);
    esp_now_send(TARGET, (uint8_t *)buf, strlen(buf));
  }

  // 3. HTTP SYNC (Every 30 Seconds)
  static uint32_t lastHttp = 0;
  if (now - lastHttp > HTTP_SYNC_INTERVAL)
  {
    lastHttp = now;
    if (WiFi.status() == WL_CONNECTED)
    {
      struct tm t_sync;
      getLocalTime(&t_sync);
      bool night = (t_sync.tm_hour >= 0 && t_sync.tm_hour < 6);
      cesanaReportAndFetch(g_lastTempC, (g_lastAction == 1), g_fixedSetpoint, night);
      LOG_PRINTF("[STATUS] T:%.1f SP:%.1f Action:%d\n", g_lastTempC, g_fixedSetpoint, g_lastAction);
    }
  }

  // 4. BLE SCAN (Every 30 Seconds, Offset by 15s from HTTP)
  static uint32_t lastBle = 0;
  if (now - lastBle > BLE_SCAN_INTERVAL && (now - lastHttp > 15000))
  {
    lastBle = now;
    runBleScan();
  }
}