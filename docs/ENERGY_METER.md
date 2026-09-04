# Energy Meter — Shelly Pro EM-50

Integration of the Shelly Pro EM-50 (photovoltaic production + grid exchange)
into the weather/home dashboards.

---

## Source: MQTT

The meter publishes one topic per clamp on the same broker as the rest of the
house (`stazionemeteo.local:1883`):

| Topic                      | Clamp | Meaning |
|----------------------------|-------|---------|
| `centralino/status/em1:1`  | id 1  | Photovoltaic production (W, always ≥ 0) |
| `centralino/status/em1:0`  | id 0  | Grid exchange (W): **> 0 = prelievo** (import), **< 0 = immissione** (export) |

Payload (both channels share the shape):

```json
{"id":1,"voltage":229.9,"current":0.826,"act_power":118.9,
 "aprt_power":190.1,"pf":0.63,"freq":50.0,"calibration":"factory"}
```

Production below **10 W** is inverter/clamp noise, not real output, so
`mqtt_receiver.py` stores it as a clean `0` (`PV_ZERO_THRESHOLD`). Both
dashboards apply the same rule when displaying, which also covers rows logged
before the threshold existed.

House load is derived, never measured:

```
casa_power = pv_power + grid_power                     (local dashboards)
casa       = pvPower  + gridPower - battPower          (remote dashboard)
```

(export is negative, so it subtracts — exactly what the house is not using.)

The second form is the one to trust since 2026-09-04. The Marstek Venus E sits
on the **house side** of the meter, so the Shelly cannot tell it from an
appliance: a pack charging at 800 W reads as 800 W of consumption, and hides
the same amount while it gives the energy back. Subtracting `battPower`
(+ = charging) leaves what the house is really using. Checked against all three
meters at once: PV 1871 W, export 877 W, battery charging 770 W, real load
224 W — and 224 + 770 + 877 = 1871.

The local dashboards (`rapsberry meteo/index.php`, `batteria.php`) still show
the gross figure; only the remote one is net. See `BATTERY_VENUS.md`.

### PV deadband

Production below **10 W** is not production — it is clamp leakage and inverter
standby — so it is recorded and displayed as **0 W**, and `casa_power` is
derived from the clamped value. The rule is applied at every stage, so rows
logged before it existed are cleaned on the way out:

| Where | What it clamps |
|-------|----------------|
| `mqtt_receiver.py` (`PV_ZERO_THRESHOLD`) | on write to `energia` |
| `meteo.py` (`read_latest_energy`) | what is forwarded to MQTT and to the server |
| `rapsberry meteo/index.php` (`pv_deadband`) | every `energia` row served by the API |
| `carica_dati.php` | the `pvPower` written to MySQL |
| `server_remoto/index.php` | the live card value |
| `server_remoto/grafico.php` (`pv_clean`) | chart series, multi-plot and kWh totals |

The grid channel is **not** clamped: small import/export values around zero are
real and the sign matters.

The unclamped reading is kept too: `mqtt_receiver.py` writes it to the
`pv_power_raw` column of `energia` (and to the tmpfs snapshot) alongside the
clamped `pv_power`. Nothing displays it — it exists so an alarm rule can tell
"the meter really reads nothing" (inverter down) from "production is just low",
which the deadband makes indistinguishable everywhere else.

### Alarm on zero production

`alarm_watcher.py` merges the latest `energia` row into the reading it
evaluates, under four sources selectable in **alarms.php**:

| Source | Column | Note |
|--------|--------|------|
| Shelly — Produzione reale (`pv_raw`) | `pv_power_raw` | unclamped |
| Shelly — Produzione (`pv_power`) | `pv_power` | deadbanded |
| Shelly — Scambio rete (`grid_power`) | `grid_power` | signed |
| Shelly — Consumo casa (`casa_power`) | `casa_power` | derived |

So the alarm is an ordinary rule with the usual custom Telegram message and bot:
a value condition **Produzione reale = 0**, normally plus a time window so it
stays quiet at night. Readings older than `ENERGY_STALE_S` (10 min) count as
missing rather than as zero, so a stopped receiver or a dead meter does not
masquerade as a stopped inverter — use a "Non trasmette" condition on one of
these sources for that case instead.

---

## Data flow

```
Shelly Pro EM-50
      │ MQTT (every ~2 s)
      ▼
mqtt_receiver.py  ──► /dev/shm/meteo.db :: energia      (1 row/min)
      │                        │
      │                        └──► rapsberry meteo/index.php   (local dashboard)
      ▼
meteo.py  ──HTTP──► carica_dati.php ──► MySQL dati_meteo / dati_instant
                                              │
                                              └──► server_remoto/index.php + grafico.php
```

