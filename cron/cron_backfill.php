#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('For CLI only');
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/lib/api.php';
require dirname(__DIR__) . '/lib/history.php';
require dirname(__DIR__) . '/lib/forecast.php';

$days = 7300; // 20 лет
$startTime = time();
$maxDur = 600; // максимум 10 минут работы
$processed = 0;
$failed = 0;

echo "[" . date('Y-m-d H:i:s') . "] cron_backfill started\n";

while (time() - $startTime < $maxDur) {
    $row = db()->query(
        "SELECT DISTINCT fl.market, fl.ticker
         FROM forecast_lines fl
         LEFT JOIN stock_map sm ON sm.market = fl.market AND sm.ticker = fl.ticker
         LEFT JOIN history_bars hb ON hb.stock_id = sm.id
         WHERE hb.stock_id IS NULL
           AND (sm.history_skip_until IS NULL OR sm.history_skip_until < NOW())
         LIMIT 1"
    )->fetch();
    
    if (!$row) break;

    try {
        $res = history_get($row['market'], $row['ticker'], $days, 'live');
        if (!empty($res['data'])) {
            $processed++;
        } else {
            $failed++;
            db()->prepare(
                "UPDATE stock_map SET history_skip_until = NOW() + INTERVAL 24 HOUR
                 WHERE market = ? AND ticker = ?"
            )->execute([$row['market'], $row['ticker']]);
        }
    } catch (Throwable $e) {
        $failed++;
        db()->prepare(
            "UPDATE stock_map SET history_skip_until = NOW() + INTERVAL 24 HOUR
             WHERE market = ? AND ticker = ?"
        )->execute([$row['market'], $row['ticker']]);
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Processed: {$processed}, failed: {$failed}\n";
    
    // Ограничиваем количество за один запуск, чтобы не зависнуть
    if ($processed + $failed >= 5) break;
}

echo "[" . date('Y-m-d H:i:s') . "] cron_backfill done. Processed: {$processed}, failed: {$failed}\n";