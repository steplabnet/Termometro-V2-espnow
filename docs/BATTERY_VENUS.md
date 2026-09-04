# Battery — Marstek Venus E 3.0

Local dashboard for the home battery, built on the same chain as the Shelly
energy meter: MQTT → `mqtt_receiver.py` → SQLite `/dev/shm/meteo.db` → PHP page
on the Pi.

Status: **live since 2026-09-03**, on Modbus TCP since **2026-09-04.** A
VenusE 3.0 cabled to the LAN at `192.168.1.153`, read by `battery_bridge.py`
(`meteo-battery.service` on the Pi) and stored one row a minute.

---

## Source: MQTT

`mqtt_receiver.py` subscribes to two shapes, so whichever bridge ends up
publishing the Venus works without another code change:

| Topic | Payload | Use |
|-------|---------|-----|
| `casa/batteria/data` | one JSON object with all fields | a Modbus→MQTT poller or an ESP publishing the whole reading |
| `marstek/venus/+` | one scalar per leaf topic (`marstek/venus/soc` → `71`) | Home-Assistant-style / `marstek2mqtt` bridges |

Both go through the same alias table, so field **names** are matched, not
positions. Unknown keys are ignored.

| Column | Accepted names |
|--------|----------------|
| `soc` | `soc`, `bat_soc`, `battery_soc`, `state_of_charge`, `battery_state_of_charge`, `capacity`, `soc_percent` |
| `battery_power` | `battery_power`, `bat_power`, `batpower`, `battery_charge_power`, `charge_power`, `p_battery`, `power` |
| `ac_power` | `ac_power`, `p_ac`, `inverter_power`, `output_power`, `ongrid_power`, `grid_power`, `ac_output_power` |
| `battery_voltage` | `battery_voltage`, `bat_voltage`, `vbat`, `voltage` |
| `battery_current` | `battery_current`, `bat_current`, `ibat`, `current` |
| `temperature` | `temperature`, `temp`, `bat_temp`, `battery_temperature`, `internal_temp`, `internal_temperature` |
| `cell_temp_max` / `cell_temp_min` | `max_cell_temperature` / `min_cell_temperature` and the obvious variants |
| `charge_total` / `discharge_total` | `total_charging_energy` / `total_discharging_energy` and the obvious variants |
| `mode` | `mode`, `work_mode`, `workmode`, `working_mode` |
| `state` | `state`, `status`, `device_state`, `work_state`, `battery_state` |

A value may also arrive wrapped (`{"value": 71, "unit": "%"}`); the wrapper is
unpacked. Anything non-numeric in a numeric column is dropped rather than
stored as 0.

### Sign convention

Everything downstream assumes:

```
battery_power > 0  →  charging
battery_power < 0  →  discharging
```

If the device publishes it the other way round, flip
`BATTERY_POWER_SIGN = -1` in `mqtt_receiver.py` — that is the only place the
convention is set, the table and both dashboards follow it.

Under ±15 W (`IDLE_W` in `batteria.php`) the pack is only feeding its own
electronics, so the page reads "in attesa" instead of flickering between the
two directions.

---

## The bridge — `battery_bridge.py`

The Venus has no user-configurable MQTT client (its built-in one talks to the
Marstek cloud), so a bridge on the Pi polls it and republishes on the house
broker:

Modbus TCP is the only transport. It is served on the battery's **wired LAN
port only** — port 502 does not answer over its wifi at all — and needs
firmware **V144 or newer** (V146+ also fixes app connectivity).

| | |
|---|---|
| Transport | Modbus TCP, wired LAN port only |
| Address | `192.168.1.153` (`BATTERY_HOST`) — the **wired** interface, port 502 |
| Unit id | 1 (`BATTERY_UNIT`; 2, 3… for further units) |
| Firmware | V144 or newer |
| Dependency | `pymodbus` — `sudo apt install python3-pymodbus` |
| Cadence | one poll every 5 s (`POLL_INTERVAL`), published to `casa/batteria/data` |

