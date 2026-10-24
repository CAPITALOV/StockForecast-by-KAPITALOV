<?php
/**
 * lib/forecast.php — helpers + метаданные трёх линий.
 *
 * Line 2 (Synthetic) теперь считается АДАПТИВНО через calc_line2_synthetic():
 * веса w1, w3 пересчитываются по rolling hit-rate каждой линии.
 * Fallback — простое среднее average_forecast_lines().
 */

require_once __DIR__ . '/verify.php';
require_once __DIR__ . '/lines_calculator.php';
if (is_file(__DIR__ . '/history.php')) {
    require_once __DIR__ . '/history.php';   // для history_get() в adaptive synthetic
}

/* =========================================================================
 * 1. Метаданные
 * ========================================================================= */
if (!function_exists('lines_meta')) {
    function lines_meta(): array {
        return [
            1 => ['name' => 'Delta Close — направление тренда Close', 'short' => 'Delta Close', 'color' => '#e11d48', 'public' => true,  'value_type' => 'delta'],
            2 => ['name' => 'Synthetic Average',                       'short' => 'Synthetic',  'color' => '#f59e0b', 'public' => true,  'value_type' => 'delta'],
            3 => ['name' => 'RPO — Weighted average price',            'short' => 'RPO',        'color' => '#2563eb', 'public' => true,  'value_type' => 'delta'],
        ];
    }
}

if (!function_exists('lines_visible')) {
    function lines_visible(bool $isAdmin): array {
        $out = [];
        foreach (lines_meta() as $num => $m) {
            if ($m['public'] || $isAdmin) $out[$num] = $m;
        }
        return $out;
    }
}

/* =========================================================================
 * 2. parse_forecast
 * ========================================================================= */
if (!function_exists('parse_forecast')) {
    function parse_forecast(string $content): array {
        $content = trim($content);
        if ($content === '') return [];
        $rows = preg_split('/\r\n|\r|\n/', $content);
        $out = [];
        foreach ($rows as $row) {
            $row = trim($row);
            if ($row === '') continue;
            $parts = _fc_split_row($row);
            if (count($parts) < 2) continue;
            $time  = _fc_parse_date(trim($parts[0]));
            $value = _fc_parse_float(trim($parts[1]));
            if ($time === null || $value === null) continue;
            $out[] = ['time' => $time, 'value' => $value];
        }
        usort($out, fn($a, $b) => strcmp($a['time'], $b['time']));
        return $out;
    }
}

if (!function_exists('_fc_split_row')) {
    function _fc_split_row(string $row): array {
        foreach ([';', "\t"] as $sep) {
            if (strpos($row, $sep) !== false) {
                $parts = explode($sep, $row, 2);
                if (count($parts) >= 2) return $parts;
            }
        }
        if (preg_match('/^(\S+)\s+(.+)$/', $row, $m)) return [$m[1], $m[2]];
        $parts = explode(',', $row);
        if (count($parts) === 2) return $parts;
        if (count($parts) >= 3 && preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/', $parts[0])) {
            return [$parts[0], implode('.', array_slice($parts, 1))];
        }
        return $parts;
    }
}

if (!function_exists('_fc_parse_date')) {
    function _fc_parse_date(string $s): ?string {
        $s = trim($s);
        if ($s === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $s, $m)) return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        if (is_numeric($s) && (int)$s > 1000000000) return date('Y-m-d', (int)$s);
        return null;
    }
}

if (!function_exists('_fc_parse_float')) {
    function _fc_parse_float(string $s): ?float {
        $s = trim($s);
        if ($s === '') return null;
        $s = str_replace([' ', "\xC2\xA0"], '', $s);
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) return null;
        return (float)$s;
    }
}

/* =========================================================================
 * 3. aggregate_forecast_lines
 * ========================================================================= */
if (!function_exists('aggregate_forecast_lines')) {
    function aggregate_forecast_lines(array $data, string $value_type, string $g = 'day'): array {
        if ($g === 'day' || empty($data)) return $data;
        $buckets = [];
        foreach ($data as $p) {
            $t = $p['time'] ?? '';
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m)) continue;
            if ($g === 'month') {
                $key = sprintf('%04d-%02d', (int)$m[1], (int)$m[2]);
            } else {
                $ts  = mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
                $key = date('o-\WW', $ts);
            }
            $buckets[$key][] = $p;
        }
        $out = [];
        foreach ($buckets as $points) {
            $vals = array_column($points, 'value');
            $v = ($value_type === 'price') ? (float)end($vals) : array_sum($vals) / count($vals);
            $out[] = ['time' => end($points)['time'], 'value' => round($v, 6)];
        }
        usort($out, fn($a, $b) => strcmp($a['time'], $b['time']));
        return $out;
    }
}

/* =========================================================================
 * 4. average_forecast_lines — fallback, если адаптив недоступен
 * ========================================================================= */
