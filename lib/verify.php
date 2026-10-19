<?php
function last_close_before($stockId, $date) {
    if ($stockId === null) return null;
    $stmt = db()->prepare(
        "SELECT close FROM history_bars
         WHERE stock_id = ? AND ts <= ? ORDER BY ts DESC LIMIT 1"
    );
    $stmt->execute([$stockId, $date]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (float)$v : null;
}

function forecast_to_price(array $points, string $type, ?float $basePrice): array {
    if (empty($points)) return [];
    if ($type === 'price') return $points;
    if ($basePrice === null || $basePrice <= 0) return [];
    $out = [];
    $price = $basePrice;
    foreach ($points as $p) {
        $price *= (1.0 + (float)$p['value']);
        $out[] = ['time' => $p['time'], 'value' => $price];
    }
    return $out;
}

function verify_forecast(string $market, string $ticker, array $points, string $type): array {
    $empty = ['rows' => [], 'mape' => null, 'hit_rate' => null, 'count' => 0, 'base_price' => null];
    $stockId = stock_id_by_ticker($market, $ticker);
    if ($stockId === null || empty($points)) return $empty;
    $firstDate = $points[0]['time'];
    $basePrice = last_close_before($stockId, $firstDate);
    $priced    = forecast_to_price($points, $type, $basePrice);
    if (empty($priced)) return $empty;

    $first = $priced[0]['time'];
    $last  = $priced[count($priced) - 1]['time'];
    $stmt = db()->prepare(
        "SELECT ts, close FROM history_bars
         WHERE stock_id = ? AND ts BETWEEN ? AND ? ORDER BY ts"
    );
    $stmt->execute([$stockId, $first, $last]);
    $facts = [];
    foreach ($stmt->fetchAll() as $r) {
        $facts[$r['ts']] = (float)$r['close'];
    }

    $rows = [];
    $sumAbsPct = 0.0; $n = 0; $hit = 0;
    $prevFact = $basePrice;
    foreach ($priced as $p) {
        $date = $p['time'];
        if (!isset($facts[$date])) continue;
        $forecastPrice = (float)$p['value'];
        $factPrice     = $facts[$date];
        $dev = $factPrice != 0.0
            ? (($forecastPrice - $factPrice) / $factPrice) * 100.0
            : null;
        $dirF = $forecastPrice > ($prevFact ?? $basePrice) ? 1 : -1;
        $dirT = $factPrice     > ($prevFact ?? $basePrice) ? 1 : -1;
        if ($dirF === $dirT) $hit++;
        $status = '—';
        if ($dev !== null) {
            $a = abs($dev);
            if ($a <= 2.0)     $status = 'сбылся';
            elseif ($a <= 5.0) $status = 'частично';
            else               $status = 'не сбылся';
        }
        $rows[] = [
            'time'          => $date,
            'forecast'      => round($forecastPrice, 4),
            'fact'          => round($factPrice, 4),
            'deviation_pct' => $dev !== null ? round($dev, 2) : null,
            'status'        => $status,
        ];
        if ($dev !== null) { $sumAbsPct += abs($dev); $n++; }
        $prevFact = $factPrice;
    }
    return [
        'rows'       => $rows,
        'mape'       => $n > 0 ? round($sumAbsPct / $n, 2) : null,
        'hit_rate'   => $n > 0 ? round($hit / $n, 4) : null,
        'count'      => $n,
        'base_price' => $basePrice,
    ];
}

function content_has_dates_in_range(string $content, string $from, string $to): bool {
    $fromMonth = substr($from, 0, 7);
    $toMonth   = substr($to, 0, 7);
    if (strpos($content, $fromMonth) !== false) return true;
    if (strpos($content, $toMonth) !== false) return true;
    $fromDayMonth = substr($from, 8, 2) . ' ' . substr($from, 5, 2);
    $toDayMonth   = substr($to, 8, 2)   . ' ' . substr($to, 5, 2);
    if (strpos($content, $fromDayMonth) !== false) return true;
    if (strpos($content, $toDayMonth) !== false) return true;
    return false;
}

function recent_hits(int $days = 7, float $maxDevPct = 2.0, int $limit = 10, bool $forceRefresh = false): array {
    $cacheFile = __DIR__ . '/../data/recent_hits_cache.json';
    $lockFile  = __DIR__ . '/../data/recent_hits.lock';
    $ttl = 300;

    if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        $j = json_decode(file_get_contents($cacheFile), true);
        if (is_array($j)) return $j;
    }

    $lock = @fopen($lockFile, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_file($cacheFile)) {
            $j = json_decode(file_get_contents($cacheFile), true);
            if (is_array($j)) return $j;
        }
        return [];
    }

    $startTime = microtime(true);
    $budget    = 8.0;

    $from = date('Y-m-d', time() - $days * 86400);
    $to   = date('Y-m-d');

    $rows = db()->query(
        "SELECT market, ticker, line_num, value_type, content
         FROM forecast_lines"
    )->fetchAll();

    $hits = [];
    $checked = 0;
    foreach ($rows as $r) {
        if (microtime(true) - $startTime > $budget) break;
        if (!content_has_dates_in_range($r['content'], $from, $to)) continue;

        $points = parse_forecast($r['content']);
        if (empty($points)) continue;

        $v = verify_forecast($r['market'], $r['ticker'], $points, $r['value_type']);
        foreach ($v['rows'] as $row) {
            if ($row['time'] < $from || $row['time'] > $to) continue;
            if ($row['status'] !== 'сбылся') continue;
            if ($row['deviation_pct'] === null) continue;
            if (abs($row['deviation_pct']) > $maxDevPct) continue;
            $hits[] = [
                'market'        => $r['market'],
                'ticker'        => $r['ticker'],
                'line_num'      => (int)$r['line_num'],
                'time'          => $row['time'],
                'forecast'      => $row['forecast'],
                'fact'          => $row['fact'],
                'deviation_pct' => $row['deviation_pct'],
            ];
        }
        $checked++;
        if ($checked >= 100) break;
    }

    usort($hits, function ($a, $b) {
        if ($a['time'] !== $b['time']) return strcmp($b['time'], $a['time']);
        return abs($a['deviation_pct']) <=> abs($b['deviation_pct']);
    });
    $hits = array_slice($hits, 0, $limit);

    @file_put_contents($cacheFile, json_encode($hits, JSON_UNESCAPED_UNICODE));

    flock($lock, LOCK_UN);
    fclose($lock);

    return $hits;
}