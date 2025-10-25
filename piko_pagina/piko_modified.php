<?php
// ---- Local API for state.json ----
// Query: ?api=read   -> returns {"actualTemp":..,"cald":..,"setPoint":..}
// POST:  setPoint=.. -> updates setPoint in state.json (keeps other fields)
if (isset($_GET['api']) && $_GET['api'] === 'read') {
  header('Content-Type: application/json; charset=utf-8');
  $stateFile = __DIR__ . '/state.json';
  if (!file_exists($stateFile)) {
    // Initialize a default state.json if missing
    $default = ['actualTemp' => 20, 'cald' => 0, 'setPoint' => 20];
    file_put_contents($stateFile, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode($default);
    exit;
  }
  $raw = file_get_contents($stateFile);
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    $data = ['actualTemp' => 20, 'cald' => 0, 'setPoint' => 20];
  }
  echo json_encode($data);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setPoint'])) {
  header('Content-Type: application/json; charset=utf-8');
  $stateFile = __DIR__ . '/state.json';
  $sp = floatval($_POST['setPoint']);
  // Clamp to a reasonable range
  if ($sp < 5)
    $sp = 5;
  if ($sp > 35)
    $sp = 35;

  $data = ['actualTemp' => 20, 'cald' => 0, 'setPoint' => 20];
  if (file_exists($stateFile)) {
    $raw = file_get_contents($stateFile);
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) {
      $data = array_merge($data, $tmp);
    }
  }
  $data['setPoint'] = $sp;

  $ok = (bool) file_put_contents($stateFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  echo json_encode(['ok' => $ok, 'state' => $data]);
  exit;
}
// ---- End local API ----
?>
<?php
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', 1);
include('../connessione.php');
?>
<!DOCTYPE html>
<html>

<head>
  <title>Page Title</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">


  <script src="../jquery/jquery.js"></script>
  <script src="../jquery/jquery-ui.min.js"></script>
  <script src="../jquery/jquery.knob.js"></script>

  <script>
    $(document).ready(function () {

      $(".dial").knob({
        'min': 10,
        'max': 26,
        'release': function (v) {

        }
      });

      $('.pulsante').button();
      $('#minus').click(function () {
      });
      $('#plus').click(function () {
        var set_temp = $('#setPoint').val();
        console.log("plus:" + set_temp);
        set_temp++;
        console.log("plus:" + set_temp);
        $('#setPoint').val(set_temp);
        set_ajax(1, set_temp);


      });
      $('#minus').click(function () {
        var set_temp = $('#setPoint').val();
        set_temp--;
        $('#setPoint').val(set_temp);
        set_ajax(1, set_temp);

      });

      $('.preset').click(function () {
        var temp = $(this).attr('set');
        var status = $(this).attr('status');
        var btn_setPoint = document.getElementById("setPoint").value;
        console.log("setpoint" + btn_setPoint);
        $('#setPoint').val(temp);

        set_ajax(status, temp);
      });

      meteo_ajax();

    });
  </script>
  <link href="../jquery/jquery-ui.css" rel="stylesheet" type="text/css">
  <link href="../termostatto.css?<?php echo rand() ?>" rel="stylesheet" type="text/css">

</head>

