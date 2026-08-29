<?php
declare(strict_types=1);

/**
 * Previsioni 7 giorni (Open-Meteo).
 *
 * Deliberately a static page: every number here belongs to the forecast model,
 * none of it to our station, so there is nothing to read from MySQL or from the
 * /dev/shm snapshot. The browser talks to api.open-meteo.com directly, exactly
 * like the "Pioggia Prevista" card on the dashboard -- one page view costs this
 * server nothing beyond serving the HTML.
 *
 * Resolutions used are the ones the API actually publishes:
 *   hourly -> up to 16 days, here 7 (temperature, pioggia, probabilita', vento)
 *   daily  -> the same 7 days aggregated (min/max, mm, probabilita', alba/tramonto)
 * The 15-minute grid is nowcast-only and stays on the dashboard card.
 */

date_default_timezone_set('Europe/Rome');
?>
<!doctype html>
<html lang="it" data-bs-theme="light">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Previsioni 7 giorni - Cesana</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
  <style>
    /* Same palette as the dashboard (index.php), so arriving here from the
       "Pioggia Prevista" card does not feel like a different site. */
    :root {
      --bg-color: #f0f2f5;
      --card-bg: #ffffff;
      --text-main: #1f2937;
      --text-muted: #6b7280;
      --text-faint: #9ca3af;
      --border: #e5e7eb;
      --accent-blue: #3b82f6;
      --accent-red: #ef4444;
      --accent-teal: #14b8a6;
      --accent-orange: #f59e0b;
      --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    body {
      background-color: var(--bg-color);
      font-family: 'Inter', 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      color: var(--text-main);
      margin: 0;
      padding: 10px 10px 20px;
    }

    .home-btn {
      position: fixed;
      top: 15px;
      right: 20px;
      z-index: 1000;
      background-color: rgba(255, 255, 255, 0.9);
      backdrop-filter: blur(4px);
      border: 1px solid var(--border);
      color: var(--text-main);
      border-radius: 50px;
      padding: 8px 16px;
      font-size: 0.9rem;
      text-decoration: none;
      box-shadow: var(--shadow);
      transition: all 0.3s ease;
    }

    .home-btn:hover {
      background-color: var(--accent-blue);
      border-color: var(--accent-blue);
      color: #fff;
      transform: translateY(-2px);
    }

    h1 {
      font-size: 1.15rem;
      font-weight: 800;
      margin: 6px 0 2px;
    }

    .subtitle {
      font-size: 0.78rem;
      color: var(--text-muted);
      margin-bottom: 14px;
    }

    /* One tile per forecast day; clicking one zooms the chart to that day. */
    .day-strip {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
      gap: 8px;
      margin-bottom: 14px;
    }

    .day-card {
      background-color: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 10px 6px;
      text-align: center;
      cursor: pointer;
      box-shadow: var(--shadow);
      transition: border-color 0.2s, transform 0.2s;
      user-select: none;
    }

    .day-card:hover {
      transform: translateY(-2px);
      border-color: #d1d5db;
    }

    .day-card.active {
      border-color: var(--accent-blue);
      box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25), var(--shadow);
    }

    .day-name {
      font-size: 0.72rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--text-muted);
    }

    .day-glyph {
      font-size: 1.5rem;
      line-height: 1.4;
    }

    .day-temp {
      font-size: 0.92rem;
      font-weight: 700;
      font-variant-numeric: tabular-nums;
    }

    .day-temp .tmin {
      color: var(--accent-blue);
    }

    .day-temp .tmax {
      color: var(--accent-red);
    }

    .day-rain {
      font-size: 0.7rem;
      color: var(--accent-blue);
      font-variant-numeric: tabular-nums;
      min-height: 1.1em;
    }

    .day-sun {
      font-size: 0.62rem;
      color: var(--text-faint);
      font-variant-numeric: tabular-nums;
      margin-top: 2px;
    }

    .chart-container {
      position: relative;
      height: 46vh;
      min-height: 300px;
      background-color: var(--card-bg);
      border-radius: 12px;
      padding: 12px;
      border: 1px solid var(--border);
      box-shadow: var(--shadow);
    }

    .note {
      font-size: 0.7rem;
      color: var(--text-muted);
      margin-top: 10px;
      text-align: center;
    }

    .note a {
      color: var(--accent-blue);
      text-decoration: none;
    }

    #status {
      color: var(--text-muted);
      font-size: 0.85rem;
      padding: 20px;
      text-align: center;
    }
  </style>
</head>

