<?php
/**
 * forecast_lib.php
 * --------------------------------------------------------------------------
 * Shared forecast logic so the dashboard (index.php) and the tuning page
 * (feedback.php) produce the SAME prediction from the SAME inputs. Keeping
 * this in one place means a threshold change is applied everywhere at once
 * and the feedback we log is always "what the dashboard actually showed".
 *
 * Two entry points:
 *   gatherForecastInputs($link) -> array of the raw signals the rule engine sees
 *   computeForecast($in)        -> ['icon','text','color'] label for those signals
 *
 * The values and thresholds mirror index.php exactly as of this writing.
 */

declare(strict_types=1);

/**
 * Read the latest sensor row + live pressure state and assemble every signal
 * the forecast ladder needs. Returns a flat associative array (all floats/ints)
 * so it can be both fed to computeForecast() and stored verbatim for tuning.
 */
function gatherForecastInputs(mysqli $link): array {
    $now = time();

    /* ---- latest sensor sample ---- */
    $result = $link->query("SELECT `temperatura`,`data`,`tombra`,`hombra`,`power`,`tMobile` FROM `dati_meteo` ORDER BY `id` DESC LIMIT 1");
    $row = ($result instanceof mysqli_result) ? $result->fetch_assoc() : null;

    $safeTemp0    = (isset($row['temperatura']) && $row['temperatura'] !== null && (float)$row['temperatura'] > -50) ? (float)$row['temperatura'] : -100.0;
    $safeTombra0  = (isset($row['tombra'])      && $row['tombra']      !== null && (float)$row['tombra']      > -50) ? (float)$row['tombra']      : -100.0;
    $safeTMobile0 = (isset($row['tMobile'])     && $row['tMobile']     !== null && (float)$row['tMobile']     > -50) ? (float)$row['tMobile']     : -100.0;
    $safeHombra0  = $row['hombra'] ?? '0';
    $safePower0   = (float)($row['power'] ?? 0);
    $safeData0    = (int)($row['data'] ?? $now);

    /* ---- live pressure + robust 3h trend (mirrors index.php) ---- */
    $stateFile       = '/dev/shm/thermo_data/state.json';
    $presHistoryFile = '/dev/shm/thermo_data/pres_history.csv';
    $safePres0       = 1013.0;
    $presTrend3h     = 0.0;
    $presTrendValid  = false;

    if (file_exists($stateFile)) {
        $stateJson = json_decode((string)file_get_contents($stateFile), true);
        if (isset($stateJson['pres'])) $safePres0 = (float)$stateJson['pres'];
    }

    $pHist = [];
    if (file_exists($presHistoryFile)) {
        foreach (file($presHistoryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $pr) {
            $pParts = explode(',', $pr);
            if (count($pParts) >= 2 && is_numeric($pParts[0]) && is_numeric($pParts[1])) {
                $pHist[] = [(int)$pParts[0], (float)$pParts[1]];
            }
        }
    }

    $presMedianAround = function (int $centerTs, int $halfWin) use ($pHist) {
        $vals = [];
        foreach ($pHist as $r) {
            if (abs($r[0] - $centerTs) <= $halfWin) $vals[] = $r[1];
        }
        if (!$vals) return null;
        sort($vals);
        $n = count($vals);
        return ($n % 2) ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2.0;
    };

    if ($pHist) {
        $pNow = $presMedianAround($now, 1200);
        $pOld = $presMedianAround($now - 10800, 1800);
        if ($pNow !== null && $pOld !== null) {
            $presTrend3h    = $pNow - $pOld;
            $presTrendValid = true;
        }
    }

    $mslp    = $safePres0 + 31.8 - 2.5;
    $outTemp = ($safeTMobile0 > -99) ? $safeTMobile0 : $safeTemp0;
    $humi    = is_numeric($safeHombra0) ? (float)$safeHombra0 : 0.0;

    /* ---- PV as clear-sky / sky-now proxy (mirrors index.php) ---- */
    $hourWindows = [];
    for ($k = 1; $k <= 7; $k++) {
        $lo = $now - ($k * 86400) - 1800;
        $hi = $now - ($k * 86400) + 1800;
        $hourWindows[] = "(data BETWEEN {$lo} AND {$hi})";
    }
    $qHourPeak = "SELECT MAX(power + 0) AS hour_peak FROM dati_meteo WHERE (" . implode(' OR ', $hourWindows) . ")";
    $resHP     = $link->query($qHourPeak);
    $hourPeak  = ($resHP && $rHP = $resHP->fetch_assoc()) ? (float)($rHP['hour_peak'] ?? 0) : 0.0;
    $sunFrac   = ($hourPeak > 20 && $safePower0 >= 0) ? min(1.0, $safePower0 / $hourPeak) : null;

    return [
        'now'            => $now,
        'data'           => $safeData0,
        'pres'           => $safePres0,
        'mslp'           => $mslp,
        'trend3h'        => $presTrend3h,
        'trend_valid'    => $presTrendValid,
        'humi'           => $humi,
        't_shade'        => $outTemp,      // tMobile (true air temp) with sun-probe fallback
        't_sun'          => $safeTemp0,
        't_interno'      => $safeTombra0,
        'pv'             => $safePower0,
        'hour_peak'      => $hourPeak,
        'sun_frac'       => $sunFrac,      // null when no clear-sky reference (night)
    ];
}

/**
 * The forecast rule ladder. Identical logic and thresholds to index.php.
 * Input keys: mslp, trend3h, humi, t_shade (outTemp). Returns the label.
 */
function computeForecast(array $in): array {
    $mslp        = (float)$in['mslp'];
    $presTrend3h = (float)$in['trend3h'];
    $humi        = (float)$in['humi'];
    $outTemp     = (float)$in['t_shade'];

    $humiValid = ($humi > 0);
    $moistAir  = (!$humiValid || $humi >= 70);
    $dryAir    = ($humiValid && $humi < 55);

    $forecast = ['icon' => 'cloud', 'text' => 'Variabile', 'color' => 'var(--text-muted)'];

    $snowLikely = false;
    if ($outTemp > -99) {
        if ($outTemp <= 1.5 && $presTrend3h <= -0.3 && $humi >= 75) $snowLikely = true;
        if ($outTemp <= 0.0 && $presTrend3h <= -0.2 && $humi >= 65) $snowLikely = true;
    }
    $stormLikely = ($presTrend3h <= -1.5 && $moistAir);

    if ($snowLikely) {
        $forecast = ['icon' => 'snow', 'text' => 'Neve', 'color' => 'var(--accent-ice)'];
    } elseif ($stormLikely) {
        if ($outTemp <= 1.0) {
            $forecast = ['icon' => 'snow_storm', 'text' => 'Bufera', 'color' => 'var(--accent-ice)'];
        } else {
            $forecast = ['icon' => 'storm', 'text' => 'Temporale', 'color' => 'var(--accent-red)'];
        }
    } elseif ($presTrend3h <= -0.5 && $moistAir) {
        if ($outTemp <= 1.0) {
            $forecast = ['icon' => 'sleet', 'text' => 'Nevischio', 'color' => 'var(--accent-ice)'];
        } else {
            $forecast = ['icon' => 'rain', 'text' => 'Pioggia', 'color' => 'var(--accent-blue)'];
        }
    } elseif ($presTrend3h <= -0.5 && $dryAir) {
        $forecast = ['icon' => 'cloud', 'text' => 'Nuvoloso', 'color' => 'var(--text-muted)'];
    } elseif ($humiValid && $humi >= 95 && abs($presTrend3h) < 0.4 && $mslp <= 1022 && $outTemp <= 8.0) {
        $forecast = ['icon' => 'cloud', 'text' => 'Nebbia', 'color' => 'var(--text-muted)'];
    } elseif ($mslp > 1022 && $dryAir) {
        $forecast = ['icon' => 'sun', 'text' => 'Sereno', 'color' => 'var(--accent-orange)'];
    } elseif ($mslp > 1022) {
        $forecast = ['icon' => 'partly_cloudy', 'text' => 'Velato', 'color' => 'var(--accent-orange)'];
    } elseif ($mslp > 1016 && $presTrend3h > -0.2) {
        $forecast = ['icon' => 'partly_cloudy', 'text' => 'Poco Nuvoloso', 'color' => 'var(--accent-orange)'];
    } elseif ($presTrend3h >= 0.5) {
        $forecast = ['icon' => 'partly_cloudy', 'text' => 'In Miglioramento', 'color' => 'var(--accent-orange)'];
    } elseif ($mslp < 1008) {
        if (!$moistAir) {
            $forecast = ['icon' => 'cloud', 'text' => 'Nuvoloso', 'color' => 'var(--text-muted)'];
        } elseif ($outTemp <= 1.0) {
            $forecast = ['icon' => 'sleet', 'text' => 'Instabile (freddo)', 'color' => 'var(--accent-ice)'];
        } else {
            $forecast = ['icon' => 'rain', 'text' => 'Instabile', 'color' => 'var(--accent-blue)'];
        }
    }

    return $forecast;
}

/**
 * PV-derived "sky right now" label (mirrors index.php $skyNow).
 * Returns null when there is no clear-sky reference (night / no data).
 */
function computeSkyNow(?float $sunFrac): ?array {
    if ($sunFrac === null) return null;
    if ($sunFrac >= 0.70) return ['text' => 'Sereno',        'color' => 'var(--accent-orange)'];
    if ($sunFrac >= 0.40) return ['text' => 'Poco Nuvoloso', 'color' => 'var(--accent-orange)'];
    if ($sunFrac >= 0.15) return ['text' => 'Nuvoloso',      'color' => 'var(--text-muted)'];
    return ['text' => 'Coperto', 'color' => 'var(--text-muted)'];
}
