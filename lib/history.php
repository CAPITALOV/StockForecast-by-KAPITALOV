<?php
function stock_id_by_ticker($market, $ticker, $create = false) {
    $stmt = db()->prepare(
        "SELECT id FROM stock_map WHERE market = ? AND ticker = ? LIMIT 1"
    );
    $stmt->execute([$market, $ticker]);
    $id = $stmt->fetchColumn();
    if ($id !== false) return (int)$id;
    if (!$create) return null;
    $max = (int)db()->query(
        "SELECT COALESCE(MAX(id), 999999) FROM stock_map WHERE id >= 1000000"
    )->fetchColumn();
    $nextId = max(1000000, $max + 1);
    try {
        $ins = db()->prepare(
            "INSERT INTO stock_map (id, market, ticker, name) VALUES (?, ?, ?, ?)"
        );
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
    $stmt = db()->prepare(
        "SELECT ts, close FROM history_bars
         WHERE stock_id = ? AND ts >= ? ORDER BY ts"
    );
    $stmt->execute([$stockId, $from]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = ['time' => $r['ts'], 'value' => (float)$r['close']];
    }
    return $out;
}

function save_bars($stockId, $data, $source) {
    if (empty($data) || $stockId <= 0) return 0;
    $stmt = db()->prepare(
        "INSERT INTO history_bars (stock_id, ts, close, source, updated_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE close      = VALUES(close),
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
            $v = $row['value'] ?? null;
            if ($v === null || !is_numeric($v)) continue;
            $stmt->execute([$stockId, $t, (float)$v, $source, $now]);
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
    if ($mode !== 'live' && $stockId !== null) {
        $db = history_from_db($stockId, $days);
        if (!empty($db)) return ['data' => $db, 'source' => 'db'];
        if ($mode === 'db') return ['data' => [], 'source' => 'db'];
    }
    $live = ($market === 'moex')
        ? moex_history($ticker, $days)
        : us_history($ticker, $days);
    if ($mode === 'auto' && $stockId !== null && !empty($live)) {
        try { save_bars($stockId, $live, $market); } catch (Throwable $e) {}
    }
    return ['data' => $live, 'source' => 'live'];
}