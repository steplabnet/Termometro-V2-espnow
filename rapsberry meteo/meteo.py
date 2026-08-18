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
ENERGY_MAX_AGE = 300  # seconds; older Shelly readings are not forwarded
PV_ZERO_THRESHOLD = 10.0     # PV production below this is noise -> treated as 0 W
ROLES_PATH = "/var/www/html/sensor_roles.json"  # persisted role->id map; survives reboot
ONLINE_THRESHOLD = 1200  # seconds; a sensor is "online" if it transmitted more recently than this


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

    # Per-sensor transmission health: one row per remote sensor, upserted each cycle.
    con.execute("""
        CREATE TABLE IF NOT EXISTS sensor_status (
            sensor_key  TEXT PRIMARY KEY,   -- 'main' / 'mobile' / 'ombra'
            kind        TEXT,               -- 'station' or 'sensor'
            identifier  INTEGER,            -- Tinkerforge station/sensor id
            last_change INTEGER,            -- seconds since last reading (as reported by Tinkerforge)
            last_seen   TEXT,               -- absolute UTC time the sensor last transmitted
            updated_at  TEXT,               -- when this row was last refreshed
            online      INTEGER             -- 1 if last_change < ONLINE_THRESHOLD, else 0
        )
    """)
    # Shelly Pro EM-50 readings. Written by mqtt_receiver.py; created here too so
    # read_latest_energy() works even before the receiver has run once.
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


def read_latest_energy(max_age=ENERGY_MAX_AGE):
    """Latest Shelly Pro EM-50 snapshot, or {} when it is missing/stale.

    mqtt_receiver.py owns the energia table; this only reads the freshest row so
    the production/grid figures ride along to the remote server with the rest of
    the telemetry.
    """
    try:
        con = sqlite3.connect(DB_PATH)
        con.row_factory = sqlite3.Row
        row = con.execute(
            "SELECT * FROM energia ORDER BY id DESC LIMIT 1"
        ).fetchone()
        con.close()
    except Exception as e:
        print("Energy read error:", e)
        return {}

    if row is None:
        return {}

    try:
        stamp = datetime.datetime.strptime(row["timestamp"], "%Y-%m-%dT%H:%M:%SZ")
    except (TypeError, ValueError):
        return {}
    if (datetime.datetime.utcnow() - stamp).total_seconds() > max_age:
        print("Energy data is stale, skipping:", row["timestamp"])
        return {}

    energy = {k: row[k] for k in row.keys()}

    # Same deadband mqtt_receiver.py applies when writing: anything under
    # PV_ZERO_THRESHOLD is not real production. Re-applied here so older rows
    # are cleaned too, and casa_power stays consistent with pv_power.
    pv = energy.get("pv_power")
    if pv is not None and abs(pv) < PV_ZERO_THRESHOLD:
        energy["pv_power"] = 0.0
        grid = energy.get("grid_power")
        energy["casa_power"] = grid if grid is not None else energy.get("casa_power")

    return energy


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


def db_update_sensor_status(sensor_key, kind, identifier, last_change):
    """Record when a remote sensor last transmitted to the Tinkerforge bricklet.

    last_change is the "seconds since last reading" reported by Tinkerforge, so the
    absolute last-seen time is (now - last_change). One row per sensor_key (upsert),
    letting a monitor query whether each sensor is still transmitting.
    """
    try:
        now = datetime.datetime.utcnow()
        last_seen = (now - datetime.timedelta(seconds=int(last_change))).strftime(
            "%Y-%m-%dT%H:%M:%SZ"
        )
        con = sqlite3.connect(DB_PATH)
        con.execute("""
            INSERT INTO sensor_status (
                sensor_key, kind, identifier, last_change, last_seen, updated_at, online
            ) VALUES (
                :sensor_key, :kind, :identifier, :last_change, :last_seen, :updated_at, :online
            )
            ON CONFLICT(sensor_key) DO UPDATE SET
                kind        = excluded.kind,
                identifier  = excluded.identifier,
                last_change = excluded.last_change,
                last_seen   = excluded.last_seen,
                updated_at  = excluded.updated_at,
                online      = excluded.online
        """, {
            "sensor_key": sensor_key,
            "kind": kind,
            "identifier": identifier,
            "last_change": int(last_change),
            "last_seen": last_seen,
            "updated_at": now.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "online": 1 if int(last_change) < ONLINE_THRESHOLD else 0,
        })
        con.commit()
        con.close()
    except Exception as e:
        print("Sensor status write error:", e)


