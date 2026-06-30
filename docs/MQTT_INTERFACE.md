# MQTT Interface — Ufficio Thermostat (ESP32-C3)

This document describes the MQTT contract exposed by the ESP32-C3 office
thermostat (`src/main.cpp`). Use it to build or modify a **receiver** — any
client that consumes the thermostat's telemetry and/or controls its heater.

> Context for an assistant: the device is the *publisher of telemetry* and the
> *subscriber of commands*. A "receiver" here is the counterpart: it subscribes
> to telemetry and publishes commands. The heater (caldaia) is controlled
> **exclusively** over MQTT — there is no longer any onboard thermostat logic
> driving it.

---

## Broker connection

| Setting   | Value                  |
|-----------|------------------------|
| Host      | `stazionemeteo.local`  |
| Port      | `1883` (plain TCP, no TLS) |
| Username  | `stzionemeto`          |
| Password  | `78f25d_78`            |
| Client ID | device uses `esp32-thermo-<mac-hex>`; pick a **different**, unique ID for the receiver |

No TLS, no client certificate. QoS 0 is used by the device. Retained flag is
**not** set on telemetry.

---

## Topics

| Direction (device POV) | Topic                 | Payload        | Who publishes |
|------------------------|-----------------------|----------------|---------------|
| Outbound (telemetry)   | `casa/ufficio/data`   | State JSON     | Device → receiver |
| Inbound (commands)     | `casa/ufficio/command`| Command JSON   | Receiver → device |

The receiver should therefore:
- **Subscribe** to `casa/ufficio/data`
- **Publish** to `casa/ufficio/command`

---

## Telemetry: `casa/ufficio/data`

Published every **30 seconds**. JSON object:

```json
{
  "temp": 16.8,
  "hum": 54.2,
  "pres": 1013.4,
  "setpoint": 17.0,
  "heater": "ON",
  "preset": "on",
  "malfunction": false,
  "time": "14:32:07"
}
```

| Field         | Type        | Unit  | Meaning |
|---------------|-------------|-------|---------|
| `temp`        | number      | °C    | BME280 temperature, with a −1.0 °C calibration offset already applied |
| `hum`         | number      | %RH   | Relative humidity |
| `pres`        | number      | hPa   | Barometric pressure |
| `setpoint`    | number      | °C    | Reported target only — **does not** drive the heater |
| `heater`      | string      | —     | `"ON"` or `"OFF"` — current relay/boiler state |
| `preset`      | string      | —     | Reported label only (e.g. `"on"`); no control effect |
| `malfunction` | boolean     | —     | `true` if the BME280 failed to initialize at boot |
| `time`        | string      | —     | Local time `HH:MM:SS`, or `"00:00:00"` before NTP sync |

### Receiver-side parsing notes
- Before the first sensor read or NTP sync, `temp` / `hum` / `pres` may arrive
  as JSON `null` (they start as `NaN` on the device). Handle `null` gracefully.
- `setpoint` and `preset` are informational. Do **not** assume changing them
  changes heater behavior.
- Telemetry is not retained: a freshly connected receiver waits up to 30 s for
  the first message.

---

## Commands: `casa/ufficio/command`

The device subscribes to this topic and parses each message as JSON. All fields
are optional; send only what you want to change.

```json
{ "heater": "ON" }
```

| Field      | Type             | Accepted values | Effect |
|------------|------------------|-----------------|--------|
| `heater`   | string / bool / int | `"ON"`/`"OFF"` (case-insensitive), `true`/`false`, `"1"`/`"0"` | Switches the boiler relay immediately and pushes the new state to the relay over ESP-NOW |
| `setpoint` | number           | any float       | Updates the reported `setpoint` and persists it; **no heating effect** |
| `preset`   | string           | any string      | Updates the reported `preset` and persists it; **no heating effect** |

Examples:

```json
{ "heater": "OFF" }
```
```json
{ "heater": true, "setpoint": 19.5, "preset": "comfort" }
```

### Command behavior
- A heater command takes effect **instantly** (no minimum on/off cycle guard).
  If the boiler needs a minimum-cycle protection, the receiver must enforce it,
  or it must be added to the device firmware.
- Invalid JSON is ignored (logged as `MQTT command: bad JSON` on the device).
- `setpoint`/`preset` changes are saved to the device's flash (`/fixed.json`)
  and survive reboots; the last MQTT-set `heater` state does **not** persist
  across reboot (defaults to `OFF`).

---

## Suggested receiver responsibilities

A typical receiver (e.g. Home Assistant, Node-RED, or a custom controller)
would:

1. Subscribe to `casa/ufficio/data`, store/display the latest telemetry.
2. Run whatever control policy you want (schedule, presence, target temp,
   hysteresis) and translate it into `heater` ON/OFF commands.
3. Publish those commands to `casa/ufficio/command`.
4. Optionally watch `malfunction == true` and raise an alert (sensor fault).
5. Optionally watch telemetry staleness (no message > ~90 s) as a liveness/loss
   signal, since messages are not retained.

> If you re-introduce automatic heating control, decide whether it lives in the
> receiver (recommended — keeps the device a dumb actuator) or back in the
> firmware. They must not both drive the heater, or they will fight.

---

## Quick test with `mosquitto`

Subscribe to telemetry:
```bash
mosquitto_sub -h stazionemeteo.local -p 1883 \
  -u stzionemeto -P 78f25d_78 -t casa/ufficio/data -v
```

Turn the heater on:
```bash
mosquitto_pub -h stazionemeteo.local -p 1883 \
  -u stzionemeto -P 78f25d_78 -t casa/ufficio/command -m '{"heater":"ON"}'
```

Turn it off:
```bash
mosquitto_pub -h stazionemeteo.local -p 1883 \
  -u stzionemeto -P 78f25d_78 -t casa/ufficio/command -m '{"heater":"OFF"}'
```
