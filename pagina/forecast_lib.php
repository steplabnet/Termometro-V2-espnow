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

/* ==========================================================================
 * FEEDBACK CORRECTION LAYER
 * --------------------------------------------------------------------------
 * Combines the rule ladder ("calculus") with the logged ground-truth
 * observations to nudge the forecast. It does NOT retrain the rules; it is a
 * lightweight weighted-analog (kernel kNN) vote:
 *
 *   1. Compare the current signals to every past feedback row and weight each
 *      by similarity (Gaussian kernel over normalized mslp/trend/humi/temp/PV).
 *   2. Vote on the observed outcome, weighted by that similarity.
 *   3. Seed the vote with the rule's own prediction as a PRIOR pseudo-count,
 *      so with little data the calculus dominates (shrinkage).
 *   4. Override the rule ONLY when the similar-evidence and confidence both
 *      clear conservative thresholds; otherwise return the rule unchanged.
 *
 * Tuned deliberately timid because the feedback set is tiny (<30 rows): it
 * takes several genuinely-similar, agreeing observations to flip a label.
 * ========================================================================== */

/** Display color for an observed-vocabulary label (mirrors feedback.php $OPTIONS). */
function observedColor(string $label): string {
    static $m = [
        'Sereno'        => 'var(--accent-orange)',
        'Poco Nuvoloso' => 'var(--accent-orange)',
        'Nuvoloso'      => 'var(--text-muted)',
        'Coperto'       => 'var(--text-muted)',
        'Nebbia'        => 'var(--text-muted)',
        'Pioggia'       => 'var(--accent-blue)',
        'Temporale'     => 'var(--accent-red)',
        'Nevischio'     => 'var(--accent-ice)',
        'Neve'          => 'var(--accent-ice)',
    ];
    return $m[$label] ?? 'var(--text-muted)';
}

/**
 * Map a rule-engine label (richer vocabulary) down to the 9-label observed
 * vocabulary, so the rule prediction can seed the feedback vote as a prior.
 */
function ruleToObserved(string $ruleText): string {
    switch ($ruleText) {
        case 'Sereno':             return 'Sereno';
        case 'Velato':             return 'Poco Nuvoloso';
        case 'Poco Nuvoloso':      return 'Poco Nuvoloso';
        case 'In Miglioramento':   return 'Poco Nuvoloso';
        case 'Variabile':          return 'Nuvoloso';
        case 'Nuvoloso':           return 'Nuvoloso';
        case 'Nebbia':             return 'Nebbia';
        case 'Pioggia':            return 'Pioggia';
        case 'Instabile':          return 'Pioggia';
        case 'Instabile (freddo)': return 'Nevischio';
        case 'Nevischio':          return 'Nevischio';
        case 'Temporale':          return 'Temporale';
        case 'Bufera':             return 'Neve';
        case 'Neve':               return 'Neve';
        default:                   return 'Nuvoloso';
    }
}

/**
 * Blend the rule forecast with the logged feedback.
 *
 * @param array $ruleForecast the output of computeForecast() (['icon','text','color'])
 * @return array {
 *   corrected:  bool    the feedback overrode the rule
 *   label:      string  final label to show (observed vocab if corrected, else rule text)
 *   color:      string  css color for `label`
 *   confidence: ?int    0..100 share of the winning label, null if no signals scored
 *   neighbors:  int     number of clearly-similar past rows
 *   evidence:   float   total similarity weight gathered
 *   reason:     string  short Italian explanation for the UI
 * }
 */
