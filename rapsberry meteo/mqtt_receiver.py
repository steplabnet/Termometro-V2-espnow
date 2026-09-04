#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import json
import os
import sqlite3
import datetime
import threading
import urllib.request
from time import monotonic, sleep

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
# Marstek Venus E 3.0 home battery (batteria table). Two shapes are accepted so
# whichever bridge ends up feeding it works without touching this file again:
#   MQTT_TOPIC_BATTERY        one JSON object per message, all fields together
#   MQTT_TOPIC_BATTERY_FIELDS one scalar per leaf topic (the Home Assistant /
#                             marstek2mqtt style), merged by leaf name
# Field names are matched through BATTERY_ALIASES, not hardcoded.
MQTT_TOPIC_BATTERY = "casa/batteria/data"
MQTT_TOPIC_BATTERY_FIELDS = "marstek/venus/+"
DB_PATH = "/dev/shm/meteo.db"

IN_SENSOR_KEYS = {"sensoreId", "sensorName", "temp", "pressure", "status"}

# The Shelly publishes every couple of seconds; the dashboards only need the
# same ~1/min cadence as the weather logger, so rows are throttled.
ENERGY_WRITE_INTERVAL = 60   # seconds between energia rows
ENERGY_MAX_AGE = 150         # a clamp's reading is ignored once this stale
ENERGY_STARTUP_GRACE = 120   # wait this long for the second clamp before logging
PV_ZERO_THRESHOLD = 10.0     # PV production below this is noise -> recorded as 0 W

# ── Shelly HTTP fallback ─────────────────────────────────────────────────────
# The Pro EM-50 (fw 2.0.0) notifies status/em1:0 every couple of seconds but
# almost never status/em1:1: measured on 2026-09-03, ONE PV message in forty
# minutes while EM1.GetStatus?id=1 read 2.2 kW over HTTP the whole time. The
# device is not misconfigured (status_ntf is on, the clamp reads fine, its
# em1data:1 energy totals do arrive) — it simply does not notify that channel.
# Without it pv_power, and the casa_power derived from it, stay NULL and both
# dashboards show "—" for production and house load.
#
# So a channel that has gone quiet on MQTT is read straight off the device over
# HTTP and fed through the same path as a message. Whenever the notifications
# do work the poller finds the channel fresh and does nothing.
SHELLY_HTTP_HOST = "192.168.1.182"   # shellyproem50-441d6474e384.local
SHELLY_HTTP_INTERVAL = 5             # seconds between checks
SHELLY_HTTP_STALE = 15               # a channel quiet this long is polled
SHELLY_HTTP_TIMEOUT = 3
SHELLY_HTTP_CHANNELS = (1, 0)        # PV first: it is the one that goes quiet

# Live snapshot, rewritten on EVERY clamp message. The energia table stays on
# its one-minute cadence for history, but meteo.py reads this file instead, so
# a change at the meter reaches the remote server in a second or two rather
# than waiting out ENERGY_WRITE_INTERVAL.
ENERGY_LATEST_PATH = "/dev/shm/energy_latest.json"

# Latest reading per clamp: channel -> (monotonic_seconds, payload dict).
energy_latest = {}
energy_last_write = 0.0
energy_start = monotonic()
# handle_energy() runs from the MQTT thread and from the HTTP poller below.
energy_lock = threading.Lock()

# ── Battery (Marstek Venus E) ────────────────────────────────────────────────
BATTERY_WRITE_INTERVAL = 60          # seconds between batteria rows
BATTERY_LATEST_PATH = "/dev/shm/battery_latest.json"
# Sign convention stored and displayed: battery_power > 0 charging, < 0
# discharging. Set to -1 if the source publishes it the other way round — the
# Venus E does: measured on 2026-09-03, ongrid_power sat at -1850 W while the
# residual capacity and the grid-input counter both climbed.
BATTERY_POWER_SIGN = -1

