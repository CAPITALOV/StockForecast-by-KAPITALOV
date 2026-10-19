<?php
$pdo = db();
$cacheFile = __DIR__ . '/../data/accuracy_cache.json';

$maxForecast = (string)$pdo->query("SELECT MAX(updated_at) FROM forecast_lines")->fetchColumn();
$maxHistory  = (string)$pdo->query("SELECT MAX(updated_at) FROM history_bars")->fetchColumn();
$cacheKey    = $maxForecast . '|' . $maxHistory;

$all = null;
$missing = [];
if (is_file($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cached) && ($cached['key'] ?? '') === $cacheKey) {
        $all = $cached['all'];
        $missing = $cached['missing'] ?? [];
    }
}

if ($all === null) {
    $rows = $pdo->query(
        "SELECT market, ticker, line_num, value_type, content
         FROM forecast_lines ORDER BY market, ticker, line_num"
    )->fetchAll();

    $all = []; $missing = [];
    foreach ($rows as $r) {
        $points = parse_forecast($r['content']);
        $v = verify_forecast($r['market'], $r['ticker'], $points, $r['value_type']);
        if ($v['count'] === 0) {
            $missing[$r['market'] . ':' . $r['ticker']] = true;
            continue;
        }
        $all[] = [
            'market' => $r['market'], 'ticker' => $r['ticker'],
            'line_num' => (int)$r['line_num'], 'value_type' => $r['value_type'],
            'mape' => $v['mape'], 'hit_rate' => $v['hit_rate'], 'count' => $v['count'],
        ];
    }

    @file_put_contents($cacheFile, json_encode([
        'key' => $cacheKey,
        'all' => $all,
        'missing' => $missing,
    ], JSON_UNESCAPED_UNICODE));
}

usort($all, function ($a, $b) { return ($a['mape'] ?? 9999) <=> ($b['mape'] ?? 9999); });

$byMarket = [];
foreach ($all as $r) {
    $m = $r['market'];
    if (!isset($byMarket[$m])) $byMarket[$m] = ['n'=>0,'mape_sum'=>0,'hit_sum'=>0,'rows'=>0];
    $byMarket[$m]['n']        += $r['count'];
    $byMarket[$m]['mape_sum'] += $r['mape'] * $r['count'];
    $byMarket[$m]['hit_sum']  += $r['hit_rate'] * $r['count'];
    $byMarket[$m]['rows']++;
}
foreach ($byMarket as &$m) {
    $m['mape']     = $m['n'] > 0 ? round($m['mape_sum'] / $m['n'], 2) : null;
    $m['hit_rate'] = $m['n'] > 0 ? round($m['hit_sum'] / $m['n'], 4) : null;
}
unset($m);

$flash = get_flash();

$pending = (int)db()->query(
    "SELECT COUNT(DISTINCT CONCAT(fl.market, ':', fl.ticker))
     FROM forecast_lines fl
     LEFT JOIN stock_map sm ON sm.market = fl.market AND sm.ticker = fl.ticker
     LEFT JOIN history_bars hb ON hb.stock_id = sm.id
     WHERE hb.stock_id IS NULL
       AND (sm.history_skip_until IS NULL OR sm.history_skip_until < NOW())"
)->fetchColumn();

$skipped = (int)db()->query(
    "SELECT COUNT(*) FROM stock_map WHERE history_skip_until > NOW()"
)->fetchColumn();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Точность прогнозов — StockForecast</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="static/style.css">
</head>
<body>
<header class="topbar">
    <a class="logo" href="index.php">📈 StockForecast</a>
    <nav>
        <a href="index.php">Главная</a>
        <a href="index.php?r=accuracy">Точность</a>
        <a href="index.php?r=compare">Сравнить</a>
        <a href="<?= is_admin() ? url('admin') : url('admin-login') ?>">Админка</a>
    </nav>
</header>
<main>
<?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:12px">
    <h1 style="margin-bottom:8px">Точность прогнозов</h1>
    <a class="btn" href="index.php?r=export-accuracy">Скачать CSV</a>
</div>
<p class="muted">
    Сводная статистика по всем прогнозам с пересечением с фактической историей.
    MAPE — средняя абсолютная ошибка в %, hit-rate — доля верно угаданных направлений.
    Сортировка: от лучших к худшим.
</p>

<?php if ($pending > 0 || $skipped > 0): ?>
    <div class="card" style="margin-bottom:24px;border-color:#26a69a">
        <h3 style="margin-top:0">⚠ Нужна история цен</h3>
        <p class="muted" style="margin:0 0 12px">
            В очереди: <b><?= $pending ?></b> тикеров.
            <?php if ($skipped > 0): ?>
                <br>Пропущено на 24 часа: <b><?= $skipped ?></b>.
            <?php endif; ?>
        </p>
        <?php if (is_admin()): ?>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <select id="bf-days" style="padding:8px;background:#0e1117;color:#e6e6e6;border:1px solid #2a2e39;border-radius:6px">
                <option value="365">1 год</option>
                <option value="730">2 года</option>
                <option value="1825">5 лет</option>
                <option value="3650">10 лет</option>
                <option value="7300" selected>20 лет</option>
            </select>
            <button type="button" id="bf-start" class="btn">Загрузить историю</button>
            <?php if ($skipped > 0): ?>
                <button type="button" id="bf-retry" class="btn">Повторить пропущенные (<?= $skipped ?>)</button>
            <?php endif; ?>
        </div>
        <p id="bf-status" class="muted" style="margin:10px 0 0"></p>
        <?php else: ?>
            <p class="muted">Войдите как администратор, чтобы запустить загрузку.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$all): ?>
    <p class="warn">
        Пока нет ни одного прогноза с пересечением по датам с фактической историей.
        <?php if ($pending > 0): ?>Нажмите «Загрузить историю» выше.<?php endif; ?>
    </p>
