/*
 * ======================================================================================
 * PROJECT: ESP32-C3 Smart Office Thermostat (BME280 VERSION)
 * TARGET TEMPERATURE: 17.0°C (Comfort Mode)
 * ======================================================================================
 */

#include <Arduino.h>
#include <WiFi.h>
#include <esp_now.h>
#include <ArduinoJson.h>
#include <Wire.h>
#include <Adafruit_Sensor.h>
#include <Adafruit_BME280.h>
#include <LittleFS.h>
#include <WebServer.h>
#include <ESPmDNS.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>
#include <Ticker.h>
#include <time.h>
#include <Adafruit_NeoPixel.h>
#include <ArduinoOTA.h>
#include <TelnetStream.h>
#include <esp_task_wdt.h>

// ======================================================================================
// CONFIGURATION & PINS
// ======================================================================================
#define WDT_TIMEOUT_MS 15000
#define RGB_PIN 8
#define I2C_SDA 7
#define I2C_SCL 6

static const char *WIFI_SSID_DEFAULT = "NETGEAR11";
static const char *WIFI_PASS_DEFAULT = "breezypiano838";
static const char *AP_SSID = "termometroUff";
static const char *AP_PASS = "12345678";
static const char *HOSTNAME = "esp32-thermo";

const uint32_t HTTP_SYNC_INTERVAL = 30000;
const uint32_t LOGIC_INTERVAL = 10000;

// MQTT Settings
static const char *MQTT_HOST = "stazionemeteo.local";
static const uint16_t MQTT_PORT = 1883;
static const char *MQTT_USER = "stzionemeteo";
static const char *MQTT_PASS = "78f25d_78";
static const char *MQTT_TOPIC_OUT = "casa/ufficio/data";
static const char *MQTT_TOPIC_IN = "casa/ufficio/command";
const uint32_t MQTT_PUBLISH_INTERVAL = 30000;

const char *ntpServer = "pool.ntp.org";
const long gmtOffset_sec = 3600;
const int daylightOffset_sec = 3600;

// Logic Settings
static float g_fixedSetpoint = 17.0f; // UPDATED TO 17
static String g_fixedPreset = "on";

// ======================================================================================
// GLOBALS
// ======================================================================================
Adafruit_BME280 bme;
WebServer server(80);
WiFiClient mqttWifiClient;
PubSubClient mqtt(mqttWifiClient);
Adafruit_NeoPixel pixels(1, RGB_PIN, NEO_GRB + NEO_KHZ800);
Ticker ledBlinker;

