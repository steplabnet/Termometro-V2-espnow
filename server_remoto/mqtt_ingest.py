#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""MQTT -> ingest.php bridge. Runs on the web host (cesana.steplab.net).

PHP only exists for the length of a request, so it cannot hold an MQTT
subscription. This service does: it stays connected to the broker, and every
message the weather station publishes is POSTed to ingest.php, which owns all
the storage rules (live row every reading, history row every 10 minutes).

Install: see mqtt-ingest.service next to this file.

Every setting can be overridden with an environment variable, so the unit file
can carry the secrets instead of this script.
"""

import json
import os
import queue
import signal
import sys
import threading
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone

import paho.mqtt.client as mqtt

# -------- MQTT --------
MQTT_HOST = os.environ.get("MQTT_HOST", "mqtt1.steplab.net")
MQTT_PORT = int(os.environ.get("MQTT_PORT", "1883"))
MQTT_USER = os.environ.get("MQTT_USER", "mark")
MQTT_PASSWORD = os.environ.get("MQTT_PASSWORD", "78f25d")
MQTT_TOPIC = os.environ.get("MQTT_TOPIC", "casa/stazionemeteo")
MQTT_TOPIC_STATUS = MQTT_TOPIC + "/status"

# -------- Ingest endpoint --------
INGEST_URL = os.environ.get("INGEST_URL", "https://cesana.steplab.net/ingest.php")
INGEST_TOKEN = os.environ.get(
    "INGEST_TOKEN", "4945cbdbe95752de9c6c6ae67118f10df884d6f87eb8ae16"
)
# ingest.php occasionally rebuilds icache.html, which takes a few seconds.
INGEST_TIMEOUT = int(os.environ.get("INGEST_TIMEOUT", "30"))
# How often to log a "still alive" line when no history row is being written.
LOG_SUMMARY_INTERVAL = int(os.environ.get("LOG_SUMMARY_INTERVAL", "300"))

# One slot only: the station publishes complete snapshots, so if the web host
# stalls there is no point queueing the old ones -- the newest is the truth.
pending: "queue.Queue[dict]" = queue.Queue(maxsize=1)
stopping = threading.Event()


def log(message):
    stamp = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    print(f"[{stamp}] {message}", flush=True)


def submit(payload):
    """Hand a payload to the worker, discarding any older one still waiting."""
    while True:
        try:
            pending.put_nowait(payload)
            return
        except queue.Full:
            try:
                pending.get_nowait()
            except queue.Empty:
                pass


def post(payload):
    """POST one reading to ingest.php; raises on anything but a 2xx."""
    body = json.dumps(payload).encode("utf-8")
    request = urllib.request.Request(
        INGEST_URL,
        data=body,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "X-Ingest-Token": INGEST_TOKEN,
            "User-Agent": "mqtt-ingest/1.0",
        },
    )
    with urllib.request.urlopen(request, timeout=INGEST_TIMEOUT) as response:
        raw = response.read().decode("utf-8", errors="replace")
    try:
        return json.loads(raw) if raw else {}
    except json.JSONDecodeError:
        # A PHP warning printed before the JSON would land here; worth seeing.
        raise RuntimeError(f"unparseable response: {raw[:200]}")


def worker():
    """Drain the slot, retrying with backoff and always sending the newest data."""
    backoff = 1
    forwarded = 0
    last_summary = time.monotonic()
    while not stopping.is_set():
        try:
            payload = pending.get(timeout=1)
        except queue.Empty:
            continue

        while not stopping.is_set():
            try:
                result = post(payload)
                forwarded += 1
                if result.get("history"):
                    log(f"stored, history row written (rev={result.get('rev')})")
                # Otherwise stay quiet per reading but confirm every so often
                # that data is still flowing -- silence alone cannot tell a
                # healthy feed from a dead station.
                elif time.monotonic() - last_summary >= LOG_SUMMARY_INTERVAL:
                    log(f"{forwarded} readings forwarded in the last "
                        f"{int(time.monotonic() - last_summary)}s "
                        f"(rev={result.get('rev')})")
                    forwarded = 0
                    last_summary = time.monotonic()
                backoff = 1
                break
            except Exception as e:
                log(f"ingest failed ({e}); retrying in {backoff}s")
                if stopping.wait(backoff):
                    return
                backoff = min(backoff * 2, 60)
                # A newer reading may have arrived while we were failing; send
                # that one instead of replaying a stale snapshot.
                try:
                    payload = pending.get_nowait()
                except queue.Empty:
                    pass


def on_connect(client, userdata, flags, reason_code, properties):
    if reason_code == 0:
        log(f"connected to {MQTT_HOST}:{MQTT_PORT}")
        # The station publishes retained, so subscribing hands us the current
        # readings straight away instead of waiting for the next change.
        client.subscribe([(MQTT_TOPIC, 1), (MQTT_TOPIC_STATUS, 1)])
        log(f"subscribed to {MQTT_TOPIC} and {MQTT_TOPIC_STATUS}")
    else:
        log(f"connection refused, reason code: {reason_code}")


def on_disconnect(client, userdata, flags, reason_code, properties):
    log(f"disconnected, reason code: {reason_code}")


def on_message(client, userdata, msg):
    if msg.topic == MQTT_TOPIC_STATUS:
        log(f"station status: {msg.payload.decode('utf-8', errors='replace')}")
        return
    try:
        payload = json.loads(msg.payload.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as e:
        log(f"ignoring unparseable message on {msg.topic}: {e}")
        return
    if not isinstance(payload, dict):
        log(f"ignoring non-object message on {msg.topic}")
        return
    submit(payload)


def main():
    def stop(signum, frame):
        log("shutting down")
        stopping.set()

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)

    threading.Thread(target=worker, name="ingest", daemon=True).start()

    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    client.username_pw_set(MQTT_USER, MQTT_PASSWORD)
    client.on_connect = on_connect
    client.on_disconnect = on_disconnect
    client.on_message = on_message
    client.reconnect_delay_set(min_delay=1, max_delay=60)

    log(f"connecting to {MQTT_HOST}:{MQTT_PORT}, forwarding to {INGEST_URL}")
    client.connect_async(MQTT_HOST, MQTT_PORT, keepalive=60)
    client.loop_start()

    while not stopping.is_set():
        time.sleep(1)

    client.loop_stop()
    client.disconnect()
    return 0


if __name__ == "__main__":
    sys.exit(main())
