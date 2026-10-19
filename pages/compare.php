<?php
$market = $_GET['market'] ?? 'moex';
$tickers = $_GET['tickers'] ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Сравнение тикеров — StockForecast</title>
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
        <a href="<?= is_admin() ? url('admin') : url('admin-login') ?>">Админка</a>
    </nav>
</header>
<main>
<h1>Сравнение тикеров</h1>
<p class="muted">До 5 тикеров одной биржи на одном графике. Все линии нормализованы к 100 на начальную дату.</p>
<form id="compare-form" class="search" style="flex-wrap:wrap;gap:8px">
    <select name="market" id="market" style="padding:8px;background:#131722;color:#e6e6e6;border:1px solid #2a2e39;border-radius:6px">
        <option value="moex" <?= $market === 'moex' ? 'selected' : '' ?>>MOEX</option>
        <option value="us"   <?= $market === 'us'   ? 'selected' : '' ?>>US</option>
    </select>
    <input type="text" name="tickers" id="tickers"
           value="<?= h($tickers) ?>"
           placeholder="SBER,GAZP,LKOH (до 5 через запятую)"
           style="flex:1;min-width:260px">
    <select name="days" id="days" style="padding:8px;background:#131722;color:#e6e6e6;border:1px solid #2a2e39;border-radius:6px">
        <option value="180">6 мес</option>
        <option value="365" selected>1 год</option>
        <option value="730">2 года</option>
    </select>
    <select name="granularity" id="granularity" style="padding:8px;background:#131722;color:#e6e6e6;border:1px solid #2a2e39;border-radius:6px">
        <option value="day">День</option>
        <option value="week" selected>Неделя</option>
        <option value="month">Месяц</option>
    </select>
    <button type="submit">Сравнить</button>
</form>
<div id="compare-chart" class="chart"></div>
<div id="compare-legend" class="legend"></div>
<h2 style="margin-top:40px">Точность моделей по этим тикерам</h2>
<div id="compare-accuracy"><p class="muted">—</p></div>
<script src="https://unpkg.com/lightweight-charts@4.1.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
const initialTickers = <?= json_encode($tickers) ?>;
const chartEl = document.getElementById('compare-chart');
const chart = LightweightCharts.createChart(chartEl, {
    width: chartEl.clientWidth, height: 520,
    layout: { background: { color: '#131722' }, textColor: '#d1d4dc' },
    grid: { vertLines: { color: '#1e222d' }, horzLines: { color: '#1e222d' } },
    crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
    rightPriceScale: { borderColor: '#2a2e39', scaleMargins: { top: 0.1, bottom: 0.1 } },
    timeScale: { borderColor: '#2a2e39' },
});
let seriesList = [];

async function runCompare() {
    const market = document.getElementById('market').value;
    const tickers = document.getElementById('tickers').value.trim();
    const days = document.getElementById('days').value;
    const g = document.getElementById('granularity').value;
    if (!tickers) return;
    const url = `index.php?r=api-compare&market=${encodeURIComponent(market)}&tickers=${encodeURIComponent(tickers)}&days=${days}&granularity=${g}`;
    const r = await fetch(url);
    const j = await r.json();
    if (!j.ok) { alert(j.error || 'Ошибка'); return; }
    seriesList.forEach(s => chart.removeSeries(s));
    seriesList = [];
    const legend = [];
    const accRows = [];
    j.series.forEach(s => {
        const line = chart.addLineSeries({
            color: s.color, lineWidth: 2, title: s.ticker,
            priceLineVisible: false, lastValueVisible: true,
        });
        line.setData(s.data);
        seriesList.push(line);
        legend.push({ color: s.color, name: s.ticker + ' · ' + s.data.length + ' точек' });
        if (s.accuracy && s.accuracy.length) {
            s.accuracy.forEach(a => {
                accRows.push({
                    ticker: s.ticker, line: a.line,
                    mape: a.mape, hit: a.hit, n: a.n,
                });
            });
        }
    });
    document.getElementById('compare-legend').innerHTML = legend.map(i =>
        `<span class="legend-item"><i style="background:${i.color}"></i> ${i.name}</span>`
    ).join('');
    chart.timeScale().fitContent();
    renderAccuracy(accRows);
}

function renderAccuracy(rows) {
    const box = document.getElementById('compare-accuracy');
    if (!rows.length) {
        box.innerHTML = '<p class="muted">Нет верифицированных прогнозов по этим тикерам.</p>';
        return;
    }
    let html = '<table class="stock-table"><thead><tr>' +
        '<th>Тикер</th><th>Линия</th><th>MAPE %</th><th>Hit-rate</th><th>Точек</th>' +
        '</tr></thead><tbody>';
    rows.sort((a, b) => (a.mape ?? 9999) - (b.mape ?? 9999));
    for (const r of rows) {
        const cls = (r.mape !== null && r.mape <= 2) ? 'status-ok'
                 : (r.mape !== null && r.mape > 5) ? 'status-no' : '';
        const hit = r.hit !== null ? (r.hit * 100).toFixed(1) + '%' : '—';
        html += `<tr>
            <td><b>${r.ticker}</b></td><td>Л${r.line}</td>
            <td class="${cls}">${r.mape}%</td><td>${hit}</td><td>${r.n}</td>
        </tr>`;
    }
    html += '</tbody></table>';
    box.innerHTML = html;
}

document.getElementById('compare-form').addEventListener('submit', e => {
    e.preventDefault();
    runCompare();
});
window.addEventListener('resize', () => chart.applyOptions({ width: chartEl.clientWidth }));
if (initialTickers) runCompare();
</script>
</main>
<footer>© StockForecast</footer>
</body>
</html>