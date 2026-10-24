<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';
// Подключаем функции парсинга и верификации (как в index.php)
require_once is_file(__DIR__ . '/../../lib/forecast.php')
    ? __DIR__ . '/../../lib/forecast.php'
    : __DIR__ . '/../../forecast.php';
require_once __DIR__ . '/../../lib/verify.php';
require_once __DIR__ . '/../../lib/screener.php';

echo "[" . date('Y-m-d H:i:s') . "] Начинаем генерацию кэша Скринера...\n";

screener_generate_cache();

echo "[" . date('Y-m-d H:i:s') . "] Готово! Кэш сохранен в data/screener_cache.json\n";