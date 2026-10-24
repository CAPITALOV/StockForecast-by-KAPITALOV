<?php
/**
 * lib/lines_calculator.php — расчёт трёх линий прогноза.
 *   Line 1: Delta Close (red)    — momentum
 *   Line 3: RPO (blue)           — Weighted average price
 *   Line 2: Synthetic (orange)   — admin-only blend с адаптивными весами
 */

function calc_line1_delta_close(array $closes, int $window = 14): array {
    $n = count($closes);
    $raw = array_fill(0, $n, null);
    for ($i = 1; $i < $n; $i++) {
        if ($closes[$i - 1] == 0) continue;
        $raw[$i] = ($closes[$i] - $closes[$i - 1]) / $closes[$i - 1];
    }
    $weights = range(1, $window);
    $wsum = array_sum($weights);
    $out = array_fill(0, $n, null);
    for ($i = $window - 1; $i < $n; $i++) {
        $sum = 0.0; $ok = true;
        for ($j = 0; $j < $window; $j++) {
            $v = $raw[$i - $window + 1 + $j];
            if ($v === null) { $ok = false; break; }
            $sum += $v * $weights[$j];
        }
        if ($ok) $out[$i] = $sum / $wsum;
    }
    return $out;
}

function calc_line3_rpo(array $closes, int $fast = 14, int $slow = 50, int $smoothing = 1): array {
    $n = count($closes);
    $maFast = _sma($closes, $fast);
    $maSlow = _sma($closes, $slow);
    $out = array_fill(0, $n, null);
    for ($i = 0; $i < $n; $i++) {
        if ($maFast[$i] === null || $maSlow[$i] === null || $maSlow[$i] == 0) continue;
        $out[$i] = (($maFast[$i] - $maSlow[$i]) / $maSlow[$i]) * 100.0;
    }
    if ($smoothing > 1) $out = _sma($out, $smoothing);
    return $out;
}

/**
 * @return array{synthetic: array, signals: array, z1: array, z3: array, w1: array, w3: array}
 */
function calc_line2_synthetic(
    array $line1, array $line3, array $closes,
    int $N = 60, int $L = 60, int $k = 3,
    float $beta = 0.9, float $wMin = 0.2, float $wMax = 0.8, float $theta = 0.25
): array {
    $n = count($line1);
    $z1 = _rolling_zscore($line1, $N);
    $z3 = _rolling_zscore($line3, $N);

    $ret = array_fill(0, $n, null);
    for ($i = 1; $i < $n; $i++) {
        if ($closes[$i - 1] != 0) $ret[$i] = ($closes[$i] - $closes[$i - 1]) / $closes[$i - 1];
    }

    $correct1 = array_fill(0, $n, null);
    $correct3 = array_fill(0, $n, null);
    for ($i = $k + 1; $i < $n; $i++) {
        if ($ret[$i] === null || $ret[$i] == 0) continue;
        if ($z1[$i - 1] === null || $z1[$i - 1 - $k] === null) continue;
        if ($z3[$i - 1] === null || $z3[$i - 1 - $k] === null) continue;
        $d1 = $z1[$i - 1] - $z1[$i - 1 - $k];
        $d3 = $z3[$i - 1] - $z3[$i - 1 - $k];
        $correct1[$i] = (_sign($d1) === _sign($ret[$i])) ? 1.0 : 0.0;
        $correct3[$i] = (_sign($d3) === _sign($ret[$i])) ? 1.0 : 0.0;
    }
    $hit1 = _rolling_mean($correct1, $L);
    $hit3 = _rolling_mean($correct3, $L);

    $w1 = array_fill(0, $n, 0.5);
    $w3 = array_fill(0, $n, 0.5);
    $prev = 0.5;
    for ($i = 0; $i < $n; $i++) {
        if ($hit1[$i] === null || $hit3[$i] === null) {
            $w1[$i] = 0.5; $w3[$i] = 0.5; $prev = 0.5;
            continue;
        }
        $r1 = max($hit1[$i] - 0.5, 0.0) + 0.01;
        $r3 = max($hit3[$i] - 0.5, 0.0) + 0.01;
        $raw = $r1 / ($r1 + $r3);
        $cur = $beta * $prev + (1 - $beta) * $raw;
        $cur = max($wMin, min($wMax, $cur));
        $w1[$i] = $cur;
        $w3[$i] = 1.0 - $cur;
        $prev   = $cur;
    }

    $synth = array_fill(0, $n, null);
    $signals = array_fill(0, $n, ['signal' => 'neutral', 'dir1' => 0, 'dir3' => 0, 'confidence' => 0.0]);
    for ($i = 0; $i < $n; $i++) {
        if ($z1[$i] === null || $z3[$i] === null) continue;
        $s = $w1[$i] * $z1[$i] + $w3[$i] * $z3[$i];
        $synth[$i] = $s;

        $d1 = ($i >= $k && $z1[$i - $k] !== null) ? _sign($z1[$i] - $z1[$i - $k]) : 0;
        $d3 = ($i >= $k && $z3[$i - $k] !== null) ? _sign($z3[$i] - $z3[$i - $k]) : 0;

        $sig = 'neutral';
        if ($d1 > 0 && $d3 > 0 && $s >  $theta) $sig = 'buy';
        elseif ($d1 < 0 && $d3 < 0 && $s < -$theta) $sig = 'sell';

        $signals[$i] = ['signal' => $sig, 'dir1' => $d1, 'dir3' => $d3, 'confidence' => abs($s)];
    }

    return ['synthetic' => $synth, 'signals' => $signals, 'z1' => $z1, 'z3' => $z3, 'w1' => $w1, 'w3' => $w3];
}

// ---------- helpers ----------

function _sma(array $d, int $p): array {
    $n = count($d);
    $out = array_fill(0, $n, null);
    for ($i = $p - 1; $i < $n; $i++) {
        $sum = 0.0; $ok = true;
        for ($j = $i - $p + 1; $j <= $i; $j++) {
            if ($d[$j] === null) { $ok = false; break; }
            $sum += $d[$j];
        }
        if ($ok) $out[$i] = $sum / $p;
    }
    return $out;
}

function _rolling_zscore(array $d, int $n): array {
    $N = count($d);
    $out = array_fill(0, $N, null);
    for ($i = $n - 1; $i < $N; $i++) {
        $w = []; $ok = true;
        for ($j = $i - $n + 1; $j <= $i; $j++) {
            if ($d[$j] === null) { $ok = false; break; }
            $w[] = $d[$j];
        }
        if (!$ok) continue;
        $mean = array_sum($w) / $n;
        $var = 0.0;
        foreach ($w as $v) $var += ($v - $mean) ** 2;
        $std = sqrt($var / $n);
        if ($std == 0) { $out[$i] = 0.0; continue; }
        $out[$i] = ($d[$i] - $mean) / $std;
    }
    return $out;
}

function _rolling_mean(array $d, int $n): array {
    $N = count($d);
    $out = array_fill(0, $N, null);
    for ($i = $n - 1; $i < $N; $i++) {
        $sum = 0.0; $cnt = 0;
        for ($j = $i - $n + 1; $j <= $i; $j++) {
            if ($d[$j] !== null) { $sum += $d[$j]; $cnt++; }
        }
        if ($cnt == $n) $out[$i] = $sum / $n;
    }
    return $out;
}

function _sign($x): int { return $x <=> 0; }