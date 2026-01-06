/*
 * ======================================================================================
 * PROJECT: ESP32-C3 Smart Office Thermostat (HEATER-PRIORITY LED VERSION)
 * LOGIC:
 *   - LED RED: Heater ON (Priority 1)
 *   - LED GREEN: Phone Detected & Heater OFF (Priority 2)
 *   - LED BLUE: Phone Signal Found & Heater OFF (Priority 3)
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
const uint32_t HTTP_SYNC_INTERVAL = 30000;
const uint32_t BLE_SCAN_INTERVAL = 5000;
const uint32_t LOGIC_INTERVAL = 2000;

const char *ntpServer = "pool.ntp.org";
const long gmtOffset_sec = 3600;
const int daylightOffset_sec = 3600;

// BLE Settings
const int BLE_RSSI_THRESHOLD = -75;
const int BLE_SCAN_TIME = 1;
#define REQUIRED_HITS 3
#define HIT_WINDOW_MS 60000

// Logic Settings
static float g_fixedSetpoint = 19.0f;
static String g_fixedPreset = "on";
static const float HYST_BAND_C = 0.5f;
const uint32_t PHONE_ABSENCE_TIMEOUT_MS = 60000;
const uint32_t TIMEOUT_HEATER_ON = 300000;
const uint32_t TIMEOUT_HEATER_OFF = 60000;

// ======================================================================================
// GLOBALS
// ======================================================================================
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);
WebServer server(80);
NimBLEScan *pBLEScan = nullptr;
Adafruit_NeoPixel pixels(1, RGB_PIN, NEO_GRB + NEO_KHZ800);
Ticker ledBlinker;

uint8_t TARGET[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
esp_now_peer_info_t peerInfo;

volatile float g_lastTempC = NAN;
volatile uint8_t g_lastAction = 0; // 1 = Heater ON, 0 = Heater OFF
bool g_phoneDetected = false;
int g_currentHits = 0;
bool g_otaInProgress = false;
bool g_ledState = false;
bool g_isBlinking = false;
uint32_t g_blinkColor = 0;
uint32_t g_lastPhoneSeenMs = 0;
unsigned long g_detectionHistory[20] = {0};
int g_historyIndex = 0;
bool g_malfunctionState = false;

uint32_t g_lastRelayChangeMs = 0;       // Memorizza l'ultimo cambio di stato
const uint32_t MIN_RELAY_TIME = 180000; // Tempo minimo di 3 minuti (180.000 ms) tra i cambi

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

bool g_waitingForTimer = false;         // Stato: stiamo aspettando il timer di sicurezza?


// Variabili per il nuovo comportamento del Ticker
uint32_t g_colorA = 0;
uint32_t g_colorB = 0;
float g_currentBlinkRate = 0.5;
// ======================================================================================
// HELPERS
// ======================================================================================

String getLogTime()
{
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo))
    return "00:00:00";
  char buf[10];
  strftime(buf, sizeof(buf), "%H:%M:%S", &timeinfo);
  return String(buf);
}

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
  pixels.setPixelColor(0, g_ledState ? g_colorA : g_colorB);
  pixels.show();
}

// Function to calculate and apply LED status
void updateLedDisplay()
{
  if (g_otaInProgress)
    return;

  uint32_t nextColorA = 0;
  uint32_t nextColorB = 0;
  float nextRate = 0.5;

  if (g_waitingForTimer)
  {
    // STATO: Vorrei cambiare ma il timer me lo impedisce -> ROSSO lampeggiante
    nextColorA = pixels.Color(150, 0, 0);
    nextColorB = 0; // Rosso / Spento
    nextRate = 0.5;
  }
  else if (g_lastAction == 1)
  {
    // STATO: Caldaia ACCESA -> Verde / Blu alternato veloce
    nextColorA = pixels.Color(0, 150, 0); // Verde
    nextColorB = pixels.Color(0, 0, 150); // Blu
    nextRate = 0.1;                       // 100ms
  }
  else if (g_currentHits > 0)
  {
    // STATO: Caldaia spenta, ma telefono rilevato o in ricerca
    nextColorA = g_phoneDetected ? pixels.Color(0, 150, 0) : pixels.Color(0, 0, 150);
    nextColorB = 0;
    nextRate = 0.5;
  }

  // Applica i cambiamenti al Ticker solo se necessario
  if (nextColorA != g_colorA || nextColorB != g_colorB || nextRate != g_currentBlinkRate)
  {
    g_colorA = nextColorA;
    g_colorB = nextColorB;
    g_currentBlinkRate = nextRate;

    ledBlinker.detach();
    if (g_colorA > 0 || g_colorB > 0)
    {
      ledBlinker.attach(g_currentBlinkRate, toggleLed);
      g_isBlinking = true;
    }
    else
    {
      pixels.clear();
      pixels.show();
      g_isBlinking = false;
    }
  }
}

void runBleScan()
{
  if (g_otaInProgress || pBLEScan == nullptr)
    return;

  NimBLEScanResults foundDevices = pBLEScan->start(BLE_SCAN_TIME, false);
  int nearbyCount = 0;
  for (int i = 0; i < (int)foundDevices.getCount(); i++)
  {
    if (foundDevices.getDevice(i).getRSSI() > BLE_RSSI_THRESHOLD)
      nearbyCount++;
  }

  if (nearbyCount > 0)
  {
    g_detectionHistory[g_historyIndex] = millis();
    g_historyIndex = (g_historyIndex + 1) % 20;
  }

  int validHits = 0;
  unsigned long now = millis();
  for (int i = 0; i < 20; i++)
  {
    if (g_detectionHistory[i] != 0 && (now - g_detectionHistory[i] <= HIT_WINDOW_MS))
      validHits++;
  }
  g_currentHits = validHits;

  if (validHits >= REQUIRED_HITS)
  {
    g_phoneDetected = true;
    g_lastPhoneSeenMs = millis();
  }
  else if (validHits == 0)
  {
    g_phoneDetected = false;
  }

  updateLedDisplay(); // Refresh LED color based on new hits
  pBLEScan->clearResults();
}

static bool cesanaReportAndFetch(float tempC, bool heating, float realSp, bool isNightMode)
{
  if (WiFi.status() != WL_CONNECTED || g_otaInProgress)
    return false;
  struct tm t_now;
  if (!getLocalTime(&t_now))
    return false;

  bool isMorningGap = (t_now.tm_hour >= 6 && t_now.tm_hour < 10);
  bool isWeekend = (t_now.tm_wday == 0 || t_now.tm_wday == 6);

  WiFiClientSecure client;
  client.setInsecure();
  client.setTimeout(4000);
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
        bool blockRemote = (isMorningGap && isWeekend && !g_phoneDetected);
        if (!blockRemote && (isNightMode || g_phoneDetected || isMorningGap))
        {
          if (remoteSp > 5.0 && remoteSp < 35.0 && abs(remoteSp - g_fixedSetpoint) > 0.1)
          {
            g_fixedSetpoint = remoteSp;
            g_fixedPreset = "remote_sync";
            saveFixedSetpoint();
          }
        }
      }
    }
    https.end();
    return true;
  }
  return false;
}

void setupOTA()
{
  ArduinoOTA.setHostname(HOSTNAME);
  ArduinoOTA.onStart([]()
                     {
    g_otaInProgress = true;
    esp_task_wdt_delete(NULL); esp_task_wdt_deinit();
    if(pBLEScan) pBLEScan->stop();
    NimBLEDevice::deinit(true);
    pBLEScan = nullptr;
    esp_now_deinit();
    server.stop();
    WiFi.softAPdisconnect(true);
    WiFi.mode(WIFI_STA);
    ledBlinker.detach();
    pixels.setPixelColor(0, pixels.Color(150, 0, 255)); pixels.show(); });
  ArduinoOTA.onEnd([]()
                   { ESP.restart(); });
  ArduinoOTA.onError([](ota_error_t error)
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
  WiFi.begin(WIFI_SSID_DEFAULT, WIFI_PASS_DEFAULT);
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 8000)
    delay(500);
  if (WiFi.status() == WL_CONNECTED)
  {
    configTime(gmtOffset_sec, daylightOffset_sec, ntpServer);
    TelnetStream.begin();
    setupOTA();
  }
  esp_now_init();
  memcpy(peerInfo.peer_addr, TARGET, 6);
  esp_now_add_peer(&peerInfo);
  esp_task_wdt_init(&twdt_config);
  esp_task_wdt_add(NULL);
  LOG_PRINTLN(">>> SYSTEM READY. Heater LED priority set to RED.");
}

void loop()
{
  ArduinoOTA.handle();
  if (g_otaInProgress)
    return;
  esp_task_wdt_reset();
  server.handleClient();
  uint32_t now = millis();

  // 1. BLE SCAN (Every 5 Seconds)
  static uint32_t lastBle = 0;
  if (now - lastBle > BLE_SCAN_INTERVAL)
  {
    lastBle = now;
    runBleScan();
  }

  // 2. THERMOSTAT LOGIC (Every 2 Seconds)
  static uint32_t lastLogic = 0;
  if (now - lastLogic > LOGIC_INTERVAL)
  {
    lastLogic = now;
    sensors.requestTemperatures();
    float t = sensors.getTempCByIndex(0)-1;
    if (t > -50 && t < 100)
      g_lastTempC = t;

    struct tm timeinfo;
    bool timeKnown = getLocalTime(&timeinfo);
    bool isNightMode = (timeKnown && timeinfo.tm_hour >= 0 && timeinfo.tm_hour < 6);
    bool isMorningGap = (timeKnown && timeinfo.tm_hour >= 6 && timeinfo.tm_hour < 10);
    bool isWeekend = (timeKnown && (timeinfo.tm_wday == 0 || timeinfo.tm_wday == 6));

    if (!g_malfunctionState)
    {
      if (isNightMode)
      {
        if (g_fixedSetpoint != 10.0f)
        {
          g_fixedSetpoint = 10.0f;
          saveFixedSetpoint();
        }
      }
      else if (isMorningGap)
      {
        if (g_phoneDetected && g_fixedSetpoint < 18.0)
        {
          g_fixedSetpoint = 18.0;
          saveFixedSetpoint();
        }
        else if (isWeekend && g_fixedSetpoint != 14.0f)
        {
          g_fixedSetpoint = 14.0f;
          saveFixedSetpoint();
        }
      }
      else
      {
        if (g_phoneDetected && g_fixedSetpoint < 18.0)
        {
          g_fixedSetpoint = 18.0;
          saveFixedSetpoint();
        }
        else if (timeKnown && !g_phoneDetected && g_fixedSetpoint > 15.0)
        {
          if (now - g_lastPhoneSeenMs > PHONE_ABSENCE_TIMEOUT_MS)
          {
            g_fixedSetpoint = 15.0;
            saveFixedSetpoint();
          }
        }
      }
    }

    // --- NUOVA LOGICA CON SAFETY TIMER E LED ---
    uint8_t desiredAction = g_lastAction;
    if (g_lastTempC < (g_fixedSetpoint - HYST_BAND_C / 2))
      desiredAction = 1;
    else if (g_lastTempC > (g_fixedSetpoint + HYST_BAND_C / 2))
      desiredAction = 0;

    if (desiredAction != g_lastAction)
    {
      // Vorremmo cambiare stato... controlliamo il timer
      if (now - g_lastRelayChangeMs >= MIN_RELAY_TIME)
      {
        g_lastAction = desiredAction;
        g_lastRelayChangeMs = now;
        g_waitingForTimer = false; // Cambio effettuato
        LOG_PRINTF(">>> [HEATER] Safety OK. New State: %d\n", g_lastAction);
      }
      else
      {
        g_waitingForTimer = true; // Siamo in attesa del timer
      }
    }
    else
    {
      g_waitingForTimer = false; // Lo stato desiderato è quello attuale, niente attesa
    }

    // Aggiorna sempre il display LED a ogni ciclo di logica
    updateLedDisplay();

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
      LOG_PRINTF("[%s] T:%.1f SP:%.1f Heat:%s Phone:%s Hits:%d\n",
                 getLogTime().c_str(), g_lastTempC, g_fixedSetpoint,
                 (g_lastAction == 1) ? "ON" : "OFF", g_phoneDetected ? "YES" : "NO", g_currentHits);
    }
  }
}