It does not work through the Marstek cloud: the battery has to be on the same
LAN as the Pi.

**The two interfaces are two hosts.** The wifi side (`192.168.1.217`, MAC
`cc:c8:37:a1:c5:f9`) and the wired side (`192.168.1.153`, MAC
`dc:04:5a:7d:ef:ec`) get separate leases. The wifi one is up and pingable and
answers the local API, but **refuses** port 502 — `Connection refused`, not a
timeout, which reads like a firewall problem and is not one. Only the cabled
address serves Modbus.

**The server is brittle, and this shapes the code.** A request the battery
does not implement is not just refused: it drops the TCP session, and then
refuses new connections for several seconds. So one bad block costs the whole
poll and part of the next. Two consequences, both deliberate:

- The blocks must cover implemented registers only — 35003 does not exist, so
  the old `35000 + 12` block took the temperatures, `state` and `mode` down
  with it. It is split into `35000+3` and `35010+2`.
- `read_battery()` abandons the remaining blocks as soon as one fails and the
  poller reconnects, instead of retrying into a lockout it is itself extending.

### Why the local API is gone

The bridge could also talk the Marstek **local API** ("Open API", JSON over
UDP :30000), which was the only local way in while the battery sat on wifi.
That path has been removed. It never exposed the pack side at all — no
voltage, no current, no cell temperatures and no DC power — so `battery_power`
had to be stood in for by the inverter's AC figure (`ongrid_power`, a few per
cent above the DC one: measured 1850 W AC while the residual capacity climbed
10 Wh every 21 s, ≈1715 W DC), `state` had to be derived from that power with
a ±15 W deadband, and four `batteria` columns stayed permanently empty. Modbus
reads all of them directly, so the workarounds went with it — along with the
UDP pacing (the battery dropped calls fired back to back) and the alias table
that absorbed key names changing between firmwares.

**The sign changed with the transport.** `BATTERY_POWER_SIGN` was `-1` for the
local API, whose `ongrid_power` is the AC side and negative while charging.
Register 30001 is the pack's own figure and reads **positive** while charging,
so the constant is back to **`1`**. Measured 2026-09-04 with the battery
charging: `battery_power` +786 W, `state` = Charge, 53.96 V × 13.8 A = 745 W
DC (the AC figure sat at −797 W at the same moment, the old convention).

The register map is taken from `ViperRNMC/marstek_venus_modbus`
(`registers/e_v3.yaml`), whose author flags the v3 map as only partially
validated on hardware — hence `--check` below.

| Register | Type | Scale | Field |
|----------|------|-------|-------|
| 30001 | int16 | 1 | `battery_power` (W) |
| 30006 | int16 | 1 | `ac_power` (W) |
| 30100 / 30101 | uint16 / int16 | 0.01 / 0.1 | `battery_voltage` / `battery_current` |
| 33000 / 33002 | uint32 / int32 | 0.01 | `charge_total` / `discharge_total` (kWh) |
| 34002 | uint16 | 0.1 | `soc` (%) |
| 32200 / 32204 | uint16 | 0.1 | `ac_voltage` (V) / `ac_frequency` (Hz) |
| 35000 | int16 | 0.1 | `temperature` (°C, electronics/MOS area — reads ~39 °C with the pack at 33) |
| 35001 / 35002 | int16 | 0.1 | `temp_mos1` / `temp_mos2` (°C) — free, they sit in the 35000 block |
| 37007 / 37008 | int16 | 0.001 | `cell_voltage_max` / `cell_voltage_min` (V) |
| 35010 / 35011 | int16 | 0.1 | `cell_temp_max` / `cell_temp_min` (°C) |
| 35100 | uint16 | — | `state`: Sleep / Standby / Charge / Discharge / Backup / OTA / Bypass |
| 43000 | uint16 | — | `mode`: Manual / Anti-feed / Trade |