<?php else: ?>
    <div class="cards" style="margin-bottom:24px">
    <?php foreach ($byMarket as $m => $s): ?>
        <div class="card">
            <h3 style="margin-top:0"><?= strtoupper(h($m)) ?> — всего</h3>
            <p class="muted" style="margin:0">
                MAPE: <b style="color:#26a69a"><?= $s['mape'] ?>%</b> ·
                Hit-rate: <b style="color:#26a69a"><?= $s['hit_rate'] !== null ? round($s['hit_rate'] * 100, 1) . '%' : '—' ?></b> ·
                Точек: <?= (int)$s['n'] ?> ·
                Прогнозов: <?= (int)$s['rows'] ?>
            </p>
        </div>
    <?php endforeach; ?>
    </div>

    <table class="stock-table">
    <thead><tr>
        <th>Биржа</th><th>Тикер</th><th>Линия</th><th>Тип</th>
        <th>MAPE, %</th><th>Hit-rate</th><th>Точек</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($all as $r):
        $cls = ($r['mape'] !== null && $r['mape'] <= 2) ? 'status-ok'
             : (($r['mape'] !== null && $r['mape'] > 5) ? 'status-no' : '');
        $hitPct = $r['hit_rate'] !== null ? round($r['hit_rate'] * 100, 1) . '%' : '—';
    ?>
        <tr>
            <td><?= strtoupper(h($r['market'])) ?></td>
            <td><b><?= h($r['ticker']) ?></b></td>
            <td>Линия <?= (int)$r['line_num'] ?></td>
            <td><?= $r['value_type'] === 'delta' ? 'Δ' : '₽' ?></td>
            <td class="<?= $cls ?>"><?= $r['mape'] ?>%</td>
            <td><?= $hitPct ?></td>
            <td><?= (int)$r['count'] ?></td>
            <td><a class="btn" href="<?= h(url('stock', ['market' => $r['market'], 'ticker' => $r['ticker']])) ?>">График →</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
<?php endif; ?>
</main>
<footer>© StockForecast</footer>
<script>
(function () {
    const startBtn = document.getElementById('bf-start');
    const retryBtn = document.getElementById('bf-retry');
    const status   = document.getElementById('bf-status');
    const daysSel  = document.getElementById('bf-days');
    if (!startBtn && !retryBtn) return;

    async function runLoop(reset) {
        if (startBtn) startBtn.disabled = true;
        if (retryBtn) retryBtn.disabled = true;
        if (daysSel) daysSel.disabled = true;

        const days = daysSel ? daysSel.value : '7300';
        let totalLoaded = 0;
        let totalFailed = 0;
        let iter = 0;
        const maxIter = 200;

        status.textContent = 'Начинаем…';

        while (iter++ < maxIter) {
            status.textContent = `Шаг ${iter}: запрашиваем… (загружено ${totalLoaded}, ошибок ${totalFailed})`;

            const url = `index.php?r=backfill-history&ajax=1&days=${days}`
                      + (reset && iter === 1 ? '&reset=1' : '');

            let j;
            try {
                const r = await fetch(url, { credentials: 'same-origin' });
                if (!r.ok) {
                    status.textContent = `Шаг ${iter}: HTTP ${r.status}`;
                    break;
                }
                j = await r.json();
            } catch (e) {
                status.textContent = `Шаг ${iter}: ошибка сети (${e.message}). Повтор через 2 с…`;
                await new Promise(res => setTimeout(res, 2000));
                iter--;
                continue;
            }

            totalLoaded += j.processed || 0;
            if (j.failed && j.failed.length) totalFailed += j.failed.length;

            status.textContent = `Шаг ${iter}: готово. `
                + `Загружено ${totalLoaded} · ошибок ${totalFailed} · `
                + `осталось ${j.remaining}`
                + (j.skipped ? ` · пропущено 24ч: ${j.skipped}` : '');

            if (j.remaining === 0) break;
            if ((j.processed || 0) === 0 && (j.failed || []).length === 0 && j.skipped > 0) break;
        }

        status.textContent += ' · обновляем страницу…';
        setTimeout(() => location.reload(), 900);
    }

    if (startBtn) startBtn.addEventListener('click', () => runLoop(false));
    if (retryBtn) retryBtn.addEventListener('click', () => runLoop(true));
})();
</script>
</body>
</html>