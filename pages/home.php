<?php
$market = $_GET['market'] ?? 'moex';
$q = trim($_GET['q'] ?? '');

$stocks = ($market === 'moex') ? moex_shares() : us_stocks_list();
if ($q !== '') {
    $ql = tolower($q);
    $stocks = array_filter($stocks, function ($s) use ($ql) {
        return strpos(tolower($s['ticker']), $ql) !== false
            || strpos(tolower($s['name']), $ql) !== false;
    });
}
$flash = get_flash();

$forecastCount = 0; $stockMapCount = 0;
try {
    $pdo = db();
    $forecastCount = (int)$pdo->query("SELECT COUNT(*) FROM forecast_lines")->fetchColumn();
    $stockMapCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_map")->fetchColumn();
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>StockForecast — Прогнозы KAPITALOV и верификация акций MOEX и US</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Проект сайта для размещения и публикации прогнозов созданных компанией KAPITALOV для прогнозирования движения трендов акций, индексов, эмитентов для дальнейшей торговой стратегии.">
<link rel="stylesheet" href="static/style.css">
</head>
<body>
<header class="topbar">
    <a class="logo" href="index.php">📈 StockForecast <small style="font-size:12px;color:#888;font-weight:400;margin-left:4px">by KAPITALOV</small></a>
    <nav>
        <a href="#features">Что внутри</a>
        <a href="#how">Как это работает</a>
        <a href="#stocks">Акции</a>
        <a href="index.php?r=screener">Screener</a>
        <a href="index.php?r=accuracy">Точность</a>
        <a href="index.php?r=compare">Сравнить</a>
        <a href="<?= is_admin() ? url('admin') : url('admin-login') ?>">
            <?= is_admin() ? 'Админка' : 'Войти' ?>
        </a>
    </nav>
</header>

<main>
<?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

<section class="hero">
    <span class="eyebrow">Прогнозы · Верификация · Точность</span>
    <h1>Прогнозы акций <span class="accent">и их честная верификация</span></h1>
    <p class="lead">
        Проект сайта для размещения и публикации прогнозов созданных компанией <b>KAPITALOV</b>
        для прогнозирования движения трендов акций, индексов и эмитентов для дальнейшей торговой стратегии.
        <br><br>
        Три независимые линии прогноза на каждый тикер. Автоматическое сравнение
        с фактической историей цен MOEX ISS и Yahoo Finance. Прозрачный расчёт
        точности — без «подгонки» задним числом.
    </p>
    <div class="hero-actions">
        <a class="btn btn-primary btn-lg" href="<?= is_admin() ? url('admin') : url('admin-login') ?>">
            <?= is_admin() ? 'Открыть админку →' : 'Войти в админку →' ?>
        </a>
        <a class="btn btn-ghost btn-lg" href="#stocks">Смотреть акции</a>
    </div>
    <div class="hero-stats">
        <div class="stat"><b><?= (int)$forecastCount ?></b><span>загруженных прогнозов</span></div>
        <div class="stat"><b><?= (int)$stockMapCount ?></b><span>тикеров в справочнике</span></div>
        <div class="stat"><b>2</b><span>биржи (MOEX · US)</span></div>
        <div class="stat"><b>3</b><span>линии прогноза</span></div>
    </div>
</section>

<section class="section" id="recent-hits-section" style="display:none">
    <div class="section-head">
        <h2>Свежие попадания</h2>
        <p>Прогнозы за последние 7 дней, где отклонение от факта не превышает 2%.</p>
    </div>
    <div id="recent-hits-table"></div>
</section>
<script>
(function () {
    const section = document.getElementById('recent-hits-section');
    const box = document.getElementById('recent-hits-table');
    fetch('index.php?r=api-recent-hits&days=7&max_dev=2&limit=8', { cache: 'no-store' })
        .then(r => r.json())
        .then(j => {
            if (!j.ok || !j.hits || !j.hits.length) return;
            let html = '<table class="stock-table"><thead><tr>' +
                '<th>Дата</th><th>Биржа</th><th>Тикер</th><th>Линия</th>' +
                '<th>Прогноз</th><th>Факт</th><th>Δ %</th>' +
                '</tr></thead><tbody>';
            for (const h of j.hits) {
                const link = 'index.php?r=stock&market=' + encodeURIComponent(h.market)
                           + '&ticker=' + encodeURIComponent(h.ticker);
                html += '<tr>' +
                    '<td>' + h.time + '</td>' +
                    '<td>' + h.market.toUpperCase() + '</td>' +
                    '<td><a href="' + link + '" style="color:#26a69a;text-decoration:none"><b>' + h.ticker + '</b></a></td>' +
                    '<td>Л' + h.line_num + '</td>' +
                    '<td>' + h.forecast + '</td>' +
                    '<td>' + h.fact + '</td>' +
                    '<td class="status-ok">' + h.deviation_pct + '%</td>' +
                    '</tr>';
            }
            html += '</tbody></table>';
            box.innerHTML = html;
            section.style.display = '';
        })
        .catch(e => {});
})();
</script>

<section id="features" class="section">
    <div class="section-head">
        <h2>Что внутри</h2>
        <p>Полный цикл: от загрузки CSV из Yii до графика с отклонением по каждой точке.</p>
    </div>
    <div class="cards">
        <article class="card feature-card">
            <div class="card-icon">01</div>
            <h3>Три линии прогноза</h3>
            <p>До трёх независимых прогнозов на тикер (цена или дельта). Разные модели, одна шкала времени.</p>
        </article>
        <article class="card feature-card">
            <div class="card-icon">02</div>
            <h3>Импорт из Yii</h3>
            <p>CSV <code>Id, StockId, Date, Delta</code> автоматически разложится по тикерам через справочник.</p>
        </article>
        <article class="card feature-card">
            <div class="card-icon">03</div>
            <h3>MOEX + US</h3>
            <p>Официальный ISS API Мосбиржи и Yahoo Finance (со Stooq-фолбэком) — без ключей.</p>
        </article>
        <article class="card feature-card">
            <div class="card-icon">04</div>
            <h3>Фиксация истории</h3>
            <p>Котировки кэшируются в <code>history_bars</code> — верификация не зависит от источника.</p>
        </article>
        <article class="card feature-card">
            <div class="card-icon">05</div>
            <h3>Верификация</h3>
            <p>Сравнение прогноза с фактом: отклонение в %, статус «сбылся / частично / не сбылся».</p>
        </article>
        <article class="card feature-card">
            <div class="card-icon">06</div>
            <h3>Метрики точности</h3>
            <p>MAPE и hit-rate по тикеру, бирже и линии. Экспорт в CSV. Сравнение нескольких тикеров.</p>
        </article>
    </div>
</section>

<section id="how" class="process">
    <div class="section-head">
        <h2>Как это работает</h2>
        <p>Четыре шага — от CSV до отчёта по точности.</p>
    </div>
    <ol class="steps">
        <li>
            <h3>Загрузите справочник</h3>
            <p>CSV <code>cap_stock.csv</code> из Yii строит маппинг <code>StockId → тикер</code>.</p>
        </li>
        <li>
            <h3>Загрузите прогнозы</h3>
            <p>CSV раскладывается по тикерам на 1–3 линии.</p>
        </li>
        <li>
            <h3>Откройте график</h3>
            <p>Цена + линии прогноза, RSI, детализация день/неделя/месяц.</p>
        </li>
        <li>
            <h3>Смотрите верификацию</h3>
            <p>По каждой точке — прогноз, факт, отклонение и статус. CSV на экспорт.</p>
        </li>
    </ol>
</section>

<section id="stocks" class="section section-stocks">
    <div class="section-head">
        <h2>Акции</h2>
        <p>Выберите биржу и тикер — увидите график, прогнозы и верификацию.</p>
    </div>
    <div class="tabs">
        <a class="tab <?= $market === 'moex' ? 'active' : '' ?>" href="index.php?market=moex#stocks">🇷🇺 MOEX (Россия)</a>
        <a class="tab <?= $market === 'us'   ? 'active' : '' ?>" href="index.php?market=us#stocks">🇺🇸 US (США)</a>
    </div>
    <form class="search" method="get" action="index.php#stocks">
        <input type="hidden" name="market" value="<?= h($market) ?>">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Поиск по тикеру или названию...">
        <button type="submit">Найти</button>
    </form>
    <?php if (!$stocks): ?>
        <p class="warn">Список пуст — проверьте доступ к API биржи (curl / allow_url_fopen на сервере).</p>
    <?php else: ?>
    <table class="stock-table">
    <thead><tr><th>Тикер</th><th>Название</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($stocks as $s): ?>
        <tr>
            <td><b><?= h($s['ticker']) ?></b></td>
            <td><?= h($s['name']) ?></td>
            <td><a class="btn" href="<?= h(url('stock', ['market' => $market, 'ticker' => $s['ticker']])) ?>">График →</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    <?php endif; ?>
</section>
</main>
<footer>
    © 2025 KAPITALOV · StockForecast ·
    <a href="index.php?r=screener" style="color:#26a69a;text-decoration:none">Screener</a> ·
    <a href="index.php?r=accuracy" style="color:#26a69a;text-decoration:none">Точность</a> ·
    <a href="index.php?r=compare" style="color:#26a69a;text-decoration:none">Сравнить</a> ·
    <a href="<?= is_admin() ? url('admin') : url('admin-login') ?>" style="color:#26a69a;text-decoration:none">Админка</a>
</footer>
</body>
</html>