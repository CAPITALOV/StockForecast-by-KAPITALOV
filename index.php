<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/api.php';
require __DIR__ . '/lib/history.php';
require __DIR__ . '/lib/telegram.php';
require_once is_file(__DIR__ . '/lib/forecast.php')
    ? __DIR__ . '/lib/forecast.php'
    : __DIR__ . '/forecast.php';
require_once is_file(__DIR__ . '/lib/import.php')
    ? __DIR__ . '/lib/import.php'
    : __DIR__ . '/import.php';
require_once __DIR__ . '/lib/verify.php';
require_once __DIR__ . '/lib/indicators.php';
require_once __DIR__ . '/lib/screener.php';

$route = $_GET['r'] ?? 'home';

$IS_ADMIN = function_exists('is_admin') ? (bool)is_admin() : false;

function gr() {
    $g = $_GET['granularity'] ?? 'day';
    return in_array($g, ['day','week','month'], true) ? $g : 'day';
}

if (!function_exists('lines_meta')) {
    function lines_meta(): array {
        return [
            1 => ['name' => 'Delta Close — направление тренда Close', 'color' => '#e11d48', 'public' => true],
            2 => ['name' => 'Synthetic Average',                       'color' => '#f59e0b', 'public' => true],
            3 => ['name' => 'RPO — Weighted average price',            'color' => '#2563eb', 'public' => true],
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

switch ($route) {

    // ---------- API: История ----------
    case 'api-history':
        header('Content-Type: application/json');
        $market = $_GET['market'] ?? 'moex';
        $ticker = strtoupper(trim($_GET['ticker'] ?? ''));
        $days   = max(30, min(18250, (int)($_GET['days'] ?? DEFAULT_DAYS)));
        $mode   = in_array($_GET['source'] ?? '', ['db','live','auto'], true) ? $_GET['source'] : 'auto';
        $g = gr();
        if ($ticker === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'ticker required']); break; }
        try {
            $res = history_get($market, $ticker, $days, $mode);
            $data = aggregate_bars($res['data'], $g);
            echo json_encode(['ok' => true, 'source' => $res['source'], 'granularity' => $g, 'data' => $data], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
        break;

    // ---------- API: Прогнозы ----------
    case 'api-forecast':
        header('Content-Type: application/json');
        $market = $_GET['market'] ?? 'moex';
        $ticker = strtoupper(trim($_GET['ticker'] ?? ''));
        $g = gr();
        $pdo = db();
        $found = [];
        
        // 1. Загружаем линии 1 и 3 из старой таблицы
        if ($ticker !== '') {
            $stmt = $pdo->prepare("SELECT line_num, content, value_type FROM forecast_lines WHERE market = ? AND ticker = ? AND line_num IN (1,3)");
            $stmt->execute([$market, $ticker]);
            foreach ($stmt->fetchAll() as $r) $found[(int)$r['line_num']] = $r;
        }
        
        $meta = lines_meta();
        $raw = [];
        for ($i = 1; $i <= 3; $i++) {
            $row = $found[$i] ?? null;
            $raw[$i] = [
                'value_type' => $row['value_type'] ?? 'delta',
                'data'       => $row ? parse_forecast($row['content']) : [],
                'computed'   => false,
            ];
        }

        // 2. ДОБАВЛЕНО: Загружаем Линию 2 (Синтетическую) из synthetic_components
        if ($ticker !== '') {
            $stmtSynth = $pdo->prepare(
                "SELECT date, synthetic, trade_action 
                 FROM synthetic_components 
                 WHERE market = ? AND ticker = ? 
                 ORDER BY date ASC"
            );
            $stmtSynth->execute([$market, $ticker]);
            $synthRows = $stmtSynth->fetchAll();
            
            if (!empty($synthRows)) {
                $raw[2]['value_type'] = 'delta'; // Использовать правую ось (как у дельт)
                $raw[2]['computed'] = true;
                foreach ($synthRows as $r) {
                    $raw[2]['data'][] = [
                        'time' => $r['date'],
                        'value' => (float)$r['synthetic'],
                        'signal' => $r['trade_action'] // Передаем buy/sell на фронт
                    ];
                }
            }
        }

        // Фолбэк, если синтетики для тикера еще нет
        if (empty($raw[2]['data']) && !empty($raw[1]['data']) && !empty($raw[3]['data'])
            && $raw[1]['value_type'] === $raw[3]['value_type']) {
            $raw[2]['data']       = average_forecast_lines($raw[1]['data'], $raw[3]['data']);
            $raw[2]['value_type'] = $raw[1]['value_type'];
            $raw[2]['computed']   = true;
        }

        $lines = [];
        for ($i = 1; $i <= 3; $i++) {
            $isPublic = $meta[$i]['public'] ?? false;
            if (!$isPublic && !$IS_ADMIN) continue;
            
            // Если это Линия 2 и у нее есть сигналы, НЕ агрегируем (чтобы не потерять метки buy/sell)
            if ($i === 2 && !empty($raw[2]['data']) && isset($raw[2]['data'][0]['signal'])) {
                $aggr = $raw[2]['data'];
            } else {
                $aggr = aggregate_forecast_lines($raw[$i]['data'], $raw[$i]['value_type'], $g);
            }
            
            $lines[] = [
                'line_num'   => $i,
                'name'       => $meta[$i]['name'] . ($raw[$i]['computed'] ? ' · авто' : ''),
                'value_type' => $raw[$i]['value_type'],
                'color'      => $meta[$i]['color'],
                'data'       => $aggr,
                'points'     => count($aggr),
                'computed'   => $raw[$i]['computed'],
            ];
        }
        echo json_encode(['lines' => $lines], JSON_UNESCAPED_UNICODE);
        break;

    // ---------- API: Тикеры ----------
    case 'api-tickers':
        header('Content-Type: application/json');
        $market = $_GET['market'] ?? 'moex';
        $list = ($market === 'moex') ? moex_shares() : us_stocks_list();
        echo json_encode(['ok' => true, 'tickers' => $list], JSON_UNESCAPED_UNICODE);
        break;

    // ---------- API: RSI ----------
    case 'api-rsi':
        header('Content-Type: application/json');
        $market = $_GET['market'] ?? 'moex';
        $ticker = strtoupper(trim($_GET['ticker'] ?? ''));
        $days   = max(30, min(18250, (int)($_GET['days'] ?? DEFAULT_DAYS)));
        $period = max(2, min(100, (int)($_GET['period'] ?? 14)));
        $g = gr();
        if ($ticker === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'ticker required']); break; }
        try {
            $res = history_get($market, $ticker, $days, 'auto');
            $bars = aggregate_bars($res['data'], $g);
            $rsi = calc_rsi($bars, $period);
            echo json_encode(['ok' => true, 'period' => $period, 'granularity' => $g, 'data' => $rsi], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
        break;

    // ---------- API: MA ----------
    case 'api-ma':
        header('Content-Type: application/json');
        $market = $_GET['market'] ?? 'moex';
        $ticker = strtoupper(trim($_GET['ticker'] ?? ''));
        $days   = max(30, min(18250, (int)($_GET['days'] ?? DEFAULT_DAYS)));
        $period = max(2, min(200, (int)($_GET['period'] ?? 20)));
        $type   = in_array($_GET['type'] ?? '', ['sma','ema'], true) ? $_GET['type'] : 'sma';
        $g = gr();
        if ($ticker === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'ticker required']); break; }
        try {
            $res = history_get($market, $ticker, $days, 'auto');
            $bars = aggregate_bars($res['data'], $g);
            $ma = ($type === 'ema') ? calc_ema($bars, $period) : calc_sma($bars, $period);
            echo json_encode(['ok' => true, 'type' => $type, 'period' => $period, 'granularity' => $g, 'bars' => count($bars), 'data' => $ma], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
        break;

    // ---------- API: MACD ----------
    case 'api-macd':
        header('Content-Type: application/json');
        $market = $_GET['market'] ?? 'moex';
        $ticker = strtoupper(trim($_GET['ticker'] ?? ''));
        $days = max(30, min(18250, (int)($_GET['days'] ?? DEFAULT_DAYS)));
        $g = gr();
        $fast = max(2, min(100, (int)($_GET['fast'] ?? 12)));
        $slow = max(2, min(200, (int)($_GET['slow'] ?? 26)));
        $signal = max(2, min(100, (int)($_GET['signal'] ?? 9)));
        if ($fast >= $slow) $fast = max(2, $slow - 1);
        if ($ticker === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'ticker required']); break; }
        try {
            $res = history_get($market, $ticker, $days, 'auto');
            $bars = aggregate_bars($res['data'], $g);
            $macd = calc_macd($bars, $fast, $slow, $signal);
            echo json_encode(['ok' => true, 'fast' => $fast, 'slow' => $slow, 'signal' => $signal, 'granularity' => $g, 'bars' => count($bars), 'data' => $macd], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
        break;

    // ---------- API: Bollinger ----------
    case 'api-bollinger':
        header('Content-Type: application/json');
        $market = $_GET['market'] ?? 'moex';
        $ticker = strtoupper(trim($_GET['ticker'] ?? ''));
        $days = max(30, min(18250, (int)($_GET['days'] ?? DEFAULT_DAYS)));
        $g = gr();
        $period = max(2, min(100, (int)($_GET['period'] ?? 20)));
        $mult = max(0.5, min(5.0, (float)($_GET['mult'] ?? 2.0)));
        if ($ticker === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'ticker required']); break; }
        try {
            $res = history_get($market, $ticker, $days, 'auto');
            $bars = aggregate_bars($res['data'], $g);
            $bb = calc_bollinger($bars, $period, $mult);
            echo json_encode(['ok' => true, 'period' => $period, 'mult' => $mult, 'granularity' => $g, 'bars' => count($bars), 'data' => $bb], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
        break;

    // ---------- API: Верификация ----------
    case 'api-verify':
        header('Content-Type: application/json');
        $market = $_GET['market'] ?? 'moex';
        $ticker = strtoupper(trim($_GET['ticker'] ?? ''));
        if ($ticker === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'ticker required']); break; }
        $pdo = db();
        echo json_encode(verify_all_lines($market, $ticker, $pdo, $IS_ADMIN), JSON_UNESCAPED_UNICODE);
        break;

    // ---------- API: Сравнение ----------
    case 'api-compare':
        header('Content-Type: application/json');
        $market  = $_GET['market'] ?? 'moex';
        $days    = max(30, min(18250, (int)($_GET['days'] ?? DEFAULT_DAYS)));
        $g       = gr();
        $tickers = array_filter(array_map('trim', explode(',', $_GET['tickers'] ?? '')));
        $tickers = array_slice($tickers, 0, 5);
        if (empty($tickers)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'tickers required']); break; }
        $colors = ['#ef5350', '#42a5f5', '#ffa726', '#66bb6a', '#ab47bc'];
        $meta   = lines_meta();                                            
        $series = [];
        foreach ($tickers as $idx => $t) {
            $t = strtoupper($t);
            try {
                $res = history_get($market, $t, $days, 'auto');
                $bars = aggregate_bars($res['data'], $g);
                if (empty($bars)) continue;
                $base = (float)$bars[0]['value']; if ($base <= 0) $base = 1.0;
                $norm = [];
                foreach ($bars as $b) $norm[] = ['time' => $b['time'], 'value' => round($b['value'] / $base * 100, 2)];
                $acc = [];
                $stmt = db()->prepare("SELECT line_num, value_type, content FROM forecast_lines WHERE market = ? AND ticker = ?");
                $stmt->execute([$market, $t]);
                foreach ($stmt->fetchAll() as $r) {
                    $num = (int)$r['line_num'];
                    $isPublic = $meta[$num]['public'] ?? false;                
                    if (!$isPublic && !$IS_ADMIN) continue;

                    $points = parse_forecast($r['content']);
                    $v = verify_forecast($market, $t, $points, $r['value_type']);
                    if ($v['count'] > 0) {
                        $acc[] = ['line' => $num, 'mape' => $v['mape'], 'hit' => $v['hit_rate'], 'n' => $v['count']];
                    }
                }
                $series[] = ['ticker' => $t, 'color' => $colors[$idx % count($colors)], 'data' => $norm, 'accuracy' => $acc];
            } catch (Throwable $e) {}
        }
        echo json_encode(['ok' => true, 'granularity' => $g, 'series' => $series], JSON_UNESCAPED_UNICODE);
        break;

    // ---------- API: Свежие попадания ----------
    case 'api-recent-hits':
        header('Content-Type: application/json');
        @set_time_limit(12);
        $days   = max(1, min(30, (int)($_GET['days'] ?? 7)));
        $maxDev = max(0.5, min(10, (float)($_GET['max_dev'] ?? 2.0)));
        $limit  = max(1, min(20, (int)($_GET['limit'] ?? 8)));
        try {
            $hits = recent_hits($days, $maxDev, $limit);
            echo json_encode(['ok' => true, 'hits' => $hits], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
        break;

    // ---------- Экспорт: верификация ----------
    case 'export-verify':
        $market = $_GET['market'] ?? 'moex';
        $ticker = strtoupper(trim($_GET['ticker'] ?? ''));
        if ($ticker === '') { http_response_code(400); exit; }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="verify_' . $market . '_' . $ticker . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Биржа','Тикер','Линия','Тип','Дата','Прогноз','Факт','Отклонение %','Статус'], ';');
        $meta = lines_meta();                                              
        $stmt = db()->prepare("SELECT line_num, value_type, content FROM forecast_lines WHERE market = ? AND ticker = ? ORDER BY line_num");
        $stmt->execute([$market, $ticker]);
        foreach ($stmt->fetchAll() as $r) {
            $num = (int)$r['line_num'];
            $isPublic = $meta[$num]['public'] ?? false;                    
            if (!$isPublic && !$IS_ADMIN) continue;

            $points = parse_forecast($r['content']);
            $v = verify_forecast($market, $ticker, $points, $r['value_type']);
            foreach ($v['rows'] as $row) {
                fputcsv($out, [
                    $market, $ticker, $num, $r['value_type'],
                    $row['time'], $row['forecast'], $row['fact'],
                    $row['deviation_pct'] !== null ? $row['deviation_pct'] : '',
                    $row['status'],
                ], ';');
            }
        }
        fclose($out);
        exit;

    // ---------- Экспорт: точность ----------
    case 'export-accuracy':
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="accuracy_' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Биржа','Тикер','Линия','Тип','MAPE %','Hit-rate %','Точек'], ';');
        $meta = lines_meta();                                              
        $rows = db()->query("SELECT market, ticker, line_num, value_type, content FROM forecast_lines ORDER BY market, ticker, line_num")->fetchAll();
        foreach ($rows as $r) {
            $num = (int)$r['line_num'];
            $isPublic = $meta[$num]['public'] ?? false;                    
            if (!$isPublic && !$IS_ADMIN) continue;

            $points = parse_forecast($r['content']);
            $v = verify_forecast($r['market'], $r['ticker'], $points, $r['value_type']);
            if ($v['count'] === 0) continue;
            fputcsv($out, [
                $r['market'], $r['ticker'], $num, $r['value_type'],
                $v['mape'], $v['hit_rate'] !== null ? round($v['hit_rate'] * 100, 2) : '', $v['count'],
            ], ';');
        }
        fclose($out);
        exit;

    // ---------- Экспорт: screener ----------
    case 'export-screener':
        $days   = max(7, min(365, (int)($_GET['days'] ?? 30)));
        $market = in_array($_GET['market'] ?? '', ['moex','us'], true) ? $_GET['market'] : '';
        $sort   = in_array($_GET['sort'] ?? '', ['mape','hit','count','ticker'], true) ? $_GET['sort'] : 'mape';
        $results = screener_data($days, $market);
        usort($results, function ($a, $b) use ($sort) {
            switch ($sort) {
                case 'hit':    return $b['hit_rate'] <=> $a['hit_rate'];
                case 'count':  return $b['count']    <=> $a['count'];
                case 'ticker': return strcmp($a['market'] . ':' . $a['ticker'], $b['market'] . ':' . $b['ticker']);
                default:       return $a['mape'] <=> $b['mape'];
            }
        });
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="screener_' . date('Y-m-d') . '_' . $days . 'd.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Биржа','Тикер','MAPE %','Hit-rate %','Точек','Лучшая линия'], ';');
        foreach ($results as $r) {
            fputcsv($out, [$r['market'], $r['ticker'], $r['mape'], round($r['hit_rate'] * 100, 2), $r['count'], $r['best_line']], ';');
        }
        fclose($out);
        exit;

    // ---------- Страницы ----------
    case 'accuracy':    include __DIR__ . '/pages/accuracy.php'; break;
    case 'screener':    include __DIR__ . '/pages/screener.php'; break;
    case 'compare':     include __DIR__ . '/pages/compare.php'; break;
    case 'news':        include __DIR__ . '/pages/news.php'; break;
    case 'stock':       include __DIR__ . '/pages/stock.php'; break;
    case 'admin-login': include __DIR__ . '/pages/admin-login.php'; break;
    case 'admin':       require_admin(); include __DIR__ . '/pages/admin.php'; break;

    // ---------- Админка: Бэкфилл истории ----------
    case 'backfill-history':
        require_admin();
        @set_time_limit(55);
        @ignore_user_abort(false);
        if (!empty($_GET['reset'])) db()->exec("UPDATE stock_map SET history_skip_until = NULL WHERE history_skip_until IS NOT NULL");
        $isAjax    = !empty($_GET['ajax']);
        $days      = max(365, min(18250, (int)($_GET['days'] ?? 7300)));
        $startTime = time();
        $maxDur    = 20;
        $processed = 0;
        $failed    = [];
        while (true) {
            if (time() - $startTime > $maxDur) break;
            $row = db()->query(
                "SELECT DISTINCT fl.market, fl.ticker FROM forecast_lines fl
                 LEFT JOIN stock_map sm ON sm.market = fl.market AND sm.ticker = fl.ticker
                 LEFT JOIN history_bars hb ON hb.stock_id = sm.id
                 WHERE hb.stock_id IS NULL
                   AND (sm.history_skip_until IS NULL OR sm.history_skip_until < NOW())
                 LIMIT 1"
            )->fetch();
            if (!$row) break;
            try {
                $res = history_get($row['market'], $row['ticker'], $days, 'live');
                if (!empty($res['data'])) $processed++;
                else {
                    $failed[] = $row['market'] . ':' . $row['ticker'];
                    db()->prepare("UPDATE stock_map SET history_skip_until = NOW() + INTERVAL 24 HOUR WHERE market = ? AND ticker = ?")->execute([$row['market'], $row['ticker']]);
                }
            } catch (Throwable $e) {
                $failed[] = $row['market'] . ':' . $row['ticker'];
                db()->prepare("UPDATE stock_map SET history_skip_until = NOW() + INTERVAL 24 HOUR WHERE market = ? AND ticker = ?")->execute([$row['market'], $row['ticker']]);
            }
            break;
        }
        $remaining = (int)db()->query(
            "SELECT COUNT(DISTINCT CONCAT(fl.market, ':', fl.ticker)) FROM forecast_lines fl
             LEFT JOIN stock_map sm ON sm.market = fl.market AND sm.ticker = fl.ticker
             LEFT JOIN history_bars hb ON hb.stock_id = sm.id
             WHERE hb.stock_id IS NULL AND (sm.history_skip_until IS NULL OR sm.history_skip_until < NOW())"
        )->fetchColumn();
        $skipped = (int)db()->query("SELECT COUNT(*) FROM stock_map WHERE history_skip_until > NOW()")->fetchColumn();
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'processed' => $processed, 'failed' => $failed, 'remaining' => $remaining, 'skipped' => $skipped, 'days' => $days], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $msg = "История (за {$days} дн.): загружено {$processed}";
        if ($failed) $msg .= ', не удалось ' . count($failed);
        if ($remaining > 0) $msg .= ". Осталось: {$remaining}";
        if ($skipped > 0) $msg .= ". Пропущено на 24ч: {$skipped}";
        flash($msg);
        header('Location: ' . url('accuracy'));
        break;

    // ---------- Админка: Анонсы ----------
    case 'admin-announcement-add':
        require_admin();
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $imagePath = null;
        if ($title === '' || $content === '') { flash('Заполните заголовок и текст новости'); header('Location: ' . url('admin')); exit; }
        if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
            finfo_close($finfo);
            if (in_array($mime, $allowed)) {
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('news_') . '.' . $ext;
                $dest = __DIR__ . '/static/uploads/' . $filename;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) $imagePath = 'static/uploads/' . $filename;
            }
        }
        db()->prepare("INSERT INTO announcements (title, content, image) VALUES (?, ?, ?)")->execute([$title, $content, $imagePath]);
        flash('Анонс успешно добавлен');
        $tgMsg  = "📰 <b>Новая новость на StockForecast!</b>\n\n";
        $tgMsg .= "🔹 <b>{$title}</b>\n";
        $tgMsg .= mb_substr($content, 0, 300) . (mb_strlen($content) > 300 ? '...' : '');
        $tgMsg .= "\n\n👉 <a href='https://forecast.sotis.org/index.php?r=news'>Читать на сайте</a>";
        telegram_send($tgMsg);
        header('Location: ' . url('admin'));
        break;

    case 'admin-announcement-delete':
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = db()->prepare("SELECT image FROM announcements WHERE id = ?");
            $stmt->execute([$id]);
            $img = $stmt->fetchColumn();
            if ($img && is_file(__DIR__ . '/' . $img)) @unlink(__DIR__ . '/' . $img);
            db()->prepare("DELETE FROM announcements WHERE id = ?")->execute([$id]);
            flash('Анонс удалён');
        }
        header('Location: ' . url('admin'));
        break;

    case 'admin-settings':
        require_admin();
        $type = ($_POST['chart_type'] ?? 'line') === 'candles' ? 'candles' : 'line';
        set_setting('chart_type', $type);
        flash('Настройки сохранены');
        header('Location: ' . url('admin'));
        break;

    case 'admin-upload':
        require_admin();
        $market     = $_POST['market'] ?? '';
        $ticker     = strtoupper(trim($_POST['ticker'] ?? ''));
        $line       = (int)($_POST['line_num'] ?? 0);
        $value_type = ($_POST['value_type'] ?? 'price') === 'delta' ? 'delta' : 'price';
        if (!in_array($market, ['moex', 'us'], true)) { flash('Неверная биржа'); header('Location: ' . url('admin')); exit; }
        if ($ticker === '' || !preg_match('/^[A-Z0-9._-]{1,32}$/', $ticker)) { flash('Укажите корректный тикер'); header('Location: ' . url('admin')); exit; }
        if ($line < 1 || $line > 3) { flash('Неверный номер линии'); header('Location: ' . url('admin')); exit; }
        $warn = '';
        if (in_array($line, [1, 3], true) && $value_type !== 'delta') $warn = ' · ВНИМАНИЕ: Линия ' . $line . ' должна иметь тип delta, а не price';
        $content = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) $content = file_get_contents($_FILES['file']['tmp_name']);
        elseif (!empty($_POST['content'])) $content = $_POST['content'];
        if (trim($content) === '') {
            flash('Файл пустой — нечего сохранять');
        } else {
            $stmt = db()->prepare(
                "INSERT INTO forecast_lines (market, ticker, line_num, value_type, content, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value_type = VALUES(value_type), content = VALUES(content), updated_at = VALUES(updated_at)"
            );
            $stmt->execute([$market, $ticker, $line, $value_type, $content, date('Y-m-d H:i:s')]);
            $parsed = count(parse_forecast($content));
            flash("Линия {$line} для {$market}:{$ticker} — сохранено {$parsed} точек (тип: {$value_type}){$warn}");
        }
        header('Location: ' . url('admin'));
        break;

    case 'admin-import-stocks':
        require_admin();
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) { flash('Файл не загружен'); header('Location: ' . url('admin')); exit; }
        $csv = file_get_contents($_FILES['file']['tmp_name']);
        $res = import_stock_map($csv, db());
        $msg = "Справочник: добавлено {$res['added']}, пропущено {$res['skipped']}";
        if (!empty($res['errors'])) $msg .= ' | Ошибки: ' . implode('; ', array_slice($res['errors'], 0, 3));
        flash($msg); header('Location: ' . url('admin'));
        break;

    case 'admin-import-forecasts':
        require_admin();
        $lineNum = (int)($_POST['line_num'] ?? 1);
        if ($lineNum < 1 || $lineNum > 3) $lineNum = 1;
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) { flash('Файл не загружен'); header('Location: ' . url('admin')); exit; }
        $csv = file_get_contents($_FILES['file']['tmp_name']);
        $res = import_forecasts($csv, db(), $lineNum);
        $msg = "Прогнозы (линия {$lineNum}): групп {$res['groups']}, точек {$res['points']}";
        if (!empty($res['errors'])) $msg .= ' | Проблемы: ' . implode('; ', array_slice($res['errors'], 0, 3));
        flash($msg); header('Location: ' . url('admin'));
        break;

    case 'admin-clear-stock-map':
        require_admin();
        db()->exec("DELETE FROM stock_map");
        flash('Справочник очищен');
        header('Location: ' . url('admin'));
        break;

    case 'admin-clear-cache':
        require_admin();
        @unlink(__DIR__ . '/data/recent_hits_cache.json');
        @unlink(__DIR__ . '/data/moex_shares.json');
        @unlink(__DIR__ . '/data/accuracy_cache.json');
        @unlink(__DIR__ . '/data/news_cache.json');
        db()->exec("UPDATE stock_map SET history_skip_until = NULL WHERE history_skip_until IS NOT NULL");
        flash('Кэш очищен, пропуски сброшены');
        header('Location: ' . url('admin'));
        break;

    case 'admin-delete':
        require_admin();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare("DELETE FROM forecast_lines WHERE id = ?")->execute([$id]);
            flash('Прогноз удалён');
        }
        header('Location: ' . url('admin'));
        break;

    case 'admin-password':
        require_admin();
        $new = $_POST['new_password'] ?? '';
        if (strlen($new) < 6) {
            flash('Пароль должен быть не короче 6 символов');
        } else {
            db()->prepare("UPDATE admins SET password_hash = ? WHERE username = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin']]);
            flash('Пароль обновлён');
        }
        header('Location: ' . url('admin'));
        break;

    case 'admin-logout':
        session_destroy();
        header('Location: ' . url('home'));
        break;

    default:
        include __DIR__ . '/pages/home.php';
}