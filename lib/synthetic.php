<?php
declare(strict_types=1);

/**
 * lib/synthetic.php — Адаптивная Линия 2 (Синтетическая) для StockForecast.
 * 
 * Включает:
 * - Сглаживание шума (SMA-5).
 * - Нормализацию (rolling z-score).
 * - Адаптивные веса на основе исторической точности направления.
 * - Генерацию торговых сигналов и уровней уверенности.
 */

// Настройки по умолчанию
const SYN_PARAMS = [
    'sma_window'       => 5,    // Сглаживание шума (5 торговых дней)
    'zscore_window'    => 60,   // Окно для нормализации (z-score)
    'weight_window'    => 60,   // Окно для оценки точности линий
    'slope_lookback'   => 5,    // Оценка тренда (за сколько дней)
    'horizon'          => 5,    // Горизонт прогноза (5 дней)
    'smooth_beta'      => 0.9,  // Сглаживание весов (чем ближе к 1, тем плавнее)
    'w_min'            => 0.2,  // Мин. вес (20%)
    'w_max'            => 0.8,  // Макс. вес (80%)
    'signal_threshold' => 0.5,  // Порог z-score для сигнала
];

/**
 * Вспомогательная функция: Простое скользящее среднее (SMA)
 */
function syn_sma(array $x, int $w): array {
    $n = count($x); 
    $out = array_fill(0, $n, null);
    if ($w <= 0 || $n < $w) return $out;
    for ($i = $w - 1; $i < $n; $i++) {
        $sum = 0.0;
        for ($j = $i - $w + 1; $j <= $i; $j++) {
            $sum += $x[$j];
        }
        $out[$i] = $sum / $w;
    }
    return $out;
}

/**
 * Вспомогательная функция: Скользящее среднее (для внутренних расчетов)
 */
function syn_rolling_mean(array $x, int $w): array {
    $n = count($x); $out = array_fill(0, $n, null);
    if ($w <= 0 || $n < $w) return $out;
    for ($i = $w - 1; $i < $n; $i++) {
        $s = 0.0; $ok = true;
        for ($j = $i - $w + 1; $j <= $i; $j++) {
            if ($x[$j] === null) { $ok = false; break; }
            $s += $x[$j];
        }
        if ($ok) $out[$i] = $s / $w;
    }
    return $out;
}

/**
 * Вспомогательная функция: Стандартное отклонение
 */
function syn_rolling_std(array $x, int $w, ?array $means = null): array {
    $n = count($x); $out = array_fill(0, $n, null);
    if ($w <= 1 || $n < $w) return $out;
    $means ??= syn_rolling_mean($x, $w);
    for ($i = $w - 1; $i < $n; $i++) {
        if ($means[$i] === null) continue;
        $m = $means[$i]; $acc = 0.0;
        for ($j = $i - $w + 1; $j <= $i; $j++) {
            $d = $x[$j] - $m;
            $acc += $d * $d;
        }
        $out[$i] = sqrt($acc / $w); // ddof=0
    }
    return $out;
}

/**
 * Нормализация (Z-score)
 */
function syn_zscore(array $x, int $w): array {
    $n = count($x);
    $means = syn_rolling_mean($x, $w);
    $stds  = syn_rolling_std($x, $w, $means);
    $out = array_fill(0, $n, 0.0);
    for ($i = 0; $i < $n; $i++) {
        if ($means[$i] !== null && $stds[$i] !== null && $stds[$i] > 0) {
            $out[$i] = ($x[$i] - $means[$i]) / $stds[$i];
        }
    }
    return $out;
}

/**
 * Оценка исторической точности направления
 */
function syn_directional_accuracy(array $x, int $w, int $k, int $h): array {
    $n = count($x);
    $hits = array_fill(0, $n, null);
    for ($i = $k; $i + $h < $n; $i++) {
        $sp = ($x[$i] <=> $x[$i - $k]);
        $sf = ($x[$i + $h] <=> $x[$i]);
        $hits[$i] = ($sp === 0 || $sf === 0) ? 0.5 : (($sp === $sf) ? 1.0 : 0.0);
    }
    // Сдвигаем во времени, чтобы избежать заглядывания в будущее
    $shifted = array_fill(0, $n, null);
    for ($i = 0; $i + $h < $n; $i++) {
        if ($hits[$i] !== null) $shifted[$i + $h] = $hits[$i];
    }
    return syn_rolling_mean($shifted, $w);
}

