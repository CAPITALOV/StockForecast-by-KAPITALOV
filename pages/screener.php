<?php
require_once __DIR__ . '/../lib/screener.php';

$days   = max(7, min(365, (int)($_GET['days'] ?? 30)));
$market = in_array($_GET['market'] ?? '', ['moex','us'], true) ? $_GET['market'] : '';
$signal = in_array($_GET['signal'] ?? '', ['buy','sell'], true) ? $_GET['signal'] : '';
$sort   = in_array($_GET['sort'] ?? '', ['mape','hit','count','ticker'], true) ? $_GET['sort'] : 'mape';

$from = date('Y-m-d', time() - $days * 86400);
$to   = date('Y-m-d');
$fromLabel = date('d.m.Y', strtotime($from));
$toLabel   = date('d.m.Y', strtotime($to));

$results = screener_data($days, $market, $signal);

usort($results, function ($a, $b) use ($sort) {
    switch ($sort) {
        case 'hit':    return $b['hit_rate'] <=> $a['hit_rate'];
        case 'count':  return $b['count']    <=> $a['count'];
        case 'ticker': return strcmp($a['market'] . ':' . $a['ticker'], $b['market'] . ':' . $b['ticker']);
        default:       return $a['mape'] <=> $b['mape'];
    }
});

