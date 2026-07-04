#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Stazione Meteo — office chronothermostat watcher (with Telegram learning).

Decides whether the office heater should be ON or OFF and publishes the command
to the board over MQTT (casa/ufficio/command). The board does NOT regulate
temperature itself — all the thermostat logic lives here.

Three layers, in priority order:
  1. Override  — a command sent via Telegram (ON→setpoint, OFF, or a bare
                 temperature) with a chosen duration. Beats everything until it
                 expires, then the schedule resumes automatically.
  2. Schedule  — weekly target-temperature bands from crono.db. Manual bands are
                 authored in cronotermostato.php; "learned" bands are generated
                 here from the history of commands (see the learning engine).
                 When bands overlap, the LAST one wins; learned bands are ordered
                 after manual ones, so learning adapts the manual baseline.
  3. Default   — the antifreeze target, used outside any band.

Telegram: the watcher long-polls ONE configured bot (chosen in the web page,
stored as settings.bot_id, looked up in alarms.db's bots table). It must be a
DIFFERENT bot from the one alarm_watcher.py polls, otherwise the two pollers
steal each other's updates.

Failsafe: if the office reading is missing, stale, or the board reports a
malfunction, the heater is commanded OFF (an OFF override still works).
"""

import json
import math
import os
import re
import sqlite3
import sys
import time
from datetime import datetime, time as dt_time, timedelta

import requests
import paho.mqtt.client as mqtt

# ── Paths ───────────────────────────────────────────────────────────────────
CRONO_DB_PATH = "/var/www/html/crono.db"     # schedule + override + learning (read-write)
ALARMS_DB_PATH = "/var/www/html/alarms.db"   # bots table (read-only)
METEO_DB_PATH = "/dev/shm/meteo.db"          # office telemetry (read-only)
STATE_PATH = "/dev/shm/crono_state.json"     # live status for the web page

# ── MQTT ────────────────────────────────────────────────────────────────────
MQTT_HOST = "stazionemeteo.local"
MQTT_PORT = 1883
MQTT_USER = "stzionemeteo"
MQTT_PASSWORD = "78f25d_78"
MQTT_TOPIC_CMD = "casa/ufficio/command"

# ── Tuning ──────────────────────────────────────────────────────────────────
CHECK_INTERVAL = 30        # seconds between schedule evaluations when idle
REPUBLISH_INTERVAL = 300   # re-send the command at least this often
STALE_SECONDS = 600        # office reading older than this is treated as missing
TG_POLL_TIMEOUT = 20       # Telegram long-poll timeout (also caps the idle cycle)

DEFAULT_HYSTERESIS = 0.3
DEFAULT_TARGET = 7.0       # antifreeze fallback when no band matches
MORNING_HOUR = 6           # "fino a domani" resumes the schedule at this hour

# ── Learning ────────────────────────────────────────────────────────────────
SLOT_MIN = 15              # learning resolution: 96 slots/day
N_SLOTS = (24 * 60) // SLOT_MIN
EMA_ALPHA = 0.40           # weight of each new command sample
PERM_ALPHA = 0.85          # stronger weight for "permanente" commands
WEIGHT_CAP = 12.0
MIN_WEIGHT = 1.0           # a slot needs this much confidence to become a band
OFF_THRESHOLD = 0.5        # off_score above this ⇒ the slot is an "off" preference
TARGET_MERGE_TOL = 0.25    # merge adjacent slots whose targets are this close
PERM_WINDOW_MIN = 120      # time a "permanente" command covers for learning


def log(msg):
    print(msg, flush=True)


def now_local():
    return datetime.now()


def now_utc():
    return datetime.utcnow()


def minute_of_day(dt):
    return dt.hour * 60 + dt.minute


# ── Schema (crono.db is read-write; both this watcher and the PHP page use it) ─
def ensure_crono_schema():
    try:
        con = sqlite3.connect(CRONO_DB_PATH, timeout=5)
        con.execute("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)")
        con.execute("""CREATE TABLE IF NOT EXISTS bands (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            enabled   INTEGER NOT NULL DEFAULT 1,
            days_mask INTEGER NOT NULL DEFAULT 127,
            time_from TEXT    NOT NULL,
            time_to   TEXT    NOT NULL,
            target    REAL    NOT NULL,
            origin    TEXT    NOT NULL DEFAULT 'manual'
        )""")
        # Override: a single active row (id=1) set by a Telegram command.
        con.execute("""CREATE TABLE IF NOT EXISTS override (
            id         INTEGER PRIMARY KEY CHECK (id = 1),
            type       TEXT,            -- 'set' | 'off'
            target     REAL,
            expires_at TEXT,            -- UTC ISO 'YYYY-MM-DDTHH:MM:SSZ'
            created_at TEXT
        )""")
        # Command history (feeds the learning engine; shown in the web page).
        con.execute("""CREATE TABLE IF NOT EXISTS commands (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            ts        TEXT,             -- local 'YYYY-MM-DD HH:MM:SS'
            dow       INTEGER,          -- 0=Mon … 6=Sun
            start_min INTEGER,
            end_min   INTEGER,
            type      TEXT,             -- 'set' | 'off'
            target    REAL,
            permanent INTEGER NOT NULL DEFAULT 0
        )""")
        # Per-weekday/per-slot EMA model the learned bands are rebuilt from.
        con.execute("""CREATE TABLE IF NOT EXISTS slots (
            dow       INTEGER NOT NULL,
            slot      INTEGER NOT NULL,
            target    REAL,
            off_score REAL NOT NULL DEFAULT 0,
            weight    REAL NOT NULL DEFAULT 0,
            PRIMARY KEY (dow, slot)
        )""")
        # Migrate a pre-existing bands table that lacks the origin column.
        cols = [r[1] for r in con.execute("PRAGMA table_info(bands)")]
        if "origin" not in cols:
            con.execute("ALTER TABLE bands ADD COLUMN origin TEXT NOT NULL DEFAULT 'manual'")
        con.commit()
        con.close()
    except Exception as e:
        log(f"[schema] error: {e}")


def crono_conn():
    con = sqlite3.connect(CRONO_DB_PATH, timeout=5)
    con.row_factory = sqlite3.Row
    return con


# ── Settings / schedule ─────────────────────────────────────────────────────
def load_settings(con):
    settings = {
        "mode": "auto", "hysteresis": DEFAULT_HYSTERESIS,
        "default_target": DEFAULT_TARGET, "bot_id": None, "learning": "on",
    }
    try:
        for r in con.execute("SELECT key, value FROM settings"):
            settings[r["key"]] = r["value"]
    except sqlite3.OperationalError:
        pass
    try:
        settings["hysteresis"] = max(0.0, float(settings.get("hysteresis", DEFAULT_HYSTERESIS)))
    except (TypeError, ValueError):
        settings["hysteresis"] = DEFAULT_HYSTERESIS
    try:
        settings["default_target"] = float(settings.get("default_target", DEFAULT_TARGET))
    except (TypeError, ValueError):
        settings["default_target"] = DEFAULT_TARGET
    settings["mode"] = "off" if settings.get("mode") == "off" else "auto"
    settings["learning_on"] = str(settings.get("learning", "on")).lower() not in ("off", "0", "false")
    try:
        settings["bot_id"] = int(settings.get("bot_id")) if settings.get("bot_id") not in (None, "", "0") else None
    except (TypeError, ValueError):
        settings["bot_id"] = None
    return settings


def load_bands(con):
    """Manual bands first, learned bands last, so learning overrides the baseline."""
    try:
        return [dict(r) for r in con.execute(
            "SELECT id, enabled, days_mask, time_from, time_to, target, origin FROM bands "
            "ORDER BY CASE WHEN origin = 'learned' THEN 1 ELSE 0 END, time_from, id"
        )]
    except sqlite3.OperationalError:
        return []


def fetch_latest_ufficio():
    try:
        con = sqlite3.connect(METEO_DB_PATH, timeout=2)
        con.row_factory = sqlite3.Row
        row = con.execute("SELECT * FROM ufficio ORDER BY id DESC LIMIT 1").fetchone()
        con.close()
        return dict(row) if row else None
    except Exception as e:
        log(f"[db] error: {e}")
        return None


def save_state(state):
    try:
        tmp = STATE_PATH + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(state, f)
        os.replace(tmp, STATE_PATH)
    except Exception as e:
        log(f"[state] save error: {e}")


# ── Schedule evaluation ─────────────────────────────────────────────────────
def parse_hm(s):
    if not s:
        return None
    try:
        h, m = str(s).split(":")
        return dt_time(int(h), int(m))
    except Exception:
        return None


def in_time_window(now_t, t_from, t_to):
    if t_from <= t_to:
        return t_from <= now_t <= t_to
    return now_t >= t_from or now_t <= t_to


def active_target(bands, now):
    """(target, band) for the current moment, or (None, None). Last match wins."""
    now_t = now.time()
    today_bit = 1 << now.weekday()
    chosen = None
    for b in bands:
        if not b.get("enabled"):
            continue
        mask = b.get("days_mask")
        mask = 127 if mask is None else mask
        if not (mask & today_bit):
            continue
        tf = parse_hm(b.get("time_from"))
        tt = parse_hm(b.get("time_to"))
        if tf is None or tt is None:
            continue
        if in_time_window(now_t, tf, tt):
            chosen = b
    if chosen is None:
        return None, None
    try:
        return float(chosen["target"]), chosen
    except (TypeError, ValueError, KeyError):
        return None, None


def reading_is_fresh(row):
    if not row:
        return False
    ts = row.get("timestamp")
    if not ts:
        return False
    try:
        when = datetime.strptime(ts, "%Y-%m-%dT%H:%M:%SZ")
    except ValueError:
        return False
    return (now_utc() - when).total_seconds() <= STALE_SECONDS


def to_float(v):
    try:
        f = float(v)
        return f if f == f else None
    except (TypeError, ValueError):
        return None


def parse_iso_utc(s):
    try:
        return datetime.strptime(s, "%Y-%m-%dT%H:%M:%SZ")
    except (TypeError, ValueError):
        return None


def get_active_override(con):
    """Return the override dict if present and not expired, else None.
    Expired overrides are deleted so the schedule resumes cleanly."""
    try:
        row = con.execute(
            "SELECT type, target, expires_at FROM override WHERE id = 1"
        ).fetchone()
    except sqlite3.OperationalError:
        return None
    if not row:
        return None
    exp = parse_iso_utc(row["expires_at"])
    if exp is None or now_utc() >= exp:
        con.execute("DELETE FROM override WHERE id = 1")
        con.commit()
        return None
    return {"type": row["type"], "target": to_float(row["target"]), "expires_at": row["expires_at"]}


def regulate(temp, target, hyst, prev_heater):
    if temp < target - hyst:
        return "ON"
    if temp > target + hyst:
        return "OFF"
    return prev_heater if prev_heater in ("ON", "OFF") else "OFF"


def decide(settings, bands, override, row, prev_heater):
    """Priority: override > mode-off > schedule > default. Returns a decision dict."""
    temp = to_float(row.get("temp")) if row else None
    hyst = settings["hysteresis"]
    fresh = reading_is_fresh(row)
    malfunction = bool(row.get("malfunction")) if row else False

    def expiry_label():
        exp = parse_iso_utc(override["expires_at"]) if override else None
        if not exp:
            return ""
        local = exp + (now_local() - now_utc())
        return local.strftime("%H:%M")

    # 1. Override (a Telegram command) wins over everything.
    if override:
        if override["type"] == "off":
            return {"heater": "OFF", "target": None, "temp": temp, "source": "override",
                    "note": f"Comando OFF attivo (fino alle {expiry_label()})."}
        # 'set' override: regulate to its target, but still needs a valid reading.
        otarget = override["target"]
        if otarget is not None:
            if not fresh or temp is None or malfunction:
                return {"heater": "OFF", "target": otarget, "temp": temp, "source": "override",
                        "note": "Comando attivo ma dati ufficio assenti/obsoleti: OFF (failsafe)."}
            heater = regulate(temp, otarget, hyst, prev_heater)
            return {"heater": heater, "target": otarget, "temp": temp, "source": "override",
                    "note": f"Comando {otarget:.1f}°C ±{hyst:.1f} (fino alle {expiry_label()}) → {heater}."}

    # 2. Global "off" mode.
    if settings["mode"] == "off":
        return {"heater": "OFF", "target": None, "temp": temp, "source": "mode_off",
                "note": "Modalità spento: caldaia forzata OFF."}

    # 3/4. Failsafe before regulating from the schedule.
    if not fresh or temp is None:
        return {"heater": "OFF", "target": None, "temp": temp, "source": "failsafe",
                "note": "Dati ufficio assenti o obsoleti: caldaia OFF (failsafe)."}
    if malfunction:
        return {"heater": "OFF", "target": None, "temp": temp, "source": "failsafe",
                "note": "Sensore scheda in malfunzionamento: caldaia OFF (failsafe)."}

    target, band = active_target(bands, now_local())
    if target is None:
        target = settings["default_target"]
        source = "default"
        label = "antigelo (fuori fascia)"
    else:
        source = "learned" if band.get("origin") == "learned" else "band"
        label = f"fascia {band.get('origin', 'manual')} #{band.get('id')}"

    heater = regulate(temp, target, hyst, prev_heater)
    note = f"{temp:.1f}°C vs target {target:.1f}°C ±{hyst:.1f} ({label}) → {heater}."
    return {"heater": heater, "target": target, "temp": temp, "source": source, "note": note}


# ── MQTT ────────────────────────────────────────────────────────────────────
def make_client():
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    client.username_pw_set(MQTT_USER, MQTT_PASSWORD)
    client.on_connect = lambda c, u, f, rc, p: log(
        f"[mqtt] connected (rc={rc})" if rc == 0 else f"[mqtt] connect failed (rc={rc})")
    client.on_disconnect = lambda c, u, f, rc, p: log(f"[mqtt] disconnected (rc={rc})")
    return client


def publish_command(client, heater, target):
    payload = {"heater": heater}
    if target is not None:
        payload["setpoint"] = round(target, 1)
    try:
        info = client.publish(MQTT_TOPIC_CMD, json.dumps(payload), qos=1)
        info.wait_for_publish(timeout=5)
        return info.rc == mqtt.MQTT_ERR_SUCCESS
    except Exception as e:
        log(f"[mqtt] publish error: {e}")
        return False


# ── Telegram bot ────────────────────────────────────────────────────────────
def get_active_bot(con):
    """Resolve settings.bot_id → {'token','chat_id'} from alarms.db, or None."""
    settings = load_settings(con)
    bot_id = settings.get("bot_id")
    if not bot_id:
        return None
    if not os.path.exists(ALARMS_DB_PATH):
        return None
    try:
        uri = f"file:{ALARMS_DB_PATH}?mode=ro"
        acon = sqlite3.connect(uri, uri=True, timeout=2)
        acon.row_factory = sqlite3.Row
        row = acon.execute("SELECT name, token, chat_id FROM bots WHERE id = ?", (bot_id,)).fetchone()
        acon.close()
        if row and row["token"] and row["chat_id"]:
            return {"name": row["name"], "token": row["token"], "chat_id": str(row["chat_id"])}
    except Exception as e:
        log(f"[bot] lookup error: {e}")
    return None


def tg(token, method, **params):
    url = f"https://api.telegram.org/bot{token}/{method}"
    try:
        r = requests.post(url, json=params, timeout=TG_POLL_TIMEOUT + 10)
        return r.json()
    except Exception as e:
        log(f"[tg] {method} error: {e}")
        return None


def kb(rows):
    """Build an inline keyboard from rows of (text, callback_data) tuples."""
    return {"inline_keyboard": [[{"text": t, "callback_data": d} for (t, d) in row] for row in rows]}


def duration_keyboard(cmd_type, target):
    tgt = "-" if target is None else _fmt_num(target)
    return kb([
        [("1h", f"d|{cmd_type}|{tgt}|60"), ("2h", f"d|{cmd_type}|{tgt}|120"), ("3h", f"d|{cmd_type}|{tgt}|180")],
        [("Fino a domani", f"d|{cmd_type}|{tgt}|morning"), ("Permanente", f"d|{cmd_type}|{tgt}|perm")],
        [("Annulla", "x")],
    ])


TEMP_CHOICES = [10, 15, 17, 18, 19, 20, 21, 22]


def temp_keyboard():
    btns = [(f"{t}°", f"t|{t}") for t in TEMP_CHOICES]
    # Lay out in rows of 4 so the keyboard stays tidy as choices grow.
    rows = [btns[i:i + 4] for i in range(0, len(btns), 4)]
    rows.append([("Annulla", "x")])
    return kb(rows)


def _fmt_num(v):
    f = float(v)
    return str(int(f)) if f == int(f) else f"{f:g}"


HELP_TEXT = (
    "Cronotermostato Ufficio 🔥\n"
    "Comandi:\n"
    "• ON / accendi — scegli temperatura e durata\n"
    "• OFF / spegni — spegni per una durata\n"
    "• <numero> (es. 21) — imposta quel target\n"
    "• /stato — stato attuale\n"
    "I comandi hanno priorità sul programma; alla scadenza si torna al programma. "
    "Le tue scelte vengono apprese e diventano fasce automatiche."
)

_NUM_RE = re.compile(r"(-?\d{1,2}(?:[.,]\d)?)")


def parse_command_text(text):
    """Return ('on'|'off'|'set'|'help'|'status'|None, value)."""
    t = (text or "").strip().lower()
    if not t:
        return None, None
    if t in ("/start", "/help", "help", "aiuto"):
        return "help", None
    if t in ("/stato", "/status", "stato", "status"):
        return "status", None
    if t in ("on", "accendi", "/on", "acceso"):
        return "on", None
    if t in ("off", "spegni", "/off", "spento"):
        return "off", None
    m = _NUM_RE.search(t)
    if m:
        val = to_float(m.group(1).replace(",", "."))
        if val is not None and 5.0 <= val <= 30.0:
            return "set", val
    return None, None


def minutes_until_morning():
    now = now_local()
    target = now.replace(hour=MORNING_HOUR, minute=0, second=0, microsecond=0)
    if now >= target:
        target += timedelta(days=1)
    return max(1, int((target - now).total_seconds() // 60))


# ── Override + command application ──────────────────────────────────────────
def set_override(con, cmd_type, target, minutes):
    expires = now_utc() + timedelta(minutes=minutes)
    con.execute("DELETE FROM override WHERE id = 1")
    con.execute(
        "INSERT INTO override (id, type, target, expires_at, created_at) VALUES (1, ?, ?, ?, ?)",
        (cmd_type, target, expires.strftime("%Y-%m-%dT%H:%M:%SZ"),
         now_utc().strftime("%Y-%m-%dT%H:%M:%SZ")),
    )
    con.commit()


def log_command(con, cmd_type, target, minutes, permanent):
    now = now_local()
    start = minute_of_day(now)
    end = min(24 * 60, start + max(SLOT_MIN, minutes))
    con.execute(
        "INSERT INTO commands (ts, dow, start_min, end_min, type, target, permanent) "
        "VALUES (?, ?, ?, ?, ?, ?, ?)",
        (now.strftime("%Y-%m-%d %H:%M:%S"), now.weekday(), start, end, cmd_type,
         target, 1 if permanent else 0),
    )
    con.commit()


def apply_command(con, settings, cmd_type, target, dur, client, ctl):
    """Apply a confirmed command. dur is 60/120/180/'morning'/'perm'.
    Returns a human-readable confirmation string."""
    permanent = (dur == "perm")
    if dur == "morning":
        minutes = minutes_until_morning()
    elif permanent:
        minutes = PERM_WINDOW_MIN
    else:
        minutes = int(dur)

    if not permanent:
        set_override(con, cmd_type, target, minutes)

    # Learning records every command (timed or permanent).
    if settings.get("learning_on", True):
        log_command(con, cmd_type, target, minutes, permanent)
        learn_from_command(con, cmd_type, target, minutes, permanent)
        relearn_bands(con, settings)

    # Make the change audible on the board immediately.
    run_control(con, settings, client, ctl, force_publish=True)

    if cmd_type == "off":
        what = "Spengo"
    else:
        what = f"Imposto {_fmt_num(target)}°C"
    if permanent:
        return f"✅ {what} e lo memorizzo nel programma (fascia appresa)."
    if dur == "morning":
        return f"✅ {what} fino a domani ({MORNING_HOUR:02d}:00)."
    hrs = minutes / 60
    return f"✅ {what} per {('%g' % hrs)}h (fino alle " \
           f"{(now_local() + timedelta(minutes=minutes)).strftime('%H:%M')})."


# ── Learning engine ─────────────────────────────────────────────────────────
def _covered_slots(start_min, end_min):
    s0 = max(0, start_min // SLOT_MIN)
    s1 = min(N_SLOTS - 1, (max(start_min + 1, end_min) - 1) // SLOT_MIN)
    return range(s0, s1 + 1)


def learn_from_command(con, cmd_type, target, minutes, permanent):
    """Update the per-slot EMA model for the command's weekday/time span."""
    now = now_local()
    dow = now.weekday()
    start = minute_of_day(now)
    end = min(24 * 60, start + max(SLOT_MIN, minutes))
    alpha = PERM_ALPHA if permanent else EMA_ALPHA
    off_sample = 1.0 if cmd_type == "off" else 0.0
    # For an OFF command we don't have a temperature; keep the existing target EMA.
    tgt_sample = to_float(target)

    for slot in _covered_slots(start, end):
        row = con.execute(
            "SELECT target, off_score, weight FROM slots WHERE dow = ? AND slot = ?",
            (dow, slot),
        ).fetchone()
        if row is None:
            new_target = tgt_sample
            new_off = off_sample
            new_weight = 1.0
        else:
            old_t = to_float(row["target"])
            new_off = alpha * off_sample + (1 - alpha) * float(row["off_score"])
            if tgt_sample is not None:
                new_target = tgt_sample if old_t is None else alpha * tgt_sample + (1 - alpha) * old_t
            else:
                new_target = old_t
            new_weight = min(WEIGHT_CAP, float(row["weight"]) + 1.0)
        con.execute(
            "INSERT INTO slots (dow, slot, target, off_score, weight) VALUES (?, ?, ?, ?, ?) "
            "ON CONFLICT(dow, slot) DO UPDATE SET target = excluded.target, "
            "off_score = excluded.off_score, weight = excluded.weight",
            (dow, slot, new_target, new_off, new_weight),
        )
    con.commit()


def _slot_to_hhmm(slot):
    m = slot * SLOT_MIN
    return f"{m // 60:02d}:{m % 60:02d}"


def _slot_end_to_hhmm(slot):
    m = (slot + 1) * SLOT_MIN
    if m >= 24 * 60:
        return "23:59"
    return f"{m // 60:02d}:{m % 60:02d}"


def relearn_bands(con, settings):
    """Rebuild all origin='learned' bands from the per-slot EMA model."""
    default_target = settings["default_target"]
    con.execute("DELETE FROM bands WHERE origin = 'learned'")

    rows = con.execute(
        "SELECT dow, slot, target, off_score, weight FROM slots WHERE weight >= ? ORDER BY dow, slot",
        (MIN_WEIGHT,),
    ).fetchall()

    # Build a per-(dow,slot) decision: a target value, or None for an off slot.
    decided = {}
    for r in rows:
        if float(r["off_score"]) >= OFF_THRESHOLD:
            decided[(r["dow"], r["slot"])] = ("off", default_target)
        else:
            t = to_float(r["target"])
            if t is None:
                continue
            decided[(r["dow"], r["slot"])] = ("set", round(t * 2) / 2)

    ins = ("INSERT INTO bands (enabled, days_mask, time_from, time_to, target, origin) "
           "VALUES (1, ?, ?, ?, ?, 'learned')")
    for dow in range(7):
        run_start = None
        run_kind = None
        run_target = None
        prev_slot = None
        for slot in range(N_SLOTS):
            cell = decided.get((dow, slot))
            same = (cell is not None and run_kind is not None
                    and cell[0] == run_kind
                    and abs(cell[1] - run_target) <= TARGET_MERGE_TOL
                    and prev_slot == slot - 1)
            if same:
                prev_slot = slot
                continue
            # Close the previous run.
            if run_start is not None:
                con.execute(ins, (1 << dow, _slot_to_hhmm(run_start),
                                  _slot_end_to_hhmm(prev_slot), run_target))
            if cell is None:
                run_start = run_kind = run_target = prev_slot = None
            else:
                run_start = slot
                run_kind, run_target = cell
                prev_slot = slot
        if run_start is not None:
            con.execute(ins, (1 << dow, _slot_to_hhmm(run_start),
                              _slot_end_to_hhmm(prev_slot), run_target))
    con.commit()


# ── Telegram polling / dialog ───────────────────────────────────────────────
def drain_pending(token):
    """Skip messages queued before we (re)started, return the next offset."""
    data = tg(token, "getUpdates", timeout=0, offset=-1)
    if data and data.get("ok") and data.get("result"):
        return data["result"][-1]["update_id"] + 1
    return 0


def telegram_poll_once(con, settings, bot, offset, client, ctl):
    data = tg(bot["token"], "getUpdates", timeout=TG_POLL_TIMEOUT, offset=offset)
    if not data or not data.get("ok"):
        time.sleep(3)
        return offset
    for upd in data.get("result", []):
        offset = upd["update_id"] + 1
        try:
            handle_update(con, settings, bot, upd, client, ctl)
        except Exception as e:
            log(f"[tg] handle error: {e}")
    return offset


def handle_update(con, settings, bot, upd, client, ctl):
    token = bot["token"]
    chat_ok = str(bot["chat_id"])

    # Inline-keyboard taps.
    cq = upd.get("callback_query")
    if cq:
        chat = str((cq.get("message") or {}).get("chat", {}).get("id"))
        if chat != chat_ok:
            tg(token, "answerCallbackQuery", callback_query_id=cq["id"])
            return
        handle_callback(con, settings, bot, cq, client, ctl)
        return

    msg = upd.get("message")
    if not msg:
        return
    if str(msg.get("chat", {}).get("id")) != chat_ok:
        return
    kind, value = parse_command_text(msg.get("text", ""))
    if kind == "help":
        tg(token, "sendMessage", chat_id=chat_ok, text=HELP_TEXT)
    elif kind == "status":
        tg(token, "sendMessage", chat_id=chat_ok, text=status_text(con, settings))
    elif kind == "on":
        tg(token, "sendMessage", chat_id=chat_ok,
           text="A che temperatura accendo?", reply_markup=temp_keyboard())
    elif kind == "off":
        tg(token, "sendMessage", chat_id=chat_ok,
           text="Spengo. Per quanto tempo?", reply_markup=duration_keyboard("off", None))
    elif kind == "set":
        tg(token, "sendMessage", chat_id=chat_ok,
           text=f"Imposto {_fmt_num(value)}°C. Per quanto tempo?",
           reply_markup=duration_keyboard("set", value))
    else:
        tg(token, "sendMessage", chat_id=chat_ok,
           text="Non ho capito. " + HELP_TEXT)


def handle_callback(con, settings, bot, cq, client, ctl):
    token = bot["token"]
    chat = str(cq["message"]["chat"]["id"])
    mid = cq["message"]["message_id"]
    data = cq.get("data", "")
    tg(token, "answerCallbackQuery", callback_query_id=cq["id"])

    if data == "x":
        tg(token, "editMessageText", chat_id=chat, message_id=mid, text="Annullato.")
        return

    parts = data.split("|")
    if parts[0] == "t" and len(parts) == 2:
        target = to_float(parts[1])
        if target is None:
            return
        tg(token, "editMessageText", chat_id=chat, message_id=mid,
           text=f"Accendo a {_fmt_num(target)}°C. Per quanto tempo?",
           reply_markup=duration_keyboard("set", target))
        return

    if parts[0] == "d" and len(parts) == 4:
        cmd_type = parts[1] if parts[1] in ("set", "off") else "set"
        target = None if parts[2] in ("-", "") else to_float(parts[2])
        dur = parts[3]
        # Reload settings so a just-changed bot/learning toggle is honoured.
        settings = load_settings(con)
        try:
            confirmation = apply_command(con, settings, cmd_type, target, dur, client, ctl)
        except Exception as e:
            log(f"[tg] apply error: {e}")
            confirmation = "Errore nell'applicare il comando."
        tg(token, "editMessageText", chat_id=chat, message_id=mid, text=confirmation)
        return


def status_text(con, settings):
    try:
        with open(STATE_PATH, encoding="utf-8") as f:
            st = json.load(f)
    except Exception:
        st = {}
    lines = ["Cronotermostato — stato"]
    temp = st.get("temp")
    lines.append(f"Temp ufficio: {temp:.1f}°C" if isinstance(temp, (int, float)) else "Temp ufficio: n/d")
    tgt = st.get("target")
    lines.append(f"Target: {tgt:.1f}°C" if isinstance(tgt, (int, float)) else "Target: —")
    lines.append(f"Caldaia: {st.get('heater', '—')}")
    if st.get("note"):
        lines.append(st["note"])
    return "\n".join(lines)


# ── Control cycle ───────────────────────────────────────────────────────────
def run_control(con, settings, client, ctl, force_publish=False):
    override = get_active_override(con)
    bands = load_bands(con)
    row = fetch_latest_ufficio()
    decision = decide(settings, bands, override, row, ctl.get("prev_heater"))

    now = time.time()
    changed = (decision["heater"] != ctl.get("prev_heater")) or (decision["target"] != ctl.get("prev_target"))
    due = (now - ctl.get("last_publish", 0.0)) >= REPUBLISH_INTERVAL

    if changed or due or force_publish:
        if publish_command(client, decision["heater"], decision["target"]):
            ctl["last_publish"] = now
            if changed or force_publish:
                log(f"[crono] {decision['note']}")
            ctl["prev_heater"] = decision["heater"]
            ctl["prev_target"] = decision["target"]

    save_state({
        "mode": settings["mode"],
        "learning": "on" if settings.get("learning_on", True) else "off",
        "heater": decision["heater"],
        "target": decision["target"],
        "temp": decision["temp"],
        "source": decision["source"],
        "override": override,
        "note": decision["note"],
        "updated": now_local().strftime("%Y-%m-%d %H:%M:%S"),
    })


# ── Main loop ───────────────────────────────────────────────────────────────
def main():
    log("Crono watcher starting…")
    ensure_crono_schema()

    client = make_client()
    try:
        client.connect(MQTT_HOST, MQTT_PORT, keepalive=60)
    except Exception as e:
        log(f"[mqtt] initial connect error: {e}")
    client.loop_start()

    ctl = {"prev_heater": None, "prev_target": None, "last_publish": 0.0}
    tg_token = None
    tg_offset = 0

    while True:
        try:
            con = crono_conn()
            settings = load_settings(con)
            bot = get_active_bot(con)

            if bot and bot["token"]:
                if bot["token"] != tg_token:
                    tg_token = bot["token"]
                    tg_offset = drain_pending(tg_token)
                    log(f"[tg] polling bot '{bot.get('name')}'")
                tg_offset = telegram_poll_once(con, settings, bot, tg_offset, client, ctl)
            else:
                tg_token = None
                time.sleep(CHECK_INTERVAL)

            # Re-read settings (a command/dialog may have changed things) and decide.
            settings = load_settings(con)
            run_control(con, settings, client, ctl)
            con.close()
        except Exception as e:
            log(f"[loop] error: {e}")
            time.sleep(5)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log("Stopped.")
        sys.exit(0)
