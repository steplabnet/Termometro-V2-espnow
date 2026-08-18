<?php

/** legge la portata del Piave  */
// Read the JSON file  
$json = file_get_contents('https://api.arpa.veneto.it/REST/v1/meteo_meteogrammi_tabella?codseqst=300001781&rnd=0.26636924190339917');

// Decode the JSON file 
$json_data = json_decode($json, true);

// Display data 
$portata = ''; // Initialize variable
foreach ($json_data['data'] as $item) {
    if ($item['tipo'] == "PORT") {
        if (strtotime($item['dataora']) > time() - 700) {
            $portata = $item['valore'] . '  ';
        }
    }
}
?>

<?php
date_default_timezone_set('Europe/Rome');
include('connessione.php');

$data = time();

// Collect data
$power = $_GET['power'];
$temp = $_GET['temp'];
$humi = $_GET['humi'];
$wind = $_GET['wind'];
$rain = $_GET['rain'];
$pres = round($_GET['pres'], 0);
$chip = $_GET['chip'];
$gust = $_GET['gust'];
$tMobile = $_GET['tMobile'];
$tombra = $_GET['tombra']; // Removed echo to keep output clean, add back if needed
$hombra = $_GET['hombra'];
$tempCpu = $_GET['tempCpu'];
$fanMode = $_GET['fan'];

// Shelly Pro EM-50 (sent by meteo.py only when the meter is reachable):
//   pvPower   = photovoltaic production, W (>= 0)
//   gridPower = grid exchange, W (> 0 importing, < 0 exporting surplus)
// Both may be absent; null keeps the previous stored value instead of writing 0.
$pvPower = isset($_GET['pvPower']) && is_numeric($_GET['pvPower']) ? (float) $_GET['pvPower'] : null;
$gridPower = isset($_GET['gridPower']) && is_numeric($_GET['gridPower']) ? (float) $_GET['gridPower'] : null;

/**
 * Add a column to a table if it is not there yet.
 * Lets the Shelly columns appear on first run without a manual migration.
 */
function ensure_column(mysqli $link, string $table, string $column, string $definition): void
{
    $res = $link->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($res instanceof mysqli_result && $res->num_rows === 0) {
        $link->query("ALTER TABLE `$table` ADD `$column` $definition");
    }
}

foreach (['dati_meteo', 'dati_instant'] as $t) {
    ensure_column($link, $t, 'pvPower', 'FLOAT NULL');
    ensure_column($link, $t, 'gridPower', 'FLOAT NULL');
}

// SQL literals: a missing reading is stored as NULL in dati_meteo, and left
// untouched in dati_instant (see the UPDATE below).
$pvSql = ($pvPower === null) ? 'NULL' : "'" . $pvPower . "'";
$gridSql = ($gridPower === null) ? 'NULL' : "'" . $gridPower . "'";

// --- Existing Logic for Insert/Update ---

$sql = "SELECT * from dati_meteo ORDER by id DESC limit 1";
$result = $link->query($sql);
$row = $result->fetch_assoc();

$last = $row['data'];
$resto = $last % 600;

if ($data >= $last + 600 - $resto) {
    // Validation to prevent bad sensor readings (-50)
    if ($tombra < -50) {
        $tombra = $row['tombra'];
    }
    if ($tMobile < -50) {
        $tMobile = $row['tMobile'];
    }

    $sql = "INSERT INTO `dati_meteo` (`temperatura`, `humi`, `wind`, `rain`, `press`, `tombra`, `hombra`, `data`,chip,gust, power, cpuTemp, fanMode, tMobile, portata, pvPower, gridPower) VALUES ('$temp','$humi', '$wind', '$rain', '$pres', '$tombra', '$hombra', '$data','$chip','$gust', '$power','$tempCpu', '$fanMode', '$tMobile', '$portata', $pvSql, $gridSql)";

    // Echo kept as per your original script
    echo $sql;
    $result = $link->query($sql);
}

// Update Instant Data
$instantExtra = '';
if ($pvPower !== null) {
    $instantExtra .= ", pvPower=$pvSql";
}
if ($gridPower !== null) {
    $instantExtra .= ", gridPower=$gridSql";
}
$sql = "update dati_instant set temperatura='$temp', humi='$humi', wind='$wind', rain='$rain', press='$pres', tombra='$tombra', hombra='$hombra', data='$data', gust='$gust', power='$power', cpuTemp='$tempCpu', chip ='$chip', fan='$fanMode', tMobile='$tMobile', portata='$portata'$instantExtra WHERE id=1";
echo $sql;
$result = $link->query($sql);


// ---------------------------------------------------------
// --- NEW SECTION: SAVE MAX/MIN 24H TO JSON ---
// ---------------------------------------------------------

// 1. Calculate the timestamp for 24 hours ago (86400 seconds)
$time24hAgo = $data - 86400;

// 2. Query the database for Max and Min values within that time range.
// We use 'val + 0' or CAST to ensure SQL treats the values as numbers, 
// just in case your database columns are set to VARCHAR.
$sqlStats = "SELECT 
    MAX(temperatura + 0) as max_temp, 
    MIN(temperatura + 0) as min_temp,
    MAX(tombra + 0) as max_tombra, 
    MIN(tombra + 0) as min_tombra,
    MAX(humi + 0) as max_humi, 
    MIN(humi + 0) as min_humi,
    MAX(press + 0) as max_press, 
    MIN(press + 0) as min_press,
    MAX(wind + 0) as max_wind, 
    MAX(gust + 0) as max_gust,
    MAX(power + 0) as max_power,
    MAX(pvPower + 0) as max_pvPower,
    MAX(gridPower + 0) as max_gridPower,
    MIN(gridPower + 0) as min_gridPower
    FROM dati_meteo 
    WHERE data >= $time24hAgo";

$resultStats = $link->query($sqlStats);

if ($resultStats) {
    // Fetch the data
    $statsData = $resultStats->fetch_assoc();

    // Add the current calculation time to the array
    $statsData['calculated_at'] = date('Y-m-d H:i:s');
    $statsData['timestamp'] = $data;

    // Encode to JSON format
    $jsonStatsString = json_encode($statsData, JSON_PRETTY_PRINT);

    // Save to file (e.g., 'minmax_24h.json')
    file_put_contents('minmax_24h.json', $jsonStatsString);
}
// ---------------------------------------------------------

// ---------------------------------------------------------
// --- REGENERATE THE STATIC DASHBOARD CACHE (icache.html) ---
// The Raspberry Pi hits this endpoint every 60s, so requesting index.php here
// keeps icache.html fresh once a minute (index.php rewrites it as a side effect)
// with the data we just stored above — no separate cron job needed.
// A loopback HTTP request keeps index.php as the single source of truth and
// isolates it from this script's variables/connection.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'cesana.steplab.net';
$ctx = stream_context_create(['http' => ['timeout' => 15]]);
@file_get_contents("{$scheme}://{$host}/index.php", false, $ctx);
// ---------------------------------------------------------

?>