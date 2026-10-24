<?php
declare(strict_types=1);

// Подключаем наш новый модуль
require_once __DIR__ . '/../../lib/synthetic.php';

// ── НАСТРОЙКИ БАЗЫ ДАННЫХ ───────────────────────────────────────────────
$dbHost = 'localhost';
$dbName = 'stockforecast';
$dbUser = 'sf_user';
$dbPass = 'MyStrongPass123!'; 
// ────────────────────────────────────────────────────────────────────────

$market = $argv[1] ?? 'moex';
$ticker = $argv[2] ?? 'LKOH';

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage() . "\n");
}

// Функция парсинга content из forecast_lines
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

// Загрузка данных
try {
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

    // Пересекаем даты
    $common = array_values(array_intersect(array_keys($byLine[1]), array_keys($byLine[3])));
    sort($common);
    $x1 = []; $x3 = [];
    foreach ($common as $d) {
        $x1[] = $byLine[1][$d];
        $x3[] = $byLine[3][$d];
    }

    // ВЫЗОВ НАШЕГО МОДУЛЯ
    echo "Запуск syn_build() для {$market}/{$ticker}...\n";
    echo "Всего точек: " . count($common) . "\n\n";
    
    $syntheticData = syn_build($common, $x1, $x3);

    // Выводим последние 15 значений
    $lastCount = 15;
    $total = count($syntheticData);
    $start = max(0, $total - $lastCount);

    echo "Последние {$lastCount} дней (проверка сигналов):\n";
    echo str_repeat('-', 95) . "\n";
    printf("%-12s | %-8s | %-8s | %-6s | %-8s | %-4s | %-8s | %-6s\n", 
        'Дата', 'z1', 'z3', 'вес w1', 'synth', 'dir1', 'Сигнал', 'Увер.');
    echo str_repeat('-', 95) . "\n";

    for ($i = $start; $i < $total; $i++) {
        $row = $syntheticData[$i];
        
        // Подсветка сигналов в консоли
        $signalStr = strtoupper($row['signal']);
        if ($row['signal'] === 'buy') {
            $signalStr = "\033[32m" . $signalStr . "\033[0m"; // Зеленый
        } elseif ($row['signal'] === 'sell') {
            $signalStr = "\033[31m" . $signalStr . "\033[0m"; // Красный
        } else {
            $signalStr = "neutral";
        }

        printf("%-12s | %+8.3f | %+8.3f | %6.2f | %+8.3f | %+4d | %-17s | %5.2f\n", 
            $row['date'], 
            $row['z1'], 
            $row['z3'], 
            $row['w1'], 
            $row['synthetic'], 
            $row['dir1'], 
            $signalStr, 
            $row['confidence']
        );
    }
    echo str_repeat('-', 95) . "\n";
    echo "Готово. Если видите BUY на минимумах графика (z-score < 0) и SELL на максимумах (z-score > 0) — логика работает верно.\n";

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}