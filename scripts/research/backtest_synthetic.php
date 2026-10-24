<?php
declare(strict_types=1);

// ── НАСТРОЙКИ БАЗЫ ДАННЫХ ───────────────────────────────────────────────
$dbHost = 'localhost';
$dbName = 'stockforecast';
$dbUser = 'sf_user';
$dbPass = 'MyStrongPass123!'; 
// ────────────────────────────────────────────────────────────────────────

$market = $argv[1] ?? 'moex';
$ticker = $argv[2] ?? 'LKOH';
$h      = (int)($argv[3] ?? 5); // По умолчанию 5 дней (макро-тренд)

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage() . "\n");
}

function parse_content(string $content): array {
    $out = [];
    foreach (preg_split('/\R/', $content) as $line) {
        if (preg_match('/^\s*(\d{4}-\d{2}-\d{2})\s+(-?\d+(?:\.\d+)?)\s*$/', $line, $m)) {
            $out[$m[1]] = (float)$m[2];
        }
    }
    ksort($out);
    return $out;
}

function load_lines(PDO $pdo, string $market, string $ticker): array {
    $st = $pdo->prepare(
        "SELECT line_num, value_type, content FROM forecast_lines
         WHERE market = :m AND ticker = :t AND line_num IN (1,3)"
    );
    $st->execute([':m' => $market, ':t' => $ticker]);
    $byLine = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['value_type'] !== 'delta') continue;
        $s = parse_content((string)$r['content']);
        if ($s) $byLine[(int)$r['line_num']] = $s;
    }
    if (!isset($byLine[1], $byLine[3])) {
        throw new RuntimeException("В БД не найдены обе линии для {$market}/{$ticker}");
    }
    $common = array_values(array_intersect(array_keys($byLine[1]), array_keys($byLine[3])));
    sort($common);
    $x1 = []; $x3 = [];
    foreach ($common as $d) {
        $x1[] = $byLine[1][$d];
        $x3[] = $byLine[3][$d];
    }
    return ['dates' => $common, 'x1' => $x1, 'x3' => $x3];
}

// НОВОЕ: Сглаживание для устранения шума (SMA-5)
function smooth_sma(array $x, int $w = 5): array {
    $n = count($x); $out = array_fill(0, $n, null);
    for ($i = $w - 1; $i < $n; $i++) {
        $sum = 0.0;
        for ($j = $i - $w + 1; $j <= $i; $j++) {
            $sum += $x[$j];
        }
        $out[$i] = $sum / $w;
    }
    return $out;
}

