# Battery — Marstek Venus E 3.0

Local dashboard for the home battery, built on the same chain as the Shelly
energy meter: MQTT → `mqtt_receiver.py` → SQLite `/dev/shm/meteo.db` → PHP page
on the Pi.

Status: **live since 2026-09-03.** A VenusE 3.0 at `192.168.1.217`, on wifi,
read through its local API by `battery_bridge.py` (`meteo-battery.service` on
the Pi) and stored one row a minute.

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

It has two transports, picked with `TRANSPORT` at the top of the file, because
which one is available depends on how the battery is cabled:

| `TRANSPORT` | | |
|---|---|---|
| `"modbus"` | Transport | Modbus TCP, **wired LAN port only** — not over the battery's wifi |
| | Firmware | **V144 or newer** (V146+ also fixes app connectivity) |
| | Port / unit id | 502 / 1 (2, 3… for further units) |
| | Dependency | `pymodbus` — **not installed on the Pi yet** |
| `"udp"` | Transport | Marstek **local API**, JSON over UDP — works over the battery's **wifi**; the one in use here |
| | Firmware | the switch is what matters, not the version: it works on **V144** with "Local API" turned on in the Marstek app |
| | Port | 30000 (`UDP_PORT`, must match the port the app shows) |
| | Dependency | none — plain sockets |

Both poll every 5 s (`POLL_INTERVAL`) and publish the same JSON to
`casa/batteria/data`; the `pymodbus` import is optional, so the UDP transport
runs on a Pi that does not have it. Neither works through the Marstek cloud:
the battery has to be on the same LAN as the Pi either way.

### The local API (wifi) — what a VenusE 3.0 on firmware 144 actually gives

| Call | Keys that end up in the DB | Keys ignored |
|------|---------------------------|--------------|
| `ES.GetStatus` | `bat_soc` → `soc`, `ongrid_power` → `ac_power`, `total_grid_input_energy` → `charge_total`, `total_grid_output_energy` → `discharge_total` (Wh → kWh) | `bat_cap`, `pv_power`, `offgrid_power`, `total_pv_energy`, `total_load_energy` — all 0 on a Venus with no PV input |
| `Bat.GetStatus` | `bat_temp` → `temperature` | `bat_capacity` (Wh left), `rated_capacity` (5120 Wh), `charg_flag`/`dischrg_flag` — permissions, not state |
| `ES.GetMode` | `mode` (`Auto`) | a second copy of the power figures, and the CT meter's |

**The pack side is not exposed at all**: no voltage, no current, no cell
temperatures, and no DC battery power. So:

- `battery_power` is the inverter's AC figure (`ongrid_power`) standing in for
  it — same direction, a few per cent higher. Measured: 1850 W AC while the
  residual capacity climbed 10 Wh every 21 s (≈1715 W DC), the difference being
  conversion loss. The `batteria` columns `battery_voltage`,
  `battery_current`, `cell_temp_max` and `cell_temp_min` stay empty, and the
  temperature card shows only the one reading — which here IS the pack, not the
  electronics.
- `state` is derived from the power (`Carica` / `Scarica` / `In attesa`, ±15 W
  deadband), because `mode` says what the battery is *told* to do, not what it
  is doing.
- The grid counters are mapped to `charge_total` / `discharge_total` on
  purpose: the Venus has no PV input, so everything in and out of the pack
  passes the grid port and those two counters are the pack's totals.

**Sign, measured not guessed**: `ongrid_power` is **negative while charging**
(watched `bat_capacity` and `total_grid_input_energy` rise with it at
−1850 W). `BATTERY_POWER_SIGN = -1` in `mqtt_receiver.py` accordingly, so the
stored column follows the house convention, + = charging.

**Pacing**: the battery drops requests that arrive on top of each other —
fired back to back, two of the three calls go unanswered. `UDP_GAP = 1.0` s
between calls and `UDP_RETRIES = 3` fixed it; the odd call is still dropped now
and then, and costs only its own fields.

### The local API (wifi) — protocol

One UDP datagram per call, `{"id": 1, "method": "ES.GetStatus", "params":
{"id": 0}}`, answered by `{"id": 1, "result": {…}}`. Three calls per poll
(`UDP_CALLS`): `ES.GetStatus` (SoC, battery and AC power), `Bat.GetStatus`
(voltage, current, temperatures) and `ES.GetMode`. The results are merged into
one flat dict — first call in `UDP_CALLS` wins a repeated key — and mapped onto
the canonical column names through `UDP_ALIASES`.

