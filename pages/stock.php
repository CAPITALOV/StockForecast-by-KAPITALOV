<?php
$market = $_GET['market'] ?? 'moex';
$ticker = $_GET['ticker'] ?? '';
$rsiPeriod = max(2, min(100, (int)($_GET['rsi'] ?? 14)));
if ($ticker === '') { header('Location: ' . url('home')); exit; }
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title><?= h($ticker) ?> — прогноз и верификация</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="static/style.css">
</head>
<body>
<header class="topbar">
    <a class="logo" href="index.php">📈 StockForecast</a>
    <nav>
        <a href="index.php?market=<?= h($market) ?>">← К списку</a>
        <a href="index.php?r=accuracy">Точность</a>
        <a href="<?= is_admin() ? url('admin') : url('admin-login') ?>">Админка</a>
    </nav>
</header>
<main>
<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:12px">
    <h1 style="margin-bottom:8px"><?= h($ticker) ?> <small>(<?= strtoupper(h($market)) ?>)</small></h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
        <a class="btn" href="index.php?r=compare&market=<?= h($market) ?>&tickers=<?= h($ticker) ?>">Сравнить с другими</a>
        <a class="btn" href="index.php?r=export-verify&market=<?= h($market) ?>&ticker=<?= h($ticker) ?>">Скачать CSV</a>
    </div>
</div>

<div class="chart-controls">
    <label>Период:
        <select id="period">
            <option value="90">3 месяца</option>
            <option value="180">6 месяцев</option>
            <option value="365" selected>1 год</option>
            <option value="730">2 года</option>
            <option value="1825">5 лет</option>
            <option value="3650">10 лет</option>
            <option value="7300">20 лет</option>
        </select>
    </label>
    <label>Детализация:
        <select id="granularity">
            <option value="day" selected>День</option>
            <option value="week">Неделя</option>
            <option value="month">Месяц</option>
        </select>
    </label>
    <label>Сглаживание:
        <select id="ma-type">
            <option value="none" selected>Нет</option>
            <option value="sma">SMA</option>
            <option value="ema">EMA</option>
        </select>
    </label>
    <label id="ma-period-wrap" style="display:none">
        Период MA:
        <input type="number" id="ma-period" min="2" max="200" value="20"
               style="width:64px;padding:6px;background:#131722;color:#e6e6e6;border:1px solid #2a2e39;border-radius:6px">
        <span id="ma-hint" class="muted" style="margin-left:4px"></span>
    </label>
    <span id="status" class="status"></span>
</div>

<div id="chart" class="chart"></div>
<div id="main-legend" class="legend"><span class="muted">Загрузка…</span></div>

<div class="rsi-head">
    <b>RSI(<?= (int)$rsiPeriod ?>)</b>
    <span class="muted">— индикатор (не прогноз). Уровни 70 / 30 — перекупленность / перепроданность.</span>
</div>
<div id="rsi-chart" class="rsi-chart"></div>
<div id="rsi-legend" class="legend"><span class="muted">Загрузка…</span></div>

<h2 style="margin-top:48px">Верификация прогнозов</h2>
<p class="muted">Сравнение прогнозной цены с фактической историей. Delta пересчитана через cumprod.</p>
<div id="verify-container"><p class="muted">Загрузка верификации…</p></div>

<script src="https://unpkg.com/lightweight-charts@4.1.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
const MARKET = <?= json_encode($market) ?>;
const TICKER = <?= json_encode($ticker) ?>;
const RSI_PERIOD = <?= (int)$rsiPeriod ?>;
const RSI_COLOR = '#ba68c8';
const MA_COLOR  = '#ffd54f';

const chartEl    = document.getElementById('chart');
const rsiChartEl = document.getElementById('rsi-chart');
const statusEl   = document.getElementById('status');
const maHintEl   = document.getElementById('ma-hint');

const commonOpts = {
    layout: { background: { color: '#131722' }, textColor: '#d1d4dc' },
    grid: { vertLines: { color: '#1e222d' }, horzLines: { color: '#1e222d' } },
    crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
};
const chart = LightweightCharts.createChart(chartEl, Object.assign({}, commonOpts, {
    width: chartEl.clientWidth, height: 480,
    leftPriceScale:  { visible: true, borderColor: '#2a2e39', scaleMargins: { top: 0.1, bottom: 0.1 } },
    rightPriceScale: { visible: true, borderColor: '#2a2e39', scaleMargins: { top: 0.1, bottom: 0.1 } },
    timeScale: { borderColor: '#2a2e39', visible: true },
}));
const rsiChart = LightweightCharts.createChart(rsiChartEl, Object.assign({}, commonOpts, {
    width: rsiChartEl.clientWidth, height: 160,
    rightPriceScale: { borderColor: '#2a2e39', scaleMargins: { top: 0.1, bottom: 0.1 } },
    timeScale: { borderColor: '#2a2e39', visible: true },
}));

