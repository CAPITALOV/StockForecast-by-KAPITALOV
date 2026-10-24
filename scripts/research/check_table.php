<?php
$dbHost = 'localhost';
$dbName = 'stockforecast';
$dbUser = 'sf_user';
$dbPass = 'MyStrongPass123!'; 

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    
    echo "--- СТРУКТУРА ТАБЛИЦЫ ---\n";
    $stmt = $pdo->query("DESCRIBE synthetic_components");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }
    
    echo "\n--- ПОСЛЕДНИЕ 3 ЗАПИСИ ---\n";
    $stmt = $pdo->query("SELECT * FROM synthetic_components ORDER BY date DESC LIMIT 3");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Дата: {$row['date']} | Тикер: {$row['ticker']} | synth: {$row['synthetic']} | Сигнал: " . (isset($row['trade_action']) ? $row['trade_action'] : (isset($row['signal']) ? $row['signal'] : 'НЕТ КОЛОНКИ')) . "\n";
    }
} catch (PDOException $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
