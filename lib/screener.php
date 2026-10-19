<?php
/**
 * Screener — сводная точность по всем тикерам за период.
 * Возвращает массив: [['market', 'ticker', 'mape', 'hit_rate', 'count', 'best_line'], ...]
 */
function screener_data(int $days = 30, string $market = ''): array {
    $from = date('Y-m-d', time() - $days * 86400);
    $to   = date('Y-m-d');

    $sql = "SELECT DISTINCT market, ticker FROM forecast_lines";
    $params = [];
    if ($market !== '') {
        $sql .= " WHERE market = ?";
        $params[] = $market;
    }
    $sql .= " ORDER BY market, ticker";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $tickers = $stmt->fetchAll();

    $results = [];

    foreach ($tickers as $t) {
        $stmt = db()->prepare(
            "SELECT line_num, value_type, content FROM forecast_lines
             WHERE market = ? AND ticker = ?"
        );
        $stmt->execute([$t['market'], $t['ticker']]);
        $lines = $stmt->fetchAll();

        $sumAbs = 0;
        $n = 0;
        $hits = 0;
        $bestLine = null;
        $bestMape = null;

        foreach ($lines as $line) {
            $points = parse_forecast($line['content']);
            if (empty($points)) continue;

            $v = verify_forecast($t['market'], $t['ticker'], $points, $line['value_type']);
            if (empty($v['rows'])) continue;

            $base = $v['base_price'];
            $prevFact = $base;
            $lineAbs = 0;
            $lineN = 0;
            $lineHits = 0;

            foreach ($v['rows'] as $row) {
                $inRange = ($row['time'] >= $from && $row['time'] <= $to);
                $hasDev  = $row['deviation_pct'] !== null;

                if ($inRange && $hasDev) {
                    $lineAbs += abs($row['deviation_pct']);
                    $lineN++;

                    $forecastPrice = $row['forecast'];
                    $factPrice     = $row['fact'];
                    $dirF = $forecastPrice > ($prevFact ?? $base) ? 1 : -1;
                    $dirT = $factPrice     > ($prevFact ?? $base) ? 1 : -1;
                    if ($dirF === $dirT) $lineHits++;
                }
                $prevFact = $row['fact'];
            }

            if ($lineN > 0) {
                $lineMape = $lineAbs / $lineN;
                if ($bestMape === null || $lineMape < $bestMape) {
                    $bestMape = $lineMape;
                    $bestLine = (int)$line['line_num'];
                }
                $sumAbs += $lineAbs;
                $n += $lineN;
                $hits += $lineHits;
            }
        }

        if ($n > 0) {
            $results[] = [
                'market'    => $t['market'],
                'ticker'    => $t['ticker'],
                'mape'      => round($sumAbs / $n, 2),
                'hit_rate'  => round($hits / $n, 4),
                'count'     => $n,
                'best_line' => $bestLine,
            ];
        }
    }

    return $results;
}