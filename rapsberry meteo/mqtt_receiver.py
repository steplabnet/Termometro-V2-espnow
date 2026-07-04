#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import json
import sqlite3
import datetime
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
DB_PATH = "/dev/shm/meteo.db"

IN_SENSOR_KEYS = {"sensoreId", "sensorName", "temp", "pressure", "status"}


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


def on_connect(client, userdata, flags, reason_code, properties):
    if reason_code == 0:
        print(f"[{now()}] Connected to MQTT broker {MQTT_HOST}:{MQTT_PORT}")
        client.subscribe(MQTT_TOPIC)
        client.subscribe(MQTT_TOPIC_UFFICIO)
        print(f"[{now()}] Subscribed to topics: {MQTT_TOPIC}, {MQTT_TOPIC_UFFICIO}")
    else:
        print(f"[{now()}] Connection failed, reason code: {reason_code}")


def on_disconnect(client, userdata, flags, reason_code, properties):
    print(f"[{now()}] Disconnected, reason code: {reason_code}")


def on_message(client, userdata, msg):
    timestamp = now()
    print(f"\n[{timestamp}] Message on topic: {msg.topic}")
    try:
        payload = json.loads(msg.payload.decode("utf-8"))
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
