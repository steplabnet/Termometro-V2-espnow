#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Marstek Venus E 3.0 → MQTT bridge.

The battery has no user-configurable MQTT client (its own client talks to the
Marstek cloud), so this polls it locally and republishes the reading on the
house broker as a single JSON object:

    Venus E ─┬─ Modbus TCP:502 (LAN cablata) ──┬► battery_bridge.py
             └─ local API UDP:30000 (wifi) ────┘
                                                        │
                                                        │ MQTT casa/batteria/data
                                                        ▼
                                                mqtt_receiver.py → batteria

Field names are the canonical ones mqtt_receiver.py expects, so nothing
downstream needs changing.

Two transports, chosen with TRANSPORT below, because Modbus TCP on the Venus E
v3 is served on the WIRED LAN port only (not over its wifi) and needs firmware
V144 or newer. With the battery on wifi the local API — JSON over UDP, firmware
V152+, switched on in the Marstek app — is the only local way in.

Neither map is settled: the register map comes from
ViperRNMC/marstek_venus_modbus (registers/e_v3.yaml), whose author flags the v3
map as only partially validated on real hardware, and the local API's key names
vary between firmwares. Hence check_once() below, which prints the raw answers
next to the decoded ones so they can be eyeballed before either is trusted.

Sign of battery_power is published exactly as the battery reports it. The
house-wide convention (+ = charging) is applied in ONE place only,
BATTERY_POWER_SIGN in mqtt_receiver.py; do not flip it here as well.
"""

import argparse
import json
import sys
import time
from datetime import datetime

import socket

import paho.mqtt.client as mqtt

try:
    from pymodbus.client import ModbusTcpClient
except ImportError:     # only needed by TRANSPORT = "modbus"
    ModbusTcpClient = None

# -------- Battery --------
# Give the Venus a DHCP reservation and put its address here.
BATTERY_HOST = "192.168.1.217"  # Venus E, DHCP reservation on the house LAN
POLL_INTERVAL = 5               # seconds between reads
CONNECT_TIMEOUT = 5

# How to talk to it:
#   "modbus"  Modbus TCP — served on the WIRED LAN port only, firmware V144+.
#   "udp"     Marstek local API, JSON over UDP — works over the battery's
#             WIFI, needs firmware V152+ and the "Local API" / "Open API"
#             switch turned on in the Marstek app (which also asks for the
#             port; keep it at 30000 or change UDP_PORT to match).
# Neither is reachable through the Marstek cloud: the battery must be on the
# same LAN as the Pi either way.
TRANSPORT = "udp"

# -------- Modbus TCP --------
BATTERY_PORT = 502
BATTERY_UNIT = 1                # Modbus unit/slave id; 2, 3… for further units

# -------- Local API (UDP) --------
UDP_PORT = 30000
# Some firmwares only answer to a request that came *from* the same port they
# listen on, so the socket binds it when it can and falls back to an ephemeral
# port when something else already holds it.
UDP_LOCAL_PORT = 30000
UDP_TIMEOUT = 2                 # seconds to wait for each answer
# The Venus drops requests that arrive on top of each other: firing the three
# calls back to back loses two of them, one per second gets all three. Retries
# are still needed, a call is silently dropped now and then even when paced.
UDP_GAP = 1.0
UDP_RETRIES = 3

# Calls made once per poll. Their results are merged into one flat dict, first
# answer wins, then mapped onto the canonical field names through UDP_ALIASES.
# What a VenusE 3.0 on firmware 144 answers (… = unused keys):
#   ES.GetStatus   bat_soc, bat_cap, pv_power, ongrid_power, offgrid_power,
#                  total_grid_input_energy, total_grid_output_energy, …
#   Bat.GetStatus  soc, bat_temp, bat_capacity (Wh left), rated_capacity,
#                  charg_flag / dischrg_flag (permissions, not state)
#   ES.GetMode     mode ("Auto"), plus a copy of the power figures
UDP_CALLS = [
    ("ES.GetStatus", {"id": 0}),
    ("Bat.GetStatus", {"id": 0}),
    ("ES.GetMode", {"id": 0}),
]

# The local API is young and its key names differ between firmwares, so every
# field is looked up through a list of spellings rather than a fixed one —
# same approach as BATTERY_ALIASES in mqtt_receiver.py. `--check` prints the
# raw answers, so an unrecognised name can just be added here.
UDP_ALIASES = {
    "soc":             ("bat_soc", "soc", "battery_soc"),
    "battery_power":   ("bat_power", "battery_power", "bat_charge_power"),
    "ac_power":        ("ongrid_power", "ac_power", "output_power",
                        "grid_power"),
    "battery_voltage": ("bat_voltage", "voltage", "battery_voltage"),
    "battery_current": ("bat_current", "current", "battery_current"),
    "temperature":     ("bat_temp", "temp", "temperature", "internal_temp"),
    "cell_temp_max":   ("max_cell_temp", "cell_temp_max",
                        "max_cell_temperature"),
    "cell_temp_min":   ("min_cell_temp", "cell_temp_min",
                        "min_cell_temperature"),
    # The Venus E has no PV input of its own, so everything it charges with
    # and everything it gives back passes through the grid port: these two
    # counters ARE the pack's charge/discharge totals on this device.
    "charge_total":    ("total_charging_energy", "total_grid_input_energy",
                        "charge_total"),
    "discharge_total": ("total_discharging_energy", "total_grid_output_energy",
                        "discharge_total"),
    "state":           ("bat_state", "state", "status"),
    "mode":            ("mode", "work_mode"),
}
UDP_TEXT = ("state", "mode")    # kept as text, everything else is numeric
# Applied after the lookup. The energy counters come in Wh, the columns are kWh.
UDP_SCALE = {"charge_total": 0.001, "discharge_total": 0.001}

# The local API does not expose the pack side at all — no voltage, no current,
# no cell temperatures, and no battery power — so battery_power is taken from
# the inverter's AC figure (ongrid_power). Same direction, and within a few per
# cent of the DC figure: measured charging at 1850 W AC while the residual
# capacity rose 10 Wh every 21 s (≈1715 W), the difference being conversion.
UDP_POWER_FROM_AC = True
# The battery's OWN sign, established by watching bat_capacity and
# total_grid_input_energy rise while ongrid_power sat at -1850 W. It is used
# only to label `state` below; the house-wide convention stays where it was,
# in BATTERY_POWER_SIGN in mqtt_receiver.py.
UDP_AC_CHARGING_SIGN = -1
UDP_IDLE_W = 15                 # same deadband as IDLE_W in batteria.php

# -------- MQTT --------
MQTT_HOST = "stazionemeteo.local"
MQTT_PORT = 1883
MQTT_USER = "stzionemeteo"
MQTT_PASSWORD = "78f25d_78"
MQTT_TOPIC = "casa/batteria/data"

# Holding-register blocks to read, (start, count). Grouped so one poll is a
# handful of reads instead of one per field; the gaps inside a block are read
# and discarded, which is cheaper than a separate round trip.
BLOCKS = [
    (30001, 6),    # battery_power .. ac_power
    (30100, 2),    # battery voltage, current
    (33000, 4),    # lifetime charge / discharge energy
    (34002, 1),    # soc
    (35000, 12),   # internal + MOS temperatures, then max/min cell (35010/11)
    (35100, 1),    # inverter state
    (43000, 1),    # user work mode
]

# name -> (register, data_type, scale). Names match BATTERY_ALIASES in
# mqtt_receiver.py, so the receiver stores them without any mapping.
FIELDS = {
    "battery_power":   (30001, "int16", 1),
    "ac_power":        (30006, "int16", 1),
    "battery_voltage": (30100, "uint16", 0.01),
    "battery_current": (30101, "int16", 0.1),
    "charge_total":    (33000, "uint32", 0.01),
    "discharge_total": (33002, "int32", 0.01),
    "soc":             (34002, "uint16", 0.1),
    "temperature":     (35000, "int16", 0.1),
    # Cell temperatures are the ones worth alarming on: `temperature` above is
    # the electronics/MOS area, which normally runs well hotter than the pack.
    "cell_temp_max":   (35010, "int16", 0.1),
    "cell_temp_min":   (35011, "int16", 0.1),
}

# Enum registers, decoded to the text the dashboard shows as-is.
INVERTER_STATES = {
    0: "Sleep", 1: "Standby", 2: "Charge", 3: "Discharge",
    4: "Backup", 5: "OTA", 6: "Bypass",
}
WORK_MODES = {0: "Manual", 1: "Anti-feed", 2: "Trade"}


def now():
    return datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")


def log(msg):
    print(f"[{now()}] {msg}", flush=True)


# Reason the last read failed, reported once when the battery goes offline
# rather than on every poll — an unreachable battery would otherwise write a
# line per block per POLL_INTERVAL into the journal.
last_error = None


def read_block(client, start, count):
    """One block of holding registers as {address: raw_word}, or None.

    pymodbus renamed the unit-id keyword across 3.x (slave → device_id), so
    both spellings are tried rather than pinning a library version on the Pi.
    """
    global last_error

    for kw in ("device_id", "slave"):
        try:
            rr = client.read_holding_registers(start, count=count, **{kw: BATTERY_UNIT})
        except TypeError:
            continue        # this pymodbus wants the other keyword
        except Exception as e:
            last_error = f"{e} (register {start})"
            return None
        if rr is None or rr.isError():
            last_error = f"error response for register {start}"
            return None
        return {start + i: v for i, v in enumerate(rr.registers)}
    last_error = "pymodbus accepted neither device_id= nor slave="
    log(last_error)
    return None


def decode(words, register, data_type, scale):
    """One field out of the raw word map, scaled. None when it was not read."""
    lo = words.get(register)
    if lo is None:
        return None

    if data_type in ("uint32", "int32"):
        hi = words.get(register + 1)
        if hi is None:
            return None
        # Big-endian word order: the first register holds the high half.
        raw = (lo << 16) | hi
        if data_type == "int32" and raw >= 0x80000000:
            raw -= 0x100000000
    else:
        raw = lo
        if data_type == "int16" and raw >= 0x8000:
            raw -= 0x10000

    value = raw * scale
    # 0.1-scaled ints come out as 24.700000000000003; keep the payload tidy.
    return round(value, 3)


def read_battery(client):
    """Full reading as the payload to publish, or None if the poll failed.

    A partial read is still published: a single flaky block should cost that
    field, not the whole message. Only losing every block counts as a failure.
    """
    words = {}
    ok = 0
    for start, count in BLOCKS:
        block = read_block(client, start, count)
        if block:
            words.update(block)
            ok += 1
    if ok == 0:
        return None

    payload = {}
    for name, (register, data_type, scale) in FIELDS.items():
        value = decode(words, register, data_type, scale)
        if value is not None:
            payload[name] = value

    state = words.get(35100)
    if state is not None:
        payload["state"] = INVERTER_STATES.get(state, f"Sconosciuto ({state})")
    mode = words.get(43000)
    if mode is not None:
        payload["mode"] = WORK_MODES.get(mode, f"Sconosciuto ({mode})")

    return payload or None


# ---------------------------------------------------------------------------
# Local API (UDP) transport
# ---------------------------------------------------------------------------

_udp_id = 0


def open_udp():
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.settimeout(UDP_TIMEOUT)
    try:
        sock.bind(("", UDP_LOCAL_PORT))
    except OSError as e:
        log(f"local UDP port {UDP_LOCAL_PORT} unavailable ({e}); "
            "using an ephemeral one — some firmwares will not answer")
    return sock


def udp_call(sock, method, params):
    """One JSON request, its `result` dict back, or None. Retried, because
    the battery drops the odd request even when the calls are paced."""
    for attempt in range(UDP_RETRIES):
        result = udp_try(sock, method, params)
        if result is not None:
            return result
        if attempt + 1 < UDP_RETRIES:
            time.sleep(UDP_GAP)
    return None


def udp_try(sock, method, params):
    global _udp_id, last_error

    _udp_id += 1
    req_id = _udp_id
    request = {"id": req_id, "method": method, "params": params}
    try:
        sock.sendto(json.dumps(request).encode(), (BATTERY_HOST, UDP_PORT))
    except OSError as e:
        last_error = f"{e} (sending {method})"
        return None

    # The socket is shared by every call and the battery also broadcasts on
    # this port, so read until the answer carrying our id turns up.
    deadline = time.monotonic() + UDP_TIMEOUT
    while True:
        left = deadline - time.monotonic()
        if left <= 0:
            break
        sock.settimeout(left)
        try:
            data, _ = sock.recvfrom(8192)
        except socket.timeout:
            break
        except OSError as e:
            last_error = f"{e} (waiting for {method})"
            return None
        try:
            message = json.loads(data.decode("utf-8", "replace"))
        except ValueError:
            continue                        # not JSON: not ours
        if not isinstance(message, dict):
            continue
        result = message.get("result")
        message_id = message.get("id")
        # An id that is not ours is a late answer to an earlier call; a
        # datagram with no id at all is one of the battery's own
        # notifications unless it actually carries a result. Both are
        # skipped rather than counted as the answer to this call.
        if message_id != req_id and not (message_id is None
                                         and isinstance(result, dict)):
            continue
        if isinstance(result, dict):
            return result
        last_error = f"{method}: {message.get('error') or message}"
        return None

    last_error = f"no answer to {method} within {UDP_TIMEOUT}s"
    return None


def pick(values, names):
    """First of `names` present in `values`, unwrapping {"value": x}."""
    for name in names:
        if name in values:
            value = values[name]
            if isinstance(value, dict):
                value = value.get("value")
            return value
    return None


def read_battery_udp(sock):
    """(payload, raw answers per method). payload is None when nothing came
    back; a call that fails costs its own fields only, as with Modbus."""
    raw = {}
    for n, (method, params) in enumerate(UDP_CALLS):
        if n:
            time.sleep(UDP_GAP)
        result = udp_call(sock, method, params)
        if result:
            raw[method] = result
    if not raw:
        return None, {}

    merged = {}
    for result in raw.values():
        for key, value in result.items():
            merged.setdefault(key, value)   # earlier call in UDP_CALLS wins

    payload = {}
    for field, names in UDP_ALIASES.items():
        value = pick(merged, names)
        if value is None:
            continue
        if field in UDP_TEXT:
            payload[field] = str(value)
        else:
            try:
                payload[field] = round(float(value) * UDP_SCALE.get(field, 1), 3)
            except (TypeError, ValueError):
                pass                        # non-numeric: leave the column out

    # Pack power is not published by the local API; stand the AC figure in for
    # it, unchanged, so the charts and the daily kWh have something to work on.
    if UDP_POWER_FROM_AC and "battery_power" not in payload:
        if "ac_power" in payload:
            payload["battery_power"] = payload["ac_power"]

    # Neither is a work state: `mode` says what the battery is told to do, not
    # what it is doing. Derive it from the power, with the dashboard's own
    # deadband so a pack feeding its own electronics does not read as charging.
    if "state" not in payload and "battery_power" in payload:
        watts = payload["battery_power"] * UDP_AC_CHARGING_SIGN
        payload["state"] = ("Carica" if watts > UDP_IDLE_W else
                            "Scarica" if watts < -UDP_IDLE_W else "In attesa")

    return (payload or None), raw


def connect_mqtt():
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    client.username_pw_set(MQTT_USER, MQTT_PASSWORD)
    client.connect(MQTT_HOST, MQTT_PORT, keepalive=60)
    client.loop_start()
    return client


def open_modbus():
    if ModbusTcpClient is None:
        log("pymodbus is not installed: apt install python3-pymodbus, "
            'or set TRANSPORT = "udp" if the battery is on wifi')
        return None
    return ModbusTcpClient(BATTERY_HOST, port=BATTERY_PORT,
                           timeout=CONNECT_TIMEOUT)


def check_once():
    """Print one decoded reading and exit — used to verify the field names
    against the real device before leaving the service running."""
    if TRANSPORT == "udp":
        sock = open_udp()
        payload, raw = read_battery_udp(sock)
        sock.close()
        if payload is None:
            log(f"no answer from {BATTERY_HOST}:{UDP_PORT} — is the local API "
                "switched on in the app? firmware >= V152?"
                + (f" ({last_error})" if last_error else ""))
            return 1
        # The raw answers first: they are what tells you whether a missing
        # field is absent or just spelled differently (add it to UDP_ALIASES).
        print("--- raw ---")
        print(json.dumps(raw, indent=2))
        print("--- published ---")
    else:
        client = open_modbus()
        if client is None:
            return 1
        if not client.connect():
            log(f"cannot reach {BATTERY_HOST}:{BATTERY_PORT} — wired LAN? "
                "firmware >= V144?")
            return 1
        payload = read_battery(client)
        client.close()
        if payload is None:
            log("connected, but no register block could be read (wrong unit id?)"
                + (f": {last_error}" if last_error else ""))
            return 1

    print(json.dumps(payload, indent=2))
    log("check the signs and magnitudes above before enabling the service")
    return 0


def make_poller():
    """A zero-argument function returning the next reading, or None.

    Both transports keep their socket across polls and drop it only when a
    read fails, so a battery that rebooted is picked up again on the next one.
    """
    if TRANSPORT == "udp":
        sock = open_udp()
        return lambda: read_battery_udp(sock)[0]

    modbus = open_modbus()
    if modbus is None:
        return None

    def poll():
        payload = read_battery(modbus) if modbus.connect() else None
        if payload is None:
            # Drop the socket so the next loop reconnects from scratch rather
            # than reusing one the battery has already forgotten about.
            modbus.close()
        return payload

    return poll


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--check", action="store_true",
                    help="print one reading and exit, without publishing")
    args = ap.parse_args()

    if TRANSPORT not in ("modbus", "udp"):
        log(f'TRANSPORT must be "modbus" or "udp", not {TRANSPORT!r}')
        return 2

    if args.check:
        return check_once()

    poll = make_poller()
    if poll is None:
        return 1

    where = (f"{BATTERY_HOST}:{UDP_PORT} (local API)" if TRANSPORT == "udp"
             else f"{BATTERY_HOST}:{BATTERY_PORT} (modbus unit {BATTERY_UNIT})")
    mqtt_client = connect_mqtt()
    log(f"publishing {where} to {MQTT_HOST}:{MQTT_PORT} {MQTT_TOPIC} "
        f"every {POLL_INTERVAL}s")

    # Only transitions are logged: a battery that has been unreachable for an
    # hour must not fill the journal with one line every POLL_INTERVAL.
    online = None

    while True:
        payload = None
        try:
            payload = poll()
        except Exception as e:
            log(f"poll error: {e}")

        if payload is None:
            if online is not False:
                log(f"battery unreachable at {where}"
                    + (f": {last_error}" if last_error else ""))
                online = False
        else:
            if online is not True:
                log(f"battery online (soc={payload.get('soc')}, "
                    f"power={payload.get('battery_power')})")
                online = True
            mqtt_client.publish(MQTT_TOPIC, json.dumps(payload))

        time.sleep(POLL_INTERVAL)


if __name__ == "__main__":
    try:
        sys.exit(main() or 0)
    except KeyboardInterrupt:
        pass