const priceSeries = chart.addLineSeries({
    color: '#26a69a', lineWidth: 2, title: 'Close',
    priceScaleId: 'left', priceLineVisible: false,
});
const rsiSeries = rsiChart.addLineSeries({
    color: RSI_COLOR, lineWidth: 2, title: 'RSI(' + RSI_PERIOD + ')',
    priceLineVisible: false, lastValueVisible: true,
});
try {
    rsiSeries.createPriceLine({ price: 70, color: '#ef5350', lineWidth: 1,
        lineStyle: LightweightCharts.LineStyle.Dashed, axisLabelVisible: true, title: '70' });
    rsiSeries.createPriceLine({ price: 30, color: '#26a69a', lineWidth: 1,
        lineStyle: LightweightCharts.LineStyle.Dashed, axisLabelVisible: true, title: '30' });
} catch (e) {}

(function sync() {
    let busy = false;
    const link = (a, b) => a.timeScale().subscribeVisibleLogicalRangeChange(r => {
        if (busy || !r) return;
        busy = true;
        try { b.timeScale().setVisibleLogicalRange(r); } catch (e) {}
        busy = false;
    });
    link(chart, rsiChart);
    link(rsiChart, chart);
})();

let forecastSeries = [];
let maSeries = null;
const legendState = { price: 0, lines: [], ma: null, rsi: 0 };

function renderLegend(items) {
    return items.map(i =>
        `<span class="legend-item${i.dimmed ? ' legend-dim' : ''}">` +
        `<i style="background:${i.color}"></i> ${i.name}` +
        (i.points !== undefined ? ` <span class="muted">(${i.points})</span>` : '') +
        `</span>`
    ).join('');
}

function rebuildLegend() {
    const items = [{ color: '#26a69a', name: 'Факт (close)', points: legendState.price }];
    legendState.lines.forEach(line => {
        items.push({
            color: line.color,
            name: line.name + ' [' + (line.value_type === 'delta' ? 'Δ' : '₽') + ']',
            points: line.points, dimmed: !line.points,
        });
    });
    if (legendState.ma) {
        items.push({
            color: legendState.ma.color,
            name: legendState.ma.name,
            points: legendState.ma.points,
            dimmed: !legendState.ma.points,
        });
    }
    document.getElementById('main-legend').innerHTML = renderLegend(items);
    document.getElementById('rsi-legend').innerHTML = renderLegend([
        { color: RSI_COLOR, name: 'RSI(' + RSI_PERIOD + ')', points: legendState.rsi, dimmed: !legendState.rsi },
    ]);
}

async function loadAll() {
    const days = document.getElementById('period').value;
    const g = document.getElementById('granularity').value;
    statusEl.textContent = 'Загрузка…';

    try {
        const url = `index.php?r=api-history&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&days=${days}&granularity=${g}`;
        const r = await fetch(url); const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'API error');
        priceSeries.setData(j.data);
        legendState.price = (j.data || []).length;
    } catch (e) {
        statusEl.textContent = 'Ошибка загрузки цен: ' + e.message;
        return;
    }

    try {
        const url = `index.php?r=api-rsi&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&days=${days}&period=${RSI_PERIOD}&granularity=${g}`;
        const r = await fetch(url); const j = await r.json();
        if (j.ok) {
            rsiSeries.setData(j.data || []);
            legendState.rsi = (j.data || []).length;
        }
    } catch (e) { console.warn('rsi:', e); }

    forecastSeries.forEach(s => chart.removeSeries(s));
    forecastSeries = [];
    legendState.lines = [];

    try {
        const fr = await fetch(
            `index.php?r=api-forecast&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&granularity=${g}`
        );
        const fj = await fr.json();
        const lines = fj.lines || [];
        legendState.lines = lines;
        lines.forEach((line) => {
            if (!line.data || !line.data.length) return;
            const scale = line.value_type === 'delta' ? 'right' : 'left';
            const s = chart.addLineSeries({
                color: line.color || '#888',
                lineWidth: 2,
                lineStyle: line.computed
                    ? LightweightCharts.LineStyle.Dotted
                    : LightweightCharts.LineStyle.Dashed,
                title: line.name, priceScaleId: scale,
                priceLineVisible: false, lastValueVisible: true,
            });
            s.setData(line.data);
            forecastSeries.push(s);
        });
    } catch (e) { console.warn('forecast:', e); }

    await loadMA();
    rebuildLegend();
    chart.timeScale().fitContent();
    rsiChart.timeScale().fitContent();
    statusEl.textContent = 'Готово';
    loadVerification();
}

