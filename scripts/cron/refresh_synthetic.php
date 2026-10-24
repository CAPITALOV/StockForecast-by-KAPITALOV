<?php
declare(strict_types=1);

set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../../lib/synthetic.php';

$dbHost = 'localhost';
$dbName = 'stockforecast';
$dbUser = 'sf_user';
$dbPass = 'MyStrongPass123!'; 

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage() . "\n");
}

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

echo "[" . date('Y-m-d H:i:s') . "] Начинаем пересчет синтетических компонентов...\n";

$stTickers = $pdo->query("SELECT DISTINCT market, ticker FROM forecast_lines WHERE line_num IN (1,3)");
$tickers = $stTickers->fetchAll();
$totalProcessed = 0;

foreach ($tickers as $t) {
    $market = $t['market'];
    $ticker = $t['ticker'];
    
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
        continue;
    }

    $common = array_values(array_intersect(array_keys($byLine[1]), array_keys($byLine[3])));
    sort($common);
    
    if (count($common) < 120) continue;

    $x1 = []; $x3 = [];
    foreach ($common as $d) {
        $x1[] = $byLine[1][$d];
        $x3[] = $byLine[3][$d];
    }

    $syntheticData = syn_build($common, $x1, $x3);

    $pdo->beginTransaction();
    
    // ИСПОЛЬЗУЕМ trade_action ВМЕСТО signal
    $ins = $pdo->prepare(
        "INSERT INTO synthetic_components 
         (market, ticker, date, z1, z3, w1, w3, synthetic, dir1, dir3, trade_action, confidence)
         VALUES (:m, :t, :d, :z1, :z3, :w1, :w3, :s, :d1, :d3, :sig, :c)
         ON DUPLICATE KEY UPDATE
           z1 = VALUES(z1), z3 = VALUES(z3), w1 = VALUES(w1), w3 = VALUES(w3),
           synthetic = VALUES(synthetic), dir1 = VALUES(dir1), dir3 = VALUES(dir3),
           trade_action = VALUES(trade_action), confidence = VALUES(confidence)"
    );

    foreach ($syntheticData as $row) {
        $ins->execute([
            ':m'   => $market, ':t' => $ticker, ':d' => $row['date'],
            ':z1'  => $row['z1'], ':z3' => $row['z3'], ':w1' => $row['w1'], ':w3' => $row['w3'],
            ':s'   => $row['synthetic'], ':d1' => $row['dir1'], ':d3' => $row['dir3'],
            ':sig' => $row['signal'], ':c' => $row['confidence']
        ]);
    }
    $pdo->commit();
    
    $totalProcessed++;
    echo "  [OK] {$market}/{$ticker} — " . count($syntheticData) . " точек записано.\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Готово! Обработано тикеров: {$totalProcessed}.\n";
