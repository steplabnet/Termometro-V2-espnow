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
casa_power = pv_power + grid_power
```

(export is negative, so it subtracts — exactly what the house is not using.)

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
  appear only once a real reading has been stored.

### Charts (`server_remoto/grafico.php`)

| URL | Series |
|-----|--------|
| `grafico.php?var=pvPower` | production, today vs yesterday |
| `grafico.php?var=gridPower` | grid exchange, today vs yesterday |
| `grafico.php?var=casa` | derived house load, today vs yesterday |
| `grafico.php?var=multi&v[]=pvPower&v[]=gridPower&v[]=casa` | overlay |

These three variables are exempt from the `< -50 = dead sensor` filter that
guards the temperature series, since grid export is legitimately very negative.

Under each chart the kWh boxes are integrated over the real sample timestamps
(gaps longer than 1 h are not bridged): production/consumption show today,
yesterday and the two-day total, while the grid chart shows **prelevato oggi**,
**immesso oggi** and yesterday's balance.

---

## Deploying

1. Copy `mqtt_receiver.py` and `meteo.py` to the Pi and restart both services.
   The `energia` table is created automatically.
2. Copy `index.php` to the Pi web root.
3. Upload `carica_dati.php`, `index.php` and `grafico.php` to the remote server.
   The MySQL columns are added on the first `carica_dati.php` hit.

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
