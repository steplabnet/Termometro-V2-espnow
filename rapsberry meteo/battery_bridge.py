#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Marstek Venus E 3.0 -> MQTT bridge, over Modbus TCP.

The battery has no user-configurable MQTT client (its own client talks to the
Marstek cloud), so this polls it locally and republishes the reading on the
house broker as a single JSON object:

    Venus E -- Modbus TCP :502 (LAN cablata) --> battery_bridge.py
                                                        |
                                                        | MQTT casa/batteria/data
                                                        v
                                                mqtt_receiver.py -> batteria

Field names are the canonical ones mqtt_receiver.py expects, so nothing
downstream needs changing.

Modbus TCP on the Venus E v3 is served on the WIRED LAN port only -- port 502
does not answer over the battery's wifi at all -- and needs firmware V144 or
newer. The Marstek local API ("Open API", JSON over UDP :30000) this bridge
used before is gone from here: it never exposed the pack side at all -- no
voltage, no current, no cell temperatures and no DC power, so battery_power
had to be stood in for by the inverter's AC figure. Modbus gives all of it
directly.

The register map comes from ViperRNMC/marstek_venus_modbus
(registers/e_v3.yaml), whose author flags the v3 map as only partially
validated on real hardware. Hence check_once() below: `--check` prints one
decoded reading so the signs and magnitudes can be eyeballed before the
service is left running.