The key names differ between firmwares and the API is young, so the lookup is
alias-based like the receiver's, and `--check` prints the **raw answers**
before the decoded payload: a field that comes out missing is either genuinely
absent or spelled differently, and in the second case the spelling only has to
be added to `UDP_ALIASES`.

Two details that cost time otherwise: the socket binds local port 30000 when it
can, because some firmwares answer only a request that came from the port they
listen on (it falls back to an ephemeral port with a log line), and datagrams
the battery sends on its own — notifications, late answers to an earlier call —
are skipped rather than mistaken for the answer in hand.

Grid counters (`total_grid_input_energy` / `total_grid_output_energy`) are
deliberately **not** mapped to `charge_total` / `discharge_total`: they measure
the grid side, not the pack.

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
| 35000 | int16 | 0.1 | `temperature` (°C, electronics/MOS area) |
| 35010 / 35011 | int16 | 0.1 | `cell_temp_max` / `cell_temp_min` (°C) |
| 35100 | uint16 | — | `state`: Sleep / Standby / Charge / Discharge / Backup / OTA / Bypass |
| 43000 | uint16 | — | `mode`: Manual / Anti-feed / Trade |

They are read in seven block reads per poll, and a block that fails costs its
own fields only — the rest of the message is still published. When every block
fails the battery is logged as unreachable **once**, not once per poll, and
nothing is published, so the dashboard's snapshot goes stale (150 s) and the
"nessun dato" banner comes back on its own.

The published sign is whatever the battery reports. The house convention
(+ = charging) is applied in one place only, `BATTERY_POWER_SIGN` in
`mqtt_receiver.py` — do not flip it in the bridge as well.

---

## Data flow

```
Marstek Venus E 3.0
      │ Modbus TCP :502 (wired LAN)  — or —  local API UDP :30000 (wifi)
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

1. Give the battery a fixed address on the house LAN (DHCP reservation), then
   pick the transport:
   - **Cabled to the LAN**, firmware V144+: leave `TRANSPORT = "modbus"`.
   - **On wifi**: set `TRANSPORT = "udp"` and turn the
     "Local API" / "Open API" switch on in the Marstek app (device settings);
     it shows the port, which must match `UDP_PORT` (30000 by default).
     Modbus is simply not served over wifi — port 502 does not answer at all,
     so `--check` reporting "cannot reach" on a wifi battery is expected, not a
     fault.
2. Set `BATTERY_HOST` in `battery_bridge.py` to that address.
3. Only for `"modbus"`, on the Pi, once: `sudo apt install python3-pymodbus`
   (or `pip3 install --break-system-packages pymodbus`). The UDP transport
   needs nothing installed.
4. Deploy: `./deploy_pi.sh battery_bridge.py` — `meteo-battery.service` is not
   installed yet, so the script says it is skipping that restart, which is
   expected.
5. Verify the register map against the real device **before** running it as a
   service:

   ```
   python3 /var/www/html/battery_bridge.py --check
   ```

   It prints one decoded reading — on `"udp"`, the raw answers first. Check SoC
   against the app, that the voltage is plausible, and that the sign of
   `battery_power` matches what the battery is actually doing. On `"udp"`, also
   look for fields missing from the decoded payload but present in the raw
   answers under another name, and add that name to `UDP_ALIASES`.
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
9. If a charging battery reads "in scarica", set `BATTERY_POWER_SIGN = -1` in
   `mqtt_receiver.py` and redeploy that file.

Deploy targets: `./deploy_pi.sh batteria.php mqtt_receiver.py battery_bridge.py`
(the script verifies each file by md5 and restarts the units that exist).

---

## Not done yet

- **The Modbus transport is still unvalidated on hardware** — the v3 register
  map is community-sourced and has never been run against this battery, which
  is on wifi. The UDP transport is the tested one; if the Venus is ever cabled,
  `--check` on `TRANSPORT = "modbus"` is a required step, not a formality.
- **Alarms** — `alarm_watcher.py` has no battery sources; a "SoC sotto il 15 %"
  or "non trasmette" rule would need `batteria` merged into the reading it
  evaluates, the way `energia` already is (see `ENERGY_METER.md`).
- **Remote server** — `meteo.py` does not forward battery figures to
  `carica_dati.php`, so the battery appears only on the LAN dashboard.
