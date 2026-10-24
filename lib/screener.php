<?php
declare(strict_types=1);

/**
 * Чтение данных из кэша для Скринера.
 */
function screener_data(int $days = 30, string $market = '', string $signal_filter = ''): array {
    $cacheFile = __DIR__ . '/../data/screener_cache.json';
    
    if (!file_exists($cacheFile)) {
        return [];
    }
    
    $json = file_get_contents($cacheFile);
    $cache = json_decode($json, true) ?: [];
    
    $data = $cache[$days] ?? [];
    $results = [];

    foreach ($data as $row) {
        if ($market !== '' && $row['market'] !== $market) continue;
        if ($signal_filter !== '' && $row['current_signal'] !== $signal_filter) continue;
        $results[] = $row;
    }

    return $results;
}

/**
 * Тяжелая функция для генерации кэша (запускается только из Cron).
 */
function screener_generate_cache(): void {
    ini_set('memory_limit', '512M');
    set_time_limit(600);

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }

    $periods = [7, 30, 90, 180, 365];
    $cache = [];

    $sql = "WITH LatestSignals AS (
                SELECT market, ticker, trade_action,
                       ROW_NUMBER() OVER(PARTITION BY market, ticker ORDER BY date DESC) as rn
                FROM synthetic_components
            )
            SELECT fl.market, fl.ticker, ls.trade_action AS current_signal
            FROM forecast_lines fl
            LEFT JOIN LatestSignals ls ON ls.market = fl.market AND ls.ticker = fl.ticker AND ls.rn = 1
            GROUP BY fl.market, fl.ticker, ls.trade_action
            ORDER BY fl.market, fl.ticker";

    $stmt = db()->query($sql);
    $tickers = $stmt->fetchAll();
    
    echo "Найдено тикеров: " . count($tickers) . "\n";

    foreach ($periods as $days) {
        $from = date('Y-m-d', time() - $days * 86400);
        $to   = date('Y-m-d');
        $cache[$days] = [];
        
        echo "Считаем период {$days} дней...\n";

        foreach ($tickers as $t) {
            $current_signal = $t['current_signal'] ?? 'neutral';

            $stmt = db()->prepare("SELECT line_num, value_type, content FROM forecast_lines WHERE market = ? AND ticker = ?");
            $stmt->execute([$t['market'], $t['ticker']]);
            $lines = $stmt->fetchAll();

            $sumAbs = 0; 
            $n_mape = 0; 
            $hits = 0; 
            $n_hit = 0;
            $bestLine = null; 
            $bestMape = null;

            foreach ($lines as $line) {
                $points = parse_forecast($line['content']);
                if (empty($points)) continue;

                $v = verify_forecast($t['market'], $t['ticker'], $points, $line['value_type']);
                if (empty($v['rows'])) continue;

                $lineAbs = 0; 
                $lineN_mape = 0; 
                $lineHits = 0; 
                $lineN_hit = 0;

                foreach ($v['rows'] as $row) {
                    // Строго проверяем, что есть время и фактическая доходность
                    if (empty($row['time']) || !isset($row['actual_return']) || $row['actual_return'] === null) {
                        continue;
                    }

                    $rowTime = substr($row['time'], 0, 10); 
                    $inRange = ($rowTime >= $from && $rowTime <= $to);

                    if ($inRange) {
                        // Считаем MAPE (только если есть deviation_pct)
                        if (isset($row['deviation_pct']) && $row['deviation_pct'] !== null) {
                            $lineAbs += abs($row['deviation_pct']);
                            $lineN_mape++;
                        }
                        
                        // Считаем Hit-rate по направлениям delta и actual_return
                        $sp = ($row['delta'] ?? 0) <=> 0;
                        $sa = ($row['actual_return'] ?? 0) <=> 0;
                        
                        if ($sp !== 0) {
                            $lineN_hit++;
                            if ($sp === $sa) $lineHits++;
                        }
                    }
                }

                if ($lineN_mape > 0) {
                    $lineMape = $lineAbs / $lineN_mape;
                    if ($bestMape === null || $lineMape < $bestMape) {
                        $bestMape = $lineMape;
                        $bestLine = (int)$line['line_num'];
                    }
                    $sumAbs += $lineAbs;
                    $n_mape += $lineN_mape;
                }
                
                if ($lineN_hit > 0) {
                    $hits += $lineHits;
                    $n_hit += $lineN_hit;
                }
            }

            if ($n_mape > 0) {
                $cache[$days][] = [
                    'market'    => $t['market'],
                    'ticker'    => $t['ticker'],
                    'mape'      => round($sumAbs / $n_mape, 2),
                    'hit_rate'  => $n_hit > 0 ? round($hits / $n_hit, 4) : 0,
                    'count'     => $n_mape,
                    'best_line' => $bestLine,
                    'current_signal' => $current_signal
                ];
            }
        }
    }

    $cacheFile = __DIR__ . '/../data/screener_cache.json';
    $res = file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE));
    
    if ($res === false) {
        echo "ОШИБКА: Не удалось записать файл кэша!\n";
    } else {
        echo "Кэш успешно записан. Размер: " . round($res / 1024, 2) . " KB\n";
    }
}