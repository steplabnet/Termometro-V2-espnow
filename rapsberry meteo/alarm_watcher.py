#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Stazione Meteo — alarm watcher and Telegram bot.

Reads /dev/shm/meteo.db every 60s, evaluates the latest row against the
rules stored in alarms.db (managed by alarms.php) and sends edge-triggered
Telegram messages with the user-configured text on each rule's first match.
Also long-polls Telegram for inbound /status, /alarms, /help commands so
the user can query the station from the chat.
"""

import json
import math
import os
import sqlite3
import subprocess
import sys
import time
from collections import namedtuple
from datetime import datetime, time as dt_time, timedelta

import requests

from zbot import BOT_TOKEN, CHAT_ID

# ── Paths ───────────────────────────────────────────────────────────────────
HERE = os.path.dirname(os.path.abspath(__file__))
# Pinned to the same absolute path used by alarms.php so the two processes
# can never read different files. Persistent (on disk, not tmpfs).
ALARMS_DB_PATH = "/var/www/html/alarms.db"
ALARM_STATE_PATH = "/dev/shm/alarm_state.json"
DB_PATH = "/dev/shm/meteo.db"

# ── Tuning ──────────────────────────────────────────────────────────────────
ALARM_CHECK_INTERVAL = 60  # seconds between DB evaluations
TG_POLL_TIMEOUT = 25       # long-polling getUpdates timeout
DEBOUNCE_COUNT = 2         # consecutive readings required to switch state

# ── Metric metadata ─────────────────────────────────────────────────────────
# `sentinel` is the magic value meteo.py writes when the source is offline.
METRICS = {
    "temp":    {"label": "Full Sun Temp",  "unit": "°C", "sentinel": -100, "decimals": 1},
    "humi":    {"label": "Full Sun Humi",  "unit": "%",  "sentinel": -1,   "decimals": 0},
    "tombra":  {"label": "Ombra Temp",     "unit": "°C", "sentinel": -100, "decimals": 1},
    "hombra":  {"label": "Ombra Humi",     "unit": "%",  "sentinel": -1,   "decimals": 0},
    "tMobile": {"label": "Interno Temp",   "unit": "°C", "sentinel": -100, "decimals": 1},
    "hMobile": {"label": "Interno Humi",   "unit": "%",  "sentinel": -1,   "decimals": 0},
    "power":   {"label": "Potenza",        "unit": "W",  "sentinel": None, "decimals": 0},
    "tempCpu": {"label": "CPU Temp",       "unit": "°C", "sentinel": None, "decimals": 1},
    # Office thermostat board — merged from the `ufficio` table by fetch_latest().
    # Stale/missing office readings are normalised to None (see UFFICIO_STALE_S).
    "uff_temp":     {"label": "Ufficio Temp",      "unit": "°C",  "sentinel": None, "decimals": 1},
    "uff_hum":      {"label": "Ufficio Umidità",   "unit": "%",   "sentinel": None, "decimals": 0},
    "uff_pres":     {"label": "Ufficio Pressione", "unit": "hPa", "sentinel": None, "decimals": 0},
    "uff_setpoint": {"label": "Ufficio Setpoint",  "unit": "°C",  "sentinel": None, "decimals": 1},
}

# Shelly Pro EM-50 sources, merged from the `energia` table by _merge_energia().
# pv_raw is the meter reading BEFORE the PV_ZERO_THRESHOLD deadband that
# mqtt_receiver.py applies, so a rule "pv_raw = 0" fires only when the meter
# really reads nothing (inverter down / string offline) and not on the leakage
# and standby noise that every other consumer of pv_power flattens to 0 W.
ENERGY_METRICS = {
    "pv_raw":     {"label": "Shelly — Produzione reale", "unit": " W", "sentinel": None, "decimals": 0},
    "pv_power":   {"label": "Shelly — Produzione",       "unit": " W", "sentinel": None, "decimals": 0},
    "grid_power": {"label": "Shelly — Scambio rete",     "unit": " W", "sentinel": None, "decimals": 0},
    "casa_power": {"label": "Shelly — Consumo casa",     "unit": " W", "sentinel": None, "decimals": 0},
}

# Energy readings older than this are treated as missing, so a dead meter or a
# stopped mqtt_receiver.py can't keep a rule latched on the last value. The
# Shelly publishes every couple of seconds and the energia table is written
# once a minute, so this is generous.
ENERGY_STALE_S = 600
ENERGY_KEYS = frozenset(ENERGY_METRICS)

# Marstek Venus E figures, copied from batteria.php so the dashboard and the
# /batteria report can never disagree: rated pack capacity (used to turn SoC
# into a residual kWh) and the deadband under which the pack counts as idle.
BATTERY_CAPACITY_KWH = 5.12
BATTERY_IDLE_W = 15
# The bridge publishes every few seconds and the batteria table is written once
# a minute, so a row older than this means the bridge or the battery is down —
# reported as "no data" rather than as a frozen last reading.
BATTERY_STALE_S = 600

# Grid voltage below this means the inverter has no AC line (blackout, breaker
# open, plug pulled). The Venus reads ~230 V when connected and drops to 0 —
# the threshold only has to sit clear of both.
BATTERY_AC_MIN_V = 100.0

# ── Allarmi predefiniti ─────────────────────────────────────────────────────
# Turn-key alarms whose condition is wired here instead of being assembled in
# alarms.php: the page can only switch them on and off (table `presets`).
# They exist for the checks that no combination of alarms.php sources can
# express — "rete AC assente" reads the `batteria` table, not the meteo row.
# Keys must match $PRESETS in alarms.php.
PRESET_ALARMS = {
    "battery_no_ac": {
        "label":           "Batteria — Rete AC assente",
        "icon":            "⚡",
        "message":         "Batteria: rete AC assente.",
        "restore_icon":    "✅",
        "restore_message": "Batteria: rete AC ripristinata.",
    },
}

# Office readings older than this are treated as missing so a board dropout
# can't keep firing alarms on a frozen last value.
UFFICIO_STALE_S = 1200

# Office sources are merged from a separate table and already nulled when stale
# (see _merge_ufficio), so a "stale" condition on them relies purely on the
# value being missing — not on the meteo row's own timestamp age.
UFFICIO_KEYS = frozenset(("uff_temp", "uff_hum", "uff_pres", "uff_setpoint"))

# Default max age for a "stale" (not-transmitting) condition when the rule
# doesn't specify one. A source counts as not transmitting if the whole meteo
# reading is older than this — the case where meteo.py died and the last row
# froze with valid values, so no per-source sentinel ever appears. Individual
# sensors that stop while meteo.py keeps running are caught immediately by their
# sentinel value regardless of this threshold.
STALE_DEFAULT_S = 900  # 15 minutes

# Special "stale" source: fires if ANY physical meteo.py sensor stops. Those are
# the METRICS entries that report absence via a sentinel (temp/tMobile/tombra →
# -100, hMobile/hombra → -1); power/tempCpu are computed locally and never drop
# out, so they carry no sentinel and are excluded. Must match the value used in
# alarms.php.
STALE_ANY = "__any__"
STALE_ANY_SOURCES = tuple(k for k, m in METRICS.items() if m["sentinel"] is not None)

# Virtual (derived) sources. Selectable in alarms.php and queryable via
# /forecast. Kept in a separate dict so /status doesn't list them as sensor
# readings — but ALL_METRICS below merges both for value-condition lookups.
FORECAST_METRICS = {
    "forecast_temp_6am":    {"label": "Forecast 6am Full Sun", "unit": "°C", "sentinel": None, "decimals": 1},
    "forecast_tombra_6am":  {"label": "Forecast 6am Ombra",    "unit": "°C", "sentinel": None, "decimals": 1},
    "forecast_tMobile_6am": {"label": "Forecast 6am Interno",  "unit": "°C", "sentinel": None, "decimals": 1},
}
# Control/state sources exposed to value & compare conditions but kept out of
# METRICS so /status doesn't double-list them (fan has its own ON/OFF line).
# `fan` is 0/1 as written by meteo.py; a rule "fan = 1" fires when it turns on.
CONTROL_METRICS = {
    "fan": {"label": "Ventola Raspberry", "unit": "", "sentinel": None, "decimals": 0},
}
ALL_METRICS = {**METRICS, **FORECAST_METRICS, **CONTROL_METRICS, **ENERGY_METRICS}


def log(msg):
    print(msg, flush=True)


# A command reply that needs more than plain text: an HTML-formatted report,
# an inline keyboard, or both. Plain strings are still valid replies.
Reply = namedtuple("Reply", "text parse_mode markup")
Reply.__new__.__defaults__ = (None, None)


# ── Telegram I/O ────────────────────────────────────────────────────────────
def set_bot_commands():
    """Register the command menu (the blue Menu / autocomplete list) with Telegram.

    Called at startup so the menu always matches handle_command() — BotFather's
    /setcommands would otherwise drift out of date. Idempotent: Telegram just
    overwrites the previous list."""
    commands = [
        {"command": "status",   "description": "Report: temperature, energia o tutto"},
        {"command": "temperature", "description": "Tutte le temperature e umidità"},
        {"command": "energia",  "description": "Produzione e consumi elettrici"},
        {"command": "batteria", "description": "Stato e salute della batteria"},
        {"command": "forecast", "description": "Previsione 6am (cielo sereno)"},
        {"command": "alarms",   "description": "Soglie configurate e allarmi attivi"},
        {"command": "reboot",   "description": "Riavvia il Raspberry"},
        {"command": "help",     "description": "Elenco comandi"},
    ]
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/setMyCommands"
    try:
        r = requests.post(url, json={"commands": commands}, timeout=10)
        r.raise_for_status()
        return r.json().get("ok", False)
    except requests.exceptions.RequestException as e:
        log(f"[commands] set error: {e}")
        return False


def send_message(text, token=None, chat_id=None, reply_markup=None, parse_mode=None):
    """Send via the given bot, or the default zbot.py bot when token/chat are None."""
    url = f"https://api.telegram.org/bot{token or BOT_TOKEN}/sendMessage"
    payload = {"chat_id": chat_id if chat_id is not None else CHAT_ID, "text": text}
    if reply_markup is not None:
        payload["reply_markup"] = json.dumps(reply_markup)
    if parse_mode:
        payload["parse_mode"] = parse_mode
    try:
        r = requests.post(url, data=payload, timeout=10)
        r.raise_for_status()
        return r.json().get("ok", False)
    except requests.exceptions.RequestException as e:
        log(f"[send] error: {e}")
        return False


def answer_callback_query(callback_id, text=None):
    """Stop the spinner on an inline button. Failing here is not fatal."""
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/answerCallbackQuery"
    payload = {"callback_query_id": callback_id}
    if text:
        payload["text"] = text
    try:
        requests.post(url, data=payload, timeout=10)
    except requests.exceptions.RequestException as e:
        log(f"[callback] answer error: {e}")


# ── Persistence helpers ─────────────────────────────────────────────────────
def _load_user_rules():
    """Return a list of rule dicts (each with a 'conditions' list) from alarms.db."""
    if not os.path.exists(ALARMS_DB_PATH):
        return []
    try:
        # Read-only URI so a watcher running as a non-www-data user never
        # tries to write to a www-data-owned file.
        uri = f"file:{ALARMS_DB_PATH}?mode=ro"
        con = sqlite3.connect(uri, uri=True, timeout=2)
        con.row_factory = sqlite3.Row
        # bot_id was added later — select it only if the column exists so an
        # un-migrated database keeps firing rules through the default bot.
        rcols = {c["name"] for c in con.execute("PRAGMA table_info(rules)")}
        cols = "id, enabled, message"
        if "bot_id" in rcols:
            cols += ", bot_id"
        # icon / restore_icon / restore_message were added later — select them
        # only if present so an un-migrated database keeps working.
        for opt in ("icon", "restore_icon", "restore_message"):
            if opt in rcols:
                cols += ", " + opt
        rules = [dict(r) for r in con.execute(
            f"SELECT {cols} FROM rules ORDER BY id"
        ).fetchall()]
        # source2 (for source-vs-source "compare" conditions) was added later —
        # select it only if the column exists so an un-migrated DB still loads.
        ccols = "kind, source, op, value, time_from, time_to, schedule_at, days_mask"
        has_source2 = any(c["name"] == "source2" for c in con.execute("PRAGMA table_info(conditions)"))
        if has_source2:
            ccols += ", source2"
        for r in rules:
            r["conditions"] = [dict(c) for c in con.execute(
                f"SELECT {ccols} FROM conditions WHERE rule_id = ? ORDER BY id",
                (r["id"],)
            ).fetchall()]
        con.close()
        return rules
    except sqlite3.OperationalError:
        # Schema not yet created — alarms.php hasn't been visited.
        return []
    except Exception as e:
        log(f"[rules] load error: {e}")
        return []


def load_preset_rules():
    """Enabled predefined alarms, as synthetic rules the normal engine can run.

    Their id is the string "preset:<key>" — unique against the integer rule ids,
    and the same key alarms.php uses for the "riarma" button on that row."""
    if not os.path.exists(ALARMS_DB_PATH):
        return []
    rules = []
    try:
        uri = f"file:{ALARMS_DB_PATH}?mode=ro"
        con = sqlite3.connect(uri, uri=True, timeout=2)
        try:
            enabled = [r[0] for r in con.execute(
                "SELECT key FROM presets WHERE enabled = 1")]
        except sqlite3.OperationalError:
            enabled = []  # presets table not created yet
        con.close()
    except Exception as e:
        log(f"[presets] load error: {e}")
        return []
    for key in enabled:
        meta = PRESET_ALARMS.get(key)
        if not meta:
            continue  # a preset removed from the code but still stored
        rules.append({
            "id": f"preset:{key}",
            "enabled": 1,
            "message": meta["message"],
            "icon": meta["icon"],
            "restore_icon": meta["restore_icon"],
            "restore_message": meta["restore_message"],
            "bot_id": None,
            "conditions": [{"kind": "preset", "source": key}],
        })
    return rules


def load_rules():
    """User rules from alarms.php plus the enabled predefined alarms."""
    return _load_user_rules() + load_preset_rules()


def load_bots():
    """Return {bot_id: {'name','token','chat_id'}} from alarms.db (read-only)."""
    bots = {}
    if not os.path.exists(ALARMS_DB_PATH):
        return bots
    try:
        uri = f"file:{ALARMS_DB_PATH}?mode=ro"
        con = sqlite3.connect(uri, uri=True, timeout=2)
        con.row_factory = sqlite3.Row
        try:
            for r in con.execute("SELECT id, name, token, chat_id FROM bots"):
                bots[r["id"]] = {"name": r["name"], "token": r["token"], "chat_id": r["chat_id"]}
        except sqlite3.OperationalError:
            pass  # bots table not created yet
        con.close()
    except Exception as e:
        log(f"[bots] load error: {e}")
    return bots


def send_rule_message(rule, bots, text=None):
    """Send a rule's alarm via its associated bot, or the default bot if unset."""
    if text is None:
        text = rule_alarm_text(rule)
    bot = bots.get(rule.get("bot_id")) if rule.get("bot_id") else None
    if bot and bot.get("token") and bot.get("chat_id"):
        return send_message(text, token=bot["token"], chat_id=bot["chat_id"])
    return send_message(text)