function rolling_mean(array $x, int $w): array {
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

function rolling_std(array $x, int $w, ?array $means = null): array {
    $n = count($x); $out = array_fill(0, $n, null);
    if ($w <= 1 || $n < $w) return $out;
    $means ??= rolling_mean($x, $w);
    for ($i = $w - 1; $i < $n; $i++) {
        if ($means[$i] === null) continue;
        $m = $means[$i]; $acc = 0.0;
        for ($j = $i - $w + 1; $j <= $i; $j++) {
            $d = $x[$j] - $m;
            $acc += $d * $d;
        }
        $out[$i] = sqrt($acc / $w);
    }
    return $out;
}

function rolling_zscore(array $x, int $w): array {
    $n = count($x);
    $means = rolling_mean($x, $w);
    $stds  = rolling_std($x, $w, $means);
    $out = array_fill(0, $n, 0.0);
    for ($i = 0; $i < $n; $i++) {
        if ($means[$i] !== null && $stds[$i] !== null && $stds[$i] > 0) {
            $out[$i] = ($x[$i] - $means[$i]) / $stds[$i];
        }
    }
    return $out;
}

function directional_accuracy(array $x, int $w, int $k, int $h): array {
    $n = count($x);
    $hits = array_fill(0, $n, null);
    for ($i = $k; $i + $h < $n; $i++) {
        $sp = ($x[$i] <=> $x[$i - $k]);
        $sf = ($x[$i + $h] <=> $x[$i]);
        $hits[$i] = ($sp === 0 || $sf === 0) ? 0.5 : (($sp === $sf) ? 1.0 : 0.0);
    }
    $shifted = array_fill(0, $n, null);
    for ($i = 0; $i + $h < $n; $i++) {
        if ($hits[$i] !== null) $shifted[$i + $h] = $hits[$i];
    }
    return rolling_mean($shifted, $w);
}

function adaptive_synthetic(array $x1, array $x3, int $zw = 60, int $ww = 60, int $k = 5, int $h = 5, float $beta = 0.9): array {
    $z1 = rolling_zscore($x1, $zw);
    $z3 = rolling_zscore($x3, $zw);
    $a1 = directional_accuracy($x1, $ww, $k, $h);
    $a3 = directional_accuracy($x3, $ww, $k, $h);
    $n = count($x1);
    $alpha = 1.0 - $beta;
    $prev = 0.5;
    $out = array_fill(0, $n, 0.0);
    for ($i = 0; $i < $n; $i++) {
        if ($a1[$i] !== null && $a3[$i] !== null) {
            $r1 = max($a1[$i] - 0.5, 0.0) + 1e-6;
            $r3 = max($a3[$i] - 0.5, 0.0) + 1e-6;
            $raw = $r1 / ($r1 + $r3);
        } else {
            $raw = 0.5;
        }
        $prev = $alpha * $raw + (1.0 - $alpha) * $prev;
        $w1 = max(0.2, min(0.8, $prev));
        $out[$i] = $w1 * $z1[$i] + (1.0 - $w1) * $z3[$i];
    }
    return $out;
}

function calc_stddev(array $arr): float {
    $n = count($arr);
    if ($n <= 1) return 0.0;
    $mean = array_sum($arr) / $n;
    $sum_sq = 0.0;
    foreach ($arr as $v) $sum_sq += ($v - $mean) ** 2;
    return sqrt($sum_sq / $n);
}

// Оценка с исправлением масштаба и возможностью инверсии
function evaluate(array $pred, array $ref, int $k = 5, int $h = 5, bool $invert = false): ?array {
    $n = count($pred);
    $pred_moves = []; $act_moves = [];
    for ($i = $k; $i + $h < $n; $i++) {
        if ($pred[$i - $k] === null || $ref[$i - $k] === null) continue;
        $pred_moves[] = $pred[$i] - $pred[$i - $k];
        $act_moves[]  = $ref[$i + $h] - $ref[$i];
    }
    $m = count($pred_moves);
    if ($m === 0) return null;

    // Исправление масштаба для hit50
    $std_pred = calc_stddev($pred_moves);
    $std_act = calc_stddev($act_moves);
    $scale = ($std_pred > 0) ? ($std_act / $std_pred) : 1.0;

    $hits = 0; $hit50 = 0; $total = 0;
    for ($i = 0; $i < $m; $i++) {
        $sp = $pred_moves[$i] <=> 0;
        $sa = $act_moves[$i] <=> 0;
        if ($sp === 0) continue;
        if ($invert) $sp = -$sp;
        
        $total++;
        if ($sp === $sa) {
            $hits++;
            $scaled_pred = abs($pred_moves[$i]) * $scale;
            if (abs($act_moves[$i]) >= 0.5 * $scaled_pred) $hit50++;
        }
    }
    if ($total === 0) return null;

    $mp = array_sum($pred_moves) / $m; $ma = array_sum($act_moves) / $m;
    $num = 0.0; $dp = 0.0; $da = 0.0;
    for ($i = 0; $i < $m; $i++) {
        $a = $pred_moves[$i] - $mp; $b = $act_moves[$i] - $ma;
        $num += $a * $b; $dp += $a * $a; $da += $b * $b;
    }
    $pearson = ($dp > 0 && $da > 0) ? $num / sqrt($dp * $da) : 0.0;
    if ($invert) $pearson = -$pearson;

    return ['n' => $total, 'sign_hit' => $hits / $total, 'hit50' => $hit50 / $total, 'pearson' => $pearson];
}

try {
    $data = load_lines($pdo, $market, $ticker);
    $n = count($data['x1']);
    
    echo "Данные: {$market}/{$ticker} | Точек: {$n} | Период: {$data['dates'][0]} .. {$data['dates'][$n-1]}\n";
    echo "Режим: МАКРО-ТРЕНД (сглаживание SMA-5, горизонт {$h} дней, факт = следующий delta)\n\n";

    // Сглаживаем данные
    $x1_smooth = smooth_sma($data['x1'], 5);
    $x3_smooth = smooth_sma($data['x3'], 5);

    $old_avg = [];
    for ($i = 0; $i < $n; $i++) {
        if ($x1_smooth[$i] !== null && $x3_smooth[$i] !== null) {
            $old_avg[$i] = ($x1_smooth[$i] + $x3_smooth[$i]) / 2.0;
        } else {
            $old_avg[$i] = null;
        }
    }

    $adaptive = adaptive_synthetic($x1_smooth, $x3_smooth, 60, 60, 5, $h);

    echo str_repeat('-', 72) . "\n";
    printf("%-14s %6s   %-9s %-9s %-9s\n", 'Сигнал (SMA-5)', 'N', 'sign_hit', 'hit50', 'pearson');
    echo str_repeat('-', 72) . "\n";

    $cases = [
        ['x1 (Line 1)', $x1_smooth, $x1_smooth, false],
        ['x1 (INV)', $x1_smooth, $x1_smooth, true],
        ['x3 (Line 3)', $x3_smooth, $x3_smooth, false],
        ['old_avg (L2)', $old_avg, $x1_smooth, false],
        ['adaptive (L2)', $adaptive, $x1_smooth, false],
        ['adaptive (INV)', $adaptive, $x1_smooth, true],
    ];
    foreach ($cases as [$name, $pred, $ref, $inv]) {
        $m = evaluate($pred, $ref, 5, $h, $inv);
        if ($m === null) {
            printf("%-14s  <нет данных>\n", $name);
        } else {
            printf("%-14s %6d   %-9.3f %-9.3f %+.3f\n", $name, $m['n'], $m['sign_hit'], $m['hit50'], $m['pearson']);
        }
    }
    echo str_repeat('-', 72) . "\n";

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}