# Source field name -> canonical column. Every alias a bridge might publish is
# listed here so the mapping lives in one place; unknown keys are ignored.
BATTERY_ALIASES = {
    "soc": (
        "soc", "bat_soc", "battery_soc", "state_of_charge",
        "battery_state_of_charge", "capacity", "soc_percent",
    ),
    "battery_power": (
        "battery_power", "bat_power", "batpower", "battery_charge_power",
        "charge_power", "p_battery", "power",
    ),
    "ac_power": (
        "ac_power", "p_ac", "inverter_power", "output_power", "ongrid_power",
        "grid_power", "ac_output_power",
    ),
    "battery_voltage": (
        "battery_voltage", "bat_voltage", "vbat", "voltage",
    ),
    "battery_current": (
        "battery_current", "bat_current", "ibat", "current",
    ),
    "temperature": (
        "temperature", "temp", "bat_temp", "battery_temperature",
        "internal_temp", "internal_temperature",
    ),
    # Hottest / coldest cell. Kept apart from `temperature` above (the
    # electronics area, normally much warmer) because these are the figures a
    # temperature alarm should look at, and their spread flags a weak cell.
    "cell_temp_max": (
        "cell_temp_max", "max_cell_temperature", "cell_temperature_max",
        "cell_temp", "max_cell_temp",
    ),
    "cell_temp_min": (
        "cell_temp_min", "min_cell_temperature", "cell_temperature_min",
        "min_cell_temp",
    ),
    "charge_total": (
        "charge_total", "total_charging_energy", "total_charge_energy",
        "charge_energy", "energy_charged",
    ),
    "discharge_total": (
        "discharge_total", "total_discharging_energy", "total_discharge_energy",
        "discharge_energy", "energy_discharged",
    ),
    "mode": ("mode", "work_mode", "workmode", "working_mode"),
    "state": ("state", "status", "device_state", "work_state", "battery_state"),
}

BATTERY_NUMERIC = {
    "soc", "battery_power", "ac_power", "battery_voltage", "battery_current",
    "temperature", "cell_temp_max", "cell_temp_min",
    "charge_total", "discharge_total",
}