`mqtt_receiver.py` owns the ingestion: it buffers both clamps and writes one
merged row per minute (`ENERGY_WRITE_INTERVAL`). A clamp that stops publishing
is dropped after `ENERGY_MAX_AGE` (150 s) instead of freezing its last value
into every later row.

`meteo.py` reads the freshest `energia` row each minute (ignoring anything
older than 5 min) and appends `&pvPower=` / `&gridPower=` to the call it already
makes to `carica_dati.php`, so the remote server gets the same figures.

---

## Storage

### Raspberry Pi — SQLite `/dev/shm/meteo.db`, table `energia`

| Column | Unit | Notes |
|--------|------|-------|
| `timestamp` | UTC ISO-Z | `YYYY-MM-DDTHH:MM:SSZ` |
| `pv_power` | W | clamp em1:1 |
| `grid_power` | W | clamp em1:0, signed |
| `casa_power` | W | derived house load |
| `pv_voltage`, `pv_current`, `pv_pf` | V / A / — | clamp em1:1 detail |
| `grid_voltage`, `grid_current`, `grid_pf` | V / A / — | clamp em1:0 detail |
| `freq` | Hz | mains frequency |

### Remote server — MySQL `dati_meteo` and `dati_instant`

Two columns, `pvPower` and `gridPower` (`FLOAT NULL`). `carica_dati.php` adds
them automatically on first run, so no manual migration is needed. The
equivalent manual statement is:

```sql
ALTER TABLE dati_meteo   ADD pvPower FLOAT NULL, ADD gridPower FLOAT NULL;
ALTER TABLE dati_instant ADD pvPower FLOAT NULL, ADD gridPower FLOAT NULL;
```

`dati_instant` is only updated when a reading is actually present, so a meter
outage leaves the last known values on screen rather than showing 0 W.

---

## Dashboards

### Local (`rapsberry meteo/batteria.php`)

- **Temperature, tensioni e correnti** group: the same fourteen figures as the
  remote card, in the page's own three-column layout.
- The headline temperature card shows the **hottest cell** with min, spread and
  the electronics reading beneath it. It used to show the electronics figure
  coloured with the *cell* thresholds — harmless over the local API, where that
  reading really was the pack, and wrong once Modbus started publishing both.

### Local (`rapsberry meteo/index.php`)

- **Energia (Shelly EM)** panel: produzione FV, scambio rete (with direction
  and colour), consumo casa, and the share of the load covered by the panels.
- Chart of the three series over the last 24 h.
- Clicking the panel opens the today-vs-yesterday modal (production and grid
  exchange).
- Three extra rows in the "Confronto con ieri (stessa ora)" table.
- APIs: `?api=energia&limit=N`, `?api=energia_latest`; `?api=yesterday` now also
  returns an `energia` snapshot.

### Remote (`server_remoto/index.php`)

- **Produzione Fotovoltaico** takes the slot of the old ADC-estimated "Potenza
  Fotovoltaico" card and links to `?var=pvPower`. If the meter (or its DB
  columns) is not available it falls back to the ADC estimate and its chart, so
  the card is never empty. The sky/irraggiamento readout below the value still
  comes from the ADC series, which is the one with seven days of history behind
  it.
- **Scambio Rete** and **Consumo Casa** follow the Temperatura Interno card, and
  appear only once a real reading has been stored. **Consumo Casa is net of the
  battery** (see the formula above); while the battery is actually moving, a
  "Con batteria" figure appears next to the 24 h peak with the gross number the
  meters see. Its share line reads "% da FV e batteria" rather than
  "% da fotovoltaico", because with a battery in the middle the PV figure alone
  no longer says how much of the load was self-covered — it is computed from
  what was *not* bought (`(casa - prelievo) / casa`).
- **Casa + Batteria** card: `casa + max(0, battPower)` — what the whole
  installation is drawing, with the split ("621 W casa + 757 W batteria")
  under it. Only a *charging* battery is added: a discharging one is not
  consumption, it is where the consumption is coming from, and adding it
  signed would make the total smaller than the house alone.

  The card is **hidden unless the battery is charging** (past the ±15 W
  deadband) — idle or discharging it would only repeat Consumo Casa next to
  it. It stays in the DOM, hidden, with `data-live-show="casaBatt.charging"`,
  so the poller brings it back the moment charging resumes instead of waiting
  for a page reload.
