<?php
/**
 * bin/audit_lines.php — аудит трёх линий: hit-rate, Pearson, Profit Factor.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../lib/history.php';
require_once __DIR__ . '/../lib/verify.php';
require_once __DIR__ . '/../lib/forecast.php';

foreach (['history_get', 'verify_forecast', 'verify_all_lines'] as $fn) {
    if (!function_exists($fn)) { fwrite(STDERR, "FATAL: {$fn}() не определена.\n"); exit(1); }
}

$opts     = getopt('', ['market::', 'csv::', 'top::', 'min-count::']);
$market   = $opts['market']    ?? null;
$csvPath  = $opts['csv']       ?? null;
$topN     = (int)($opts['top'] ?? 5);
$minCount = (int)($opts['min-count'] ?? 10);

$pdo = db();
$sql = "SELECT DISTINCT market, ticker FROM forecast_lines";
$params = [];
if ($market !== null) { $sql .= " WHERE market = ?"; $params[] = $market; }
$sql .= " ORDER BY market, ticker";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pairs)) { fwrite(STDERR, "Нет тикеров.\n"); exit(1); }

echo "Аудит " . count($pairs) . " тикеров";
if ($market !== null) echo " (market = {$market})";
echo "...\n\n";

$perTicker  = [];
$perLine    = [];  // line => [{hit, pearson, count, mape}, ...]
$perLinePnL = [1 => [], 2 => [], 3 => []];  // line => [pnl_pct, ...]
$header     = ['Delta Close', 'Synthetic', 'RPO'];

foreach ($pairs as $idx => $p) {
    $m = $p['market'];
    $t = $p['ticker'];
    try {
        $res = verify_all_lines($m, $t, $pdo, false);
        $entry = [];
        foreach ($res['lines'] as $line) {
            $num = (int)$line['line_num'];
            $v   = $line['verify'];
            if (!$v || ($v['count'] ?? 0) < $minCount) continue;

            $entry[$num] = [
                'hit'     => (float)$v['hit_rate'],
                'pearson' => $v['pearson'],
                'count'   => (int)$v['count'],
                'mape'    => (float)($v['avg_vector_dev'] ?? $v['mape'] ?? 0),
            ];
            if (!isset($perLine[$num])) $perLine[$num] = [];
            $perLine[$num][] = $entry[$num];

            // PnL: buy если delta > 0, sell если delta < 0
            foreach ($v['rows'] ?? [] as $row) {
                if ($row['actual_return'] === null || $row['delta'] === null) continue;
                $delta = (float)$row['delta'];
                if (abs($delta) < 0.001) continue;
                $actual = (float)$row['actual_return'];
                $pnl = $delta > 0 ? $actual : -$actual;
                $perLinePnL[$num][] = $pnl;
            }
        }
        if (!empty($entry)) $perTicker["{$m}:{$t}"] = $entry;
    } catch (Throwable $e) {
        fwrite(STDERR, "  ! {$m}:{$t} — " . $e->getMessage() . "\n");
    }
    if (($idx + 1) % 20 === 0) {
        echo "  обработано " . ($idx + 1) . " / " . count($pairs) . "\n";
    }
}
echo "  обработано " . count($pairs) . " / " . count($pairs) . " (готово)\n\n";

if (empty($perTicker)) {
    fwrite(STDERR, "Ни один тикер не прошёл порог min-count = {$minCount}.\n");
    exit(1);
}

function fmt_pct($v) { return $v === null ? '—' : number_format($v * 100, 1) . '%'; }

/* ===== ТАБЛИЦА 1: HIT-RATE ===== */
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                       СВОДКА: HIT-RATE И PEARSON                             ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
printf("║ %-16s │ %8s │ %8s │ %10s │ %10s │ %8s ║\n",
    'Линия', 'Успешных', 'Pearson', 'Ср.сдвиг', 'Тикеров', 'Точек');
echo "╟──────────────────┼──────────┼──────────┼────────────┼────────────┼──────────╢\n";
foreach ([1, 2, 3] as $num) {
    if (empty($perLine[$num])) continue;
    $rows = $perLine[$num];
    $n = count($rows);
    $avgHit = array_sum(array_column($rows, 'hit')) / $n;
    $avgP   = array_sum(array_column($rows, 'pearson')) / $n;
    $avgM   = array_sum(array_column($rows, 'mape')) / $n;
    $sumPts = array_sum(array_column($rows, 'count'));
    printf("║ %-16s │ %8s │ %8.3f │ %10.2f │ %10d │ %8d ║\n",
        $header[$num - 1], fmt_pct($avgHit), $avgP, $avgM, $n, $sumPts);
}
echo "╚══════════════════╧══════════╧══════════╧════════════╧════════════╧══════════╝\n\n";