function computeForecastFeedback(mysqli $link, array $in, array $ruleForecast): array {
    // Tunables — conservative on purpose: the feedback set is tiny.
    $SCALES     = ['mslp' => 8.0, 'trend3h' => 1.0, 'humi' => 15.0, 't_shade' => 8.0, 'sun_frac' => 0.25];
    $KERNEL_MIN = 0.20;   // min kernel weight for a row to count as a "similar" neighbor
    $PRIOR_W    = 2.5;    // pseudo-weight given to the rule prediction (shrinkage strength)
    $MIN_EVID   = 1.5;    // total neighbor weight required before feedback is trusted at all
    $MIN_NEIGH  = 2;      // and at least this many clearly-similar rows
    $MIN_CONF   = 55;     // % confidence the winner must reach to override the rule

    $ruleText   = (string)$ruleForecast['text'];
    $ruleBucket = ruleToObserved($ruleText);

    $out = [
        'corrected'  => false,
        'label'      => $ruleText,
        'color'      => (string)$ruleForecast['color'],
        'confidence' => null,
        'neighbors'  => 0,
        'evidence'   => 0.0,
        'reason'     => 'Nessuna osservazione simile — solo calcolo',
    ];

    // Current signals.
    $cMslp   = (float)$in['mslp'];
    $cTrend  = (float)$in['trend3h'];
    $cTrendV = !empty($in['trend_valid']);
    $cHumi   = (float)$in['humi'];
    $cTemp   = (float)$in['t_shade'];
    $cSun    = ($in['sun_frac'] !== null) ? (float)$in['sun_frac'] : null;

    $res = $link->query(
        "SELECT observed, mslp, trend3h, trend_valid, humi, t_shade, sun_frac FROM forecast_feedback"
    );
    if (!($res instanceof mysqli_result)) return $out;

    $scores    = [];    // observed label -> summed kernel weight
    $evidence  = 0.0;   // total weight across all rows
    $neighbors = 0;     // rows with weight >= KERNEL_MIN

    while ($r = $res->fetch_assoc()) {
        $obs = (string)$r['observed'];
        if ($obs === '') continue;

        // Mean squared normalized distance over the signals both rows share.
        $d2 = 0.0; $dims = 0;
        if ($r['mslp'] !== null) {
            $d = ($cMslp - (float)$r['mslp']) / $SCALES['mslp'];       $d2 += $d * $d; $dims++;
        }
        if ($cTrendV && (int)$r['trend_valid'] === 1 && $r['trend3h'] !== null) {
            $d = ($cTrend - (float)$r['trend3h']) / $SCALES['trend3h']; $d2 += $d * $d; $dims++;
        }
        if ($cHumi > 0 && $r['humi'] !== null && (float)$r['humi'] > 0) {
            $d = ($cHumi - (float)$r['humi']) / $SCALES['humi'];        $d2 += $d * $d; $dims++;
        }
        if ($cTemp > -99 && $r['t_shade'] !== null && (float)$r['t_shade'] > -99) {
            $d = ($cTemp - (float)$r['t_shade']) / $SCALES['t_shade'];  $d2 += $d * $d; $dims++;
        }
        if ($cSun !== null && $r['sun_frac'] !== null && $r['sun_frac'] !== '') {
            $d = ($cSun - (float)$r['sun_frac']) / $SCALES['sun_frac']; $d2 += $d * $d; $dims++;
        }
        if ($dims === 0) continue;

        $w = exp(-0.5 * ($d2 / $dims));   // Gaussian kernel: 1.0 at identical signals
        if ($w < 0.05) continue;          // negligible, skip

        $scores[$obs] = ($scores[$obs] ?? 0.0) + $w;
        $evidence    += $w;
        if ($w >= $KERNEL_MIN) $neighbors++;
    }

    // Seed the rule prediction as a prior so scarce data can't flip the label.
    $scores[$ruleBucket] = ($scores[$ruleBucket] ?? 0.0) + $PRIOR_W;

    // Argmax over the weighted vote.
    $best = $ruleBucket; $bestScore = -1.0; $total = 0.0;
    foreach ($scores as $lab => $sc) {
        $total += $sc;
        if ($sc > $bestScore) { $bestScore = $sc; $best = $lab; }
    }

    $out['neighbors']  = $neighbors;
    $out['evidence']   = round($evidence, 2);
    $out['confidence'] = $total > 0 ? (int)round(100 * $bestScore / $total) : null;

    // Not enough trustworthy evidence → keep the pure calculus.
    if ($evidence < $MIN_EVID || $neighbors < $MIN_NEIGH) {
        $out['reason'] = $evidence > 0
            ? "Feedback scarso ({$neighbors} oss. simili) — prevale il calcolo"
            : 'Nessuna osservazione simile — solo calcolo';
        return $out;
    }

    if ($best !== $ruleBucket && $out['confidence'] !== null && $out['confidence'] >= $MIN_CONF) {
        $out['corrected'] = true;
        $out['label']     = $best;
        $out['color']     = observedColor($best);
        $out['reason']    = "Corretto da {$neighbors} osservazioni simili";
    } else {
        // Feedback agrees with the rule (or doesn't clearly beat it): reinforce it.
        $out['reason'] = "Confermato da {$neighbors} osservazioni simili";
    }

    return $out;
}
