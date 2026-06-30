#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import json
import sqlite3
import datetime
import urllib.request
from time import sleep, time

import numpy as np
import board
import busio
from RPi import GPIO

# -------- ADS1115 --------
import adafruit_ads1x15.ads1115 as ADS
from adafruit_ads1x15.analog_in import AnalogIn

# -------- Tinkerforge --------
from tinkerforge.ip_connection import IPConnection
from tinkerforge.bricklet_outdoor_weather import BrickletOutdoorWeather

# -------- MQTT --------
import paho.mqtt.client as mqtt

# -------- GPIO / FAN --------
GPIO.setmode(GPIO.BCM)
GPIO.setup(15, GPIO.OUT)
GPIO.output(15, GPIO.LOW)
fanMode = 0  # ventola spenta

# -------- ADS1115 / I2C --------
i2c = busio.I2C(board.SCL, board.SDA)
ads = ADS.ADS1115(i2c)
chan = AnalogIn(ads, 0, 1)  # differential

ADCgains = [1, 2, 4, 8, 16]
ads.gain = 1
adcGainIdx = 0

# -------- Tinkerforge --------
HOST = "localhost"
PORT = 4223
UID = "EEL"  # weather bricklet uid
mainStation = 146
sensoreTemperatura = 138
sensoreTemperatura2 = 96

# -------- MQTT --------
MQTT_HOST = "stazionemeteo.local"
MQTT_PORT = 1883
MQTT_USER = "stzionemeteo"
MQTT_PASSWORD = "78f25d_78"
MQTT_TOPIC = "casa/stazionemeteo/OUT"

# -------- Globals --------
log_int = 1000  # seconds
tb = 0
urltime = 0

tensioni = [0.0] * 20
powerPrev = 0
fanHistory = np.array([48] * 10, dtype=float)  # moving avg of CPU temp


DB_PATH = "/dev/shm/meteo.db"


def init_db():
    con = sqlite3.connect(DB_PATH)
    con.execute("""
        CREATE TABLE IF NOT EXISTS meteo (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp TEXT,
            tMobile   REAL, hMobile INTEGER, fan INTEGER, tempCpu REAL,
            temp      REAL, humi INTEGER,
            wind      REAL, gust REAL, rain REAL, wdir INTEGER,
            tombra    REAL, hombra INTEGER,
            chip      REAL, pres INTEGER, power INTEGER,
            mean_voltage REAL, adc_voltage REAL, adc_raw INTEGER,
            station_id INTEGER, data_valid INTEGER
        )
    """)
    # Safe migration: add hMobile to pre-existing tmpfs DBs (ignored if already present)
    try:
        con.execute("ALTER TABLE meteo ADD COLUMN hMobile INTEGER")
    except sqlite3.OperationalError:
        pass
    con.commit()
    con.close()


def db_store_payload(payload):
    try:
        con = sqlite3.connect(DB_PATH)
        con.execute("""
            INSERT INTO meteo (
                timestamp, tMobile, hMobile, fan, tempCpu, temp, humi,
                wind, gust, rain, wdir, tombra, hombra,
                chip, pres, power, mean_voltage, adc_voltage, adc_raw,
                station_id, data_valid
            ) VALUES (
                :timestamp, :tMobile, :hMobile, :fan, :tempCpu, :temp, :humi,
                :wind, :gust, :rain, :wdir, :tombra, :hombra,
                :chip, :pres, :power, :mean_voltage, :adc_voltage, :adc_raw,
                :station_id, :data_valid
            )
        """, payload)
        con.commit()
        con.close()
    except Exception as e:
        print("DB write error:", e)


def scan_for_active_station(ow, start_id=1, end_id=255):
    """
    Scans for a station ID that returns valid, recent data.
    Returns the first valid station ID found, or None if none found.
    """
    print(f"Scanning for new station ID between {start_id} and {end_id}...")
    for station_id in range(start_id, end_id + 1):
        try:
            dati = ow.get_station_data(station_id)
            # Check if data is recent (last change < 1200 seconds)
            if dati[7] < 1200:
                print(f"Found active station: {station_id}")
                return station_id
        except Exception:
            continue
    return None