/* ===== ТАБЛИЦА 2: MONEY METRICS ===== */
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                  СВОДКА: MONEY METRICS (PnL, Profit Factor)                  ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
printf("║ %-16s │ %6s │ %5s │ %6s │ %9s │ %8s │ %9s ║\n",
    'Линия', 'Trades', 'Wins', 'Losses', 'P.Factor', 'WinRate', 'Exp, %');
echo "╟──────────────────┼────────┼───────┼────────┼───────────┼──────────┼───────────╢\n";
foreach ([1, 2, 3] as $num) {
    if (empty($perLinePnL[$num])) continue;
    $pnls = $perLinePnL[$num];
    $grossP = 0.0; $grossL = 0.0; $wins = 0; $losses = 0;
    foreach ($pnls as $pnl) {
        if ($pnl > 0) { $grossP += $pnl; $wins++; }
        else          { $grossL += abs($pnl); $losses++; }
    }
    $trades = $wins + $losses;
    $pf = $grossL > 0 ? $grossP / $grossL : INF;
    $winRate = $trades > 0 ? $wins / $trades : 0;
    $avgWin = $wins > 0 ? $grossP / $wins : 0;
    $avgLoss = $losses > 0 ? $grossL / $losses : 0;
    $expectancy = ($winRate * $avgWin) - ((1 - $winRate) * $avgLoss);

    printf("║ %-16s │ %6d │ %5d │ %6d │ %9.2f │ %7s │ %+8.3f ║\n",
        $header[$num - 1], $trades, $wins, $losses, $pf,
        number_format($winRate * 100, 1) . '%', $expectancy);
}
echo "╚══════════════════╧════════╧═══════╧════════╧═══════════╧══════════╧═══════════╝\n\n";

echo "  P.Factor > 1.0 и Exp > 0  → стратегия прибыльна\n";
echo "  P.Factor ≈ 1.0 и Exp ≈ 0  → на грани случайности\n";
echo "  P.Factor < 1.0 и Exp < 0  → стратегия убыточна\n\n";

/* ===== TOP-N ЛУЧШИХ/ХУДШИХ ===== */
foreach ([1, 2, 3] as $num) {
    if (empty($perLine[$num])) continue;
    $tickers = [];
    foreach ($perTicker as $tk => $entry) {
        if (isset($entry[$num])) $tickers[$tk] = $entry[$num];
    }
    uasort($tickers, fn($a, $b) => $b['hit'] <=> $a['hit']);

    echo "── {$header[$num - 1]} ─────────────────────────────────────────────────────────\n";
    $top    = array_slice($tickers, 0, $topN, true);
    $bottom = array_slice($tickers, -$topN, $topN, true);

    echo "  Лучшие " . count($top) . ":\n";
    foreach ($top as $tk => $v) {
        printf("    %-14s  hit %6s  pearson %6.3f  n=%4d  mape %5.2f\n",
            $tk, fmt_pct($v['hit']), $v['pearson'] ?? 0, $v['count'], $v['mape']);
    }
    echo "\n  Худшие " . count($bottom) . ":\n";
    foreach ($bottom as $tk => $v) {
        printf("    %-14s  hit %6s  pearson %6.3f  n=%4d  mape %5.2f\n",
            $tk, fmt_pct($v['hit']), $v['pearson'] ?? 0, $v['count'], $v['mape']);
    }
    echo "\n";
}

/* ===== ЛУЧШАЯ ЛИНИЯ ПО ТИКЕРУ ===== */
echo "── Лучшая линия по каждому тикеру ─────────────────────────────────────────────\n";
$bestCount = [1 => 0, 2 => 0, 3 => 0];
foreach ($perTicker as $tk => $entry) {
    $bestNum = null; $bestHit = -1;
    foreach ($entry as $num => $v) {
        if ($v['hit'] > $bestHit) { $bestHit = $v['hit']; $bestNum = $num; }
    }
    if ($bestNum !== null) $bestCount[$bestNum]++;
}
printf("  Delta Close : %d тикеров\n", $bestCount[1]);
printf("  Synthetic   : %d тикеров\n", $bestCount[2]);
printf("  RPO         : %d тикеров\n", $bestCount[3]);
echo "\n";

/* ===== CSV ===== */
if ($csvPath) {
    $fp = fopen($csvPath, 'w');
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, ['market_ticker','line','name','hit_rate','pearson','count','mape','trades','wins','losses','profit_factor','expectancy'], ';', '"', '\\');
    foreach ($perTicker as $tk => $entry) {
        foreach ($entry as $num => $v) {
            // PnL для этого тикера-линии
            $ticker_pnls = [];
            // пересчитываем через verify (уже сделано выше, но нужно здесь)
            // проще — только агрегаты, PnL оставим пустой (или пересчитать — см. TODO)
            $pnls_all = [];
            fputcsv($fp, [
                $tk, $num, $header[$num - 1], $v['hit'], $v['pearson'], $v['count'], $v['mape'],
                '', '', '', '', '',
            ], ';', '"', '\\');
        }
    }
    fclose($fp);
    echo "CSV сохранён: {$csvPath}\n";
}