$byMarket = [];
foreach ($results as $r) {
    $m = $r['market'];
    if (!isset($byMarket[$m])) {
        $byMarket[$m] = ['n'=>0,'mape_sum'=>0,'hit_sum'=>0,'rows'=>0];
    }
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

$csvQuery = http_build_query([
    'days' => $days,
    'market' => $market,
    'signal' => $signal,
    'sort' => $sort,
]);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Screener — точность тикеров — StockForecast</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="static/style.css">
</head>
<body>
<header class="topbar">
    <a class="logo" href="index.php">📈 StockForecast</a>
    <nav>
        <a href="index.php">Главная</a>
        <a href="index.php?r=screener">Screener</a>
        <a href="index.php?r=accuracy">Точность</a>
        <a href="index.php?r=compare">Сравнить</a>
        <a href="<?= is_admin() ? url('admin') : url('admin-login') ?>">Админка</a>
    </nav>
</header>
<main>

<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:12px">
    <h1 style="margin-bottom:8px">Screener точности</h1>
    <a class="btn" href="index.php?r=export-screener&<?= h($csvQuery) ?>">Скачать CSV</a>
</div>
<p class="muted">
    Все тикеры с прогнозами, у которых есть факт за период
    <b><?= h($fromLabel) ?></b> — <b><?= h($toLabel) ?></b>.
    MAPE — средняя абсолютная ошибка (меньше = точнее).
    Hit-rate — доля верных направлений (0.5 = случайно).
</p>

<form method="get" action="index.php" class="search" style="flex-wrap:wrap;gap:8px">
    <input type="hidden" name="r" value="screener">
    <label>Период:
        <select name="days" style="padding:8px;background:#131722;color:#e6e6e6;border:1px solid #2a2e39;border-radius:6px">
            <option value="7"   <?= $days===7   ? 'selected' : '' ?>>7 дней</option>
            <option value="30"  <?= $days===30  ? 'selected' : '' ?>>30 дней</option>
            <option value="90"  <?= $days===90  ? 'selected' : '' ?>>90 дней</option>
            <option value="180" <?= $days===180 ? 'selected' : '' ?>>180 дней</option>
            <option value="365" <?= $days===365 ? 'selected' : '' ?>>1 год</option>
        </select>
    </label>
    <label>Биржа:
        <select name="market" style="padding:8px;background:#131722;color:#e6e6e6;border:1px solid #2a2e39;border-radius:6px">
            <option value=""     <?= $market===''     ? 'selected' : '' ?>>Все</option>
            <option value="moex" <?= $market==='moex' ? 'selected' : '' ?>>MOEX</option>
            <option value="us"   <?= $market==='us'   ? 'selected' : '' ?>>US</option>
        </select>
    </label>
    <label>Текущий сигнал:
        <select name="signal" style="padding:8px;background:#131722;color:#e6e6e6;border:1px solid #2a2e39;border-radius:6px">
            <option value=""     <?= $signal===''     ? 'selected' : '' ?>>Все</option>
            <option value="buy"  <?= $signal==='buy'  ? 'selected' : '' ?>>🟢 Только BUY</option>
            <option value="sell" <?= $signal==='sell' ? 'selected' : '' ?>>🔴 Только SELL</option>
        </select>
    </label>
    <label>Сортировка:
        <select name="sort" style="padding:8px;background:#131722;color:#e6e6e6;border:1px solid #2a2e39;border-radius:6px">
            <option value="mape"   <?= $sort==='mape'   ? 'selected' : '' ?>>по MAPE (лучшие)</option>
            <option value="hit"    <?= $sort==='hit'    ? 'selected' : '' ?>>по Hit-rate</option>
            <option value="count"  <?= $sort==='count'  ? 'selected' : '' ?>>по количеству точек</option>
            <option value="ticker" <?= $sort==='ticker' ? 'selected' : '' ?>>по тикеру (A-Z)</option>
        </select>
    </label>
    <button type="submit">Применить</button>
</form>

<?php if (empty($results)): ?>
    <p class="warn">
        За период <b><?= h($fromLabel) ?> — <?= h($toLabel) ?></b> нет ни одного прогноза,
        для которого нашёлся бы факт. 
        <?php if ($signal !== ''): ?>
            <br>Или ни один тикер не имеет текущий сигнал <b><?= strtoupper($signal) ?></b>.
        <?php endif; ?>
        <br>Проверьте <a href="index.php?r=accuracy" style="color:#26a69a">Точность</a> →
        кнопку «Загрузить историю».
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
                Тикеров: <?= (int)$s['rows'] ?>
            </p>
        </div>
    <?php endforeach; ?>
    </div>

    <table class="stock-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Биржа</th>
            <th>Тикер</th>
            <th>Сигнал</th>
            <th>MAPE, %</th>
            <th>Hit-rate</th>
            <th>Точек</th>
            <th>Лучшая линия</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($results as $i => $r):
        $cls = ($r['mape'] <= 2) ? 'status-ok' : (($r['mape'] > 5) ? 'status-no' : '');
        $hitPct = round($r['hit_rate'] * 100, 1);
        $hitCls = ($r['hit_rate'] >= 0.6) ? 'status-ok' : (($r['hit_rate'] < 0.4) ? 'status-no' : '');
        
        $sig = $r['current_signal'] ?? 'neutral';
        $sigColor = $sig === 'buy' ? '#26a69a' : ($sig === 'sell' ? '#ef5350' : '#888');
        $sigLabel = $sig === 'buy' ? '🟢 BUY' : ($sig === 'sell' ? '🔴 SELL' : '⚪ neutral');
    ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= strtoupper(h($r['market'])) ?></td>
            <td><b><?= h($r['ticker']) ?></b></td>
            <td style="color:<?= $sigColor ?>; font-weight:700"><?= $sigLabel ?></td>
            <td class="<?= $cls ?>"><?= $r['mape'] ?>%</td>
            <td class="<?= $hitCls ?>"><?= $hitPct ?>%</td>
            <td><?= (int)$r['count'] ?></td>
            <td>Линия <?= (int)$r['best_line'] ?></td>
            <td><a class="btn" href="<?= h(url('stock', ['market' => $r['market'], 'ticker' => $r['ticker']])) ?>">График →</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    </table>

    <p class="muted" style="margin-top:16px">
        «Лучшая линия» — та из 1/2/3 линий прогноза, которая за выбранный период дала
        наименьшую MAPE. Ссылка «График →» открывает страницу тикера.
    </p>

<?php endif; ?>
</main>
<footer>© StockForecast</footer>
</body>
</html>