**Register 37004 is deliberately NOT read.** The community map calls it
`ac_current`, but on this firmware it returns the AC *power* — the same word as
30006, verified over three consecutive samples (−796/−796, −797/−797,
−797/−796). Both dashboards derive the AC current from `|ac_power| / ac_voltage`
and label it as derived rather than publish a mislabelled register.

**Only one Modbus client at a time.** The Venus accepts a single TCP
connection: while `meteo-battery.service` holds it, every other client gets
`noconn`, which looks exactly like the lockout an illegal register causes and
is not the same thing. To probe by hand, stop the service first —
`sudo systemctl stop meteo-battery`, probe, start it again.

They are read in eleven block reads per poll (see the register-range note
above for why the 35000 range is split in two). A poll that breaks off part
way still publishes what it got; when the very first block fails the battery
is logged as unreachable **once**, not once per poll, and nothing is
published, so the dashboard's snapshot goes stale (150 s) and the "nessun
dato" banner comes back on its own. `pymodbus`'s own logger is turned down to
CRITICAL for the same reason: it prints a full ERROR line per refused
connection, which would otherwise fill the journal at one line every 5 s while
the battery is down.

The published sign is whatever the battery reports. The house convention
(+ = charging) is applied in one place only, `BATTERY_POWER_SIGN` in
`mqtt_receiver.py` — do not flip it in the bridge as well.

---

## Data flow

```
Marstek Venus E 3.0
      │ Modbus TCP :502 (wired LAN)
      ▼
battery_bridge.py  ──MQTT casa/batteria/data──┐
                                              │
                            (or any other bridge, on marstek/venus/+)
      ┌───────────────────────────────────────┘
      ▼
mqtt_receiver.py ──► /dev/shm/battery_latest.json   (every message)
      │
      └──────────► /dev/shm/meteo.db :: batteria    (1 row/min)
                             │
                             └──► rapsberry meteo/batteria.php
```

Two cadences, exactly as for the Shelly: the tmpfs snapshot is rewritten on
every message so the cards follow the device within a second or two, while the
`batteria` table keeps its one row per minute (`BATTERY_WRITE_INTERVAL`) for
the 24 h charts.

Each stored row also carries the PV production and house load taken from the
Shelly snapshot at that moment (`pv_power`, `casa_power`), so the "batteria e
impianto" chart is one query rather than a join across two tables.

---

## Storage — SQLite `/dev/shm/meteo.db`, table `batteria`

| Column | Unit | Notes |
|--------|------|-------|
| `timestamp` | UTC ISO-Z | `YYYY-MM-DDTHH:MM:SSZ` |
| `soc` | % | state of charge |
| `battery_power` | W | signed, + charging |
| `ac_power` | W | inverter output as published |
| `battery_voltage`, `battery_current` | V / A | pack side |
| `temperature` | °C | **internal/MOS**, not the cells — runs 10–20 °C hotter |
| `cell_temp_max`, `cell_temp_min` | °C | hottest / coldest cell — the figures to judge the pack by |
| `charge_total`, `discharge_total` | kWh | lifetime counters, when the source has them |
| `mode`, `state` | text | e.g. `AI` / `Manual`, `charging` / `standby` |
| `pv_power`, `casa_power` | W | copied from the Shelly snapshot at write time |

The table is created by `init_db()` on start-up, so an existing `meteo.db`
gains it on the next restart of `meteo-receiver.service`.

---

## Dashboard — `rapsberry meteo/batteria.php`

Reached from the header of `index.php` ("Batteria"), same layout language as
the weather dashboard: live cards, panels with a chart each, two tables.

- **SoC gauge** — a bar pinned to 0–100 % (green ≥ 50, amber ≥ 20, red below)
  plus the 24 h SoC chart on the same scale, so a 62→68 % swing looks like what
  it is.
- **Potenza** — signed chart of battery power and AC output, with the current
  work mode as a badge.
