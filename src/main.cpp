#include <Arduino.h>
#include <OneWire.h>
#include <DallasTemperature.h>

// DS18B20 data pin on D4 (GPIO2)
#define ONE_WIRE_BUS D4

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);

void setup() {
  Serial.begin(115200);
  delay(500);

  pinMode(ONE_WIRE_BUS, INPUT_PULLUP); // enable internal pull-up, still add external 4.7kΩ to 3.3V

  Serial.println("Starting DS18B20 demo...");

  sensors.begin();
  sensors.setWaitForConversion(true); // block until conversion is complete
  sensors.setResolution(12);

  int count = sensors.getDeviceCount();
  Serial.printf("Devices found on bus: %d\n", count);

  if (count == 0) {
    Serial.println("⚠️  No DS18B20 sensors detected. Check wiring & pull-up resistor!");
  }
}

void loop() {
  sensors.requestTemperatures(); // start conversion, waits until ready
  float tempC = sensors.getTempCByIndex(0);

  if (tempC == DEVICE_DISCONNECTED_C || tempC < -55 || tempC > 125) {
    Serial.println("Failed to read from DS18B20 sensor");
  } else {
    Serial.printf("Temperature: %.2f °C\n", tempC);
  }

  delay(1000);
}
