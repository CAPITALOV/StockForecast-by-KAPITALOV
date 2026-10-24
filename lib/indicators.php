<?php
function calc_rsi(array $bars, int $period = 14): array {
    $n = count($bars);
    if ($period < 2 || $n < $period + 1) return [];
    $closes = []; $times = [];
    foreach ($bars as $b) {
        $closes[] = (float)$b['value'];
        $times[]  = (string)$b['time'];
    }
    $gains = []; $losses = [];
    for ($i = 1; $i < $n; $i++) {
        $diff = $closes[$i] - $closes[$i - 1];
        $gains[]  = $diff > 0 ? $diff : 0.0;
        $losses[] = $diff < 0 ? -$diff : 0.0;
    }
    $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
    $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;
    $rsiFn = function ($g, $l) {
        if ($l == 0.0) return $g == 0.0 ? 50.0 : 100.0;
        return 100.0 - (100.0 / (1.0 + $g / $l));
    };
    $out = [];
    $out[] = ['time' => $times[$period], 'value' => round($rsiFn($avgGain, $avgLoss), 2)];
    $totalChanges = count($gains);
    for ($i = $period; $i < $totalChanges; $i++) {
        $avgGain = ($avgGain * ($period - 1) + $gains[$i]) / $period;
        $avgLoss = ($avgLoss * ($period - 1) + $losses[$i]) / $period;
        $out[] = ['time' => $times[$i + 1], 'value' => round($rsiFn($avgGain, $avgLoss), 2)];
    }
    return $out;
}

function calc_sma(array $bars, int $period): array {
    $n = count($bars);
    if ($period < 2 || $n < $period) return [];
    $out = [];
    $sum = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sum += (float)$bars[$i]['value'];
        if ($i >= $period) $sum -= (float)$bars[$i - $period]['value'];
        if ($i >= $period - 1) {
            $out[] = ['time' => (string)$bars[$i]['time'], 'value' => round($sum / $period, 4)];
        }
    }
    return $out;
}

function calc_ema(array $bars, int $period): array {
    $n = count($bars);
    if ($period < 2 || $n < $period) return [];
    $k = 2.0 / ($period + 1);
    $sum = 0.0;
    for ($i = 0; $i < $period; $i++) $sum += (float)$bars[$i]['value'];
    $ema = $sum / $period;
    $out = [];
    $out[] = ['time' => (string)$bars[$period - 1]['time'], 'value' => round($ema, 4)];
    for ($i = $period; $i < $n; $i++) {
        $ema = ((float)$bars[$i]['value'] - $ema) * $k + $ema;
        $out[] = ['time' => (string)$bars[$i]['time'], 'value' => round($ema, 4)];
    }
    return $out;
}

function calc_macd(array $bars, int $fast = 12, int $slow = 26, int $signal = 9): array {
    $emaFast = calc_ema($bars, $fast);
    $emaSlow = calc_ema($bars, $slow);
    if (empty($emaFast) || empty($emaSlow)) return ['macd' => [], 'signal' => [], 'histogram' => []];

    $fastByDate = [];
    foreach ($emaFast as $p) $fastByDate[$p['time']] = $p['value'];
    $slowByDate = [];
    foreach ($emaSlow as $p) $slowByDate[$p['time']] = $p['value'];

    $macd = [];
    foreach ($fastByDate as $time => $fv) {
        if (!isset($slowByDate[$time])) continue;
        $macd[] = ['time' => $time, 'value' => round($fv - $slowByDate[$time], 4)];
    }
    usort($macd, function ($a, $b) { return strcmp($a['time'], $b['time']); });
    if (empty($macd)) return ['macd' => [], 'signal' => [], 'histogram' => []];

    $signalLine = calc_ema($macd, $signal);
    $signalByDate = [];
    foreach ($signalLine as $p) $signalByDate[$p['time']] = $p['value'];

    $histogram = [];
    $signalResult = [];
    $macdResult = [];
    foreach ($macd as $m) {
        $t = $m['time'];
        $macdResult[] = $m;
        if (isset($signalByDate[$t])) {
            $sig = $signalByDate[$t];
            $signalResult[] = ['time' => $t, 'value' => $sig];
            $histogram[] = ['time' => $t, 'value' => round($m['value'] - $sig, 4)];
        }
    }
    return ['macd' => $macdResult, 'signal' => $signalResult, 'histogram' => $histogram];
}

