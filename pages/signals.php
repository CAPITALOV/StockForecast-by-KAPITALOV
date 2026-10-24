<?php

$market = $_GET['market'] ?? 'moex';
$ticker = strtoupper($_GET['ticker'] ?? '');

if ($ticker === '') {
    header('Location: index.php');
    exit;
}

// Получаем последние 30 сигналов (BUY/SELL)
$stmt = db()->prepare("
    SELECT date, trade_action, synthetic 
    FROM synthetic_components 
    WHERE market = ? AND ticker = ? AND trade_action IN ('buy', 'sell') 
    ORDER BY date DESC 
    LIMIT 30
");
$stmt->execute([$market, $ticker]);
$signals = $stmt->fetchAll();

// Функция для получения цены закрытия на конкретную дату (или ближайшую после)
function get_close_price($market, $ticker, $date, $offsetDays = 0) {
    $targetDate = date('Y-m-d', strtotime($date . ' +' . $offsetDays . ' days'));
    // Ищем цену в history_bars через stock_map
    $sql = "SELECT hb.close 
            FROM history_bars hb
            JOIN stock_map sm ON sm.id = hb.stock_id
            WHERE sm.market = ? AND sm.ticker = ? AND hb.date >= ?
            ORDER BY hb.date ASC LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute([$market, $ticker, $targetDate]);
    return $st->fetchColumn();
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>История сигналов — <?= h($ticker) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="static/style.css">
</head>
<body>
<header class="topbar">
    <a class="logo" href="index.php">📈 StockForecast</a>
    <nav>
        <a href="index.php?market=<?= h($market) ?>">← К списку</a>
        <a href="index.php?r=stock&market=<?= h($market) ?>&ticker=<?= h($ticker) ?>">← К графику <?= h($ticker) ?></a>
        <a href="index.php?r=screener">Screener</a>
        <a href="index.php?r=accuracy">Точность</a>
    </nav>
</header>
<main>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px;">
        <h1 style="margin:0;">История сигналов: <span style="color:#f59e0b"><?= h($ticker) ?></span></h1>
        <a class="btn" href="index.php?r=stock&market=<?= h($market) ?>&ticker=<?= h($ticker) ?>">Вернуться к графику</a>
    </div>
    
    <p class="muted">Таблица показывает, как отработали прошлые сигналы нашей системы. Для сигналов <b>SELL</b> доходность считается как падение цены (то есть, если цена упала, вы заработали).</p>

    <?php if (empty($signals)): ?>
        <div class="warn">
            По этому тикеру пока нет исторических сигналов BUY/SELL. <br>
            Возможно, данные еще не пересчитаны, или по этой акции не было точек входа.
        </div>
    <?php else: ?>
        <table class="stock-table">
            <thead>
                <tr>
                    <th>Дата сигнала</th>
                    <th>Сигнал</th>
                    <th style="text-align:right">Цена входа</th>
                    <th style="text-align:right">Доходность через 5 дней</th>
                    <th style="text-align:right">Доходность через 10 дней</th>
                    <th style="text-align:right">Доходность через 20 дней</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($signals as $s): 
                    $isBuy = $s['trade_action'] === 'buy';
                    $color = $isBuy ? '#26a69a' : '#ef5350';
                    $label = $isBuy ? '🟢 BUY' : '🔴 SELL';
                    
                    $priceEntry = get_close_price($market, $ticker, $s['date'], 0);
                    $price5  = get_close_price($market, $ticker, $s['date'], 5);
                    $price10 = get_close_price($market, $ticker, $s['date'], 10);
                    $price20 = get_close_price($market, $ticker, $s['date'], 20);
                    
                    // Расчет доходности
                    $ret5 = ($priceEntry && $price5) ? (($price5 - $priceEntry) / $priceEntry) * 100 : null;
                    $ret10 = ($priceEntry && $price10) ? (($price10 - $priceEntry) / $priceEntry) * 100 : null;
                    $ret20 = ($priceEntry && $price20) ? (($price20 - $priceEntry) / $priceEntry) * 100 : null;
                    
                    // Корректировка для SELL (если продали, то доходность инвертируется)
                    if (!$isBuy) {
                        $ret5 = $ret5 !== null ? -$ret5 : null;
                        $ret10 = $ret10 !== null ? -$ret10 : null;
                        $ret20 = $ret20 !== null ? -$ret20 : null;
                    }
                ?>
                <tr>
                    <td><?= date('d.m.Y', strtotime($s['date'])) ?></td>
                    <td style="color:<?= $color ?>; font-weight:bold;"><?= $label ?></td>
                    <td style="text-align:right"><?= $priceEntry ? number_format($priceEntry, 2) : '—' ?></td>
                    <td style="text-align:right; color:<?= $ret5 > 0 ? '#26a69a' : ($ret5 < 0 ? '#ef5350' : 'inherit') ?>">
                        <?= $ret5 !== null ? ($ret5 > 0 ? '+' : '') . number_format($ret5, 2) . '%' : '—' ?>
                    </td>
                    <td style="text-align:right; color:<?= $ret10 > 0 ? '#26a69a' : ($ret10 < 0 ? '#ef5350' : 'inherit') ?>">
                        <?= $ret10 !== null ? ($ret10 > 0 ? '+' : '') . number_format($ret10, 2) . '%' : '—' ?>
                    </td>
                    <td style="text-align:right; color:<?= $ret20 > 0 ? '#26a69a' : ($ret20 < 0 ? '#ef5350' : 'inherit') ?>">
                        <?= $ret20 !== null ? ($ret20 > 0 ? '+' : '') . number_format($ret20, 2) . '%' : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
<footer>© StockForecast</footer>
</body>
</html>