- **Flusso energetico** — PV / rete / batteria / casa side by side, right now,
  reading the Shelly snapshot the same way `index.php` does (including the
  10 W PV deadband).
- **Batteria e impianto (24 h)** — battery power against production and house
  load.
- **Riepilogo giornaliero** — charged / discharged kWh, SoC min-max and the
  round-trip yield, today against yesterday.
- **Temperature** — one card for the hottest cell (with the coldest and the
  spread underneath, coloured past 40 / 45 °C) and one for the internal
  electronics reading; the readings table carries both columns.

### Reading the temperatures

Two different things, deliberately kept apart:

- **`temperature` (register 35000)** is the electronics/MOS area. It normally
  runs well above the cells, so the pack thresholds below do not apply to it —
  worry at ~55 °C, alarm at ~65 °C.
- **`cell_temp_max` / `cell_temp_min`** are the pack itself, and what a
  temperature alarm should look at.

LiFePO4 cells, for the "temp. celle" card (`CELL_WARM_C` / `CELL_HOT_C` in
`batteria.php`, which colour the value amber then red):

| Cell temp | Reading |
|-----------|---------|
| 10–35 °C | normal |
| > 40 °C | warm, worth watching |
| > 45 °C | too high — BMS derating territory |
| > 50 °C | the BMS should be throttling or stopping charge itself |
| < 0 °C | **charging** blocked by the BMS; discharge still fine to −20 °C. Normal in winter, not a fault |

Device limits for reference: operating −20…+55 °C, storage −30…+85 °C.

Two things matter more than the absolute number: how fast it is rising, and the
**spread** — the card shows `Δ = max − min`, and above ~5 °C that points at a
weak cell or poor balance even while both values look fine.

### Daily kWh are integrated, not read

The Venus does not necessarily publish usable daily counters, so
`?api=daily` integrates `battery_power` over the stored rows (trapezoid,
positive = charge, negative = discharge). Gaps longer than 5 minutes are
skipped rather than bridged: a receiver that was down must not invent energy.
The figures are therefore a good estimate, not a revenue-grade meter reading.

`BATTERY_CAPACITY_KWH` (2.56 kWh usable on a Venus E 3.0) only feeds the
"energia residua" card, which is labelled *stimati* for the same reason.

### Endpoints

| `?api=` | Returns |
|---------|---------|
| `batteria&limit=N` | up to 2880 history rows, oldest first |
| `instant` | freshest battery + Shelly snapshot — what the 3 s poll hits |
| `daily` | today and yesterday: charge/discharge kWh, SoC min/max |

---

## Bringing the battery online

1. Cable the battery to the LAN — Modbus is served on the wired port only, so
   `--check` reporting "cannot reach" against the wifi address is expected,
   not a fault — and give that interface (its own MAC, its own lease) a fixed
   address. Firmware must be V144 or newer. To find it, scan the LAN for a
   host with 502 open and read register 34002: it must come back as SoC × 10.
2. Set `BATTERY_HOST` in `battery_bridge.py` to that address.
3. On the Pi, once: `sudo apt install python3-pymodbus`
   (or `pip3 install --break-system-packages pymodbus`).
4. Deploy: `./deploy_pi.sh battery_bridge.py` — `meteo-battery.service` is not
   installed yet, so the script says it is skipping that restart, which is
   expected.
5. Verify the register map against the real device **before** running it as a
   service:

   ```
   python3 /var/www/html/battery_bridge.py --check
   ```

   It prints one decoded reading. Check SoC against the app, that the voltage
   is plausible, that the cell temperatures are not wild, and that the sign of
   `battery_power` matches what the battery is actually doing — the map is
   community-sourced, so a field that comes out absurd is a wrong register,
   not a broken battery.
6. Install the service:

   ```
   sudo cp /var/www/html/meteo-battery.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now meteo-battery
   ```

   (`meteo-battery.service` ships next to the script; deploy it the same way or
   scp it across.) From then on `./deploy_pi.sh battery_bridge.py` restarts it
   like the other services.