def load_state():
    try:
        with open(ALARM_STATE_PATH, encoding="utf-8") as f:
            data = json.load(f)
            return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def save_state(state):
    try:
        tmp = ALARM_STATE_PATH + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(state, f)
        os.replace(tmp, ALARM_STATE_PATH)
        # Every save creates a new file, so the mode has to be re-applied each
        # time: alarms.php (www-data) rewrites this file in place to clear a
        # single rule's latched state from its "riarma" button.
        os.chmod(ALARM_STATE_PATH, 0o666)
    except Exception as e:
        log(f"[state] save error: {e}")


def _merge_ufficio(con, row):
    """Augment the meteo row with the latest office reading under uff_* keys.

    Values are nulled out if the office reading is older than UFFICIO_STALE_S so
    a board dropout doesn't keep alarms latched on a stale value. NaN/None map
    to None as well. `row` is assumed to be a dict (created by the caller if the
    meteo table was empty)."""
    for key in ("uff_temp", "uff_hum", "uff_pres", "uff_setpoint"):
        row.setdefault(key, None)
    try:
        urow = con.execute(
            "SELECT timestamp, temp, hum, pres, setpoint FROM ufficio ORDER BY id DESC LIMIT 1"
        ).fetchone()
    except sqlite3.OperationalError:
        return row  # ufficio table not created yet
    if not urow:
        return row

    ts = urow["timestamp"]
    fresh = False
    if ts:
        try:
            age = (datetime.utcnow() - datetime.strptime(ts, "%Y-%m-%dT%H:%M:%SZ")).total_seconds()
            fresh = age <= UFFICIO_STALE_S
        except ValueError:
            fresh = False
    if not fresh:
        return row

    def clean(v):
        if v is None:
            return None
        try:
            f = float(v)
            return f if f == f else None  # reject NaN
        except (TypeError, ValueError):
            return None

    row["uff_temp"]     = clean(urow["temp"])
    row["uff_hum"]      = clean(urow["hum"])
    row["uff_pres"]     = clean(urow["pres"])
    row["uff_setpoint"] = clean(urow["setpoint"])
    return row


