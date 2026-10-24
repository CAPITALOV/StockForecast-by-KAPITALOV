<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/forecast.php';
require_once __DIR__ . '/../../lib/verify.php';

ini_set('memory_limit', '512M');
set_time_limit(600);

$pdo = db();
$cacheFile = __DIR__ . '/../../data/accuracy_cache.json';

$maxForecast = (string)$pdo->query("SELECT MAX(updated_at) FROM forecast_lines")->fetchColumn();
$maxHistory  = (string)$pdo->query("SELECT MAX(updated_at) FROM history_bars")->fetchColumn();
$cacheKey    = $maxForecast . '|' . $maxHistory;

if (is_file($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cached) && ($cached['key'] ?? '') === $cacheKey) {
        echo "[" . date('Y-m-d H:i:s') . "] Данные не изменились. Кэш актуален.\n";
        exit;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Начинаем пересчет точности...\n";

$rows = $pdo->query(
    "SELECT market, ticker, line_num, value_type, content
     FROM forecast_lines ORDER BY market, ticker, line_num"
)->fetchAll();

$all = [];
$missing = [];

foreach ($rows as $r) {
    $points = parse_forecast($r['content']);
    $v = verify_forecast($r['market'], $r['ticker'], $points, $r['value_type']);
    
    if ($v['count'] === 0) {
        $missing[$r['market'] . ':' . $r['ticker']] = true;
        continue;
    }
    
    $all[] = [
        'market' => $r['market'],
        'ticker' => $r['ticker'],
        'line_num' => (int)$r['line_num'],
        'value_type' => $r['value_type'],
        'mape' => $v['mape'],
        'hit_rate' => $v['hit_rate'],
        'count' => $v['count'],
    ];
}

@mkdir(dirname($cacheFile), 0777, true);
file_put_contents($cacheFile, json_encode([
    'key' => $cacheKey,
    'all' => $all,
    'missing' => $missing,
], JSON_UNESCAPED_UNICODE));

echo "[" . date('Y-m-d H:i:s') . "] Готово! Записано " . count($all) . " строк.\n";