function calc_bollinger(array $bars, int $period = 20, float $mult = 2.0): array {
    $n = count($bars);
    if ($period < 2 || $n < $period) return ['sma' => [], 'upper' => [], 'lower' => []];
    $sma = []; $upper = []; $lower = [];
    for ($i = $period - 1; $i < $n; $i++) {
        $sum = 0; $sumSq = 0;
        for ($j = $i - $period + 1; $j <= $i; $j++) {
            $v = (float)$bars[$j]['value'];
            $sum += $v; $sumSq += $v * $v;
        }
        $mean = $sum / $period;
        $variance = ($sumSq / $period) - ($mean * $mean);
        $std = sqrt(max(0, $variance));
        $time = (string)$bars[$i]['time'];
        $sma[] = ['time' => $time, 'value' => round($mean, 4)];
        $upper[] = ['time' => $time, 'value' => round($mean + $mult * $std, 4)];
        $lower[] = ['time' => $time, 'value' => round($mean - $mult * $std, 4)];
    }
    return ['sma' => $sma, 'upper' => $upper, 'lower' => $lower];
}

/* ============================================================
 * Guard: average_forecast_lines объявлена ещё и в lib/forecast.php.
 * Порядок загрузки: forecast.php → indicators.php.
 * Без guard'а PHP падает с "Cannot redeclare function".
 * ============================================================ */
if (!function_exists('average_forecast_lines')) {
    function average_forecast_lines(array $a, array $b): array {
        $byDate = [];
        foreach ($a as $p) { $byDate[$p['time']][] = (float)$p['value']; }
        foreach ($b as $p) { $byDate[$p['time']][] = (float)$p['value']; }
        $out = [];
        foreach ($byDate as $date => $vals) {
            $out[] = ['time' => (string)$date, 'value' => array_sum($vals) / count($vals)];
        }
        usort($out, function ($x, $y) { return strcmp($x['time'], $y['time']); });
        return $out;
    }
}

function aggregate_bars(array $bars, string $granularity): array {
    if ($granularity === 'day' || empty($bars)) return $bars;
    $grouped = [];
    foreach ($bars as $b) {
        $date = (string)$b['time'];
        $ts = strtotime($date);
        $key = $granularity === 'week' ? date('o-W', $ts) : substr($date, 0, 7);
        $grouped[$key] = $b;
    }
    $out = array_values($grouped);
    usort($out, function ($a, $b) { return strcmp($a['time'], $b['time']); });
    return $out;
}

/* ============================================================
 * Guard: aggregate_forecast_lines тоже объявлена в lib/forecast.php.
 * ============================================================ */
if (!function_exists('aggregate_forecast_lines')) {
    function aggregate_forecast_lines(array $points, string $type, string $granularity): array {
        if ($granularity === 'day' || empty($points)) return $points;
        $grouped = [];
        foreach ($points as $p) {
            $date = (string)$p['time'];
            $ts = strtotime($date);
            $key = $granularity === 'week' ? date('o-W', $ts) : substr($date, 0, 7);
            $grouped[$key][] = $p;
        }
        $out = [];
        foreach ($grouped as $items) {
            $last = end($items);
            if ($type === 'delta') {
                $compound = 1.0;
                foreach ($items as $p) $compound *= (1.0 + (float)$p['value']);
                $out[] = ['time' => $last['time'], 'value' => $compound - 1.0];
            } else {
                $out[] = ['time' => $last['time'], 'value' => (float)$last['value']];
            }
        }
        usort($out, function ($a, $b) { return strcmp($a['time'], $b['time']); });
        return $out;
    }
}