def _merge_energia(con, row):
    """Augment the reading with the latest Shelly Pro EM-50 row under the
    ENERGY_METRICS keys.

    Same contract as _merge_ufficio: the energia table has its own cadence and
    its own timestamp, so anything older than ENERGY_STALE_S is left as None
    rather than kept alive by the meteo row's freshness. pv_raw comes from
    pv_power_raw, the value as the meter reported it — older rows (written
    before that column existed) simply have no raw value and leave it None."""
    for key in ENERGY_METRICS:
        row.setdefault(key, None)
    try:
        erow = con.execute(
            "SELECT * FROM energia ORDER BY id DESC LIMIT 1"
        ).fetchone()
    except sqlite3.OperationalError:
        return row  # energia table not created yet
    if not erow:
        return row

    ts = erow["timestamp"]
    fresh = False
    if ts:
        try:
            age = (datetime.utcnow() - datetime.strptime(ts, "%Y-%m-%dT%H:%M:%SZ")).total_seconds()
            fresh = age <= ENERGY_STALE_S
        except ValueError:
            fresh = False
    if not fresh:
        return row

    cols = erow.keys()

    def clean(name):
        if name not in cols:
            return None
        v = erow[name]
        if v is None:
            return None
        try:
            f = float(v)
            return f if f == f else None  # reject NaN
        except (TypeError, ValueError):
            return None

    row["pv_raw"]     = clean("pv_power_raw")
    row["pv_power"]   = clean("pv_power")
    row["grid_power"] = clean("grid_power")
    row["casa_power"] = clean("casa_power")
    return row


def fetch_latest():
    try:
        con = sqlite3.connect(DB_PATH, timeout=2)
        con.row_factory = sqlite3.Row
        meteo_row = con.execute(
            "SELECT * FROM meteo ORDER BY id DESC LIMIT 1"
        ).fetchone()
        # Office sources live in a separate table; merge them so value
        # conditions on uff_* resolve through the same code path.
        row = dict(meteo_row) if meteo_row else {}
        row = _merge_ufficio(con, row)
        # Shelly Pro EM-50 sources, likewise from their own table.
        row = _merge_energia(con, row)
        con.close()
        # Return None only if there is genuinely no data at all, preserving the
        # previous contract for the meteo-only case.
        if not meteo_row and all(row.get(k) is None for k in
                                 ("uff_temp", "uff_hum", "uff_pres", "uff_setpoint",
                                  *ENERGY_KEYS)):
            return None
        return row
    except Exception as e:
        log(f"[db] error: {e}")
        return None


# ── Evaluation ──────────────────────────────────────────────────────────────
def is_missing(value, sentinel):
    if value is None:
        return True
    if sentinel is not None and value == sentinel:
        return True
    return False


def fmt_value(value, meta):
    if is_missing(value, meta["sentinel"]):
        return "n/a"
    if meta["decimals"] > 0 and isinstance(value, (int, float)):
        return f"{value:.{meta['decimals']}f}{meta['unit']}"
    return f"{int(value)}{meta['unit']}"


DAY_NAMES_IT = ['lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom']
DAY_RANGE_LABELS = {
    127: 'ogni giorno',
    31:  'lun-ven',
    63:  'lun-sab',
    96:  'sab-dom',
}


def format_days_mask(mask):
    if mask is None:
        mask = 127
    if mask in DAY_RANGE_LABELS:
        return DAY_RANGE_LABELS[mask]
    days = [DAY_NAMES_IT[i] for i in range(7) if mask & (1 << i)]
    return ', '.join(days) if days else 'mai'


def parse_hm(s):
    """Parse 'HH:MM' into a datetime.time, or None on bad input."""
    if not s:
        return None
    try:
        h, m = s.split(":")
        return dt_time(int(h), int(m))
    except Exception:
        return None


def in_time_window(now_t, t_from, t_to):
    """Inclusive window. Crosses midnight if t_from > t_to."""
    if t_from <= t_to:
        return t_from <= now_t <= t_to
    return now_t >= t_from or now_t <= t_to


# ── Forecast model ──────────────────────────────────────────────────────────
# Clear-sky radiative cooling: temperature drops at roughly 1.2°C/h on a calm,
# cloudless night and asymptotes at the dew point (the temperature at which
# the air's water vapour starts to condense — the physical floor for cooling).
# We cap the cooling integration window so daytime calls (where the next 6am
# is many hours away) don't compound the linear rate into absurd drops.
CLEAR_SKY_COOLING_RATE = 1.2  # °C per hour
MAX_COOLING_HOURS = 10        # cap on cooling time used by the model

# (temp_source, humidity_source, forecast_key). Humidity is only used to
# compute the dew-point floor; if it's missing the forecast still runs but
# can drop arbitrarily low (linear projection only).
FORECAST_INPUTS = [
    ("temp",    "humi",    "forecast_temp_6am"),
    ("tombra",  "hombra",  "forecast_tombra_6am"),
    ("tMobile", "hMobile", "forecast_tMobile_6am"),
]