def left_shift(arr, value):
    for i in range(len(arr) - 1):
        arr[i] = arr[i + 1]
    arr[len(arr) - 1] = value
    return arr


def volt_average(arr):
    # average first 9 elements (like original)
    s = 0.0
    for i in range(9):
        s += arr[i]
    return s / 9.0


def temperature_of_raspberry_pi():
    global fanMode, fanHistory

    cpu_temp = os.popen("vcgencmd measure_temp").readline().strip()
    cpu_temp = cpu_temp.replace("'C", "").replace("temp=", "")

    try:
        thermoTemp = float(cpu_temp)
    except ValueError:
        thermoTemp = fanHistory[-1]

    # update rolling array (size 10)
    fanHistory = np.roll(fanHistory, -1)
    fanHistory[-1] = thermoTemp
    ctMedia = fanHistory.mean()

    if ctMedia > 53:
        GPIO.output(15, GPIO.HIGH)
        fanMode = 1
    elif ctMedia < 49:
        GPIO.output(15, GPIO.LOW)
        fanMode = 0

    return float(ctMedia)


def log_write(message):
    log_paths = [
        "/home/pi/logs/logs.txt",
        "/home/admin/logs/logs.txt",
        "./logs.txt",
    ]

    now = datetime.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")

    for path in log_paths:
        try:
            (
                os.makedirs(os.path.dirname(path), exist_ok=True)
                if "/" in path and os.path.dirname(path)
                else None
            )
            with open(path, "a") as f:
                f.write(f"{message}:{now}\n")
            return
        except Exception:
            continue

    print("Log error: unable to write log file")


def autogain_read():
    """Return (voltage, raw) with gain adjustment"""
    global adcGainIdx

    max_v = 0.0
    max_raw = 0

    for _ in range(6):
        max_v = 0.0
        max_raw = 0

        for _ in range(100):
            try:
                v = abs(chan.voltage)
                r = abs(chan.value)
            except Exception as e:
                print("ADS1115 read error:", e)
                return 0.0, 0

            if v > max_v:
                max_v = v
            if r > max_raw:
                max_raw = r

        if max_raw > 32000 and ads.gain > 1:
            adcGainIdx = max(0, adcGainIdx - 1)
            ads.gain = ADCgains[adcGainIdx]
            print("gain:", ads.gain)
        elif max_raw < 16000 and ads.gain < 16:
            adcGainIdx = min(4, adcGainIdx + 1)
            ads.gain = ADCgains[adcGainIdx]
            print("gain:", ads.gain)
        else:
            return max_v, max_raw

    return max_v, max_raw


def mqtt_connect():
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    client.username_pw_set(MQTT_USER, MQTT_PASSWORD)
    client.connect(MQTT_HOST, MQTT_PORT, 60)
    client.loop_start()
    return client


def mqtt_publish_payload(client, payload):
    try:
        info = client.publish(MQTT_TOPIC, json.dumps(payload), qos=0, retain=True)
        print("MQTT publish queued, rc:", info.rc)
    except Exception as e:
        print("MQTT publish error:", e)
        try:
            client.reconnect()
            info = client.publish(MQTT_TOPIC, json.dumps(payload), qos=0, retain=True)
            print("MQTT publish OK after reconnect, rc:", info.rc)
        except Exception as e2:
            print("MQTT reconnect/publish error:", e2)


