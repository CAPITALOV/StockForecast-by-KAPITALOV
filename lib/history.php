<?php
function stock_id_by_ticker($market, $ticker, $create = false) {
    $stmt = db()->prepare("SELECT id FROM stock_map WHERE market = ? AND ticker = ? LIMIT 1");
    $stmt->execute([$market, $ticker]);
    $id = $stmt->fetchColumn();
    if ($id !== false) return (int)$id;
    if (!$create) return null;
    $max = (int)db()->query("SELECT COALESCE(MAX(id), 999999) FROM stock_map WHERE id >= 1000000")->fetchColumn();
    $nextId = max(1000000, $max + 1);
    try {
        $ins = db()->prepare("INSERT INTO stock_map (id, market, ticker, name) VALUES (?, ?, ?, ?)");
        $ins->execute([$nextId, $market, $ticker, $ticker]);
        return $nextId;
    } catch (Throwable $e) {
        $stmt->execute([$market, $ticker]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }
}

function history_from_db($stockId, $days) {
    $from = date('Y-m-d', time() - $days * 86400);
    $stmt = db()->prepare("SELECT ts, open, high, low, close FROM history_bars WHERE stock_id = ? AND ts >= ? ORDER BY ts");
    $stmt->execute([$stockId, $from]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $c = (float)$r['close'];
        $out[] = [
            'time'  => $r['ts'],
            'open'  => $r['open'] !== null ? (float)$r['open'] : $c,
            'high'  => $r['high'] !== null ? (float)$r['high'] : $c,
            'low'   => $r['low']  !== null ? (float)$r['low']  : $c,
            'close' => $c,
            'value' => $c,
        ];
    }
    return $out;
}

function save_bars($stockId, $data, $source) {
    if (empty($data) || $stockId <= 0) return 0;
    $stmt = db()->prepare(
        "INSERT INTO history_bars (stock_id, ts, open, high, low, close, source, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE open       = VALUES(open),
                                 high       = VALUES(high),
                                 low        = VALUES(low),
                                 close      = VALUES(close),
                                 source     = VALUES(source),
                                 updated_at = VALUES(updated_at)"
    );
    $now = date('Y-m-d H:i:s');
    $n = 0;
    db()->beginTransaction();
    try {
        foreach ($data as $row) {
            $t = (string)($row['time'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) continue;
            $c = $row['close'] ?? $row['value'] ?? null;
            if ($c === null || !is_numeric($c)) continue;
            $o = $row['open']  ?? $c;
            $h = $row['high']  ?? $c;
            $l = $row['low']   ?? $c;
            $stmt->execute([$stockId, $t, (float)$o, (float)$h, (float)$l, (float)$c, $source, $now]);
            $n++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return $n;
}

function history_get($market, $ticker, $days, $mode = 'auto') {
    $stockId = stock_id_by_ticker($market, $ticker, true);
    $db = [];

    // ─── 1. Читаем из БД (кроме mode='live') ─────────────────────
    if ($mode !== 'live' && $stockId !== null) {
        $db = history_from_db($stockId, $days);

        if ($mode === 'db') {
            return ['data' => $db, 'source' => 'db'];
        }

        // Если БД ПОКРЫВАЕТ запрошенный период — возвращаем как есть
        if (!empty($db)) {
            $earliestDb   = $db[0]['time'];
            $expectedFrom = date('Y-m-d', time() - $days * 86400);
            if ($earliestDb <= $expectedFrom) {
                return ['data' => $db, 'source' => 'db'];
            }
            // Иначе — БД не покрывает диапазон, идём в live
        }
    }

    // ─── 2. Live: запрос к API ────────────────────────────────────
    $live = [];
    try {
        $live = ($market === 'moex')
            ? moex_history($ticker, $days)
            : us_history($ticker, $days);
    } catch (Throwable $e) {
        // API недоступен — вернём то, что есть в БД
        if (!empty($db)) return ['data' => $db, 'source' => 'db'];
        throw $e;
    }

    // ─── 3. ВСЕГДА сохраняем live в БД (и в auto, и в live) ──────
    if ($stockId !== null && !empty($live)) {
        try { save_bars($stockId, $live, $market); } catch (Throwable $e) {}
    }

    // ─── 4. Мержим DB + live (дедупликация по дате) ──────────────
    if (!empty($db) && !empty($live)) {
        $seen = [];
        $merged = [];
        foreach (array_merge($db, $live) as $row) {
            if (isset($seen[$row['time']])) continue;
            $seen[$row['time']] = true;
            $merged[] = $row;
        }
        usort($merged, fn($a, $b) => strcmp($a['time'], $b['time']));
        return ['data' => $merged, 'source' => 'db+live'];
    }

    if (!empty($live)) return ['data' => $live, 'source' => 'live'];
    return ['data' => $db, 'source' => 'db'];
}