def get_active_station_ids(ow):
    """Return station ids that transmitted recently, freshest first.

    Uses the bricklet's own list of heard-from stations; falls back to a 1-255
    sweep if that call is unavailable on the installed firmware/bindings.
    """
    try:
        candidates = list(ow.get_station_identifiers())
    except Exception:
        candidates = range(1, 256)

    active = []
    for sid in candidates:
        try:
            data = ow.get_station_data(sid)
            if data[7] < ONLINE_THRESHOLD:  # data[7] = seconds since last reading
                active.append((sid, data[7]))
        except Exception:
            continue
    active.sort(key=lambda x: x[1])
    return [sid for sid, _ in active]


def get_active_sensor_ids(ow):
    """Return sensor ids that transmitted recently, freshest first.

    Uses the bricklet's own list of heard-from sensors; falls back to a 1-255
    sweep if that call is unavailable on the installed firmware/bindings.
    """
    try:
        candidates = list(ow.get_sensor_identifiers())
    except Exception:
        candidates = range(1, 256)

    active = []
    for sid in candidates:
        try:
            data = ow.get_sensor_data(sid)
            if data[2] < ONLINE_THRESHOLD:  # data[2] = seconds since last reading
                active.append((sid, data[2]))
        except Exception:
            continue
    active.sort(key=lambda x: x[1])
    return [sid for sid, _ in active]


def scan_for_active_station(ow):
    """Return the first station id returning fresh data, or None."""
    ids = get_active_station_ids(ow)
    if ids:
        print(f"Found active station: {ids[0]}")
        return ids[0]
    return None


def load_sensor_roles():
    """Load the persisted role->id map from the web dir.

    Falls back to the hardcoded defaults for any role missing from the file (or
    if the file does not yet exist / is unreadable). Returns {'main','mobile','ombra'}.
    """
    defaults = {
        "main": mainStation,
        "mobile": sensoreTemperatura2,
        "ombra": sensoreTemperatura,
    }
    try:
        with open(ROLES_PATH) as f:
            data = json.load(f)
    except Exception:
        return dict(defaults)

    roles = {}
    for role, dflt in defaults.items():
        entry = data.get(role)
        if isinstance(entry, dict) and "id" in entry:
            roles[role] = int(entry["id"])
        elif isinstance(entry, int):
            roles[role] = entry
        else:
            roles[role] = dflt
    print("Loaded persisted sensor roles:", roles)
    return roles


def save_sensor_roles(roles):
    """Persist the role->id map to the web dir (atomic write).

    Each entry stores the id plus 'updated_at', which is refreshed only when the
    id actually changes, so the file records when each role was last reassigned.
    """
    try:
        now = datetime.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
        try:
            with open(ROLES_PATH) as f:
                prev = json.load(f)
        except Exception:
            prev = {}

        payload = {}
        for role, sid in roles.items():
            prev_entry = prev.get(role) if isinstance(prev.get(role), dict) else {}
            unchanged = prev_entry.get("id") == sid and "updated_at" in prev_entry
            payload[role] = {
                "id": sid,
                "updated_at": prev_entry["updated_at"] if unchanged else now,
            }

        tmp = ROLES_PATH + ".tmp"
        with open(tmp, "w") as f:
            json.dump(payload, f, indent=2)
        os.replace(tmp, ROLES_PATH)  # atomic: readers never see a half-written file
    except Exception as e:
        print("Sensor roles save error:", e)


def update_role_if_changed(roles, role, sid):
    """If a role's id changed, update the in-memory map and rewrite the file."""
    if roles.get(role) != sid:
        print(f"Role '{role}' id changed: {roles.get(role)} -> {sid}")
        log_write(f"role {role} id {roles.get(role)}->{sid}")
        roles[role] = sid
        save_sensor_roles(roles)


