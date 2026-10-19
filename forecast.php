<?php
/**
 * Парсер TXT/CSV прогноза.
 */

function parse_forecast($content) {
    if (!is_string($content) || trim($content) === '') return [];
    $out = [];

    foreach (preg_split('/\r?\n/', $content) as $raw) {
        $line = trim($raw);
        if ($line === '' || $line[0] === '#') continue;

        // CSV: Id, StockId, Date, Delta
        if (strpos($line, ',') !== false) {
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) >= 4 && preg_match('/^\d+$/', $parts[0])) {
                $date = $parts[2];
                $val  = $parts[3];
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                    && strtotime($date) !== false) {
                    $out[] = [
                        'time'  => $date,
                        'value' => (float)str_replace(',', '.', $val),
                    ];
                    continue;
                }
            }
        }

        $line = str_replace(["\t", ';'], ' ', $line);
        $parts = preg_split('/\s+/', $line);
        if (count($parts) < 2) continue;

        // ISO: YYYY-MM-DD VALUE
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[0]) && count($parts) >= 2) {
            $out[] = [
                'time'  => $parts[0],
                'value' => (float)str_replace(',', '.', $parts[1]),
            ];
            continue;
        }

        if (count($parts) < 4) continue;

        $try = function ($d, $m, $y, $v) {
            $d = (int)$d; $m = (int)$m; $y = (int)$y;
            if (!checkdate($m, $d, $y) || $y < 1900 || $y > 2100) return null;
            return [
                'time'  => sprintf('%04d-%02d-%02d', $y, $m, $d),
                'value' => (float)str_replace(',', '.', $v),
            ];
        };

        $r = $try($parts[0], $parts[1], $parts[2], $parts[3]);
        if ($r) { $out[] = $r; continue; }

        $r = $try($parts[1], $parts[2], $parts[3], $parts[0]);
        if ($r) { $out[] = $r; }
    }

    usort($out, function ($a, $b) {
        return strcmp($a['time'], $b['time']);
    });
    return $out;
}