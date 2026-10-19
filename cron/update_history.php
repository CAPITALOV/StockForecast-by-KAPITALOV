<?php
/**
 * Ежедневное обновление history_bars.
 * Запуск: php cron/update_history.php [TICKER]
 *
 * Требует фикс пагинации в lib/api.php → moex_history().
 */
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/api.php';
require __DIR__ . '/../lib/history.php';

const CRON_DAYS      = 365;   // глубина бэкфилла
const CRON_SLEEP     = 1;     // пауза между тикерами, сек
const CRON_MIN_BARS  = 50;    // ниже — помечаем как SHORT, но сохраняем

// Не акции / ределистинг / индексы — не пытаемся тянуть с TQBR
const SKIP = [
    'IMOEX' => 'INDEX',       // индекс Мосбиржи
    'RTSI'  => 'INDEX',
    'POLY'  => 'DELISTED',    // ределистинг 2024
    'TCSG'  => 'DELISTED',    // → T
    'QIWI'  => 'DELISTED',    // почти нет баров
    'RSTI'  => 'REBRANDED',
];

$only = isset($argv[1]) ? strtoupper($argv[1]) : null;

// Один тикер = одна запись (MIN(id) убирает дубли stock_map)
$rows = db()->query(
    "SELECT MIN(id) AS id, market, ticker
       FROM stock_map
      GROUP BY market, ticker
      ORDER BY market, ticker"
)->fetchAll();

$total = 0; $bars = 0;
$ok = []; $short = []; $empty = []; $failed = [];

foreach ($rows as $r) {
    if ($only !== null && $r['ticker'] !== $only) continue;

    $label = "{$r['market']}:{$r['ticker']}";
    $total++;

    // 1) известные не-акции — не тратим запрос
    if (isset(SKIP[$r['ticker']])) {
        $empty[$label] = SKIP[$r['ticker']];
        fwrite(STDERR, "SKIP {$label} (" . SKIP[$r['ticker']] . ")\n");
        continue;
    }

    try {
        $data = ($r['market'] === 'moex')
            ? moex_history($r['ticker'], CRON_DAYS)
            : us_history($r['ticker'], CRON_DAYS);

        if (empty($data)) {
            $empty[$label] = 'EMPTY';
            fwrite(STDERR, "EMPTY {$label}\n");
        } else {
            $n = save_bars((int)$r['id'], $data, $r['market']);
            $bars += $n;
            if ($n < CRON_MIN_BARS) {
                $short[$label] = $n;
                echo "SHORT {$label} {$n}\n";
            } else {
                $ok[$label] = $n;
                echo "[{$label}] {$n}\n";
            }
        }
    } catch (Throwable $e) {
        $failed[$label] = $e->getMessage();
        fwrite(STDERR, "FAIL {$label}: " . $e->getMessage() . "\n");
    }

    if (CRON_SLEEP > 0) usleep(CRON_SLEEP * 1_000_000);
}

// ---------- сводка ----------
$line = sprintf(
    "%s stocks=%d bars=%d ok=%d short=%d empty=%d failed=%d\n",
    date('c'), $total, $bars,
    count($ok), count($short), count($empty), count($failed)
);
@file_put_contents(__DIR__ . '/../data/cron_history.log', $line, FILE_APPEND);

echo "\nDone: stocks={$total} bars={$bars} "
   . "ok=" . count($ok)
   . " short=" . count($short)
   . " empty=" . count($empty)
   . " failed=" . count($failed) . "\n";

if ($short) {
    echo "Short history (< " . CRON_MIN_BARS . " bars):\n";
    foreach ($short as $k => $n) echo "  {$k} = {$n}\n";
}
if ($empty) {
    echo "Empty / skipped:\n";
    foreach ($empty as $k => $why) echo "  {$k} ({$why})\n";
}
if ($failed) {
    echo "Failed:\n";
    foreach (array_slice($failed, 0, 20, true) as $k => $msg) echo "  {$k}: {$msg}\n";
    if (count($failed) > 20) echo "  ... и ещё " . (count($failed) - 20) . "\n";
}