def hours_until_next_6am(now):
    target = now.replace(hour=6, minute=0, second=0, microsecond=0)
    if now >= target:
        target = target + timedelta(days=1)
    return (target - now).total_seconds() / 3600.0


def dew_point(temp_c, rh):
    """Magnus approximation. Returns None on bad/edge input."""
    if temp_c is None or rh is None or rh <= 0 or rh > 100:
        return None
    a, b = 17.625, 243.04
    try:
        alpha = (a * temp_c) / (b + temp_c) + math.log(rh / 100.0)
        denom = a - alpha
        if denom == 0:
            return None
        return (b * alpha) / denom
    except (ValueError, ZeroDivisionError):
        return None


def forecast_temp_6am(temp_c, rh, hours_left):
    if temp_c is None:
        return None
    cooling_h = min(hours_left, MAX_COOLING_HOURS)
    raw = temp_c - CLEAR_SKY_COOLING_RATE * cooling_h
    floor = dew_point(temp_c, rh)
    if floor is not None and raw < floor:
        return floor
    return raw


def compute_forecasts(row, now):
    """Return {forecast_key: value or None} for all virtual sources."""
    out = {key: None for _, _, key in FORECAST_INPUTS}
    if not row:
        return out
    h_left = hours_until_next_6am(now)
    for t_src, h_src, fkey in FORECAST_INPUTS:
        t_meta = METRICS[t_src]
        h_meta = METRICS[h_src]
        t_val = row.get(t_src)
        if is_missing(t_val, t_meta["sentinel"]):
            continue
        h_val = row.get(h_src)
        if is_missing(h_val, h_meta["sentinel"]):
            h_val = None
        out[fkey] = forecast_temp_6am(t_val, h_val, h_left)
    return out


def evaluate_value_cond(cond, row):
    """Return True/False, or None if the source is missing/unknown."""
    src = cond["source"]
    meta = ALL_METRICS.get(src)
    if meta is None:
        return None
    value = row.get(src) if row else None
    if is_missing(value, meta["sentinel"]):
        return None
    op = cond["op"]
    threshold = cond["value"]
    if op == ">":
        return value > threshold
    if op == "<":
        return value < threshold
    if op == "=":
        return value == threshold
    return None


def evaluate_compare_cond(cond, row):
    """Compare two sources (e.g. temp < tombra). True/False, or None if either
    source is missing/sentinel/unknown so we can't decide yet."""
    meta1 = ALL_METRICS.get(cond.get("source"))
    meta2 = ALL_METRICS.get(cond.get("source2"))
    if meta1 is None or meta2 is None:
        return None
    v1 = row.get(cond["source"]) if row else None
    v2 = row.get(cond["source2"]) if row else None
    if is_missing(v1, meta1["sentinel"]) or is_missing(v2, meta2["sentinel"]):
        return None
    op = cond["op"]
    if op == ">":
        return v1 > v2
    if op == "<":
        return v1 < v2
    if op == "=":
        return v1 == v2
    return None


def _stale_max_age_s(cond):
    """Max reading age (seconds) after which a source counts as not transmitting.
    The rule stores the threshold in `value` as minutes; blank/invalid → default."""
    v = cond.get("value")
    if v is None:
        return STALE_DEFAULT_S
    try:
        s = float(v) * 60.0
        return s if s > 0 else STALE_DEFAULT_S
    except (TypeError, ValueError):
        return STALE_DEFAULT_S


def evaluate_stale_cond(cond, row):
    """A 'stale' condition is True when the chosen source is NOT transmitting.

    Two independent signals, either of which makes it True:
      • the source's latest value is missing/sentinel (the sensor itself
        dropped out — meteo.py writes -100/-1 when a station goes silent, and
        office readings are nulled when stale); or
      • for meteo/forecast sources, the whole latest reading is older than the
        configured max age — i.e. meteo.py stopped and the last row froze with
        still-valid values, so no per-source sentinel ever appears.

    The special source STALE_ANY covers "any meteo sensor": True if the whole
    reading is too old, or any physical sensor has gone to its sentinel.

    Never returns None: staleness is always decidable (no data at all is stale).
    Returns None only for an unknown/misconfigured source so the rule holds."""
    src = cond.get("source")

    if src == STALE_ANY:
        # No reading at all → not transmitting.
        if not row:
            return True
        # Whole pipeline silent longer than the threshold (meteo.py died).
        ts = row.get("timestamp")
        if ts:
            try:
                age = (datetime.utcnow()
                       - datetime.strptime(ts, "%Y-%m-%dT%H:%M:%SZ")).total_seconds()
                if age > _stale_max_age_s(cond):
                    return True
            except ValueError:
                pass
        # Any single transmitter dropped (its value went to the sentinel).
        for s in STALE_ANY_SOURCES:
            if is_missing(row.get(s), METRICS[s]["sentinel"]):
                return True
        return False

    meta = ALL_METRICS.get(src)
    if meta is None:
        return None

    # No reading at all → definitely not transmitting.
    if not row:
        return True

    # Whole-pipeline staleness. Office and energy sources carry their own
    # freshness (their merge nulls stale values), so skip the meteo-row age
    # check for them — meteo.py stopping says nothing about the meter.
    if src not in UFFICIO_KEYS and src not in ENERGY_KEYS:
        ts = row.get("timestamp")
        if ts:
            try:
                age = (datetime.utcnow()
                       - datetime.strptime(ts, "%Y-%m-%dT%H:%M:%SZ")).total_seconds()
                if age > _stale_max_age_s(cond):
                    return True
            except ValueError:
                pass  # unparseable timestamp — fall through to the value check

    value = row.get(src)
    if is_missing(value, meta["sentinel"]):
        return True
    return False


def is_scheduled_rule(rule):
    return any(c["kind"] == "schedule" for c in rule.get("conditions", []))


def rule_describe(rule):
    """Short human description of a rule's conditions, used as fallback message."""
    parts = []
    for c in rule.get("conditions", []):
        if c["kind"] == "value":
            meta = ALL_METRICS.get(c["source"], {"label": c["source"], "unit": ""})
            parts.append(f"{meta['label']} {c['op']} {c['value']}{meta['unit']}")
        elif c["kind"] == "compare":
            m1 = ALL_METRICS.get(c.get("source"),  {"label": c.get("source"),  "unit": ""})
            m2 = ALL_METRICS.get(c.get("source2"), {"label": c.get("source2"), "unit": ""})
            parts.append(f"{m1['label']} {c['op']} {m2['label']}")
        elif c["kind"] == "stale":
            src = c.get("source")
            if src == STALE_ANY:
                label = "Qualsiasi sensore meteo"
            else:
                label = ALL_METRICS.get(src, {"label": src})["label"]
            mins = c.get("value")
            if mins:
                parts.append(f"{label} non trasmette (>{int(mins)} min)")
            else:
                parts.append(f"{label} non trasmette")
        elif c["kind"] == "time_window":
            days = format_days_mask(c.get("days_mask"))
            tail = "" if days == "ogni giorno" else f" ({days})"
            parts.append(f"finestra {c['time_from']}-{c['time_to']}{tail}")
        elif c["kind"] == "schedule":
            days = format_days_mask(c.get("days_mask"))
            tail = "" if days == "ogni giorno" else f" ({days})"
            parts.append(f"alle {c['schedule_at']}{tail}")
    return " AND ".join(parts) if parts else f"regola #{rule['id']}"


def _with_icon(icon, text):
    icon = (icon or "").strip()
    return f"{icon} {text}" if icon else text