<body>
  <div class="colonna" align="center">
    <div>
      <p class="testo">Temp.</p>
      <p class="testo" id="tempmis" style="font-weight:bold">Wait...</p>
      <br><span id="times" style="color:#FFF"></span>
    </div>
    <div class="pulsante plusmin" set="10" status="5" id="minus">-</div>
    <img src="" id="caldaia" height="100px">
    <div class="pulsante plusmin" set="10" status="6" id="plus">+</div>
    <div><input type="text" value="26" class="dial" id="setPoint"></div>
    <div style="margin-top:10px">
      <?php
      // nomi puslanti
      $query = "SELECT * FROM `piko_btn`";
      $result = $link->query($query);

      ?>
      <?php
      $a = 0;
      while ($row = $result->fetch_assoc()) {
        $a++;
        ?>
        <div class="pulsante preset" set="<?php echo $row['valore'] ?>" status="1"><?php echo $row['nome'] ?></div>

      <?php } ?>


    </div>
  </div>

  <script>
    // --- Local state.json integration (read + write setPoint) ---
    (function () {
      function getJSON(url) { return fetch(url, { cache: "no-store" }).then(r => r.json()); }
      function postForm(url, data) {
        const body = new URLSearchParams(data);
        return fetch(url, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body })
          .then(r => r.json());
      }

      const spInput = document.getElementById("setPoint") || document.querySelector('input[name="setPoint"]') || document.querySelector('.dial');
      const tempEl = document.getElementById("tempmis") || document.getElementById("temperatura") || document.querySelector('#temp,#temperatura,#actualTemp,.actualTemp');
      const statusEl = document.getElementById("status") || document.querySelector('#status,#cald,.cald');
      const imgEl = document.getElementById("caldaia");

      // Clamp helper (server also clamps, but do it client-side too)
      function clamp(v, min, max) { v = Number(v); if (isNaN(v)) v = min; return Math.max(min, Math.min(max, v)); }

      function renderState(state) {
        if (tempEl) {
          tempEl.textContent = (typeof state.actualTemp === 'number' ? state.actualTemp.toFixed(1) : state.actualTemp) + "°C";
        }
        const on = (state.cald == 1 || state.cald === "1" || state.cald === true);
        if (statusEl) {
          statusEl.textContent = on ? "Heat: ON" : "Heat: OFF";
          statusEl.classList.toggle("on", on);
          statusEl.classList.toggle("off", !on);
        }
        if (imgEl) {
          const file = on ? "caldaiaon.png" : "caldaiaoff.png";
          if (imgEl.dataset.last !== file) {
            imgEl.src = file + "?cb=" + Date.now();
            imgEl.dataset.last = file;
          }
        }
        if (spInput) {
          if (typeof jQuery !== "undefined" && jQuery.fn && jQuery.fn.trigger && jQuery(spInput).trigger) {
            jQuery(spInput).val(state.setPoint).trigger("change");
          } else {
            spInput.value = state.setPoint;
          }
        }
      }

      function loadState() {
        getJSON(location.pathname + "?api=read&ts=" + Date.now())
          .then(renderState)
          .catch(console.warn);
      }

      function hookSetpointChange() {
        if (!spInput) return;

        // If earlier code attached old handlers (set_ajax etc.), remove them to avoid conflicts.
        if (window.jQuery) {
          try { jQuery('#plus,#minus,.preset').off('click'); } catch (e) { }
        }

        let lastSent = null;
        function send(sp) {
          sp = clamp(sp, 5, 35);                // same range as PHP
          if (lastSent !== null && Number(lastSent) === Number(sp)) return;
          lastSent = sp;

          // Reflect in UI ASAP
          if (typeof jQuery !== "undefined" && jQuery(spInput).trigger) {
            jQuery(spInput).val(sp).trigger("change");
          } else {
            spInput.value = sp;
          }

          // Persist to state.json
          postForm(location.pathname, { setPoint: sp })
            .then(resp => {
              if (resp && resp.state) renderState(resp.state);
            })
            .catch(console.warn);
        }

        // Save when the input changes (knob/slider/manual)
        spInput.addEventListener("change", () => send(spInput.value));
        spInput.addEventListener("input", () => {
          clearTimeout(spInput._deb);
          spInput._deb = setTimeout(() => send(spInput.value), 300);
        });

        // Wire + / − buttons to save immediately
        const plus = document.getElementById('plus');
        const minus = document.getElementById('minus');

        if (plus) {
          plus.addEventListener('click', () => {
            const cur = parseFloat(spInput.value) || 0;
            send(cur + 1);   // change step if you prefer 0.5
          });
        }
        if (minus) {
          minus.addEventListener('click', () => {
            const cur = parseFloat(spInput.value) || 0;
            send(cur - 1);
          });
        }

        // Wire preset buttons (use attribute set="..")
        document.querySelectorAll('.preset,[data-setpoint]').forEach(el => {
          el.addEventListener('click', () => {
            const v = el.getAttribute('set') ?? el.dataset.setpoint;
            if (v != null) send(v);
          });
        });
      }

      function startPolling() {
        loadState();                  // immediate read on page load
        setInterval(loadState, 10000);
      }

      document.addEventListener("DOMContentLoaded", function () {
        startPolling();
        hookSetpointChange();
      });

      const timeEl = document.getElementById("times");  // <— add this near your other consts

      function updateClock() {
        // Always show Europe/Rome time, regardless of the device timezone
        const now = new Date();
        const fmt = new Intl.DateTimeFormat('it-IT', {
          timeZone: 'Europe/Rome',
          year: 'numeric', month: '2-digit', day: '2-digit',
          hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
        if (timeEl) timeEl.textContent = fmt.format(now).replace(',', '');
      }

      document.addEventListener("DOMContentLoaded", function () {
        startPolling();
        hookSetpointChange();

        updateClock();                 // <— add
        setInterval(updateClock, 1000); // <— add
      });

    })();



  </script>




</body>

</html>