<body>
  <a href="index.php" class="home-btn"><i class="fa-solid fa-house"></i> Dashboard</a>

  <h1>Previsioni 7 giorni &mdash; Cesana</h1>
  <div class="subtitle" id="subtitle">Modello Open-Meteo &middot; risoluzione oraria</div>

  <div class="day-strip" id="day-strip"></div>

  <div class="chart-container">
    <canvas id="chart"></canvas>
    <div id="status">Caricamento previsioni&hellip;</div>
  </div>

  <div class="note">
    Dati: <a href="https://open-meteo.com" target="_blank" rel="noopener">Open-Meteo</a> &middot;
    risoluzione oraria fino a 16 giorni (qui 7) &middot; il dettaglio a 15 minuti &egrave; sulla dashboard
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    (function () {
      var LAT = 46.0186, LON = 11.9931;   // Cesana di Lentiai (BL), 264 m slm
      var REFRESH_MS = 15 * 60 * 1000;    // the model itself refreshes ~4x/hour

      var URL = 'https://api.open-meteo.com/v1/forecast'
        + '?latitude=' + LAT + '&longitude=' + LON
        + '&hourly=temperature_2m,precipitation,precipitation_probability,wind_speed_10m,weather_code'
        + '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,'
        + 'precipitation_probability_max,sunrise,sunset'
        + '&forecast_days=7&timezone=Europe%2FRome';

      /** WMO weather codes -> glyph + wording (the page is served as UTF-8). */
      var WMO = {
        0: ['☀️', 'Sereno'],
        1: ['🌤️', 'Poco nuvoloso'],
        2: ['⛅', 'Parz. nuvoloso'],
        3: ['☁️', 'Coperto'],
        45: ['🌫️', 'Nebbia'],
        48: ['🌫️', 'Nebbia gelata'],
        51: ['🌧️', 'Pioviggine'],
        53: ['🌧️', 'Pioviggine'],
        55: ['🌧️', 'Pioviggine forte'],
        56: ['🌨️', 'Pioviggine gelata'],
        57: ['🌨️', 'Pioviggine gelata'],
        61: ['🌧️', 'Pioggia debole'],
        63: ['🌧️', 'Pioggia'],
        65: ['🌧️', 'Pioggia forte'],
        66: ['🌨️', 'Pioggia gelata'],
        67: ['🌨️', 'Pioggia gelata'],
        71: ['❄️', 'Neve debole'],
        73: ['❄️', 'Neve'],
        75: ['❄️', 'Neve forte'],
        77: ['❄️', 'Granuli di neve'],
        80: ['🌦️', 'Rovesci'],
        81: ['🌦️', 'Rovesci'],
        82: ['⛈️', 'Rovesci forti'],
        85: ['🌨️', 'Rovesci di neve'],
        86: ['🌨️', 'Rovesci di neve'],
        95: ['⛈️', 'Temporale'],
        96: ['⛈️', 'Temporale e grandine'],
        99: ['⛈️', 'Temporale e grandine']
      };

      function wmo(code) { return WMO[code] || ['❓', '—']; }

      var chart = null;
      var data = null;
      var selectedDay = null;   // null = the whole week

      var statusEl = document.getElementById('status');
      var stripEl = document.getElementById('day-strip');

      /** "2026-08-29T14:00" -> Date. Already local: we asked for Europe/Rome. */
      function parse(s) { return new Date(s.replace(' ', 'T')); }

      function dayName(d, i) {
        if (i === 0) return 'Oggi';
        if (i === 1) return 'Domani';
        var n = d.toLocaleDateString('it-IT', { weekday: 'short' });
        return n.charAt(0).toUpperCase() + n.slice(1);
      }

      function hhmm(s) { return s.slice(11, 16); }

      function buildStrip() {
        var d = data.daily;
        stripEl.innerHTML = '';
        d.time.forEach(function (day, i) {
          var w = wmo(d.weather_code[i]);
          var mm = d.precipitation_sum[i];
          var prob = d.precipitation_probability_max[i];
          var el = document.createElement('div');
          el.className = 'day-card' + (selectedDay === i ? ' active' : '');
          el.innerHTML =
            '<div class="day-name">' + dayName(parse(day + 'T00:00'), i) + '</div>' +
            '<div class="day-glyph" title="' + w[1] + '">' + w[0] + '</div>' +
            '<div class="day-temp"><span class="tmax">' + Math.round(d.temperature_2m_max[i]) + '°</span>' +
            ' / <span class="tmin">' + Math.round(d.temperature_2m_min[i]) + '°</span></div>' +
            '<div class="day-rain">' +
            ((mm && mm >= 0.05) ? mm.toFixed(1) + ' mm' : '') +
            ((prob === null || prob === undefined) ? '' : ' <span style="color:var(--text-faint)">' + prob + '%</span>') +
            '</div>' +
            '<div class="day-sun">↑' + hhmm(d.sunrise[i]) + ' ↓' + hhmm(d.sunset[i]) + '</div>';
          el.addEventListener('click', function () {
            // Clicking the selected day again goes back to the full week.
            selectedDay = (selectedDay === i) ? null : i;
            buildStrip();
            drawChart();
          });
          stripEl.appendChild(el);
        });
      }

      /** Slice of the hourly series to plot, given the day selection. */
      function range() {
        var t = data.hourly.time;
        if (selectedDay === null) return [0, t.length];
        var day = data.daily.time[selectedDay];
        var from = -1, to = t.length;
        for (var i = 0; i < t.length; i++) {
          if (t[i].slice(0, 10) === day) { if (from < 0) from = i; to = i + 1; }
        }
        return from < 0 ? [0, t.length] : [from, to];
      }

      function drawChart() {
        var h = data.hourly;
        var r = range();
        var times = h.time.slice(r[0], r[1]);
        var whole = selectedDay === null;

        var labels = times.map(function (s) {
          var d = parse(s);
          return whole
            ? d.toLocaleDateString('it-IT', { weekday: 'short' }) + ' ' + s.slice(11, 13) + 'h'
            : s.slice(11, 16);
        });

        var cfg = {
          data: {
            labels: labels,
            datasets: [
              {
                type: 'bar', label: 'Pioggia (mm)', yAxisID: 'mm',
                data: h.precipitation.slice(r[0], r[1]),
                backgroundColor: 'rgba(59, 130, 246, 0.45)',
                borderColor: 'rgba(59, 130, 246, 0.9)', borderWidth: 1, order: 3
              },
              {
                type: 'line', label: 'Probabilità (%)', yAxisID: 'pct',
                data: h.precipitation_probability.slice(r[0], r[1]),
                borderColor: '#14b8a6', backgroundColor: 'rgba(20, 184, 166, 0.10)',
                borderWidth: 1.5, borderDash: [4, 3], pointRadius: 0, tension: 0.3,
                fill: true, order: 2
              },
              {
                type: 'line', label: 'Temperatura (°C)', yAxisID: 'temp',
                data: h.temperature_2m.slice(r[0], r[1]),
                borderColor: '#ef4444', borderWidth: 2, pointRadius: 0,
                tension: 0.35, fill: false, order: 1
              },
              {
                // Off by default: useful now and then, but it crowds the plot.
                type: 'line', label: 'Vento (km/h)', yAxisID: 'wind',
                data: h.wind_speed_10m.slice(r[0], r[1]),
                borderColor: '#f59e0b', borderWidth: 1.2, pointRadius: 0,
                tension: 0.3, fill: false, hidden: true, order: 4
              }
            ]
          },
          options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { labels: { color: '#6b7280', boxWidth: 12, font: { size: 11 } } },
              tooltip: {
                callbacks: {
                  title: function (items) {
                    return parse(times[items[0].dataIndex]).toLocaleString('it-IT',
                      { weekday: 'long', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
                  },
                  afterBody: function (items) {
                    return wmo(h.weather_code[r[0] + items[0].dataIndex])[1];
                  }
                }
              }
            },
            scales: {
              x: {
                ticks: {
                  color: '#6b7280', maxRotation: 0, autoSkip: true,
                  maxTicksLimit: whole ? 14 : 12, font: { size: 10 }
                },
                grid: { color: 'rgba(0,0,0,0.06)' }
              },
              temp: {
                position: 'left', title: { display: true, text: '°C', color: '#ef4444' },
                ticks: { color: '#ef4444', font: { size: 10 } },
                grid: { color: 'rgba(0,0,0,0.06)' }
              },
              mm: {
                position: 'right', beginAtZero: true,
                title: { display: true, text: 'mm', color: '#3b82f6' },
                ticks: { color: '#3b82f6', font: { size: 10 } },
                grid: { display: false }
              },
              // Hidden axes: the probability and the wind share the plot without
              // adding two more rulers to read.
              pct: { display: false, min: 0, max: 100 },
              wind: { display: false, beginAtZero: true }
            }
          }
        };

        if (chart) chart.destroy();
        chart = new Chart(document.getElementById('chart'), cfg);
      }

      function load() {
        fetch(URL, { cache: 'no-store' })
          .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
          })
          .then(function (j) {
            if (!j || !j.hourly || !j.daily) throw new Error('payload');
            data = j;
            statusEl.style.display = 'none';
            document.getElementById('subtitle').textContent =
              'Modello Open-Meteo · risoluzione oraria · aggiornato ' +
              new Date().toLocaleTimeString('it-IT', { hour12: false });
            buildStrip();
            drawChart();
          })
          .catch(function () {
            statusEl.style.display = '';
            statusEl.textContent = 'Previsione non disponibile';
          });
      }

      load();
      setInterval(function () { if (!document.hidden) load(); }, REFRESH_MS);
    })();
  </script>
</body>

</html>