- **Diagnostica Batteria** card: everything the pack reports about itself, in
  three groups — temperatures (cell max/min and their spread, internal, both
  MOS sensors), voltages (pack, cell max/min, their spread in mV, AC with
  frequency) and currents (pack, and AC *derived* from |W| / V). The two
  spreads turn amber past 5 °C and 50 mV, which is where a pack stops being
  balanced. **Every value links to its own chart**, so this is the one card not
  wrapped in a single `<a>` — a link inside a link is not valid HTML, hence its
  hand-written markup and the `.diag-rows a` rule that undoes the global anchor
  styling.

  The eleven fields behind it (`BATTERY_DIAG_FIELDS` in `instant_lib.php`) go
  to **both** tables. They began as live-row-only — read by one card, charted
  by nothing — and moved into `dati_meteo` when the card became clickable,
  because a chart needs a history to draw. Rows logged before that are NULL and
  show as gaps.
- **Batteria** card: state of charge with a fill bar, charge/discharge state
  (green charging, orange discharging, ±15 W deadband), cell temperature
  (coloured on the BMS thresholds), current power, residual kWh and the 24 h
  SoC range. It is in the markup only while the battery is
  reporting, exactly like the two meter cards — `battAvailable` in the payload,
  and the browser reloads rather than patches when it flips.

### Charts (`server_remoto/grafico.php`)

| URL | Series |
|-----|--------|
| `grafico.php?var=pvPower` | production, today vs yesterday |
| `grafico.php?var=gridPower` | grid exchange, today vs yesterday |
| `grafico.php?var=casa` | derived house load (net of the battery), today vs yesterday |
| `grafico.php?var=battPower` | battery power, + charging / − discharging |
| `grafico.php?var=battSoc` | state of charge, % |
| `grafico.php?var=battTemp` | hottest cell, °C |
| `grafico.php?var=casaBatt` | house + charging battery, today vs yesterday |
| `battTempMin`, `battTempInt`, `battTempMos1`, `battTempMos2` | the other pack temperatures |
| `battVolt`, `battCellVMax`, `battCellVMin`, `battAcV` | voltages |
| `battCurr`, `battAcW`, `battAcHz` | pack current, AC power, mains frequency |
| `battTempSpread`, `battCellVSpread`, `battAcCurr` | derived: the two balance spreads and \|W\| / V |

The diagnostics are single-variable charts only: they are deliberately kept out
of `MULTI_CATALOG`, which would otherwise grow to two dozen checkboxes in the
compare toolbar.
| `grafico.php?var=multi&v[]=pvPower&v[]=gridPower&v[]=casa` | overlay |

These variables (plus `battPower`, negative while discharging) are exempt from
the `< -50 = dead sensor` filter that guards the temperature series, since grid
export is legitimately very negative. `battSoc` is not exempt: a percentage
never legitimately goes below zero.

`var=battPower` gets its own kWh boxes, split the way the grid chart is:
**caricato oggi**, **scaricato oggi**, caricato ieri.

Under each chart the kWh boxes are integrated over the real sample timestamps
(gaps longer than 1 h are not bridged): production/consumption show today,
yesterday and the two-day total, while the grid chart shows **prelevato oggi**,
**immesso oggi** and yesterday's balance.

---

## Deploying

The two Pi scripts do **not** live in the same directory — copying both to the
web root looks right and silently leaves the old `meteo.py` running:

| File | Path on the Pi |
|------|----------------|
| `mqtt_receiver.py` | `/var/www/html/mqtt_receiver.py` |
| `meteo.py` | `/home/admin/Desktop/meteo/meteo.py` |
| local dashboard `index.php` | `/var/www/html/index.php` |

Confirm with `pgrep -af meteo.py` before copying, and check the deployed copy
afterwards with `grep -c read_latest_energy <path>` (0 = still the old file).

1. Copy `mqtt_receiver.py` and `meteo.py` to the paths above and restart both.
   The `energia` table is created automatically.
2. Copy `index.php` to the Pi web root.
3. Upload `carica_dati.php`, `index.php` and `grafico.php` to the remote server.
   The MySQL columns are added on the first `carica_dati.php` hit.

`check_energy.py` (next to `mqtt_receiver.py` in this repo) verifies the whole
chain hop by hop and prints what to fix; run it on the Pi after deploying.

If the meter's MQTT prefix is not `centralino`, change `MQTT_TOPIC_EM_PV` /
`MQTT_TOPIC_EM_GRID` at the top of `mqtt_receiver.py`.

## Quick test

```bash
mosquitto_sub -h stazionemeteo.local -p 1883 \
  -u stzionemeteo -P 78f25d_78 -t 'centralino/status/em1:0' -t 'centralino/status/em1:1' -v
```

```bash
sqlite3 /dev/shm/meteo.db 'SELECT * FROM energia ORDER BY id DESC LIMIT 5;'
```
