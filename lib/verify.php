<?php
// lib/verify.php
//
// Верификация по ВЕКТОРУ + тренд дельты (ускорение/замедление).
//
// Возвращает на строку:
//   time          — дата
//   delta         — прогноз (в %, из CSV)
//   expected_dir  — знак дельты (↑/↓/→), для совместимости
//   delta_trend   — тренд дельты: ↑ если delta[i] > delta[i-1], ↓ если <, → если =
//   delta_change  — числовое изменение дельты, п.п.
//   prev_delta    — предыдущая дельта (для контекста)
//   actual_return — фактическое day-over-day изменение, %
//   vector_dev    — actual_return − delta, п.п.
//   fact          — фактическая цена закрытия
//   status        — сбылся / частично / не сбылся / ожидается
//   deviation_pct — алиас vector_dev (backward-compat)
//
// Сводные метрики:
//   hit_rate, pearson, count, avg_vector_dev, mape(alias)

function verify_forecast($market, $ticker, $points, $value_type) {
    $history_bars = [];
    try {
        $res = history_get($market, $ticker, 3650, 'auto');
        $history_bars = $res['data'] ?? [];
    } catch (Throwable $e) {}

    if (empty($history_bars) || empty($points)) {
        return [
            'rows' => [], 'hit_rate' => null, 'pearson' => null,
            'count' => 0, 'mape' => null, 'avg_vector_dev' => null,
        ];
    }

    usort($history_bars, function ($a, $b) {
        return strcmp((string)$a['time'], (string)$b['time']);
    });

    $prices   = [];
    $prev_map = [];
    $n_bars   = count($history_bars);
    for ($i = 0; $i < $n_bars; $i++) {
        $d = $history_bars[$i]['time'];
        $prices[$d] = (float)$history_bars[$i]['value'];
        if ($i > 0) {
            $prev_map[$d] = (float)$history_bars[$i - 1]['value'];
        }
    }

    $last_history_date = end($history_bars)['time'];
// ← было ±90 дней, стало «вся доступная история + горизонт прогноза»
$limit_past   = date('Y-m-d', strtotime($last_history_date . ' -3650 days')); // 10 лет назад
$limit_future = date('Y-m-d', strtotime($last_history_date . ' +365 days'));  // 1 год вперёд

    $rows             = [];
    $count            = 0;
    $hits             = 0;
    $sum_abs_vec_dev  = 0.0;
    $forecast_deltas  = [];
    $actual_returns   = [];

    // ← NEW: для отслеживания тренда дельты
    $prev_delta = null;

    foreach ($points as $p) {
        $date = $p['time'];
        if ($date < $limit_past || $date > $limit_future) continue;

        $forecast_delta = (float)$p['value'];

        // ← NEW: тренд дельты относительно предыдущей точки
        if ($prev_delta === null) {
            // Первая точка — стрелка по знаку дельты
            $delta_trend  = $forecast_delta > 0 ? '↑' : ($forecast_delta < 0 ? '↓' : '→');
            $delta_change = null;
        } else {
            $delta_change = round($forecast_delta - $prev_delta, 4);
            $delta_trend  = $delta_change > 0 ? '↑' : ($delta_change < 0 ? '↓' : '→');
        }

        $fact = $prices[$date]   ?? null;
        $prev = $prev_map[$date] ?? null;

        $actual_return = null;
        $vector_dev    = null;
        $status        = 'ожидается';

        if ($fact !== null && $prev !== null && $prev > 0) {
            $actual_return = ($fact - $prev) / $prev * 100.0;

            $fdir = ($forecast_delta > 0) ? 1 : (($forecast_delta < 0) ? -1 : 0);
            $adir = ($actual_return  > 0) ? 1 : (($actual_return  < 0) ? -1 : 0);

            $same_dir      = ($fdir === $adir) && $fdir !== 0;
            $achieved_half = $same_dir && (abs($actual_return) >= abs($forecast_delta) * 0.5);

            if ($achieved_half) {
                $status = 'сбылся (успех)';
                $hits++;
            } elseif ($same_dir) {
                $status = 'частично (слабый)';
            } elseif ($fdir === 0) {
                $status = 'нейтрально';
            } else {
                $status = 'не сбылся (убыток)';
            }

            $vector_dev = round($actual_return - $forecast_delta, 2);
            $sum_abs_vec_dev += abs($vector_dev);

            $count++;
            $forecast_deltas[] = $forecast_delta;
            $actual_returns[]  = $actual_return;
        }

        $rows[] = [
            'time'          => $date,
            'delta'         => round($forecast_delta, 4),
            'expected_dir'  => $forecast_delta > 0 ? '↑' : ($forecast_delta < 0 ? '↓' : '→'),
            'delta_trend'   => $delta_trend,
            'delta_change'  => $delta_change,
            'prev_delta'    => $prev_delta !== null ? round($prev_delta, 4) : null,
            'actual_return' => $actual_return !== null ? round($actual_return, 2) : null,
            'vector_dev'    => $vector_dev,
            'deviation_pct' => $vector_dev,
            'fact'          => $fact,
            'status'        => $status,
        ];

        $prev_delta = $forecast_delta;
    }

    // Pearson между delta и actual_return
    $pearson = null;
    if ($count > 2) {
        $n   = $count;
        $sx  = array_sum($forecast_deltas);
        $sy  = array_sum($actual_returns);
        $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sxy += $forecast_deltas[$i] * $actual_returns[$i];
            $sxx += $forecast_deltas[$i] ** 2;
            $syy += $actual_returns[$i]  ** 2;
        }
        $num = $n * $sxy - $sx * $sy;
        $den = sqrt(($n * $sxx - $sx ** 2) * ($n * $syy - $sy ** 2));
        if ($den != 0) $pearson = round($num / $den, 3);
    }

    $avg_vec_dev = $count > 0 ? round($sum_abs_vec_dev / $count, 2) : null;

    return [
        'rows'           => $rows,
        'hit_rate'       => $count > 0 ? round($hits / $count, 4) : null,
        'pearson'        => $pearson,
        'count'          => $count,
        'avg_vector_dev' => $avg_vec_dev,
        'mape'           => $avg_vec_dev,
    ];
}