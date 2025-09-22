// src/main.cpp  (SENDER with DS18B20 on D5)
#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <espnow.h>
#include <ArduinoJson.h>
#include <OneWire.h>
#include <DallasTemperature.h>

extern "C"
{
#include "user_interface.h"
}

// ===== Wi-Fi credentials =====
static const char *WIFI_SSID = "NETGEAR11";
static const char *WIFI_PASS = "breezypiano838";

// ===== DS18B20 on D5 (GPIO14) =====
#define ONE_WIRE_BUS D4 // Wemos D1 mini alias for GPIO14
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);

// ===== Target: start with broadcast, later switch to unicast =====
static uint8_t TARGET[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
// After it works, replace with the receiver MAC:
// static uint8_t TARGET[6] = { 0x84,0xF3,0xEB,0xAA,0xBB,0xCC };

static void printMac(const uint8_t *mac)
{
  for (int i = 0; i < 6; ++i)
  {
    if (i)
      Serial.print(":");
    Serial.printf("%02X", mac[i]);
  }
}

static void onDataSent(uint8_t *mac, uint8_t status)
{
  Serial.print("[TX] Sent to ");
  printMac(mac);
  Serial.print(" -> status=");
  Serial.println(status == 0 ? "OK" : "ERR");
}

static void connectWiFi()
{
  Serial.printf("[TX] Connecting to %s ...\n", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.persistent(false);
  WiFi.disconnect(true);
  delay(100);
  WiFi.begin(WIFI_SSID, WIFI_PASS);

  const uint32_t T0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - T0 < 15000)
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
    Serial.println("[TX] WiFi timeout; continuing anyway.");
  }
}

void setup()
{
  Serial.begin(115200);
  delay(200);

  // DS18B20 init
  sensors.begin();
  // Optional: set 9–12 bit resolution (default often 12)
  sensors.setResolution(12);
  Serial.println("[DS18B20] Started on D5");

  connectWiFi();

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
  Serial.println("[TX] Sender ready. Sending JSON with DS18B20 temp...");
}

void loop()
{
  static uint32_t counter = 0;

  // Request and read temperature
  sensors.requestTemperatures();
  float tempC = sensors.getTempCByIndex(0); // first sensor

  bool valid = (tempC != DEVICE_DISCONNECTED_C && tempC > -100 && tempC < 125);

  // Build JSON
  JsonDocument doc;
  doc["type"] = "telemetry";
  doc["count"] = counter;
  if (valid)
  {
    doc["temp"] = tempC;
  }
  else
  {
    doc["temp"] = nullptr; // or comment this line to omit the field
  }
  doc["note"] = "d1mini-ds18b20@D5";

  char buf[200];
  size_t n = serializeJson(doc, buf, sizeof(buf)); // keep < ~250B

  // esp_now_send needs non-const pointer + int len on ESP8266
  uint8_t rc = esp_now_send(TARGET, (uint8_t *)buf, (int)n);

  Serial.print("[TX] send -> ");
  if (rc == 0)
  {
    Serial.println("OK");
  }
  else
  {
    Serial.println(rc);
  }

  counter++;
  delay(1000);
}