async function loadMA() {
    const type = document.getElementById('ma-type').value;
    const wrap = document.getElementById('ma-period-wrap');
    if (maSeries) { chart.removeSeries(maSeries); maSeries = null; }
    maHintEl.textContent = '';
    legendState.ma = null;
    if (type === 'none') { wrap.style.display = 'none'; rebuildLegend(); return; }
    wrap.style.display = '';

    const period = Math.max(2, Math.min(200, parseInt(document.getElementById('ma-period').value, 10) || 20));
    const days   = document.getElementById('period').value;
    const g      = document.getElementById('granularity').value;

    try {
        const url = `index.php?r=api-ma&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&days=${days}&period=${period}&type=${type}&granularity=${g}`;
        const r = await fetch(url); const j = await r.json();
        if (!j.ok) return;
        const name = type.toUpperCase() + '(' + period + ')';
        const pts  = j.data || [];
        if (!pts.length) {
            maHintEl.textContent = 'недостаточно баров (есть ' + (j.bars || 0) + ', нужно ' + period + ')';
            legendState.ma = { color: MA_COLOR, name: name, points: 0 };
            rebuildLegend();
            return;
        }
        maSeries = chart.addLineSeries({
            color: MA_COLOR, lineWidth: 2, title: name,
            priceScaleId: 'left', priceLineVisible: false, lastValueVisible: true,
        });
        maSeries.setData(pts);
        legendState.ma = { color: MA_COLOR, name: name, points: pts.length };
        rebuildLegend();
    } catch (e) { console.warn('ma:', e); }
}

async function loadVerification() {
    const box = document.getElementById('verify-container');
    try {
        const r = await fetch(
            `index.php?r=api-verify&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}`
        );
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'API error');
        renderVerification(j.lines);
    } catch (e) {
        box.innerHTML = '<p class="warn">Не удалось загрузить верификацию: ' + e.message + '</p>';
    }
}

function renderVerification(lines) {
    const box = document.getElementById('verify-container');
    if (!lines || !lines.length) {
        box.innerHTML = '<p class="warn">Прогнозов для верификации нет.</p>';
        return;
    }
    let html = '';
    for (const line of lines) {
        const v = line.verify;
        html += `<h3 style="margin-top:28px;color:${line.color}">${line.name}</h3>`;
        if (!v.rows || !v.rows.length) {
            html += '<p class="muted">Нет пересечения прогноза с фактической историей.</p>';
            continue;
        }
        if (v.mape !== null) {
            html += `<p class="muted">MAPE: <b>${v.mape}%</b> · Hit-rate: <b>${(v.hit_rate*100).toFixed(1)}%</b> · Точек: ${v.count}</p>`;
        }
        html += '<table class="stock-table"><thead><tr>' +
                '<th>Дата</th><th>Прогноз (цена)</th><th>Факт</th><th>Отклонение, %</th><th>Статус</th>' +
                '</tr></thead><tbody>';
        for (const row of v.rows) {
            const dev = row.deviation_pct !== null ? row.deviation_pct.toFixed(2) : '—';
            const cls = row.status === 'сбылся' ? 'status-ok' :
                        row.status === 'не сбылся' ? 'status-no' : '';
            html += `<tr>
                <td>${row.time}</td><td>${row.forecast}</td><td>${row.fact}</td>
                <td class="${cls}">${dev}</td><td class="${cls}">${row.status}</td>
            </tr>`;
        }
        html += '</tbody></table>';
    }
    box.innerHTML = html;
}

document.getElementById('period').addEventListener('change', loadAll);
document.getElementById('granularity').addEventListener('change', loadAll);
document.getElementById('ma-type').addEventListener('change', loadMA);

let maDebounce = null;
document.getElementById('ma-period').addEventListener('input', function () {
    clearTimeout(maDebounce);
    maDebounce = setTimeout(loadMA, 400);
});
window.addEventListener('resize', function () {
    chart.applyOptions({ width: chartEl.clientWidth });
    rsiChart.applyOptions({ width: rsiChartEl.clientWidth });
});
loadAll();
</script>
</main>
<footer>© StockForecast</footer>
</body>
</html>