uint8_t TARGET[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
esp_now_peer_info_t peerInfo;

volatile float g_lastTempC = NAN;
volatile float g_lastHumidity = NAN;
volatile float g_lastPressure = NAN;
volatile uint8_t g_lastAction = 0;
bool g_otaInProgress = false;
bool g_ledState = false;
bool g_isBlinking = false;
uint32_t g_blinkColor = 0;
bool g_malfunctionState = false;

uint32_t g_lastRelayChangeMs = 0;
const uint32_t MIN_RELAY_TIME = 180000;

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

bool g_waitingForTimer = false;
uint32_t g_colorA = 0;
uint32_t g_colorB = 0;
float g_currentBlinkRate = 0.5;

// ======================================================================================
// HELPERS & STORAGE
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
    g_fixedSetpoint = doc["setpoint"] | 17.0; // Updated fallback to 17
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

void updateLedDisplay()
{
  if (g_otaInProgress)
    return;
  uint32_t nextColorA = 0;
  uint32_t nextColorB = 0;
  float nextRate = 0.5;

  if (g_waitingForTimer)
  {
    nextColorA = pixels.Color(150, 0, 0); // Safety Timer Red
    nextColorB = 0;
    nextRate = 0.5;
  }
  else if (g_lastAction == 1)
  {
    nextColorA = pixels.Color(0, 150, 0);
    nextColorB = pixels.Color(0, 0, 150);
    nextRate = 0.1;
  }

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

static bool cesanaReportAndFetch(float tempC, bool heating, float realSp, bool isNightMode, float hum, float pres)
{
  if (WiFi.status() != WL_CONNECTED || g_otaInProgress)
    return false;
  WiFiClientSecure client;
  client.setInsecure();
  HTTPClient https;
  String url = "https://cesana.steplab.net/get_setpoint.php?temp=" + String(tempC, 1) +
               "&cald=" + (heating ? "1" : "0") +
               "&real=" + String(realSp, 1) +
               "&humi=" + String(hum, 1) +
               "&pres=" + String(pres, 1);

  if (https.begin(client, url))
  {
    int code = https.GET();
    https.end();
    return (code == HTTP_CODE_OK);
  }
  return false;
}

static void sendRelayState()
{
  JsonDocument jtx;
  jtx["heater"] = (g_lastAction == 1) ? "ON" : "OFF";
  jtx["temp"] = g_lastTempC;
  jtx["hum"] = g_lastHumidity;
  jtx["id"] = 12;
  char buf[128];
  serializeJson(jtx, buf);
  esp_now_send(TARGET, (uint8_t *)buf, strlen(buf));
}

static void mqttCallback(char *topic, byte *payload, unsigned int length)
{
  JsonDocument doc;
  if (deserializeJson(doc, payload, length))
  {
    LOG_PRINTLN("MQTT command: bad JSON");
    return;
  }

  // Heater (caldaia) ON/OFF — controlled exclusively via MQTT.
  if (!doc["heater"].isNull())
  {
    JsonVariant h = doc["heater"];
    bool on;
    if (h.is<bool>())
      on = h.as<bool>();
    else
    {
      String s = h.as<String>();
      on = (s.equalsIgnoreCase("ON") || s == "1" || s.equalsIgnoreCase("true"));
    }
    g_lastAction = on ? 1 : 0;
    g_lastRelayChangeMs = millis();
    updateLedDisplay();
    sendRelayState(); // push to relay immediately for responsiveness
    LOG_PRINTF("MQTT heater command: %s\n", on ? "ON" : "OFF");
  }

  bool changed = false;
  if (!doc["setpoint"].isNull())
  {
    g_fixedSetpoint = doc["setpoint"].as<float>();
    changed = true;
  }
  if (!doc["preset"].isNull())
  {
    g_fixedPreset = doc["preset"].as<String>();
    changed = true;
  }

  if (changed)
  {
    saveFixedSetpoint();
    LOG_PRINTF("MQTT command applied: setpoint=%.1f preset=%s\n",
               g_fixedSetpoint, g_fixedPreset.c_str());
  }
}

static bool mqttEnsureConnected()
{
  if (WiFi.status() != WL_CONNECTED || g_otaInProgress)
    return false;
  if (mqtt.connected())
    return true;

  // Throttle reconnect attempts so we never block the loop for long.
  static uint32_t lastAttempt = 0;
  uint32_t now = millis();
  if (lastAttempt != 0 && now - lastAttempt < 5000)
    return false;
  lastAttempt = now;

  String clientId = String(HOSTNAME) + "-" + String((uint32_t)ESP.getEfuseMac(), HEX);
  if (mqtt.connect(clientId.c_str(), MQTT_USER, MQTT_PASS))
  {
    LOG_PRINTLN("MQTT connected");
    mqtt.subscribe(MQTT_TOPIC_IN);
    return true;
  }
  LOG_PRINTF("MQTT connect failed, rc=%d\n", mqtt.state());
  return false;
}

static void mqttPublishState()
{
  if (!mqttEnsureConnected())
    return;

  JsonDocument doc;
  doc["temp"] = g_lastTempC;
  doc["hum"] = g_lastHumidity;
  doc["pres"] = g_lastPressure;
  doc["setpoint"] = g_fixedSetpoint;
  doc["heater"] = (g_lastAction == 1) ? "ON" : "OFF";
  doc["preset"] = g_fixedPreset;
  doc["malfunction"] = g_malfunctionState;
  doc["time"] = getLogTime();

  char buf[256];
  size_t n = serializeJson(doc, buf);

  if (mqtt.publish(MQTT_TOPIC_OUT, buf, n))
  {
    LOG_PRINTLN("MQTT state published");
  }
  else
  {
    LOG_PRINTLN("MQTT publish failed");
  }
}

void setupOTA()
{
  ArduinoOTA.setHostname(HOSTNAME);
  ArduinoOTA.onStart([]()
                     {
    g_otaInProgress = true;
    esp_task_wdt_delete(NULL);
    esp_task_wdt_deinit();
    esp_now_deinit();
    ledBlinker.detach();
    pixels.setPixelColor(0, pixels.Color(150, 0, 255)); pixels.show(); });
  ArduinoOTA.begin();
}

void setup()
{
  Serial.begin(115200);
  LittleFS.begin(true);
  loadFixedSetpoint();
  pixels.begin();
  pixels.show();

  Wire.begin(I2C_SDA, I2C_SCL);
  if (!bme.begin(0x76))
  {
    LOG_PRINTLN("BME280 error!");
    g_malfunctionState = true;
  }

  WiFi.mode(WIFI_AP_STA);
  WiFi.begin(WIFI_SSID_DEFAULT, WIFI_PASS_DEFAULT);

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 8000)
    delay(500);

  if (WiFi.status() == WL_CONNECTED)
  {
    configTime(gmtOffset_sec, daylightOffset_sec, ntpServer);
    TelnetStream.begin();
    setupOTA();
    mqtt.setServer(MQTT_HOST, MQTT_PORT);
    mqtt.setBufferSize(256);
    mqtt.setCallback(mqttCallback);
  }

  esp_now_init();
  memcpy(peerInfo.peer_addr, TARGET, 6);
  esp_now_add_peer(&peerInfo);
  esp_task_wdt_init(&twdt_config);
  esp_task_wdt_add(NULL);
}

void loop()
{
  ArduinoOTA.handle();
  if (g_otaInProgress)
    return;
  esp_task_wdt_reset();
  server.handleClient();
  mqtt.loop();
  uint32_t now = millis();

  // 1. SENSOR READ + RELAY REFRESH
  //    The heater (caldaia) is controlled exclusively via MQTT commands
  //    (see mqttCallback). Here we only refresh sensor readings and
  //    periodically re-send the current heater state to the relay.
  static uint32_t lastLogic = 0;
  if (now - lastLogic > LOGIC_INTERVAL)
  {
    lastLogic = now;

    float t = bme.readTemperature();
    g_lastHumidity = bme.readHumidity();
    g_lastPressure = bme.readPressure() / 100.0F;

    if (t > -40 && t < 85)
    {
      g_lastTempC = t - 1.0f; // Manual calibration offset
    }

    updateLedDisplay();
    sendRelayState();
  }

  // 2. HTTP SYNC
  static uint32_t lastHttp = 0;
  if (now - lastHttp > HTTP_SYNC_INTERVAL)
  {
    lastHttp = now;
    if (WiFi.status() == WL_CONNECTED)
    {
      struct tm t_sync;
      getLocalTime(&t_sync);
      bool night = (t_sync.tm_hour >= 0 && t_sync.tm_hour < 6);
      cesanaReportAndFetch(g_lastTempC, (g_lastAction == 1), g_fixedSetpoint, night, g_lastHumidity, g_lastPressure);
    }
  }

  // 3. MQTT PUBLISH
  static uint32_t lastMqtt = 0;
  if (now - lastMqtt > MQTT_PUBLISH_INTERVAL)
  {
    lastMqtt = now;
    mqttPublishState();
  }
}