def identify_devices_on_boot(ow, preferred, wait_seconds=90):
    """Auto-identify the station and both sensors at startup.

    Waits up to wait_seconds for devices to transmit at least once (the bricklet
    buffer is empty right after boot). The `preferred` map (persisted role->id)
    is favoured so the physical mobile/ombra roles stay stable across reboots; a
    role whose preferred id is silent is filled from a freshly discovered id
    (e.g. after a battery swap re-randomised it). Returns a {role: id} dict.
    """
    print("Boot scan: waiting for stations/sensors to transmit...")
    deadline = time() + wait_seconds
    station_ids, sensor_ids = [], []
    while True:
        station_ids = get_active_station_ids(ow)
        sensor_ids = get_active_sensor_ids(ow)
        if (station_ids and sensor_ids) or time() >= deadline:
            break
        sleep(5)

    print(f"Boot scan found -> stations: {station_ids}, sensors: {sensor_ids}")

    pref_station = preferred["main"]
    pref_mobile = preferred["mobile"]
    pref_ombra = preferred["ombra"]

    # Station: prefer the remembered id if active, else freshest active, else keep it.
    if pref_station in station_ids:
        station = pref_station
    elif station_ids:
        station = station_ids[0]
    else:
        station = pref_station
        print("Boot scan: no active station, keeping", pref_station)

    # Sensors: keep remembered ids where present, then fill missing roles from the pool.
    remaining = [s for s in sensor_ids if s not in (pref_ombra, pref_mobile)]
    if pref_mobile in sensor_ids:
        mobile = pref_mobile
    elif remaining:
        mobile = remaining.pop(0)
    else:
        mobile = pref_mobile
        print("Boot scan: no id for mobile, keeping", pref_mobile)

    if pref_ombra in sensor_ids:
        ombra = pref_ombra
    elif remaining:
        ombra = remaining.pop(0)
    else:
        ombra = pref_ombra
        print("Boot scan: no id for ombra, keeping", pref_ombra)

    result = {"main": station, "mobile": mobile, "ombra": ombra}
    print("Boot scan: using", result)
    log_write(f"boot scan station={station} mobile={mobile} ombra={ombra}")
    return result


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

    # Load persisted roles, auto-identify at boot, persist any changes
    roles = load_sensor_roles()
    roles = identify_devices_on_boot(ow, roles)
    save_sensor_roles(roles)
    current_main_station = roles["main"]
    mobile_id = roles["mobile"]
    ombra_id = roles["ombra"]

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
                db_update_sensor_status(
                    "main", "station", current_main_station, lastChangeStation
                )

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
                        db_update_sensor_status(
                            "main", "station", current_main_station, dati[7]
                        )
                        update_role_if_changed(roles, "main", current_main_station)
                        print(f"Switched to active station: {current_main_station}")
                    except Exception:
                        data_valid = False

            # 3. Final fallback if everything failed
            if not data_valid:
                temp = -100
                print("No active weather station found.")

            # --- Sensor 2 (Mobile) ---
            try:
                tMobile = ow.get_sensor_data(mobile_id)
                db_update_sensor_status(
                    "mobile", "sensor", mobile_id, tMobile[2]
                )
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
                ombra = ow.get_sensor_data(ombra_id)
                db_update_sensor_status(
                    "ombra", "sensor", ombra_id, ombra[2]
                )
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

            # Shelly Pro EM-50: real PV production and grid exchange. Omitted
            # from the URL when unavailable so the server keeps its last values
            # instead of recording a bogus zero.
            energy = read_latest_energy()
            if energy.get("pv_power") is not None:
                url_string += f"&pvPower={round(float(energy['pv_power']), 1)}"
            if energy.get("grid_power") is not None:
                url_string += f"&gridPower={round(float(energy['grid_power']), 1)}"
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
                "pvPower": energy.get("pv_power"),
                "gridPower": energy.get("grid_power"),
                "casaPower": energy.get("casa_power"),
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
