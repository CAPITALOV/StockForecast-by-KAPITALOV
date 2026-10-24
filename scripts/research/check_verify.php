<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/forecast.php';
require_once __DIR__ . '/../../lib/verify.php';

$market = 'moex';
$ticker = 'LKOH';

$stmt = db()->prepare("SELECT line_num, value_type, content FROM forecast_lines WHERE market = ? AND ticker = ? AND line_num = 1");
$stmt->execute([$market, $ticker]);
$row = $stmt->fetch();

if (!$row) {
    die("Нет данных для $ticker\n");
}

$points = parse_forecast($row['content']);
$v = verify_forecast($market, $ticker, $points, $row['value_type']);

echo "Всего строк в verify_forecast: " . count($v['rows']) . "\n";
echo "Последние 10 строк (time | forecast | fact | deviation_pct):\n";

$last10 = array_slice($v['rows'], -10);
foreach ($last10 as $r) {
    printf("%s | %s | %s | %s\n",
        $r['time'] ?? 'NULL',
        $r['forecast'] ?? 'NULL',
        $r['fact'] ?? 'NULL',
        $r['deviation_pct'] ?? 'NULL'
    );
}

$from = date('Y-m-d', time() - 30 * 86400);
$to   = date('Y-m-d');
echo "\nПериод для проверки: $from — $to\n";

$countInRange = 0;
$countWithDev = 0;
foreach ($v['rows'] as $r) {
    if (($r['time'] ?? '') >= $from && ($r['time'] ?? '') <= $to) {
        $countInRange++;
        if ($r['deviation_pct'] !== null) $countWithDev++;
    }
}
echo "Строк в диапазоне 30 дней: $countInRange\n";
echo "Из них с рассчитанным deviation_pct: $countWithDev\n";