def main():
    global tb, urltime, tensioni, powerPrev

    current_main_station = mainStation

    # connect once
    ipcon = IPConnection()
    ow = BrickletOutdoorWeather(UID, ipcon)
    ipcon.connect(HOST, PORT)

    mqtt_client = mqtt_connect()
    init_db()

    # BME280 removed: use neutral fallback values
    temperature = 0.0
    pressure = 0
    humidity = 0

    while True:
        print("Temperature :", temperature, "C")

        sleep(1)

        # read ADS with autogain
        voltage, raw_value = autogain_read()
        print("volt:", voltage)
        print("raw:", raw_value)

        left_shift(tensioni, voltage)
        mean_v = volt_average(tensioni)
        print("mean:", mean_v)

        power = max(230 * (mean_v - 0.0102) * 30 / 1.08, 0)

        if abs(power - powerPrev) < 1000:
            powerPrev = power
        else:
            powerPrev = power

        now = time()

        if now - tb > log_int:
            log_write("meteo up")
            tb = now

        if now - urltime > 60:
            urltime = now
            data_valid = False
            dati = None

            # 1. Try to read from the current known station
            try:
                dati = ow.get_station_data(current_main_station)
                lastChangeStation = dati[7]

                # Check if data is fresh (less than 20 mins old)
                if lastChangeStation < 1200:
                    temp = dati[0] / 10.0
                    data_valid = True
                    print(
                        f"Main station {current_main_station} updated {lastChangeStation}s ago."
                    )
                else:
                    print(
                        f"Main station {current_main_station} data is too old ({lastChangeStation}s)."
                    )
            except Exception:
                print(f"Main station {current_main_station} not responding.")

            # 2. If current station failed or is old, scan for a new one
            if not data_valid:
                new_id = scan_for_active_station(ow)
                if new_id:
                    current_main_station = new_id
                    try:
                        dati = ow.get_station_data(current_main_station)
                        temp = dati[0] / 10.0
                        data_valid = True
                        print(f"Switched to active station: {current_main_station}")
                    except Exception:
                        data_valid = False

            # 3. Final fallback if everything failed
            if not data_valid:
                temp = -100
                print("No active weather station found.")

            # --- Sensor 2 (Mobile) ---
            try:
                tMobile = ow.get_sensor_data(sensoreTemperatura2)
                temp2 = tMobile[0] / 10.0
                hum_mobile = tMobile[1]
                if tMobile[2] > 1200:  # data too old
                    temp2 = -100
                    hum_mobile = -1
            except Exception:
                temp2 = -100
                hum_mobile = -1

            # --- Sensor 1 (Ombra) ---
            try:
                ombra = ow.get_sensor_data(sensoreTemperatura)
                temp1 = ombra[0] / 10.0
                hum_ombra = ombra[1]
                if ombra[2] > 1200:
                    temp1 = -100
            except Exception:
                temp1 = -100
                hum_ombra = -1

            # --- fan / cpu temp ---
            tempCpu = temperature_of_raspberry_pi()

            humi_value = dati[1] if data_valid else humidity
            wind_value = dati[2] / 10.0 if data_valid else 0
            gust_value = dati[3] / 10.0 if data_valid else 0
            rain_value = dati[4] / 10.0 if data_valid else 0
            wdir_value = dati[5] if data_valid else 0

            # --- Build HTTP URL ---
            url_string = (
                "https://cesana.steplab.net/carica_dati.php"
                f"?tMobile={temp2}"
                f"&hMobile={hum_mobile}"
                f"&fan={fanMode}"
                f"&tempCpu={tempCpu}"
                f"&temp={temp}"
                f"&humi={humi_value}"
                f"&wind={wind_value}"
                f"&gust={gust_value}"
                f"&rain={rain_value}"
                f"&wdir={wdir_value}"
                f"&tombra={temp1}"
                f"&hombra={hum_ombra}"
                f"&chip={temperature}"
                f"&pres={round(pressure)}"
                f"&power={int(power)}"
            )
            print(url_string)

            try:
                weburl = urllib.request.urlopen(url_string, timeout=5)
                print("Server response code:", weburl.getcode())
            except Exception as e:
                print("Network Connection Error:", e)

            # --- MQTT JSON publish ---
            payload = {
                "tMobile": temp2,
                "hMobile": hum_mobile,
                "fan": fanMode,
                "tempCpu": round(tempCpu, 2),
                "temp": temp,
                "humi": humi_value,
                "wind": wind_value,
                "gust": gust_value,
                "rain": rain_value,
                "wdir": wdir_value,
                "tombra": temp1,
                "hombra": hum_ombra,
                "chip": temperature,
                "pres": round(pressure),
                "power": int(power),
                "mean_voltage": round(mean_v, 6),
                "adc_voltage": round(voltage, 6),
                "adc_raw": int(raw_value),
                "station_id": current_main_station if data_valid else -1,
                "data_valid": data_valid,
                "timestamp": datetime.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ"),
            }
            print("MQTT payload:", payload)
            mqtt_publish_payload(mqtt_client, payload)
            db_store_payload(payload)


if __name__ == "__main__":
    try:
        main()
    finally:
        GPIO.cleanup()
