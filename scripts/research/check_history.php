<?php
require_once __DIR__ . '/../../bootstrap.php';

$pdo = db();
$count = (int)$pdo->query("SELECT COUNT(*) FROM history_bars")->fetchColumn();
$tickers = (int)$pdo->query("SELECT COUNT(DISTINCT stock_id) FROM history_bars")->fetchColumn();
$lastUpdate = $pdo->query("SELECT MAX(updated_at) FROM history_bars")->fetchColumn();

echo "Всего записей в history_bars: $count\n";
echo "Уникальных тикеров: $tickers\n";
echo "Последнее обновление: " . ($lastUpdate ?: 'нет данных') . "\n";