if (!function_exists('average_forecast_lines')) {
    function average_forecast_lines(array $d1, array $d2): array {
        $m1 = []; foreach ($d1 as $p) $m1[$p['time']] = $p['value'];
        $m2 = []; foreach ($d2 as $p) $m2[$p['time']] = $p['value'];
        $times = array_unique(array_merge(array_keys($m1), array_keys($m2)));
        sort($times);
        $out = [];
        foreach ($times as $t) {
            $v1 = $m1[$t] ?? null;
            $v2 = $m2[$t] ?? null;
            if ($v1 === null && $v2 === null) continue;
            if ($v1 === null)      $v = (float)$v2;
            elseif ($v2 === null)  $v = (float)$v1;
            else                   $v = ((float)$v1 + (float)$v2) / 2.0;
            $out[] = ['time' => $t, 'value' => round($v, 6)];
        }
        return $out;
    }
}

/* =========================================================================
 * 5. NEW: _get_adaptive_synthetic — Line 2 через calc_line2_synthetic()
 *
 * Берём веса w1, w3 из адаптивного алгоритма и применяем к СЫРЫМ дельтам
 * (не к z-score — иначе масштаб не совпадёт с Line 1 / Line 3).
 * ========================================================================= */
if (!function_exists('_get_adaptive_synthetic')) {
    function _get_adaptive_synthetic(
        string $market, string $ticker,
        array $parsed_1, array $parsed_3
    ): array {
        if (empty($parsed_1) || empty($parsed_3) || !function_exists('history_get')) {
            return [];
        }

        // История цен для синтеза
        $hist = [];
        try {
            $res  = history_get($market, $ticker, 3650, 'auto');
            $hist = $res['data'] ?? [];
        } catch (Throwable $e) {
            return [];
        }
        if (empty($hist)) return [];

        $closes = [];
        foreach ($hist as $bar) {
            $closes[$bar['time']] = (float)$bar['value'];
        }

        // Выравниваем по общим датам: Line 1 ∩ Line 3 ∩ history
        $m1 = [];
        foreach ($parsed_1 as $p) $m1[$p['time']] = (float)$p['value'];
        $m3 = [];
        foreach ($parsed_3 as $p) $m3[$p['time']] = (float)$p['value'];

        $dates = array_intersect(array_keys($m1), array_keys($m3), array_keys($closes));
        sort($dates);

        // Минимум 65 точек (N=60 + запас)
        if (count($dates) < 65) return [];

        $l1 = []; $l3 = []; $cl = [];
        foreach ($dates as $d) {
            $l1[] = $m1[$d];
            $l3[] = $m3[$d];
            $cl[] = $closes[$d];
        }

        $result = calc_line2_synthetic($l1, $l3, $cl);

        // Применяем веса к СЫРЫМ дельтам, а не к z-score
        $out = [];
        foreach ($dates as $i => $d) {
            if (!isset($result['w1'][$i]) || $result['w1'][$i] === null) continue;
            $w1i = (float)$result['w1'][$i];
            $w3i = (float)$result['w3'][$i];
            $synth_delta = $w1i * $l1[$i] + $w3i * $l3[$i];
            $out[] = ['time' => $d, 'value' => round($synth_delta, 6)];
        }
        return $out;
    }
}

/* =========================================================================
 * 6. build_forecast_lines — для api-forecast (график)
 * ========================================================================= */
if (!function_exists('build_forecast_lines')) {
    function build_forecast_lines(
        string $market, string $ticker, PDO $pdo,
        string $g = 'day', bool $isAdmin = false
    ): array {
        $stmt = $pdo->prepare(
            "SELECT line_num, value_type, content FROM forecast_lines
             WHERE market = ? AND ticker = ?"
        );
        $stmt->execute([$market, $ticker]);
        $found = [];
        foreach ($stmt->fetchAll() as $r) $found[(int)$r['line_num']] = $r;

        $raw = [];
        for ($i = 1; $i <= 3; $i++) {
            $row = $found[$i] ?? null;
            $raw[$i] = [
                'value_type'    => $row['value_type'] ?? 'delta',
                'data'          => $row ? parse_forecast($row['content']) : [],
                'computed'      => false,
                'computed_mode' => null,
            ];
        }

        // Line 2 отсутствует → адаптив, иначе fallback на среднее
        if (empty($raw[2]['data'])
            && !empty($raw[1]['data']) && !empty($raw[3]['data'])
            && $raw[1]['value_type'] === $raw[3]['value_type']) {

            $adaptive = _get_adaptive_synthetic($market, $ticker, $raw[1]['data'], $raw[3]['data']);
            if (!empty($adaptive)) {
                $raw[2]['data']          = $adaptive;
                $raw[2]['value_type']    = $raw[1]['value_type'];
                $raw[2]['computed']      = true;
                $raw[2]['computed_mode'] = 'adaptive';
            } else {
                $raw[2]['data']          = average_forecast_lines($raw[1]['data'], $raw[3]['data']);
                $raw[2]['value_type']    = $raw[1]['value_type'];
                $raw[2]['computed']      = true;
                $raw[2]['computed_mode'] = 'average';
            }
        }

        $meta  = lines_meta();
        $lines = [];
        for ($i = 1; $i <= 3; $i++) {
            $isPublic = $meta[$i]['public'] ?? false;
            if (!$isPublic && !$isAdmin) continue;

            $suffix = '';
            if ($raw[$i]['computed']) {
                $suffix = $raw[$i]['computed_mode'] === 'adaptive' ? ' · adaptive' : ' · avg';
            }

            $aggr = aggregate_forecast_lines($raw[$i]['data'], $raw[$i]['value_type'], $g);
            $lines[] = [
                'line_num'      => $i,
                'name'          => $meta[$i]['name'] . $suffix,
                'value_type'    => $raw[$i]['value_type'],
                'color'         => $meta[$i]['color'],
                'data'          => $aggr,
                'points'        => count($aggr),
                'computed'      => $raw[$i]['computed'],
                'computed_mode' => $raw[$i]['computed_mode'],
            ];
        }
        return ['lines' => $lines];
    }
}

