#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Diagnose the Shelly Pro EM-50 chain, hop by hop.

Run it on the Raspberry Pi:

    python3 check_energy.py

It reports, in order:
  1. is mqtt_receiver.py running?
  2. is the Shelly publishing to this broker?      (listens for a few seconds)
  3. is the energia table being written?
  4. is the snapshot fresh enough for meteo.py to forward it?

Each check prints OK / FAIL plus what to do about a failure. Read-only: it
stores nothing and changes nothing.
"""

import datetime
import sqlite3
import subprocess
import sys
from time import monotonic, sleep

import paho.mqtt.client as mqtt

# Kept in sync with mqtt_receiver.py.
MQTT_HOST = "stazionemeteo.local"
MQTT_PORT = 1883
MQTT_USER = "stzionemeteo"
MQTT_PASSWORD = "78f25d_78"
TOPIC_PV = "centralino/status/em1:1"
TOPIC_GRID = "centralino/status/em1:0"
DB_PATH = "/dev/shm/meteo.db"

LISTEN_SECONDS = 15      # the Shelly publishes every ~2 s, so this is generous
FORWARD_MAX_AGE = 300    # meteo.py ignores anything older than this

ok_count = 0
fail_count = 0


def ok(msg):
    global ok_count
    ok_count += 1
    print(f"  OK   {msg}")


def fail(msg, fix):
    global fail_count
    fail_count += 1
    print(f"  FAIL {msg}")
    print(f"       -> {fix}")


def head(n, title):
    print(f"\n[{n}] {title}")


# --- 1. receiver process ----------------------------------------------------
def check_process():
    head(1, "mqtt_receiver.py running?")
    try:
        out = subprocess.run(
            ["pgrep", "-af", "mqtt_receiver.py"],
            capture_output=True, text=True, timeout=10,
        ).stdout.strip()
    except Exception as e:
        print(f"  ?    could not check processes ({e})")
        return
    if out:
        ok("running: " + out.splitlines()[0])
    else:
        fail("not running",
             "start it (systemctl start <unit>, or python3 mqtt_receiver.py) "
             "and remember it must be the NEW version, the one that knows the "
             "energia table")


# --- 2. broker ---------------------------------------------------------------
def check_mqtt():
    head(2, f"Shelly publishing to {MQTT_HOST}?")
    seen = {}
    other = set()

    def on_connect(client, userdata, flags, reason_code, properties):
        if reason_code == 0:
            client.subscribe("#")   # everything, so we can see wrong prefixes too
        else:
            print(f"  ?    connect refused, reason code {reason_code}")

    def on_message(client, userdata, msg):
        if msg.topic in (TOPIC_PV, TOPIC_GRID):
            seen[msg.topic] = msg.payload.decode("utf-8", errors="replace")
        elif "em1" in msg.topic or "em:" in msg.topic:
            other.add(msg.topic)

    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    client.username_pw_set(MQTT_USER, MQTT_PASSWORD)
    client.on_connect = on_connect
    client.on_message = on_message
    try:
        client.connect(MQTT_HOST, MQTT_PORT, 60)
    except Exception as e:
        fail(f"cannot reach the broker: {e}",
             "check that the broker is up and the host/credentials at the top "
             "of this script are right")
        return

    client.loop_start()
    print(f"       listening {LISTEN_SECONDS}s...")
    deadline = monotonic() + LISTEN_SECONDS
    while monotonic() < deadline and len(seen) < 2:
        sleep(0.5)
    client.loop_stop()
    client.disconnect()

    for label, topic in (("production (em1:1)", TOPIC_PV), ("grid (em1:0)", TOPIC_GRID)):
        if topic in seen:
            ok(f"{label}: {seen[topic][:90]}")
        else:
            fail(f"{label}: nothing on {topic}",
                 "point the Shelly's MQTT settings at this broker, or correct "
                 "MQTT_TOPIC_EM_* in mqtt_receiver.py if the prefix differs")
    if other:
        print("       other energy-looking topics seen: " + ", ".join(sorted(other)))


# --- 3 + 4. database ---------------------------------------------------------
def check_db():
    head(3, f"energia rows in {DB_PATH}?")
    try:
        con = sqlite3.connect(DB_PATH)
        con.row_factory = sqlite3.Row
        rows = con.execute(
            "SELECT * FROM energia ORDER BY id DESC LIMIT 3"
        ).fetchall()
        total = con.execute("SELECT COUNT(*) FROM energia").fetchone()[0]
        con.close()
    except sqlite3.OperationalError as e:
        fail(f"cannot read the table: {e}",
             "the running mqtt_receiver.py is the old version (it never creates "
             "'energia'): deploy the new one and restart it")
        return
    except Exception as e:
        fail(f"cannot open {DB_PATH}: {e}", "check the path and permissions")
        return

    if not rows:
        fail("table exists but is empty",
             "the receiver is not storing: re-check hop 2, then its log "
             "(journalctl -u <unit> -f) for 'DB write error (energia)'")
        return

    ok(f"{total} rows, newest: " + ", ".join(
        f"{k}={rows[0][k]}" for k in ("timestamp", "pv_power", "grid_power", "casa_power")
    ))

    head(4, "fresh enough for meteo.py to forward?")
    try:
        stamp = datetime.datetime.strptime(rows[0]["timestamp"], "%Y-%m-%dT%H:%M:%SZ")
    except (TypeError, ValueError):
        fail(f"unreadable timestamp: {rows[0]['timestamp']!r}", "should not happen")
        return

    age = (datetime.datetime.utcnow() - stamp).total_seconds()
    if age <= FORWARD_MAX_AGE:
        ok(f"newest row is {int(age)}s old (limit {FORWARD_MAX_AGE}s)")
        print("       meteo.py will append &pvPower=/&gridPower= on its next "
              "call. If the remote dashboard still hides the cards, meteo.py "
              "itself needs restarting on the new version")
    else:
        fail(f"newest row is {int(age)}s old, over the {FORWARD_MAX_AGE}s limit",
             "the receiver stopped storing: it is stale, not missing: check "
             "hops 1 and 2")


def main():
    print(f"Shelly Pro EM-50 chain check - {datetime.datetime.now():%Y-%m-%d %H:%M:%S}")
    check_process()
    check_mqtt()
    check_db()
    print(f"\n{ok_count} ok, {fail_count} failed")
    print("Remote server (last hop), run against the MySQL DB:")
    print("  SELECT pvPower, gridPower, FROM_UNIXTIME(data) "
          "FROM dati_instant WHERE id=1;")
    return 1 if fail_count else 0


if __name__ == "__main__":
    sys.exit(main())
