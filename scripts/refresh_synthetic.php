<?php
declare(strict_types=1);
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/synthetic.php';

$tickers = $pdo->query("SELECT id FROM tickers WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tickers as $tid) {
    $sql = "SELECT line_num, date, value FROM forecasts
            WHERE ticker_id = :t AND line_num IN (1,3)
            ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':t' => $tid]);
    $byLine = [1 => [], 3 => []];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byLine[(int)$r['line_num']][$r['date']] = (float)$r['value'];
    }
    $dates = array_values(array_intersect(array_keys($byLine[1]), array_keys($byLine[3])));
    sort($dates);
    if (count($dates) < SYN_PARAMS_DEFAULT['zscore_window']) continue;

    $x1 = array_map(fn($d) => $byLine[1][$d], $dates);
    $x3 = array_map(fn($d) => $byLine[3][$d], $dates);
    $rows = syn_build($dates, $x1, $x3);

    $pdo->beginTransaction();
    $ins = $pdo->prepare(
        "INSERT INTO synthetic_components
         (ticker_id,date,z1,z3,w1,w3,synthetic,dir1,dir3,signal,confidence)
         VALUES (:t,:d,:z1,:z3,:w1,:w3,:s,:d1,:d3,:sig,:c)
         ON DUPLICATE KEY UPDATE
           z1=VALUES(z1), z3=VALUES(z3), w1=VALUES(w1), w3=VALUES(w3),
           synthetic=VALUES(synthetic), dir1=VALUES(dir1), dir3=VALUES(dir3),
           signal=VALUES(signal), confidence=VALUES(confidence)"
    );
    foreach ($rows as $r) {
        $ins->execute([
            ':t' => $tid, ':d' => $r['date'],
            ':z1' => $r['z1'], ':z3' => $r['z3'],
            ':w1' => $r['w1'], ':w3' => $r['w3'],
            ':s'  => $r['synthetic'],
            ':d1' => $r['dir1'], ':d3' => $r['dir3'],
            ':sig' => $r['signal'], ':c' => $r['confidence'],
        ]);
    }
    $pdo->commit();
    echo "ticker {$tid}: " . count($rows) . " rows\n";
}