/* =========================================================================
 * 7. verify_all_lines — для api-verify (таблица)
 * ========================================================================= */
if (!function_exists('verify_all_lines')) {
    function verify_all_lines(
        string $market, string $ticker, PDO $pdo, bool $isAdmin = false
    ): array {
        $meta = lines_meta();
        $stmt = $pdo->prepare(
            "SELECT line_num, value_type, content FROM forecast_lines
             WHERE market = ? AND ticker = ? ORDER BY line_num"
        );
        $stmt->execute([$market, $ticker]);

        $found = [];
        foreach ($stmt->fetchAll() as $r) $found[(int)$r['line_num']] = $r;

        $parsed = [];
        for ($i = 1; $i <= 3; $i++) {
            $parsed[$i] = isset($found[$i]) ? parse_forecast($found[$i]['content'] ?? '') : [];
        }

        // Line 2: адаптив или fallback
        $computed = [];
        $computed_mode = [];
        if (empty($parsed[2])
            && !empty($parsed[1]) && !empty($parsed[3])
            && ($found[1]['value_type'] ?? '') === ($found[3]['value_type'] ?? '')
        ) {
            $adaptive = _get_adaptive_synthetic($market, $ticker, $parsed[1], $parsed[3]);
            if (!empty($adaptive)) {
                $computed[2]      = ['value_type' => $found[1]['value_type'], 'points' => $adaptive];
                $computed_mode[2] = 'adaptive';
            } else {
                $computed[2]      = ['value_type' => $found[1]['value_type'], 'points' => average_forecast_lines($parsed[1], $parsed[3])];
                $computed_mode[2] = 'average';
            }
        }

        $lines = [];
        for ($num = 1; $num <= 3; $num++) {
            if (!isset($meta[$num])) continue;
            $isPublic = $meta[$num]['public'] ?? false;
            if (!$isPublic && !$isAdmin) continue;

            $isComputed = isset($computed[$num]);
            if (!$isComputed && empty($parsed[$num]) && !isset($found[$num])) continue;

            if ($isComputed) {
                $points     = $computed[$num]['points'];
                $value_type = $computed[$num]['value_type'];
            } else {
                $points     = $parsed[$num];
                $value_type = $found[$num]['value_type'] ?? 'delta';
            }

            $v = verify_forecast($market, $ticker, $points, $value_type);

            $suffix = '';
            if ($isComputed) {
                $suffix = ($computed_mode[$num] ?? 'average') === 'adaptive' ? ' · adaptive' : ' · avg';
            }

            $lines[] = [
                'line_num'      => $num,
                'name'          => $meta[$num]['name'] . $suffix,
                'color'         => $meta[$num]['color'],
                'value_type'    => $value_type,
                'computed'      => $isComputed,
                'computed_mode' => $isComputed ? ($computed_mode[$num] ?? null) : null,
                'verify'        => $v,
            ];
        }
        return ['ok' => true, 'isAdmin' => $isAdmin, 'lines' => $lines];
    }
}

/* =========================================================================
 * 8. handle_forecast_route — опциональный вход
 * ========================================================================= */
if (!function_exists('handle_forecast_route')) {
    function handle_forecast_route(string $route, array $get, ?PDO $pdo = null): void {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if ($pdo === null && function_exists('db')) $pdo = db();
            if (!$pdo instanceof PDO) throw new RuntimeException('No PDO');

            $market  = $get['market'] ?? 'moex';
            $ticker  = strtoupper(trim($get['ticker'] ?? ''));
            if ($ticker === '') throw new InvalidArgumentException('ticker required');

            $isAdmin = function_exists('is_admin') ? (bool)is_admin() : false;

            if ($route === 'api-forecast') {
                $g = $get['granularity'] ?? 'day';
                if (!in_array($g, ['day','week','month'], true)) $g = 'day';
                echo json_encode(build_forecast_lines($market, $ticker, $pdo, $g, $isAdmin), JSON_UNESCAPED_UNICODE);
            } elseif ($route === 'api-verify') {
                echo json_encode(verify_all_lines($market, $ticker, $pdo, $isAdmin), JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'unknown route']);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}