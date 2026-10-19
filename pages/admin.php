<?php
$pdo = db();
$all = $pdo->query(
    "SELECT id, market, ticker, line_num, value_type, content, updated_at
     FROM forecast_lines
     ORDER BY market, ticker, line_num"
)->fetchAll();

$stockCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_map")->fetchColumn();
$stockSample = $pdo->query(
    "SELECT id, market, ticker, name FROM stock_map ORDER BY id LIMIT 10"
)->fetchAll();

$flash = get_flash();
$currentChartType = get_setting('chart_type', 'line');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Админка — прогнозы</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="static/style.css">
</head>
<body>
<header class="topbar">
    <a class="logo" href="index.php">📈 StockForecast</a>
    <nav>
        <a href="index.php">На главную</a>
        <a href="<?= url('admin-logout') ?>">Выйти (<?= h($_SESSION['admin']) ?>)</a>
    </nav>
</header>
<main>
<?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

<h1>Панель администратора</h1>

<h2>Массовый импорт из Yii</h2>
<div class="cards">
    <div class="card">
        <h3>1. Справочник акций</h3>
        <p class="muted">
            Файл <code>cap_stock.csv</code> — таблица из Yii. Строит маппинг
            <code>StockId → тикер</code>.
        </p>
        <p class="status-<?= $stockCount > 0 ? 'ok' : 'no' ?>">
            Записей в справочнике: <b><?= $stockCount ?></b>
        </p>
        <?php if ($stockCount > 0 && $stockSample): ?>
            <details>
                <summary>Показать пример (первые 10)</summary>
                <table class="stock-table" style="margin-top:8px">
                    <thead><tr><th>StockId</th><th>Биржа</th><th>Тикер</th><th>Имя</th></tr></thead>
                    <tbody>
                    <?php foreach ($stockSample as $s): ?>
                        <tr>
                            <td><?= (int)$s['id'] ?></td>
                            <td><?= strtoupper(h($s['market'])) ?></td>
                            <td><b><?= h($s['ticker']) ?></b></td>
                            <td><?= h(mb_substr($s['name'] ?? '', 0, 40)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>
        <form method="post" action="<?= url('admin-import-stocks') ?>" enctype="multipart/form-data">
            <input type="file" name="file" accept=".csv,.txt" required>
            <button type="submit">Загрузить справочник</button>
        </form>
        <?php if ($stockCount > 0): ?>
        <form method="post" action="<?= url('admin-clear-stock-map') ?>"
              onsubmit="return confirm('Удалить весь справочник?')">
            <button type="submit" class="btn-danger">Очистить справочник</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>2. Прогнозы</h3>
        <p class="muted">
            CSV с прогнозами (<code>Id, StockId, Date, Delta</code>).
            Автоматически разложится по тикерам через справочник.
        </p>
        <form method="post" action="<?= url('admin-import-forecasts') ?>" enctype="multipart/form-data">
            <label>В какую линию
                <select name="line_num" required>
                    <option value="1">1 (красная)</option>
                    <option value="2">2 (синяя)</option>
                    <option value="3">3 (оранжевая)</option>
                </select>
            </label>
            <input type="file" name="file" accept=".csv,.txt" required>
            <button type="submit" <?= $stockCount === 0 ? 'disabled title="Сначала загрузите справочник"' : '' ?>>
                Импортировать прогнозы
            </button>
        </form>
        <?php if ($stockCount === 0): ?>
            <p class="warn">⚠ Сначала загрузите справочник акций (форма слева)</p>
        <?php endif; ?>
    </div>
</div>

<hr style="margin:32px 0;border:0;border-top:1px solid #2a2e39">

<h2>Ручная загрузка прогноза</h2>
<p class="muted">
    Для одной линии одного тикера. Форматы: <code>250.50 15 03 2024</code> ·
    <code>15 03 2024 250.50</code> · <code>2024-03-15 250.50</code>.
    Десятичный разделитель — <code>.</code> или <code>,</code>.
</p>
<div class="card upload-card">
    <form method="post" action="<?= url('admin-upload') ?>" enctype="multipart/form-data" id="uploadForm">
        <div class="row">
            <label>Биржа
                <select name="market" id="marketSelect">
                    <option value="moex">MOEX (Россия)</option>
                    <option value="us">US (США)</option>
                </select>
            </label>
            <label>Тикер
                <input type="text" name="ticker" id="tickerInput" list="tickerList"
                       placeholder="Например SBER или AAPL" required autocomplete="off"
                       pattern="[A-Za-z0-9._\-]{1,32}">
                <datalist id="tickerList"></datalist>
                <small class="hint" id="tickerHint">Загрузка списка…</small>
            </label>
            <label>Линия
                <select name="line_num" required>
                    <option value="1">1 (красная)</option>
                    <option value="2">2 (синяя)</option>
                    <option value="3">3 (оранжевая)</option>
                </select>
            </label>
        </div>
        <div class="row">
            <label>Что в файле
                <select name="value_type">
                    <option value="price">Цена (например 250.50)</option>
                    <option value="delta" selected>Дельта / индекс (например 0.0542)</option>
                </select>
            </label>
        </div>
        <label>Файл (.txt или .csv)
            <input type="file" name="file" accept=".txt,.csv">
        </label>
        <details>
            <summary>Или вставить текст вручную</summary>
            <textarea name="content" rows="6"
                placeholder="250.5 15 03 2024&#10;252.3 16 03 2024&#10;255.0 17 03 2024"></textarea>
        </details>
        <button type="submit">Сохранить прогноз</button>
    </form>
</div>

<h2 style="margin-top:32px">Загруженные прогнозы</h2>
<?php if (!$all): ?>
    <p class="warn">Пока ничего не загружено.</p>
<?php else: ?>
<table class="stock-table">
<thead><tr>
    <th>Биржа</th><th>Тикер</th><th>Линия</th><th>Тип</th>
    <th>Точек</th><th>Обновлено</th><th>Действия</th>
</tr></thead>
<tbody>
<?php foreach ($all as $r):
    $points = $r['content'] ? substr_count($r['content'], "\n") + 1 : 0;
?>
    <tr>
        <td><?= strtoupper(h($r['market'])) ?></td>
        <td>
            <a href="<?= h(url('stock', ['market' => $r['market'], 'ticker' => $r['ticker']])) ?>">
                <b><?= h($r['ticker']) ?></b>
            </a>
        </td>
        <td>Линия <?= (int)$r['line_num'] ?></td>
        <td><?= $r['value_type'] === 'delta' ? 'Δ delta' : '₽ цена' ?></td>
        <td><?= $points ?></td>
        <td><?= h($r['updated_at'] ?? '—') ?></td>
        <td>
            <form method="post" action="<?= url('admin-delete') ?>"
                  onsubmit="return confirm('Удалить прогноз?')"
                  style="display:inline">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn-danger">Удалить</button>
            </form>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<hr style="margin:40px 0 24px;border:0;border-top:1px solid #2a2e39">

<!-- ================= НАСТРОЙКИ ОТОБРАЖЕНИЯ ================= -->
<h2>Настройки отображения</h2>
<div class="card upload-card" style="max-width: 500px;">
    <form method="post" action="<?= url('admin-settings') ?>">
        <label>Тип графика по умолчанию
            <select name="chart_type">
                <option value="line" <?= $currentChartType === 'line' ? 'selected' : '' ?>>Линии (Line)</option>
                <option value="candles" <?= $currentChartType === 'candles' ? 'selected' : '' ?>>Японские свечи (Candles)</option>
            </select>
        </label>
        <p class="muted" style="font-size:12px;margin:8px 0 0">
            Пользователь на сайте может переопределить этот выбор в селекторе «Тип графика»
            (сохраняется в его браузере).
        </p>
        <button type="submit" style="margin-top: 12px;">Сохранить</button>
    </form>
</div>

<hr style="margin:40px 0 24px;border:0;border-top:1px solid #2a2e39">
<h2>Сменить пароль</h2>
<form method="post" action="<?= url('admin-password') ?>" class="form-narrow">
    <label>Новый пароль (минимум 6 символов)
        <input type="password" name="new_password" required minlength="6">
    </label>
    <button type="submit">Обновить пароль</button>
</form>
</main>
<script>
const marketSelect = document.getElementById('marketSelect');
const tickerList   = document.getElementById('tickerList');
const tickerHint   = document.getElementById('tickerHint');
async function loadTickers(market) {
    tickerHint.textContent = 'Загрузка списка…';
    tickerList.innerHTML = '';
    try {
        const r = await fetch(`index.php?r=api-tickers&market=${encodeURIComponent(market)}`);
        const j = await r.json();
        const list = j.tickers || [];
        list.forEach(function (t) {
            const opt = document.createElement('option');
            opt.value = t.ticker;
            opt.label = t.name || '';
            tickerList.appendChild(opt);
        });
        tickerHint.textContent = list.length
            ? list.length + ' тикеров доступно.'
            : 'Список пуст — введите тикер вручную.';
    } catch (e) {
        tickerHint.textContent = 'Не удалось загрузить список — введите тикер вручную.';
    }
}
marketSelect.addEventListener('change', function () { loadTickers(marketSelect.value); });
loadTickers(marketSelect.value);
</script>
</body>
</html>