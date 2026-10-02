#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Bridge between the Pi's crono.php and its remote copy on cesana.steplab.net.

The Pi stays the only thermostat: crono.db and crono_watcher.py decide
everything. This script just lets the remote page see and drive the local one,
over the same public broker meteo.py already publishes to:

  casa/crono/state      retained JSON snapshot (crono.php?api=snapshot + "ts")
  casa/crono/status     retained "online" / "offline" (last will)
  casa/crono/cmd        remote page -> Pi: {"id", "ts", "action", ...form fields}
  casa/crono/ack/<id>   Pi -> remote page: {"ok", "msg"}

A command is replayed as a form post to the local crono.php (reply=json), so a
remote action goes through exactly the code a tap on the local page does —
same validation, same override row, same learning queue.

Kept out of crono_watcher.py on purpose: if the internet or this bridge
misbehaves, heating control is not affected at all.
"""

import json
import queue
import re
import time
from collections import deque

import paho.mqtt.client as mqtt
import requests

# ── Remote broker (same account meteo.py uses) ──────────────────────────────
MQTT_HOST = "mqtt1.steplab.net"
MQTT_PORT = 1883
MQTT_USER = "mark"
MQTT_PASSWORD = "78f25d"
TOPIC_STATE = "casa/crono/state"
TOPIC_STATUS = "casa/crono/status"
TOPIC_CMD = "casa/crono/cmd"
TOPIC_ACK = "casa/crono/ack/"

# ── Local page ──────────────────────────────────────────────────────────────
LOCAL_URL = "http://127.0.0.1/crono.php"

# ── Tuning ──────────────────────────────────────────────────────────────────
SNAPSHOT_INTERVAL = 10     # seconds between local snapshot reads
HEARTBEAT = 60             # republish an unchanged snapshot this often (fresh "ts")
CMD_MAX_AGE = 120          # drop commands older than this (delayed by an outage)
CMD_MAX_SKEW = 60          # ...or stamped this far in the future

# Form fields a remote command may carry; anything else is dropped.
ACTIONS = {"mode", "manual_target", "default_target", "command", "cancel_override"}
FIELDS = ("mode", "target", "type", "dur")

commands: "queue.Queue[dict]" = queue.Queue()
seen_ids = deque(maxlen=200)


def log(msg):
    print(msg, flush=True)


def on_connect(client, userdata, flags, reason_code, properties):
    if reason_code != 0:
        log(f"[mqtt] connect refused: {reason_code}")
        return
    log(f"[mqtt] connected to {MQTT_HOST}")
    client.publish(TOPIC_STATUS, "online", qos=1, retain=True)
    client.subscribe(TOPIC_CMD, qos=1)
    userdata["force"] = True   # republish the snapshot after a reconnect


def on_disconnect(client, userdata, flags, reason_code, properties):
    log(f"[mqtt] disconnected: {reason_code}")


def on_message(client, userdata, msg):
    try:
        cmd = json.loads(msg.payload.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        log("[cmd] ignoring unparseable message")
        return
    if not isinstance(cmd, dict):
        return
    cid = str(cmd.get("id", ""))
    if not re.fullmatch(r"[A-Za-z0-9_-]{8,64}", cid):
        log("[cmd] ignoring command without a valid id")
        return
    if cid in seen_ids:
        return
    seen_ids.append(cid)
    try:
        age = time.time() - float(cmd.get("ts", 0))
    except (TypeError, ValueError):
        age = float("inf")
    if age > CMD_MAX_AGE or age < -CMD_MAX_SKEW:
        log(f"[cmd] {cid}: dropped, {int(age)}s old")
        ack(client, cid, False, "Comando scaduto, non applicato")
        return
    commands.put(cmd)


def ack(client, cid, ok, text):
    client.publish(TOPIC_ACK + cid, json.dumps({"ok": ok, "msg": text}), qos=1)


def apply_command(cmd):
    """Replay one remote command as a form post to the local page; returns (ok, msg)."""
    action = cmd.get("action")
    if action not in ACTIONS:
        return False, "Azione sconosciuta"
    form = {"action": action, "reply": "json"}
    for k in FIELDS:
        if k in cmd and cmd[k] is not None:
            form[k] = str(cmd[k])[:16]
    try:
        r = requests.post(LOCAL_URL, data=form, timeout=10, allow_redirects=False)
        res = r.json()
        ok, text = bool(res.get("ok")), str(res.get("msg", ""))
    except Exception as e:
        ok, text = False, f"Errore sul Pi: {e}"
    log(f"[cmd] {cmd['id']} {form} -> {'ok' if ok else 'FAIL'}: {text}")
    return ok, text


def read_snapshot():
    r = requests.get(LOCAL_URL, params={"api": "snapshot"}, timeout=10)
    r.raise_for_status()
    snap = r.json()
    if "error" in snap:
        raise RuntimeError(snap["error"])
    return snap


class Publisher:
    """Publishes the local snapshot, skipping unchanged ones between heartbeats."""

    def __init__(self, client):
        self.client = client
        self.last_body = None
        self.last_pub = 0.0

    def publish(self, force=False):
        try:
            snap = read_snapshot()
        except Exception as e:
            log(f"[snapshot] {e}")
            return
        now = time.time()
        body = json.dumps(snap, sort_keys=True)
        if not force and body == self.last_body and now - self.last_pub < HEARTBEAT:
            return
        snap["ts"] = int(now)
        info = self.client.publish(TOPIC_STATE, json.dumps(snap), qos=1, retain=True)
        if info.rc == mqtt.MQTT_ERR_SUCCESS:
            self.last_body, self.last_pub = body, now


def main():
    log("Crono remote bridge starting…")
    userdata = {"force": True}
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="crono-remote-pi",
                         userdata=userdata)
    client.username_pw_set(MQTT_USER, MQTT_PASSWORD)
    client.will_set(TOPIC_STATUS, "offline", qos=1, retain=True)
    client.on_connect = on_connect
    client.on_disconnect = on_disconnect
    client.on_message = on_message
    client.reconnect_delay_set(min_delay=2, max_delay=60)
    client.connect_async(MQTT_HOST, MQTT_PORT, keepalive=60)
    client.loop_start()

    pub = Publisher(client)
    next_read = 0.0
    while True:
        try:
            cmd = commands.get(timeout=max(0.1, next_read - time.time()))
        except queue.Empty:
            cmd = None
        if cmd:
            ok, text = apply_command(cmd)
            # New state first, then the ack: the remote page reloads on the ack
            # and must find the snapshot that already includes the change.
            pub.publish(force=True)
            ack(client, cmd["id"], ok, text)
            next_read = time.time() + SNAPSHOT_INTERVAL
            continue
        if time.time() >= next_read or userdata["force"]:
            force, userdata["force"] = userdata["force"], False
            pub.publish(force=force)
            next_read = time.time() + SNAPSHOT_INTERVAL


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log("Stopped.")
