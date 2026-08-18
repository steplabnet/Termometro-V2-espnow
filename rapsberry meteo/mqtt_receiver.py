#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import json
import sqlite3
import datetime
from time import monotonic

import paho.mqtt.client as mqtt

# -------- MQTT --------
MQTT_HOST = "stazionemeteo.local"
MQTT_PORT = 1883
MQTT_USER = "stzionemeteo"
MQTT_PASSWORD = "78f25d_78"
# Incoming generic IN-sensor messages (sensors table).
MQTT_TOPIC = "casa/stazionemeteo/IN"
# Office thermostat board (Termometro V2 espnow) telemetry (ufficio table).
MQTT_TOPIC_UFFICIO = "casa/ufficio/data"
# Shelly Pro EM-50 energy meter (energia table). One topic per clamp:
#   em1:1 -> photovoltaic production (always >= 0)
#   em1:0 -> grid exchange: act_power > 0 while importing, < 0 while exporting
MQTT_TOPIC_EM_PV = "centralino/status/em1:1"
MQTT_TOPIC_EM_GRID = "centralino/status/em1:0"
DB_PATH = "/dev/shm/meteo.db"

IN_SENSOR_KEYS = {"sensoreId", "sensorName", "temp", "pressure", "status"}

# The Shelly publishes every couple of seconds; the dashboards only need the
# same ~1/min cadence as the weather logger, so rows are throttled.
ENERGY_WRITE_INTERVAL = 60   # seconds between energia rows
ENERGY_MAX_AGE = 150         # a clamp's reading is ignored once this stale
ENERGY_STARTUP_GRACE = 120   # wait this long for the second clamp before logging
PV_ZERO_THRESHOLD = 10.0     # PV production below this is noise -> recorded as 0 W

# Latest reading per clamp: channel -> (monotonic_seconds, payload dict).
energy_latest = {}
energy_last_write = 0.0
energy_start = monotonic()


def init_db():
    con = sqlite3.connect(DB_PATH)
    con.execute("""
        CREATE TABLE IF NOT EXISTS sensors (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp  TEXT,
            sensoreId  INTEGER,
            sensorName TEXT,
            temp       REAL,
            pressure   REAL,
            status     TEXT
        )
    """)
    # Office thermostat telemetry, fed by the casa/ufficio/data topic.
    con.execute("""
        CREATE TABLE IF NOT EXISTS ufficio (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp   TEXT,
            temp        REAL,
            hum         REAL,
            pres        REAL,
            setpoint    REAL,
            heater      TEXT,
            preset      TEXT,
            malfunction INTEGER
        )
    """)
    # Shelly Pro EM-50 readings, fed by the centralino/status/em1:* topics.
    # casa_power is the derived house load (production + grid exchange).
    con.execute("""
        CREATE TABLE IF NOT EXISTS energia (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp    TEXT,
            pv_power     REAL,
            grid_power   REAL,
            casa_power   REAL,
            pv_voltage   REAL,
            pv_current   REAL,
            pv_pf        REAL,
            grid_voltage REAL,
            grid_current REAL,
            grid_pf      REAL,
            freq         REAL
        )
    """)
    con.commit()
    con.close()


def db_store_sensor(payload, timestamp):
    try:
        con = sqlite3.connect(DB_PATH)
        con.execute("""
            INSERT INTO sensors (timestamp, sensoreId, sensorName, temp, pressure, status)
            VALUES (:timestamp, :sensoreId, :sensorName, :temp, :pressure, :status)
        """, {**payload, "timestamp": timestamp})
        con.commit()
        con.close()
    except Exception as e:
        print("DB write error:", e)


def db_store_ufficio(payload, timestamp):
    try:
        con = sqlite3.connect(DB_PATH)
        con.execute("""
            INSERT INTO ufficio (timestamp, temp, hum, pres, setpoint, heater, preset, malfunction)
            VALUES (:timestamp, :temp, :hum, :pres, :setpoint, :heater, :preset, :malfunction)
        """, {
            "timestamp":   timestamp,
            "temp":        payload.get("temp"),
            "hum":         payload.get("hum"),
            "pres":        payload.get("pres"),
            "setpoint":    payload.get("setpoint"),
            "heater":      payload.get("heater"),
            "preset":      payload.get("preset"),
            "malfunction": 1 if payload.get("malfunction") else 0,
        })
        con.commit()
        con.close()
    except Exception as e:
        print("DB write error (ufficio):", e)


def db_store_energy(row):
    try:
        con = sqlite3.connect(DB_PATH)
        con.execute("""
            INSERT INTO energia (
                timestamp, pv_power, grid_power, casa_power,
                pv_voltage, pv_current, pv_pf,
                grid_voltage, grid_current, grid_pf, freq
            ) VALUES (
                :timestamp, :pv_power, :grid_power, :casa_power,
                :pv_voltage, :pv_current, :pv_pf,
                :grid_voltage, :grid_current, :grid_pf, :freq
            )
        """, row)
        con.commit()
        con.close()
    except Exception as e:
        print("DB write error (energia):", e)