def rule_alarm_text(rule):
    msg = (rule.get("message") or "").strip()
    if not msg:
        msg = f"[ALARM] {rule_describe(rule)}"
    return _with_icon(rule.get("icon"), msg)


def rule_restore_text(rule):
    """Message to send when an edge-triggered rule rearms, or None if unset."""
    msg = (rule.get("restore_message") or "").strip()
    if not msg:
        return None
    return _with_icon(rule.get("restore_icon"), msg)


# Debounce counters for edge-triggered rules. Lost on restart, which only
# delays the next transition by DEBOUNCE_COUNT cycles.
_debounce = {}


def _eval_non_schedule_conditions(rule, row, now_t, today_bit):
    """Evaluate value + time_window conditions (AND).

    Returns True if all hold, False if any fails, None if a value source is
    missing/sentinel and we can't decide yet. `today_bit` is 1 << weekday
    and is used to gate time_window conditions by their days_mask.
    """
    for c in rule["conditions"]:
        kind = c["kind"]
        if kind == "value":
            res = evaluate_value_cond(c, row)
            if res is None:
                return None
            if not res:
                return False
        elif kind == "compare":
            res = evaluate_compare_cond(c, row)
            if res is None:
                return None
            if not res:
                return False
        elif kind == "stale":
            res = evaluate_stale_cond(c, row)
            if res is None:
                return None
            if not res:
                return False
        elif kind == "time_window":
            tf = parse_hm(c["time_from"])
            tt = parse_hm(c["time_to"])
            if tf is None or tt is None:
                return None
            if not in_time_window(now_t, tf, tt):
                return False
            mask = c.get("days_mask")
            if mask is None:
                mask = 127
            if not (mask & today_bit):
                return False
        # schedule conditions are handled by the scheduled-rule branch
    return True


def check_alarms():
    rules = load_rules()
    bots = load_bots()
    state = load_state()
    row = fetch_latest()

    now = datetime.now()
    today_str = now.strftime("%Y-%m-%d")
    now_t = now.time()
    today_bit = 1 << now.weekday()  # Mon=bit0 … Sun=bit6, matches days_mask

    # Augment the latest reading with forecast values so value-conditions on
    # virtual sources (forecast_*_6am) resolve through the same code path.
    if row is not None:
        row.update(compute_forecasts(row, now))

    valid_ids = {str(r["id"]) for r in rules}
    state_changed = False
    for stale in [k for k in state.keys() if k not in valid_ids]:
        state.pop(stale, None)
        _debounce.pop(stale, None)
        state_changed = True

    for rule in rules:
        rid = str(rule["id"])

        if not rule["enabled"]:
            _debounce.pop(rid, None)
            if state.pop(rid, None) is not None:
                state_changed = True
            continue

        if is_scheduled_rule(rule):
            # Scheduled mode: fire once per day when *all* schedule conditions
            # are active today (days_mask) and their time has passed, AND the
            # other conditions are satisfied.
            entry = state.get(rid) if isinstance(state.get(rid), dict) else {}
            if entry.get("last_fired") == today_str:
                continue

            schedule_ok = True
            for c in rule["conditions"]:
                if c["kind"] != "schedule":
                    continue
                mask = c.get("days_mask")
                if mask is None:
                    mask = 127
                if not (mask & today_bit):
                    schedule_ok = False
                    break
                sched_t = parse_hm(c["schedule_at"])
                if sched_t is None or now_t < sched_t:
                    schedule_ok = False
                    break
            if not schedule_ok:
                continue

            if not _eval_non_schedule_conditions(rule, row, now_t, today_bit):
                continue

            send_rule_message(rule, bots)
            state[rid] = {"last_fired": today_str}
            state_changed = True
            continue

        # Edge-triggered mode. Fires once on the rising edge, auto-rearms on
        # the falling edge (`verified is False`), and can fire again on the
        # next rising edge. `verified is None` (missing source) keeps the
        # current state so a sensor dropout doesn't silently rearm.
        entry = state.get(rid) if isinstance(state.get(rid), dict) else {}
        if row is None:
            continue
        verified = _eval_non_schedule_conditions(rule, row, now_t, today_bit)

        if entry.get("fired"):
            if verified is False:
                restore = rule_restore_text(rule)
                if restore:
                    send_rule_message(rule, bots, text=restore)
                state.pop(rid, None)
                _debounce.pop(rid, None)
                state_changed = True
            continue

        if not verified:  # False or None
            _debounce.pop(rid, None)
            continue

        d = _debounce.get(rid) or {"count": 0}
        d["count"] += 1
        _debounce[rid] = d
        if d["count"] < DEBOUNCE_COUNT:
            continue

        send_rule_message(rule, bots)
        state[rid] = {"fired": True, "fired_at": now.strftime("%Y-%m-%d %H:%M:%S")}
        _debounce.pop(rid, None)
        state_changed = True

    if state_changed:
        save_state(state)


# ── Bot commands ────────────────────────────────────────────────────────────
def format_status():
    """The "Tutto" report: both sections from a single reading, so the two
    halves can never show values taken a minute apart."""
    row = fetch_latest()
    if not row:
        return "Nessun dato disponibile."

    parts = [format_temperatures(row), format_energy(row), format_battery()]
    sid = row.get("station_id")
    if sid is not None:
        parts.append(f"<i>Stazione {sid}</i>")
    return "\n\n".join(parts)


# ── /status reports ─────────────────────────────────────────────────────────
# Sensors listed by the temperature report: icon, label, current-value keys (as
# fetch_latest() returns them) and where their 24h history lives. The office
# board logs to its own table, so its extremes come from there.
TEMP_REPORT = (
    ("☀️", "Full Sun", "temp",     "humi",    "meteo",   "temp",    "humi"),
    ("🌳", "Ombra",    "tombra",   "hombra",  "meteo",   "tombra",  "hombra"),
    ("🏠", "Interno",  "tMobile",  "hMobile", "meteo",   "tMobile", "hMobile"),
    ("🏢", "Ufficio",  "uff_temp", "uff_hum", "ufficio", "temp",    "hum"),
)