/**
 * Главная функция: Расчет Линии 2 и сигналов
 * 
 * @param array $dates Массив дат (Y-m-d)
 * @param array $x1 Значения Линии 1 (дельты)
 * @param array $x3 Значения Линии 3 (дельты)
 * @param array $overrides Переопределение параметров
 * @return array Массив с результатами для каждой даты
 */
function syn_build(array $dates, array $x1, array $x3, array $overrides = []): array {
    $p = array_merge(SYN_PARAMS, $overrides);
    $n = count($dates);
    if (count($x1) !== $n || count($x3) !== $n) {
        throw new InvalidArgumentException('dates, x1, x3 должны быть одинаковой длины');
    }

    // 1. Сглаживаем шум
    $x1_smooth = syn_sma($x1, $p['sma_window']);
    $x3_smooth = syn_sma($x3, $p['sma_window']);

    // 2. Считаем Z-score
    $z1 = syn_zscore($x1_smooth, $p['zscore_window']);
    $z3 = syn_zscore($x3_smooth, $p['zscore_window']);

    // 3. Считаем адаптивные веса
    $a1 = syn_directional_accuracy($x1_smooth, $p['weight_window'], $p['slope_lookback'], $p['horizon']);
    $a3 = syn_directional_accuracy($x3_smooth, $p['weight_window'], $p['slope_lookback'], $p['horizon']);
    
    $alpha = 1.0 - $p['smooth_beta'];
    $prev_w1 = 0.5;
    
    $result = [];
    $k = $p['slope_lookback'];

    for ($i = 0; $i < $n; $i++) {
        // Если данных для расчета недостаточно (начало графика) — ставим нейтральные значения
        if ($x1_smooth[$i] === null || $x3_smooth[$i] === null) {
            $result[] = [
                'date' => $dates[$i], 'z1' => 0.0, 'z3' => 0.0, 'w1' => 0.5, 'w3' => 0.5,
                'synthetic' => 0.0, 'dir1' => 0, 'dir3' => 0, 'signal' => 'neutral', 'confidence' => 0.0
            ];
            continue;
        }

        // Расчет веса w1
        if ($a1[$i] !== null && $a3[$i] !== null) {
            $r1 = max($a1[$i] - 0.5, 0.0) + 1e-6;
            $r3 = max($a3[$i] - 0.5, 0.0) + 1e-6;
            $raw_w1 = $r1 / ($r1 + $r3);
        } else {
            $raw_w1 = 0.5;
        }
        $prev_w1 = $alpha * $raw_w1 + (1.0 - $alpha) * $prev_w1;
        $w1 = max($p['w_min'], min($p['w_max'], $prev_w1));
        $w3 = 1.0 - $w1;

        // Расчет синтетического индекса
        $synthetic = $w1 * $z1[$i] + $w3 * $z3[$i];

        // Определение направления тренда (5 дней)
        $dir1 = 0; $dir3 = 0;
        if ($i >= $k && $x1_smooth[$i - $k] !== null) {
            $dir1 = $x1_smooth[$i] <=> $x1_smooth[$i - $k];
            $dir3 = $x3_smooth[$i] <=> $x3_smooth[$i - $k];
        }

        // Генерация сигнала
        $signal = 'neutral';
        
        // Логика "Покупай на дне": если z-score сильно отрицательный, но тренд пошел вверх
        if ($synthetic < -$p['signal_threshold'] && $dir1 > 0) {
            $signal = 'buy';
        } 
        // Логика "Продавай на пике": если z-score сильно положительный, но тренд пошел вниз
        elseif ($synthetic > $p['signal_threshold'] && $dir1 < 0) {
            $signal = 'sell';
        }

        $confidence = min(1.0, abs($synthetic) / 3.0);

        $result[] = [
            'date'       => $dates[$i],
            'z1'         => round($z1[$i], 4),
            'z3'         => round($z3[$i], 4),
            'w1'         => round($w1, 4),
            'w3'         => round($w3, 4),
            'synthetic'  => round($synthetic, 4),
            'dir1'       => $dir1,
            'dir3'       => $dir3,
            'signal'     => $signal,
            'confidence' => round($confidence, 4),
        ];
    }

    return $result;
}