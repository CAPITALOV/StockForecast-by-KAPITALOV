<?php
function import_stock_map($csvContent, $pdo) {
    $lines = preg_split('/\r?\n/', trim($csvContent));
    if (count($lines) < 2) {
        return ['added' => 0, 'skipped' => 0, 'errors' => ['CSV пуст или содержит только заголовок']];
    }
    $header = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), ',', '"');
    $header = array_map(function ($h) {
        return strtolower(trim($h, '" '));
    }, $header);
    $idx = array_flip($header);

    foreach (['id', 'finam_code', 'finam_market'] as $need) {
        if (!isset($idx[$need])) {
            return ['added' => 0, 'skipped' => 0,
                    'errors' => ["В CSV нет колонки '$need'"]];
        }
    }
    $idxName = $idx['name'] ?? null;

    $stmt = $pdo->prepare(
        "INSERT INTO stock_map (id, market, ticker, name)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE market = VALUES(market),
                                 ticker = VALUES(ticker),
                                 name   = VALUES(name)"
    );

    $added = 0; $skipped = 0; $errors = [];
    for ($i = 1; $i < count($lines); $i++) {
        $row = str_getcsv($lines[$i], ',', '"');
        if (count($row) <= max($idx)) { $skipped++; continue; }
        $id     = (int)trim($row[$idx['id']]);
        $code   = trim($row[$idx['finam_code']]);
        $fm     = trim($row[$idx['finam_market']]);
        $name   = $idxName !== null ? trim($row[$idxName]) : null;
        if ($id <= 0 || $code === '' || $code === 'NULL') { $skipped++; continue; }
        $market = null;
        if ($fm === '1' || preg_match('/\.(ME|MISX)$/i', $code)) {
            $market = 'moex';
        } elseif ($fm === '2' || $fm === '25' || $fm === '517'
                  || preg_match('/\.US$/i', $code)) {
            $market = 'us';
        } else { $skipped++; continue; }

        $ticker = strtoupper($code);
        $ticker = preg_replace('/^US[12]\./i', '', $ticker);
        $ticker = preg_replace('/^SPBEX\./i', '', $ticker);
        $ticker = preg_replace('/\.(ME|MISX|US|INDX|UK)$/i', '', $ticker);
        $ticker = trim($ticker);
        if ($ticker === '' || !preg_match('/^[A-Z0-9._-]{1,32}$/', $ticker)) {
            $skipped++;
            continue;
        }
        try {
            $stmt->execute([$id, $market, $ticker, $name ?: null]);
            $added++;
        } catch (PDOException $e) {
            $errors[] = "Строка $i (id=$id): " . $e->getMessage();
        }
    }
    return ['added' => $added, 'skipped' => $skipped, 'errors' => $errors];
}

function import_forecasts($csvContent, $pdo, $lineNum = 1) {
    if ($lineNum < 1 || $lineNum > 3) $lineNum = 1;
    $lines = preg_split('/\r?\n/', trim($csvContent));
    if (count($lines) < 2) {
        return ['groups' => 0, 'points' => 0, 'errors' => ['CSV пуст']];
    }
    $header = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), ',', '"');
    $header = array_map(function ($h) {
        return strtolower(str_replace([' ', '_'], '', trim($h, '" ')));
    }, $header);
    $idx = array_flip($header);

    $aliases = [
        'stockid' => ['stockid'],
        'date'    => ['date', 'dt'],
        'delta'   => ['delta', 'value', 'val'],
    ];
    $col = [];
    foreach ($aliases as $key => $names) {
        foreach ($names as $n) {
            if (isset($idx[$n])) { $col[$key] = $idx[$n]; break; }
        }
    }
    if (count($col) < 3) {
        return ['groups' => 0, 'points' => 0,
                'errors' => ['Не найдены колонки StockId/Date/Delta']];
    }

    $byStock = [];
    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if ($line === '') continue;
        $row = str_getcsv($line, ',', '"');
        if (count($row) <= max($col)) continue;
        $stockId = (int)trim($row[$col['stockid']]);
        $date    = trim($row[$col['date']], " \"'");
        $delta   = trim($row[$col['delta']], " \"'");
        if ($stockId <= 0) continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $delta = str_replace(',', '.', $delta);
        if ($delta === '' || !is_numeric($delta)) continue;
        $byStock[$stockId][] = ['time' => $date, 'value' => (float)$delta];
    }
    if (empty($byStock)) {
        return ['groups' => 0, 'points' => 0,
                'errors' => ['В CSV не найдено валидных строк']];
    }

    $stmtGet = $pdo->prepare("SELECT market, ticker FROM stock_map WHERE id = ?");
    $stmtPut = $pdo->prepare(
        "INSERT INTO forecast_lines
            (market, ticker, line_num, value_type, content, updated_at)
         VALUES (?, ?, ?, 'delta', ?, ?)
         ON DUPLICATE KEY UPDATE value_type = 'delta',
                                 content    = VALUES(content),
                                 updated_at = VALUES(updated_at)"
    );
    $groups = 0; $points = 0; $errors = [];
    foreach ($byStock as $stockId => $rows) {
        $stmtGet->execute([$stockId]);
        $m = $stmtGet->fetch();
        if (!$m) {
            $errors[] = "StockId $stockId — нет в справочнике (пропущено "
                      . count($rows) . " точек)";
            continue;
        }
        usort($rows, function ($a, $b) { return strcmp($a['time'], $b['time']); });
        $content = '';
        foreach ($rows as $r) {
            $content .= $r['time'] . ' ' . $r['value'] . "\n";
        }
        try {
            $stmtPut->execute([$m['market'], $m['ticker'], $lineNum, $content, date('Y-m-d H:i:s')]);
            $groups++;
            $points += count($rows);
        } catch (PDOException $e) {
            $errors[] = "StockId $stockId ({$m['market']}:{$m['ticker']}): " . $e->getMessage();
        }
    }
    return ['groups' => $groups, 'points' => $points, 'errors' => $errors];
}