def fmt_ts_local(ts):
    """'2026-08-29T17:06:17Z' -> '29/08 19:06 · 2 min fa' in station local time.

    The reports are read on a phone, where "how old is this" matters more than
    the raw UTC stamp the row carries."""
    if not ts:
        return None
    try:
        when = datetime.strptime(ts, "%Y-%m-%dT%H:%M:%SZ")
    except (TypeError, ValueError):
        return str(ts)
    # The watcher works in naive local time everywhere else; derive the offset
    # rather than pulling in a tz library for one line.
    local = when + (datetime.now() - datetime.utcnow())
    age_min = max(0, int((datetime.utcnow() - when).total_seconds() // 60))
    if age_min < 1:
        age = "ora"
    elif age_min < 60:
        age = f"{age_min} min fa"
    else:
        age = f"{age_min // 60}h {age_min % 60}min fa"
    return f"{local.strftime('%d/%m %H:%M')} · {age}"


def report_header(title, ts):
    lines = [f"<b>{title}</b>"]
    stamp = fmt_ts_local(ts)
    if stamp:
        lines.append(f"<i>{stamp}</i>")
    lines.append("")
    return lines


def window_extremes(table, col, valid_min=None, hours=24):
    """(min, max) of one column over the last `hours`, or (None, None).

    `valid_min` filters out the offline sentinels (-100 °C, -1 %), which would
    otherwise win every MIN() as soon as a sensor blinks out once."""
    since = (datetime.utcnow() - timedelta(hours=hours)).strftime("%Y-%m-%dT%H:%M:%SZ")
    sql = f"SELECT MIN({col}), MAX({col}) FROM {table} WHERE timestamp >= ?"
    params = [since]
    if valid_min is not None:
        sql += f" AND {col} > ?"
        params.append(valid_min)
    try:
        con = sqlite3.connect(DB_PATH, timeout=2)
        row = con.execute(sql, params).fetchone()
        con.close()
    except Exception as e:
        log(f"[report] extremes {table}.{col}: {e}")
        return (None, None)
    if not row:
        return (None, None)
    return (row[0], row[1])


def fmt_w(value, default="--"):
    """Watts the way the dashboard prints them: kW past 1000 W."""
    if value is None:
        return default
    value = float(value)
    if abs(value) >= 1000:
        return f"{value / 1000:.2f} kW"
    return f"{round(value):.0f} W"


def fmt_range(lo, hi, decimals, unit):
    if lo is None or hi is None:
        return None
    return f"{lo:.{decimals}f}–{hi:.{decimals}f}{unit}"


def format_temperatures(row=None):
    """All temperature and humidity sensors, with their 24h range.

    One block per sensor: the two current values on the headline, the 24h
    extremes on a dimmer second line, so the numbers that matter are the ones
    you read first."""
    row = row if row is not None else fetch_latest()
    if not row:
        return "Nessun dato disponibile."

    lines = report_header("🌡️ Temperature e umidità", row.get("timestamp"))

    for icon, label, tkey, hkey, table, tcol, hcol in TEMP_REPORT:
        t_str = fmt_value(row.get(tkey), METRICS[tkey])
        h_str = fmt_value(row.get(hkey), METRICS[hkey])
        lines.append(f"{icon} <b>{label}</b>   {t_str}   ·   {h_str}")
        t_rng = fmt_range(*window_extremes(table, tcol, valid_min=-99), decimals=1, unit="°C")
        h_rng = fmt_range(*window_extremes(table, hcol, valid_min=0),   decimals=0, unit="%")
        if t_rng or h_rng:
            detail = "   ·   ".join(x for x in (t_rng, h_rng) if x)
            lines.append(f"      <i>24h  {detail}</i>")

    sp = row.get("uff_setpoint")
    if sp is not None:
        lines.append(f"      <i>setpoint {fmt_value(sp, METRICS['uff_setpoint'])}</i>")

    lines.append("")
    lines.append(f"🖥 CPU {fmt_value(row.get('tempCpu'), METRICS['tempCpu'])}"
                 f"   ·   🌀 Ventola {'ON' if row.get('fan') else 'OFF'}")
    return "\n".join(lines)


def format_energy(row=None):
    """Production and consumption, mirroring the cards of the remote dashboard
    (server_remoto/index.php): produzione, scambio rete, prelievo, consumo casa.

    Same block shape as the temperature report: value on the headline, the
    24h context in italics underneath."""
    row = row if row is not None else fetch_latest()
    pv   = row.get("pv_power")   if row else None
    grid = row.get("grid_power") if row else None
    casa = row.get("casa_power") if row else None
    if casa is None and pv is not None and grid is not None:
        casa = pv + grid          # same definition the dashboard uses
    if pv is None and grid is None:
        return ("⚡ Energia\nNessun dato dal Shelly Pro EM-50 "
                "(meter fermo o mqtt_receiver.py non in esecuzione).")

    max_pv              = window_extremes("energia", "pv_power")[1]
    min_grid, max_grid  = window_extremes("energia", "grid_power")
    max_casa            = window_extremes("energia", "casa_power")[1]

    lines = report_header("⚡ Energia", row.get("timestamp"))

    lines.append(f"☀️ <b>Produzione FV</b>   {fmt_w(pv)}")
    lines.append(f"      <i>picco 24h {fmt_w(max_pv)}</i>")

    if grid is None:
        lines.append("🔌 <b>Scambio rete</b>   --")
    else:
        if grid < 0:
            flow = "↑ immissione"
        elif grid > 5:
            flow = "↓ prelievo"
        else:
            flow = "equilibrio"
        lines.append(f"🔌 <b>Scambio rete</b>   {fmt_w(abs(grid))}   ·   {flow}")
        lines.append(f"      <i>max 24h  ↓ {fmt_w(max(0.0, max_grid) if max_grid is not None else None)}"
                     f"   ·   ↑ {fmt_w(abs(min(0.0, min_grid)) if min_grid is not None else None)}</i>")

        draw = max(0.0, grid)
        lines.append(f"⬇️ <b>Prelievo rete</b>   {fmt_w(draw)}")
        detail = ("100% da fotovoltaico" if grid <= 5
                  else (f"{round(min(100, draw / casa * 100))}% del consumo"
                        if casa and casa > 0 else "dalla rete"))
        lines.append(f"      <i>{detail}</i>")

    if casa is None:
        lines.append("🏠 <b>Consumo casa</b>   --")
    else:
        lines.append(f"🏠 <b>Consumo casa</b>   {fmt_w(casa)}")
        share_pv = (f"{round(min(100, pv / casa * 100))}% da fotovoltaico   ·   "
                    if pv is not None and casa > 0 else "")
        lines.append(f"      <i>{share_pv}picco 24h {fmt_w(max_casa)}</i>")
    return "\n".join(lines)


# ── Battery report ──────────────────────────────────────────────────────────
def fetch_battery():
    """Latest `batteria` row as a dict, or None when the table is absent, empty
    or its newest row is older than BATTERY_STALE_S."""
    try:
        con = sqlite3.connect(DB_PATH, timeout=2)
        con.row_factory = sqlite3.Row
        row = con.execute("SELECT * FROM batteria ORDER BY id DESC LIMIT 1").fetchone()
        con.close()
    except Exception as e:
        log(f"[db] battery: {e}")
        return None
    if not row:
        return None
    row = dict(row)
    try:
        age = (datetime.utcnow()
               - datetime.strptime(row.get("timestamp"), "%Y-%m-%dT%H:%M:%SZ")).total_seconds()
    except (TypeError, ValueError):
        return None
    return row if age <= BATTERY_STALE_S else None


def battery_today_kwh():
    """(charged, discharged) kWh since local midnight.

    Integrated from the stored power samples because the Venus' lifetime
    counters are not reliably published: trapezoid over consecutive rows, gaps
    longer than 5 min skipped rather than bridged so a receiver that was down
    cannot invent energy. Mirrors integrate_kwh() in batteria.php."""
    local_midnight = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
    since = (local_midnight + (datetime.utcnow() - datetime.now())).strftime("%Y-%m-%dT%H:%M:%SZ")
    try:
        con = sqlite3.connect(DB_PATH, timeout=2)
        rows = con.execute(
            "SELECT timestamp, battery_power FROM batteria WHERE timestamp >= ? ORDER BY id ASC",
            (since,)).fetchall()
        con.close()
    except Exception as e:
        log(f"[report] battery daily: {e}")
        return (None, None)

    charge = discharge = 0.0
    prev_t = prev_p = None
    for ts, power in rows:
        try:
            t = datetime.strptime(ts, "%Y-%m-%dT%H:%M:%SZ")
            p = float(power)
        except (TypeError, ValueError):
            prev_t = prev_p = None
            continue
        if prev_t is not None:
            dt_s = (t - prev_t).total_seconds()
            if 0 < dt_s <= 300:
                wh = (p + prev_p) / 2.0 * dt_s / 3600.0
                if wh > 0:
                    charge += wh
                else:
                    discharge -= wh
        prev_t, prev_p = t, p
    return (charge / 1000.0, discharge / 1000.0)


def battery_state_label(power):
    """Same three states the dashboard badge shows, from the pack power."""
    if power is None:
        return "nessun dato"
    if power > BATTERY_IDLE_W:
        return "in carica"
    if power < -BATTERY_IDLE_W:
        return "in scarica"
    return "in attesa"


def format_battery(row=None):
    """Marstek Venus E: charge state first, then the pack detail (temperatures
    and cell balance) that says whether it is healthy.

    Same block shape as the other reports: the value on the headline, the
    context in italics underneath."""
    row = row if row is not None else fetch_battery()
    if not row:
        return ("🔋 Batteria\nNessun dato dalla Marstek Venus E "
                "(battery_bridge.py fermo o batteria non raggiungibile).")

    def num(key):
        v = row.get(key)
        try:
            f = float(v)
            return f if f == f else None   # reject NaN
        except (TypeError, ValueError):
            return None

    def unit(value, decimals, suffix):
        return f"{value:.{decimals}f}{suffix}" if value is not None else "--"

    soc   = num("soc")
    power = num("battery_power")
    lines = report_header("🔋 Batteria", row.get("timestamp"))

    residual = BATTERY_CAPACITY_KWH * soc / 100 if soc is not None else None
    lines.append(f"🔋 <b>Carica</b>   {unit(soc, 0, '%')}"
                 f"   ·   {unit(residual, 2, ' kWh')} di {BATTERY_CAPACITY_KWH:.2f} kWh")
    soc_rng = fmt_range(*window_extremes("batteria", "soc"), decimals=0, unit="%")
    if soc_rng:
        lines.append(f"      <i>24h  {soc_rng}</i>")

    lines.append(f"⚡ <b>Potenza pacco</b>   {fmt_w(abs(power) if power is not None else None)}"
                 f"   ·   {battery_state_label(power)}")
    charged, discharged = battery_today_kwh()
    if charged is not None:
        lines.append(f"      <i>oggi  ↑ {charged:.2f} kWh caricati"
                     f"   ·   ↓ {discharged:.2f} kWh erogati</i>")

    ac = num("ac_power")
    lines.append(f"🔌 <b>Uscita AC</b>   {fmt_w(abs(ac) if ac is not None else None)}")
    lines.append(f"      <i>{unit(num('ac_voltage'), 1, ' V')}"
                 f"   ·   {unit(num('ac_frequency'), 2, ' Hz')}</i>")

    lines.append(f"🔧 <b>Pacco</b>   {unit(num('battery_voltage'), 2, ' V')}"
                 f"   ·   {unit(num('battery_current'), 1, ' A')}")
    v_max, v_min = num("cell_voltage_max"), num("cell_voltage_min")
    v_spread = (v_max - v_min) * 1000 if (v_max is not None and v_min is not None) else None
    lines.append(f"      <i>celle {unit(v_min, 3, ' V')}–{unit(v_max, 3, ' V')}"
                 f"   ·   Δ {unit(v_spread, 0, ' mV')}</i>")

    t_max, t_min = num("cell_temp_max"), num("cell_temp_min")
    t_spread = t_max - t_min if (t_max is not None and t_min is not None) else None
    lines.append(f"🌡️ <b>Celle</b>   {unit(t_min, 1, '°C')}–{unit(t_max, 1, '°C')}"
                 f"   ·   Δ {unit(t_spread, 1, '°C')}")
    lines.append(f"      <i>interna {unit(num('temperature'), 1, '°C')}"
                 f"   ·   MOS {unit(num('temp_mos1'), 1, '°C')}"
                 f" / {unit(num('temp_mos2'), 1, '°C')}</i>")

    # The device's own strings, when the bridge publishes them: they say more
    # than the power sign alone (e.g. a work mode that explains an idle pack).
    device = "   ·   ".join(str(row[k]) for k in ("state", "mode") if row.get(k))
    if device:
        lines.append("")
        lines.append(f"<i>{device}</i>")
    return "\n".join(lines)


# Report chooser shown by a bare /status. The callback_data values are also
# accepted as text arguments (/status temp, /status energia, /status tutto).
STATUS_REPORTS = {
    "temp":   ("🌡️ Temperature", format_temperatures),
    "energy": ("⚡ Energia",      format_energy),
    "battery": ("🔋 Batteria",    format_battery),
    "all":    ("📋 Tutto",        lambda: format_status()),
}
STATUS_ALIASES = {
    "temp": "temp", "temperature": "temp", "temperatura": "temp", "t": "temp",
    "energy": "energy", "energia": "energy", "e": "energy",
    "battery": "battery", "batteria": "battery", "b": "battery",
    "all": "all", "tutto": "all", "completo": "all",
}


def status_menu():
    """The /status report chooser: one button per report, on its own row."""
    keyboard = [[{"text": label, "callback_data": f"st:{key}"}]
                for key, (label, _) in STATUS_REPORTS.items()]
    return Reply("Quale report vuoi?", markup={"inline_keyboard": keyboard})


def format_status_report(key):
    """The report as a Telegram reply. Reports are the only replies formatted
    with HTML — everything else stays plain text and needs no escaping."""
    entry = STATUS_REPORTS.get(key)
    return Reply(entry[1](), parse_mode="HTML") if entry else None


def format_forecast():
    row = fetch_latest()
    if not row:
        return "Nessun dato disponibile."
    now = datetime.now()
    target = now.replace(hour=6, minute=0, second=0, microsecond=0)
    if now >= target:
        target = target + timedelta(days=1)
    h_left = hours_until_next_6am(now)
    forecasts = compute_forecasts(row, now)

    lines = [
        "Previsione 6am (cielo sereno)",
        f"Target: {target.strftime('%Y-%m-%d %H:%M')} (fra {h_left:.1f}h)",
        "",
    ]
    for t_src, h_src, fkey in FORECAST_INPUTS:
        t_meta = METRICS[t_src]
        h_meta = METRICS[h_src]
        f_meta = FORECAST_METRICS[fkey]
        t_val = row.get(t_src)
        h_val = row.get(h_src)
        t_missing = is_missing(t_val, t_meta["sentinel"])
        h_missing = is_missing(h_val, h_meta["sentinel"])
        dp = dew_point(t_val, h_val) if not t_missing and not h_missing else None
        f_str  = fmt_value(forecasts[fkey], f_meta)
        t_str  = fmt_value(t_val, t_meta)
        h_str  = fmt_value(h_val, h_meta)
        dp_str = f"{dp:.1f}°C" if dp is not None else "n/a"
        lines.append(f"{t_meta['label']}: {f_str}  (now {t_str}, RH {h_str}, dp {dp_str})")
    return "\n".join(lines)


def format_alarms_summary():
    rules = load_rules()
    bots = load_bots()
    state = load_state()
    if not rules:
        return "Nessuna regola configurata. Aggiungile da alarms.php."

    lines = ["Regole di allarme:"]
    active = []
    today_str = datetime.now().strftime("%Y-%m-%d")
    for rule in rules:
        rid = str(rule["id"])
        msg = (rule.get("message") or "").strip()
        label = _with_icon(rule.get("icon"), msg if msg else f"regola #{rid}")
        descr = rule_describe(rule)
        bot = bots.get(rule.get("bot_id")) if rule.get("bot_id") else None
        if bot:
            descr += f" → bot {bot.get('name') or ('#' + str(rule.get('bot_id')))}"

        if not rule["enabled"]:
            lines.append(f"  • #{rid} \"{label}\" [{descr}] — disattiva")
            continue

        entry = state.get(rid) if isinstance(state.get(rid), dict) else {}
        if is_scheduled_rule(rule):
            last = entry.get("last_fired")
            if last == today_str:
                lines.append(f"  • #{rid} \"{label}\" [{descr}]  [INVIATO OGGI]")
                active.append(label)
            elif last:
                lines.append(f"  • #{rid} \"{label}\" [{descr}] (ultimo invio: {last})")
            else:
                lines.append(f"  • #{rid} \"{label}\" [{descr}]")
        else:
            if entry.get("fired"):
                fired_at = entry.get("fired_at", "")
                tail = f"  [FIRED {fired_at}]" if fired_at else "  [FIRED]"
                lines.append(f"  • #{rid} \"{label}\" [{descr}]{tail}")
                active.append(label)
            else:
                lines.append(f"  • #{rid} \"{label}\" [{descr}]")

    lines.append("")
    lines.append("Allarmi attivi: " + (", ".join(active) if active else "nessuno"))
    return "\n".join(lines)


HELP_TEXT = (
    "Stazione Meteo bot\n"
    "Comandi:\n"
    "/status — scegli il report (temperature / energia / batteria / tutto)\n"
    "/temperature — tutte le temperature e umidità\n"
    "/energia — produzione e consumi elettrici\n"
    "/batteria — carica, potenza e salute della batteria\n"
    "/forecast — previsione 6am (cielo sereno)\n"
    "/alarms — soglie configurate e allarmi attivi\n"
    "/reboot — riavvia il Raspberry\n"
    "/help — questo messaggio"
)


def do_reboot():
    """Reboot the Raspberry after a short delay.

    Launched detached with a small sleep so the confirmation message is safely
    delivered (and the getUpdates offset is already advanced) before the box
    goes down — otherwise the same /reboot could be replayed on next boot.
    Runs `sudo reboot`, so the watcher's user needs a NOPASSWD sudoers entry for
    it (e.g. `pi ALL=(ALL) NOPASSWD: /sbin/reboot`).
    """
    try:
        subprocess.Popen(
            ["sh", "-c", "sleep 3 && sudo reboot"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        return True
    except Exception as e:
        log(f"[cmd] reboot spawn error: {e}")
        return False


def handle_command(text):
    if not text:
        return None
    parts = text.strip().split()
    if not parts:
        return None
    cmd = parts[0].lower()
    # Strip @botname suffix Telegram adds in groups
    if "@" in cmd:
        cmd = cmd.split("@", 1)[0]

    if cmd in ("/start", "/help"):
        return HELP_TEXT
    if cmd == "/status":
        # Bare /status opens the chooser; an argument (or one of the shortcut
        # commands below) goes straight to that report.
        arg = parts[1].lower().lstrip("/") if len(parts) > 1 else ""
        if not arg:
            return status_menu()
        key = STATUS_ALIASES.get(arg)
        if not key:
            return ("Report sconosciuto. Usa /status temp, /status energia, "
                    "/status batteria o /status tutto.")
        return format_status_report(key)
    if cmd == "/temperature":
        return format_status_report("temp")
    if cmd == "/energia":
        return format_status_report("energy")
    if cmd == "/batteria":
        return format_status_report("battery")
    if cmd == "/forecast":
        return format_forecast()
    if cmd == "/alarms":
        return format_alarms_summary()
    if cmd == "/reboot":
        log("[cmd] /reboot requested")
        # Send the confirmation synchronously first: do_reboot() detaches with a
        # delay, so by the time the box goes down the reply is already delivered.
        send_message("Riavvio del Raspberry in corso… 🔄")
        if not do_reboot():
            return "Impossibile avviare il riavvio (vedi log del watcher)."
        return None  # confirmation already sent above
    return None  # silently ignore unknown commands


def send_reply(reply, chat_id):
    """Deliver whatever handle_command() returned: a plain string, or a Reply
    carrying a parse mode and/or the inline keyboard of the /status chooser."""
    if not reply:
        return
    if isinstance(reply, Reply):
        send_message(reply.text, chat_id=chat_id,
                     reply_markup=reply.markup, parse_mode=reply.parse_mode)
    else:
        send_message(reply, chat_id=chat_id)


# ── Telegram long polling ───────────────────────────────────────────────────
_offset = 0


def telegram_poll_once():
    """Fetch updates with long polling. Returns after up to TG_POLL_TIMEOUT s."""
    global _offset
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/getUpdates"
    params = {"timeout": TG_POLL_TIMEOUT, "offset": _offset}
    try:
        r = requests.get(url, params=params, timeout=TG_POLL_TIMEOUT + 10)
        r.raise_for_status()
        data = r.json()
    except requests.exceptions.RequestException as e:
        log(f"[poll] error: {e}")
        time.sleep(5)
        return
    except ValueError as e:
        log(f"[poll] decode error: {e}")
        time.sleep(5)
        return

    if not data.get("ok"):
        log(f"[poll] api not-ok: {data}")
        time.sleep(5)
        return

    for update in data.get("result", []):
        _offset = update["update_id"] + 1

        # Inline button on the /status chooser.
        cb = update.get("callback_query")
        if cb:
            cb_chat = (cb.get("message") or {}).get("chat", {}).get("id")
            answer_callback_query(cb.get("id"))
            if cb_chat != CHAT_ID:
                log(f"[poll] ignoring callback from chat_id={cb_chat}")
                continue
            data_str = cb.get("data") or ""
            key = data_str[3:] if data_str.startswith("st:") else ""
            try:
                reply = format_status_report(key)
            except Exception as e:
                log(f"[cmd] callback error: {e}")
                reply = "Errore interno durante l'esecuzione del comando."
            send_reply(reply, cb_chat)
            continue

        msg = update.get("message") or update.get("edited_message")
        if not msg:
            continue
        chat = msg.get("chat", {})
        chat_id = chat.get("id")
        if chat_id != CHAT_ID:
            log(f"[poll] ignoring message from chat_id={chat_id}")
            continue
        text = msg.get("text", "")
        try:
            reply = handle_command(text)
        except Exception as e:
            log(f"[cmd] error: {e}")
            reply = "Errore interno durante l'esecuzione del comando."
        send_reply(reply, chat_id)


# ── Main loop ───────────────────────────────────────────────────────────────
def drain_pending_updates():
    """Skip any messages queued while the watcher was offline."""
    global _offset
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/getUpdates"
    try:
        r = requests.get(url, params={"timeout": 0, "offset": -1}, timeout=10)
        r.raise_for_status()
        data = r.json()
        if data.get("ok") and data.get("result"):
            _offset = data["result"][-1]["update_id"] + 1
    except Exception as e:
        log(f"[poll] drain error: {e}")


def main():
    log("Alarm watcher starting…")
    set_bot_commands()
    drain_pending_updates()
    # Announce startup. The watcher is launched at boot (systemd/rc), so this
    # doubles as a "Raspberry acceso" notification. Sent after draining pending
    # updates so it isn't lost among queued messages.
    send_message("🔌 Raspberry avviato — stazione meteo online.")
    last_alarm_check = 0.0
    while True:
        telegram_poll_once()
        now = time.time()
        if now - last_alarm_check >= ALARM_CHECK_INTERVAL:
            try:
                check_alarms()
            except Exception as e:
                log(f"[alarm] error: {e}")
            last_alarm_check = now


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log("Stopped.")
        sys.exit(0)
