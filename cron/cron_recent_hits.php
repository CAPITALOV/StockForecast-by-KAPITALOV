#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('For CLI only');
}
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/forecast.php';
require __DIR__ . '/lib/verify.php';
require __DIR__ . '/lib/telegram.php';

echo "[" . date('Y-m-d H:i:s') . "] cron_recent_hits started\n";

$hits = recent_hits(7, 2.0, 50, true);
$sentFile = __DIR__ . '/data/telegram_sent.json';
$sent = [];
if (is_file($sentFile)) {
    $sent = json_decode(file_get_contents($sentFile), true) ?: [];
}

$newHits = [];
foreach ($hits as $h) {
    $key = $h['market'] . ':' . $h['ticker'] . ':' . $h['line_num'] . ':' . $h['time'];
    if (!isset($sent[$key])) {
        $newHits[] = $h;
    }
}

echo "Total hits: " . count($hits) . ", new: " . count($newHits) . "\n";

if (!empty($newHits)) {
    $msg = "🎯 <b>Сбывшиеся прогнозы</b>\n\n";
    $count = 0;
    foreach ($newHits as $h) {
        $msg .= "• <b>{$h['ticker']}</b> ({$h['market']}) линия {$h['line_num']}\n";
        $msg .= "  Дата: {$h['time']}\n";
        $msg .= "  Прогноз: {$h['forecast']}, Факт: {$h['fact']} (Δ {$h['deviation_pct']}%)\n\n";
        $sent[$key] = true;
        $count++;
        if ($count >= 10) break;
    }
    $ok = telegram_send($msg);
    echo $ok ? "Telegram: sent\n" : "Telegram: failed or disabled\n";
    @file_put_contents($sentFile, json_encode($sent, JSON_UNESCAPED_UNICODE));
} else {
    echo "Nothing new to send\n";
}