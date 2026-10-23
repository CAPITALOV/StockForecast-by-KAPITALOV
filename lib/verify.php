<?php
// lib/verify.php

function verify_forecast($market, $ticker, $points, $value_type) {
    // 1. Получаем историю цен за последние 10 лет (или сколько есть)
    $history_bars = [];
    try {
        $res = history_get($market, $ticker, 3650, 'auto');
        $history_bars = $res['data'] ?? [];
    } catch (Throwable $e) {}

    if (empty($history_bars) || empty($points)) {
        return ['rows' => [], 'mape' => null, 'hit_rate' => null, 'pearson' => null, 'count' => 0];
    }

    // 2. Карта фактических цен (date => value)
    $actual_map = [];
    foreach ($history_bars as $bar) {
        $actual_map[$bar['time']] = (float)$bar['value'];
    }
    
    $last_history_date = end($history_bars)['time'];

    // Окно: -90 дней до конца истории, +90 дней после
    $limit_past = date('Y-m-d', strtotime($last_history_date . ' -90 days'));
    $limit_future = date('Y-m-d', strtotime($last_history_date . ' +90 days'));

    $rows = [];
    $count_verified = 0;
    $hits = 0;
    $sum_abs_dev = 0;
    
    $forecast_arr = [];
    $actual_arr = [];

    // Базовая цена (находим цену за день ДО начала прогноза)
    $base_price = (float)$history_bars[0]['value'];
    $first_forecast_date = $points[0]['time'] ?? null;
    
    if ($first_forecast_date) {
        foreach ($history_bars as $bar) {
            if ($bar['time'] < $first_forecast_date) {
                $base_price = (float)$bar['value'];
            } else {
                break;
            }
        }
    }

    $current_forecast_price = $base_price;
    $prev_actual_price = $base_price;

    foreach ($points as $p) {
        $date = $p['time'];
        $val = (float)$p['value'];

        // Ограничиваем окно +/- 90 дней от конца истории
        if ($date < $limit_past || $date > $limit_future) {
            continue;
        }

        // Пересчитываем прогнозную цену из дельты (сложный процент)
        if ($value_type === 'delta') {
            $current_forecast_price = $current_forecast_price * (1 + $val / 100);
            $forecast_price = $current_forecast_price;
        } else {
            $forecast_price = $val;
        }

        $status = 'ожидается';
        $fact = null;
        $deviation = null;

        if (isset($actual_map[$date])) {
            $fact = $actual_map[$date];
            $deviation = (($forecast_price - $fact) / $fact) * 100;
            $abs_dev = abs($deviation);
            
            $actual_delta = (($fact - $prev_actual_price) / $prev_actual_price) * 100;
            $forecast_delta = $val;

            // Векторный анализ (правило 50%)
            $same_direction = ($actual_delta > 0 && $forecast_delta > 0) || ($actual_delta < 0 && $forecast_delta < 0);
            $achieved_half = abs($actual_delta) >= abs($forecast_delta) * 0.5;

            if ($same_direction && $achieved_half) {
                $status = 'сбылся (успех)';
                $hits++;
            } elseif ($same_direction) {
                $status = 'частично (слабый)';
            } else {
                $status = 'не сбылся (убыток)';
            }

            $count_verified++;
            $sum_abs_dev += $abs_dev;
            
            $forecast_arr[] = $forecast_price;
            $actual_arr[] = $fact;

            $prev_actual_price = $fact;
        }

        $rows[] = [
            'time' => $date,
            'delta' => round($val, 4),
            'forecast' => round($forecast_price, 2),
            'fact' => $fact !== null ? round($fact, 2) : null,
            'deviation_pct' => $deviation !== null ? round($deviation, 2) : null,
            'status' => $status
        ];
    }

    // Корреляция Пирсона
    $pearson = null;
    if ($count_verified > 2) {
        $n = $count_verified;
        $sum_x = array_sum($forecast_arr);
        $sum_y = array_sum($actual_arr);
        $sum_xy = 0; $sum_x2 = 0; $sum_y2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sum_xy += $forecast_arr[$i] * $actual_arr[$i];
            $sum_x2 += pow($forecast_arr[$i], 2);
            $sum_y2 += pow($actual_arr[$i], 2);
        }
        
        $num = ($n * $sum_xy) - ($sum_x * $sum_y);
        $den = sqrt(($n * $sum_x2 - pow($sum_x, 2)) * ($n * $sum_y2 - pow($sum_y, 2)));
        
        if ($den != 0) {
            $pearson = round($num / $den, 2);
        }
    }

    return [
        'rows' => $rows,
        'mape' => $count_verified > 0 ? round($sum_abs_dev / $count_verified, 2) : null,
        'hit_rate' => $count_verified > 0 ? $hits / $count_verified : null,
        'pearson' => $pearson,
        'count' => $count_verified
    ];
}