def handle_energy(channel, payload):
    """Buffer one clamp reading and, at most once per ENERGY_WRITE_INTERVAL,
    write the merged PV + grid snapshot to the energia table.

    Each clamp arrives on its own topic, so the two are merged here. A clamp
    that stopped publishing drops out (ENERGY_MAX_AGE) instead of freezing its
    last value into every subsequent row.
    """
    global energy_last_write

    mono = monotonic()
    energy_latest[channel] = (mono, payload)

    if mono - energy_last_write < ENERGY_WRITE_INTERVAL:
        return

    # Right after startup only one clamp may have reported yet; hold off briefly
    # so the first row carries both. Past the grace period a silent clamp is
    # recorded as NULL instead of blocking the log.
    if len(energy_latest) < 2 and mono - energy_start < ENERGY_STARTUP_GRACE:
        return

    energy_last_write = mono

    def fresh(ch):
        entry = energy_latest.get(ch)
        if not entry or mono - entry[0] > ENERGY_MAX_AGE:
            return {}
        return entry[1]

    pv = fresh(1)
    grid = fresh(0)
    if not pv and not grid:
        return

    pv_power = pv.get("act_power")
    # Below the threshold the inverter is not really producing (clamp leakage,
    # standby draw); record a clean 0 W so the series and the derived house
    # load do not carry that noise.
    if pv_power is not None and abs(pv_power) < PV_ZERO_THRESHOLD:
        pv_power = 0.0
    grid_power = grid.get("act_power")
    # House load = what the panels make plus what the grid supplies (a negative
    # grid_power means surplus is being exported, so it subtracts).
    casa_power = (
        pv_power + grid_power
        if pv_power is not None and grid_power is not None
        else None
    )

    row = {
        "timestamp":    now(),
        "pv_power":     pv_power,
        "grid_power":   grid_power,
        "casa_power":   casa_power,
        "pv_voltage":   pv.get("voltage"),
        "pv_current":   pv.get("current"),
        "pv_pf":        pv.get("pf"),
        "grid_voltage": grid.get("voltage"),
        "grid_current": grid.get("current"),
        "grid_pf":      grid.get("pf"),
        "freq":         pv.get("freq", grid.get("freq")),
    }
    db_store_energy(row)
    print(f"[{row['timestamp']}] Energy stored to DB "
          f"(pv={pv_power}, grid={grid_power}, casa={casa_power})")


def on_connect(client, userdata, flags, reason_code, properties):
    if reason_code == 0:
        print(f"[{now()}] Connected to MQTT broker {MQTT_HOST}:{MQTT_PORT}")
        client.subscribe(MQTT_TOPIC)
        client.subscribe(MQTT_TOPIC_UFFICIO)
        client.subscribe(MQTT_TOPIC_EM_PV)
        client.subscribe(MQTT_TOPIC_EM_GRID)
        print(f"[{now()}] Subscribed to topics: {MQTT_TOPIC}, {MQTT_TOPIC_UFFICIO}, "
              f"{MQTT_TOPIC_EM_PV}, {MQTT_TOPIC_EM_GRID}")
    else:
        print(f"[{now()}] Connection failed, reason code: {reason_code}")


def on_disconnect(client, userdata, flags, reason_code, properties):
    print(f"[{now()}] Disconnected, reason code: {reason_code}")


def on_message(client, userdata, msg):
    timestamp = now()
    # Energy messages arrive every couple of seconds, so they are handled
    # quietly (handle_energy logs only the row it actually stores) and the
    # journal stays readable for the sensor topics.
    is_energy = msg.topic in (MQTT_TOPIC_EM_PV, MQTT_TOPIC_EM_GRID)
    if not is_energy:
        print(f"\n[{timestamp}] Message on topic: {msg.topic}")
    try:
        payload = json.loads(msg.payload.decode("utf-8"))
        if is_energy:
            channel = 1 if msg.topic == MQTT_TOPIC_EM_PV else 0
            handle_energy(channel, payload)
            return
        print(json.dumps(payload, indent=2))
        if msg.topic == MQTT_TOPIC_UFFICIO:
            db_store_ufficio(payload, timestamp)
            print(f"[{timestamp}] Office data stored to DB "
                  f"(temp={payload.get('temp')}, heater={payload.get('heater')})")
        elif IN_SENSOR_KEYS.issubset(payload.keys()):
            db_store_sensor(payload, timestamp)
            print(f"[{timestamp}] Sensor data stored to DB (sensoreId={payload['sensoreId']})")
    except json.JSONDecodeError:
        print("Raw payload:", msg.payload.decode("utf-8", errors="replace"))


def now():
    return datetime.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")


def main():
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    client.username_pw_set(MQTT_USER, MQTT_PASSWORD)
    client.on_connect = on_connect
    client.on_disconnect = on_disconnect
    client.on_message = on_message

    init_db()
    print(f"[{now()}] Connecting to {MQTT_HOST}:{MQTT_PORT}...")
    client.connect(MQTT_HOST, MQTT_PORT, keepalive=60)
    client.loop_forever()


if __name__ == "__main__":
    main()