# canonical field -> latest value seen. Kept across messages so the per-field
# topics, which arrive one at a time, still produce a complete row.
battery_fields = {}
battery_last_seen = 0.0
battery_last_write = 0.0


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
            pv_power_raw REAL,
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
    # pv_power_raw was added after the first deploys; older DB files (and the
    # copy meteo.py may have created) lack it.
    cols = {r[1] for r in con.execute("PRAGMA table_info(energia)")}
    if "pv_power_raw" not in cols:
        con.execute("ALTER TABLE energia ADD COLUMN pv_power_raw REAL")
    # Marstek Venus E readings. pv_power / casa_power are copied from the
    # Shelly snapshot at write time so batteria.php can draw the battery
    # against the rest of the plant from a single table.
    con.execute("""
        CREATE TABLE IF NOT EXISTS batteria (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp       TEXT,
            soc             REAL,
            battery_power   REAL,
            ac_power        REAL,
            battery_voltage REAL,
            battery_current REAL,
            temperature     REAL,
            cell_temp_max   REAL,
            cell_temp_min   REAL,
            charge_total    REAL,
            discharge_total REAL,
            mode            TEXT,
            state           TEXT,
            pv_power        REAL,
            casa_power      REAL
        )
    """)
    # Cell temperatures were added after the battery page first shipped; a
    # meteo.db created before that has the table without them.
    bcols = {r[1] for r in con.execute("PRAGMA table_info(batteria)")}
    for col in ("cell_temp_max", "cell_temp_min"):
        if col not in bcols:
            con.execute(f"ALTER TABLE batteria ADD COLUMN {col} REAL")
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
                timestamp, pv_power, pv_power_raw, grid_power, casa_power,
                pv_voltage, pv_current, pv_pf,
                grid_voltage, grid_current, grid_pf, freq
            ) VALUES (
                :timestamp, :pv_power, :pv_power_raw, :grid_power, :casa_power,
                :pv_voltage, :pv_current, :pv_pf,
                :grid_voltage, :grid_current, :grid_pf, :freq
            )
        """, row)
        con.commit()
        con.close()
    except Exception as e:
        print("DB write error (energia):", e)


def shelly_em1_status(channel):
    """One clamp read over HTTP, in the same shape the MQTT topic carries."""
    url = f"http://{SHELLY_HTTP_HOST}/rpc/EM1.GetStatus?id={channel}"
    with urllib.request.urlopen(url, timeout=SHELLY_HTTP_TIMEOUT) as response:
        payload = json.loads(response.read().decode("utf-8"))
    if isinstance(payload, dict) and "act_power" in payload:
        return payload
    return None


def shelly_poll_loop():
    """Fill in whatever the meter is not notifying, forever.

    Only channels that have gone quiet are fetched, so this costs one HTTP
    request every SHELLY_HTTP_INTERVAL while em1:1 stays silent and nothing at
    all if the notifications ever start working properly.
    """
    reachable = None
    while True:
        sleep(SHELLY_HTTP_INTERVAL)
        mono = monotonic()
        stale = [ch for ch in SHELLY_HTTP_CHANNELS
                 if ch not in energy_latest
                 or mono - energy_latest[ch][0] > SHELLY_HTTP_STALE]
        if not stale:
            continue

        polled = []
        error = None
        for channel in stale:
            try:
                payload = shelly_em1_status(channel)
            except Exception as e:
                error = e
                break
            if payload is not None:
                handle_energy(channel, payload)
                polled.append(channel)

        # As everywhere else here, only transitions are logged: the meter is
        # polled every few seconds and must not write a line each time.
        if error is not None:
            if reachable is not False:
                print(f"[{now()}] Shelly unreachable at {SHELLY_HTTP_HOST}: {error}")
                reachable = False
        elif polled and reachable is not True:
            print(f"[{now()}] Shelly polled over HTTP for channel(s) "
                  f"{', '.join(f'em1:{c}' for c in polled)} — not notified over MQTT")
            reachable = True


def merge_energy(mono):
    """Current PV + grid figures merged from the two clamp topics.

    Each clamp arrives on its own topic, so the two are combined here. A clamp
    that stopped publishing drops out (ENERGY_MAX_AGE) instead of freezing its
    last value into every subsequent reading. Returns None when neither clamp
    has anything fresh to say.
    """
    def fresh(ch):
        entry = energy_latest.get(ch)
        if not entry or mono - entry[0] > ENERGY_MAX_AGE:
            return {}
        return entry[1]

    pv = fresh(1)
    grid = fresh(0)
    if not pv and not grid:
        return None

    pv_power = pv.get("act_power")
    # Kept unclamped so the alarm rules can tell "no production at all" (the
    # inverter is down: the meter really reads 0) from the noise band that the
    # deadband below flattens to 0 anyway.
    pv_power_raw = pv_power
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

    return {
        "timestamp":    now(),
        "pv_power":     pv_power,
        "pv_power_raw": pv_power_raw,
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


def write_energy_latest(row):
    """Publish the snapshot to tmpfs for meteo.py, atomically.

    Written via a temp file + rename so meteo.py, which polls this about once a
    second, can never read a half-written file.
    """
    try:
        tmp = ENERGY_LATEST_PATH + ".tmp"
        with open(tmp, "w") as f:
            json.dump(row, f)
        os.replace(tmp, ENERGY_LATEST_PATH)
    except Exception as e:
        print("Energy snapshot write error:", e)


def handle_energy(channel, payload):
    """Buffer one clamp reading, publish it for meteo.py at once, and log it to
    the energia table at most once per ENERGY_WRITE_INTERVAL.

    Two different cadences on purpose: the snapshot has to be immediate so the
    dashboard follows the meter, while the history table only needs a row a
    minute and would otherwise grow by a row every couple of seconds.
    """
    with energy_lock:
        _handle_energy(channel, payload)


def _handle_energy(channel, payload):
    global energy_last_write

    mono = monotonic()
    energy_latest[channel] = (mono, payload)

    row = merge_energy(mono)
    if row is None:
        return

    # Immediate: this is what meteo.py picks up on its next loop.
    write_energy_latest(row)

    if mono - energy_last_write < ENERGY_WRITE_INTERVAL:
        return

    # Right after startup only one clamp may have reported yet; hold off briefly
    # so the first row carries both. Past the grace period a silent clamp is
    # recorded as NULL instead of blocking the log.
    if len(energy_latest) < 2 and mono - energy_start < ENERGY_STARTUP_GRACE:
        return

    energy_last_write = mono
    db_store_energy(row)
    print(f"[{row['timestamp']}] Energy stored to DB "
          f"(pv={row['pv_power']}, grid={row['grid_power']}, casa={row['casa_power']})")


def db_store_battery(row):
    try:
        con = sqlite3.connect(DB_PATH)
        con.execute("""
            INSERT INTO batteria (
                timestamp, soc, battery_power, ac_power,
                battery_voltage, battery_current, temperature,
                cell_temp_max, cell_temp_min,
                charge_total, discharge_total, mode, state,
                pv_power, casa_power
            ) VALUES (
                :timestamp, :soc, :battery_power, :ac_power,
                :battery_voltage, :battery_current, :temperature,
                :cell_temp_max, :cell_temp_min,
                :charge_total, :discharge_total, :mode, :state,
                :pv_power, :casa_power
            )
        """, row)
        con.commit()
        con.close()
    except Exception as e:
        print("DB write error (batteria):", e)


def battery_canonical(name):
    """Canonical column for one published field name, or None if unknown."""
    key = str(name).strip().lower().replace("-", "_").replace(" ", "_")
    for canon, aliases in BATTERY_ALIASES.items():
        if key == canon or key in aliases:
            return canon
    return None


def battery_value(canon, value):
    """Coerce one published value; None means "unusable, keep the old one"."""
    if isinstance(value, dict):
        # Bridges that wrap the reading, e.g. {"value": 71, "unit": "%"}.
        value = value.get("value", value.get("state"))
    if value is None:
        return None
    if canon in BATTERY_NUMERIC:
        try:
            return float(value)
        except (TypeError, ValueError):
            return None
    return str(value)


def battery_merge(payload):
    """Fold a payload (or a single scalar under its topic leaf) into the
    running field set. Returns True when something usable was found."""
    got = False
    for name, raw in payload.items():
        canon = battery_canonical(name)
        if canon is None:
            continue
        val = battery_value(canon, raw)
        if val is None:
            continue
        if canon == "battery_power":
            val *= BATTERY_POWER_SIGN
        battery_fields[canon] = val
        got = True
    return got


def battery_row():
    """Current battery figures as a `batteria` row, with the live PV / house
    load from the Shelly snapshot alongside them."""
    row = {"timestamp": now()}
    for canon in BATTERY_ALIASES:
        row[canon] = battery_fields.get(canon)

    energy = merge_energy(monotonic()) or {}
    row["pv_power"] = energy.get("pv_power")
    row["casa_power"] = energy.get("casa_power")
    return row


def write_battery_latest(row):
    """Publish the snapshot to tmpfs for batteria.php, atomically (temp file +
    rename), so a reader never sees a half-written file."""
    try:
        tmp = BATTERY_LATEST_PATH + ".tmp"
        with open(tmp, "w") as f:
            json.dump(row, f)
        os.replace(tmp, BATTERY_LATEST_PATH)
    except Exception as e:
        print("Battery snapshot write error:", e)


def handle_battery(payload):
    """Same two cadences as the energy meter: the snapshot is rewritten on
    every message so the dashboard follows the device, the batteria table gets
    one row per BATTERY_WRITE_INTERVAL."""
    global battery_last_write, battery_last_seen

    if not battery_merge(payload):
        return

    mono = monotonic()
    battery_last_seen = mono
    row = battery_row()
    write_battery_latest(row)

    if mono - battery_last_write < BATTERY_WRITE_INTERVAL:
        return

    battery_last_write = mono
    db_store_battery(row)
    print(f"[{row['timestamp']}] Battery stored to DB "
          f"(soc={row['soc']}, power={row['battery_power']})")


def on_connect(client, userdata, flags, reason_code, properties):
    if reason_code == 0:
        print(f"[{now()}] Connected to MQTT broker {MQTT_HOST}:{MQTT_PORT}")
        client.subscribe(MQTT_TOPIC)
        client.subscribe(MQTT_TOPIC_UFFICIO)
        client.subscribe(MQTT_TOPIC_EM_PV)
        client.subscribe(MQTT_TOPIC_EM_GRID)
        client.subscribe(MQTT_TOPIC_BATTERY)
        client.subscribe(MQTT_TOPIC_BATTERY_FIELDS)
        print(f"[{now()}] Subscribed to topics: {MQTT_TOPIC}, {MQTT_TOPIC_UFFICIO}, "
              f"{MQTT_TOPIC_EM_PV}, {MQTT_TOPIC_EM_GRID}, "
              f"{MQTT_TOPIC_BATTERY}, {MQTT_TOPIC_BATTERY_FIELDS}")
    else:
        print(f"[{now()}] Connection failed, reason code: {reason_code}")


def on_disconnect(client, userdata, flags, reason_code, properties):
    print(f"[{now()}] Disconnected, reason code: {reason_code}")


def topic_matches(topic, pattern):
    """MQTT single-level (+) / multi-level (#) wildcard match."""
    t = topic.split("/")
    p = pattern.split("/")
    for i, part in enumerate(p):
        if part == "#":
            return True
        if i >= len(t) or (part != "+" and part != t[i]):
            return False
    return len(t) == len(p)


def on_message(client, userdata, msg):
    timestamp = now()
    # Energy and battery messages arrive every couple of seconds, so they are
    # handled quietly (their handlers log only the row actually stored) and the
    # journal stays readable for the sensor topics.
    is_energy = msg.topic in (MQTT_TOPIC_EM_PV, MQTT_TOPIC_EM_GRID)
    is_battery = (msg.topic == MQTT_TOPIC_BATTERY
                  or topic_matches(msg.topic, MQTT_TOPIC_BATTERY_FIELDS))
    if not is_energy and not is_battery:
        print(f"\n[{timestamp}] Message on topic: {msg.topic}")
    try:
        raw = msg.payload.decode("utf-8")
        try:
            payload = json.loads(raw)
        except json.JSONDecodeError:
            if not is_battery:
                raise
            # Per-field topics usually carry a bare scalar ("71", "ON"), which
            # is not JSON: the topic leaf names the field.
            payload = raw
        if is_battery:
            # A per-field topic gives one value, keyed by its leaf; a JSON
            # object gives several at once.
            if not isinstance(payload, dict):
                payload = {msg.topic.rsplit("/", 1)[-1]: payload}
            handle_battery(payload)
            return
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
    threading.Thread(target=shelly_poll_loop, daemon=True).start()
    print(f"[{now()}] Connecting to {MQTT_HOST}:{MQTT_PORT}...")
    client.connect(MQTT_HOST, MQTT_PORT, keepalive=60)
    client.loop_forever()


if __name__ == "__main__":
    main()