7. `sudo systemctl restart meteo-receiver` once, to create the `batteria` table
   on the existing DB, then watch it arrive:
   `journalctl -u meteo-receiver -f` prints one `Battery stored to DB` line per
   minute.
8. Open `http://stazionemeteo.local/batteria.php` — the banner disappears with
   the first message.
9. If a charging battery reads "in scarica", flip `BATTERY_POWER_SIGN` in
   `mqtt_receiver.py` and redeploy that file. It is `1` for the Modbus source.

Deploy targets: `./deploy_pi.sh batteria.php mqtt_receiver.py battery_bridge.py`
(the script verifies each file by md5 and restarts the units that exist).

---

## The remote dashboard (`server_remoto/`)

Since 2026-09-04 the battery also reaches `cesana.steplab.net`, over the same
path the weather reading already took: `meteo.py` reads the tmpfs snapshot,
puts three keys in its MQTT payload, and `mqtt_ingest.py` -> `ingest.php` ->
`store_lib.php` stores them.

| Key | Column | Meaning |
|-----|--------|---------|
| `battPower` | `battPower` | W, + charging (house convention, already flipped by the receiver) |
| `battSoc` | `battSoc` | % |
| `battTemp` | `battTemp` | °C, **hottest cell** — `cell_temp_max`, falling back to the MOS reading |
| `battTs` | `battTs` (live row only) | unix seconds, when the BATTERY was read |

`battTemp` is the pack, not the box: `temperature` (register 35000) is the
electronics/MOS area and runs some 6 °C above the cells (38 °C against 32 °C on
2026-09-04), and it is the cell figure the BMS limits work from — no charging
below 0 °C, derating above 45 °C. The remote card colours it on those
thresholds and says *carica bloccata dal BMS* below zero, which is a state and
not a fault.

`battTs` is the one that is not obvious. The server keeps the last value it was
sent for every field — a message that omits a key leaves the stored one alone,
which is what stops a partial reading from zeroing good data. For the battery
that rule is dangerous: a bridge that died at 800 W would have the dashboard
subtracting 800 W from the house load for ever. So the battery figures carry
their own age, `meteo.py` sends the three together or not at all, and
`instant_lib.php` treats anything older than `BATT_MAX_AGE` (300 s) as no
battery at all — the card disappears and `casa` goes back to `pv + grid`.

History rows need none of this: a `dati_meteo` row is already stamped, and a
NULL `battPower` there means the battery said nothing in that ten-minute
window. `casa_power()` in `grafico.php` therefore keeps the plain sum when the
column is NULL, so charts that predate the battery are unaffected.

What the house load means changed with it — see `ENERGY_METER.md`.

---

## Not done yet

- **The register map is now validated where it is used** — all ten fields
  above read plausible values on 2026-09-04 (SoC 70 % against the app,
  53.96 V × 13.8 A ≈ 745 W against `battery_power` 770 W, cells 30–32 °C,
  state Charge while charging). What is *not* mapped is still unchecked, and
  probing for more registers is not free: a wrong address locks the Modbus
  server out for seconds (see above), so it is worth doing deliberately, not
  by sweeping.
- **Alarms** — `alarm_watcher.py` has no battery sources; a "SoC sotto il 15 %"
  or "non trasmette" rule would need `batteria` merged into the reading it
  evaluates, the way `energia` already is (see `ENERGY_METER.md`).
- **Local dashboards still show the gross house load** — `casa_power` in
  `mqtt_receiver.py` (and so `rapsberry meteo/index.php` and `batteria.php`) is
  still `pv_power + grid_power`, which counts a charging battery as
  consumption. Only the remote dashboard subtracts it. Changing the local one
  means changing what the `energia` table has always meant, so it was left
  alone deliberately rather than overlooked.