Sign of battery_power is published exactly as the battery reports it. The
house-wide convention (+ = charging) is applied in ONE place only,
BATTERY_POWER_SIGN in mqtt_receiver.py; do not flip it here as well.
"""

import argparse
import inspect
import json
import sys
import time
from datetime import datetime

import logging

import paho.mqtt.client as mqtt

try:
    from pymodbus.client import ModbusTcpClient
except ImportError:
    ModbusTcpClient = None

# -------- Battery --------
# Give the Venus a DHCP reservation on its WIRED port and put its address here.
# It has to be the cabled interface, which is a SECOND address with its own MAC:
# the wifi one (192.168.1.217 here) answers on the LAN but refuses port 502.
BATTERY_HOST = "192.168.1.153"  # Venus E, wired port (MAC dc:04:5a:7d:ef:ec)
BATTERY_PORT = 502
BATTERY_UNIT = 1                # Modbus unit/slave id; 2, 3... for further units
POLL_INTERVAL = 5               # seconds between reads
CONNECT_TIMEOUT = 5

# -------- MQTT --------
MQTT_HOST = "stazionemeteo.local"
MQTT_PORT = 1883
MQTT_USER = "stzionemeteo"
MQTT_PASSWORD = "78f25d_78"
MQTT_TOPIC = "casa/batteria/data"

# Holding-register blocks to read, (start, count). Grouped so one poll is a
# handful of reads instead of one per field; the gaps inside a block are read
# and discarded, which is cheaper than a separate round trip.
#
# The grouping is NOT free to widen: a block that runs over a register the
# battery does not implement is refused, and the Venus answers a refusal by
# dropping the TCP session AND refusing new connections for several seconds,
# so one bad block costs the whole poll and the two after it. 35000..35002 and
# 35010..35011 exist, 35003 does not — hence the split. Verified 2026-09-04:
# all eight blocks below read clean in one session.
BLOCKS = [
    (30001, 6),    # battery_power .. ac_power
    (30100, 2),    # battery voltage, current
    (32200, 1),    # AC voltage
    (32204, 1),    # AC frequency
    (33000, 4),    # lifetime charge / discharge energy
    (34002, 1),    # soc
    (35000, 3),    # internal + MOS1 + MOS2 temperatures
    (35010, 2),    # max / min cell temperature
    (35100, 1),    # inverter state
    (37007, 2),    # max / min cell voltage
    (43000, 1),    # user work mode
]

# Register 37004 is "ac_current" in the community map and is NOT read: on this
# firmware it returns the AC POWER, byte for byte the same word as 30006
# (checked over three samples on 2026-09-04: -796/-796, -797/-797, -797/-796).
# The dashboards derive the AC current from |ac_power| / ac_voltage instead and
# label it as derived, rather than publishing a mislabelled register.

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
    # The two MOS sensors sit in the block 35000 was already reading, so they
    # cost nothing: worth having, because a MOS running away from the internal
    # reading is a cooling problem the cell temperatures would not show.
    "temp_mos1":       (35001, "int16", 0.1),
    "temp_mos2":       (35002, "int16", 0.1),
    # Cell extremes. Their spread is the balance indicator: a healthy pack sits
    # within a few mV, and 37007/37008 are what say so without reading all 16.
    "cell_voltage_max": (37007, "int16", 0.001),
    "cell_voltage_min": (37008, "int16", 0.001),
    "ac_voltage":      (32200, "uint16", 0.1),
    "ac_frequency":    (32204, "uint16", 0.1),
}

# Enum registers, decoded to the text the dashboard shows as-is.
INVERTER_STATES = {
    0: "Sleep", 1: "Standby", 2: "Charge", 3: "Discharge",
    4: "Backup", 5: "OTA", 6: "Bypass",
}
WORK_MODES = {0: "Manual", 1: "Anti-feed", 2: "Trade"}


# pymodbus logs a full ERROR line for every refused connection, which would put
# one line per POLL_INTERVAL in the journal while the battery is down and undo
# the log-once-per-transition handling below. The reason is kept: it still
# reaches the log through last_error.
logging.getLogger("pymodbus").setLevel(logging.CRITICAL)


def now():
    return datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")


def log(msg):
    print(f"[{now()}] {msg}", flush=True)


# Reason the last read failed, reported once when the battery goes offline
# rather than on every poll -- an unreachable battery would otherwise write a
# line per block per POLL_INTERVAL into the journal.
last_error = None


def unit_keyword(client):
    """Which keyword this pymodbus wants for the unit id.

    It was renamed across 3.x (slave -> device_id), and the older versions
    take **kwargs, so passing the wrong spelling is NOT a TypeError there: it
    is swallowed and the request goes out to unit 0. Hence reading the real
    signature instead of trying one and catching the failure. The Pi has
    3.0.0 (slave=), a current pip install has device_id=.
    """
    try:
        params = inspect.signature(client.read_holding_registers).parameters
    except (TypeError, ValueError):
        return "slave"
    for kw in ("device_id", "slave", "unit"):
        if kw in params:
            return kw
    return "slave"


def read_block(client, start, count, kw):
    """One block of holding registers as {address: raw_word}, or None."""
    global last_error

    try:
        rr = client.read_holding_registers(start, count=count,
                                           **{kw: BATTERY_UNIT})
    except Exception as e:
        last_error = f"{e} (register {start})"
        return None
    if rr is None or rr.isError():
        last_error = f"error response for register {start}"
        return None
    return {start + i: v for i, v in enumerate(rr.registers)}


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
    """(payload or None, session still good).

    A partial read is still published: a flaky block should cost that field,
    not the whole message. But the remaining blocks are abandoned as soon as
    one fails, because on this battery a failure means the session is gone --
    every later read in the same poll would fail too, and each attempt extends
    the lockout. The caller reconnects on `clean` being False.
    """
    kw = unit_keyword(client)
    words = {}
    ok = 0
    clean = True
    for start, count in BLOCKS:
        block = read_block(client, start, count, kw)
        if block is None:
            clean = False
            break
        words.update(block)
        ok += 1
    if ok == 0:
        return None, False

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

    return (payload or None), clean


def connect_mqtt():
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    client.username_pw_set(MQTT_USER, MQTT_PASSWORD)
    client.connect(MQTT_HOST, MQTT_PORT, keepalive=60)
    client.loop_start()
    return client


def open_modbus():
    if ModbusTcpClient is None:
        log("pymodbus is not installed: sudo apt install python3-pymodbus "
            "(or pip3 install --break-system-packages pymodbus)")
        return None
    return ModbusTcpClient(BATTERY_HOST, port=BATTERY_PORT,
                           timeout=CONNECT_TIMEOUT)


def check_once():
    """Print one decoded reading and exit -- used to verify the register map
    against the real device before leaving the service running."""
    client = open_modbus()
    if client is None:
        return 1
    if not client.connect():
        log(f"cannot reach {BATTERY_HOST}:{BATTERY_PORT} -- is the battery on "
            "the WIRED LAN port? firmware >= V144?")
        return 1
    payload, _ = read_battery(client)
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

    The client keeps its socket across polls and drops it only when a read
    fails, so a battery that rebooted is picked up again on the next one.
    """
    modbus = open_modbus()
    if modbus is None:
        return None

    def poll():
        if not modbus.connect():
            return None
        payload, clean = read_battery(modbus)
        if not clean:
            # Drop the socket so the next loop reconnects from scratch rather
            # than reusing one the battery has already dropped its end of.
            modbus.close()
        return payload

    return poll


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--check", action="store_true",
                    help="print one reading and exit, without publishing")
    args = ap.parse_args()

    if args.check:
        return check_once()

    poll = make_poller()
    if poll is None:
        return 1

    where = f"{BATTERY_HOST}:{BATTERY_PORT} (modbus unit {BATTERY_UNIT})"
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
