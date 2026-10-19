<?php
$market = $_GET['market'] ?? 'moex';
$ticker = $_GET['ticker'] ?? '';
$rsiPeriod = max(2, min(100, (int)($_GET['rsi'] ?? 14)));
if ($ticker === '') { header('Location: ' . url('home')); exit; }

require_once __DIR__ . '/../lib/news.php';
$news = get_financial_news($ticker, 5);
$defaultChartType = get_setting('chart_type', 'line');
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
        <a href="index.php?r=screener">Screener</a>
        <a href="index.php?r=accuracy">Точность</a>
        <a href="<?= is_admin() ? url('admin') : url('admin-login') ?>">Админка</a>
        <button type="button" id="theme-toggle" class="btn" title="Переключить тему">🌓</button>
    </nav>
</header>
<main>
<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:12px">
    <h1 style="margin-bottom:8px"><?= h($ticker) ?> <small>(<?= strtoupper(h($market)) ?>)</small></h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
        <a class="btn" href="index.php?r=compare&market=<?= h($market) ?>&tickers=<?= h($ticker) ?>">Сравнить с другими</a>
        <a class="btn" href="index.php?r=export-verify&market=<?= h($market) ?>&ticker=<?= h($ticker) ?>">Скачать CSV</a>
        <button type="button" id="btn-export-png" class="btn">Скачать PNG</button>
    </div>
</div>

<div class="chart-controls">
    <label>Тип графика:
        <select id="chart-type">
            <option value="line" <?= $defaultChartType === 'line' ? 'selected' : '' ?>>Линии</option>
            <option value="candles" <?= $defaultChartType === 'candles' ? 'selected' : '' ?>>Свечи</option>
        </select>
    </label>
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
               style="width:64px;padding:6px;background:var(--bg-card);color:var(--text);border:1px solid var(--border);border-radius:6px">
        <span id="ma-hint" class="muted" style="margin-left:4px"></span>
    </label>
    <label>
        <input type="checkbox" id="bb-toggle"> Bollinger
    </label>
    <label>Нижняя панель:
        <select id="lower-panel">
            <option value="rsi" selected>RSI</option>
            <option value="macd">MACD</option>
        </select>
    </label>
    <span id="status" class="status"></span>
</div>

<div id="chart" class="chart"></div>
<div id="main-legend" class="legend"><span class="muted">Загрузка…</span></div>

<div class="rsi-head">
    <b id="lower-title">RSI(<?= (int)$rsiPeriod ?>)</b>
    <span class="muted" id="lower-subtitle">— индикатор (не прогноз). Уровни 70 / 30 — перекупленность / перепроданность.</span>
</div>
<div id="rsi-chart" class="rsi-chart"></div>
<div id="rsi-legend" class="legend"><span class="muted">Загрузка…</span></div>

<h2 style="margin-top:48px">Верификация прогнозов</h2>
<p class="muted">Сравнение прогнозной цены с фактической историей. Delta пересчитана через cumprod.</p>
<div id="verify-container"><p class="muted">Загрузка верификации…</p></div>

<!-- Сравнение прогнозов -->
<section class="section" style="margin-top:48px">
    <div class="section-head">
        <h2>Сравнение моделей прогноза</h2>
        <p class="muted">Все три линии на одном графике (нормализованы к 100 на начальную дату).</p>
    </div>
    <div id="compare-forecasts-chart" class="chart" style="height:360px"></div>
    <div id="compare-forecasts-legend" class="legend"></div>
</section>

<!-- Новости -->
<?php if (!empty($news)): ?>
<section class="section" style="margin-top:48px">
    <div class="section-head">
        <h2>Новости по тикеру <?= h($ticker) ?></h2>
    </div>
    <div class="card">
        <?php foreach ($news as $item): ?>
            <div style="display:flex; gap:12px; padding:12px 0; border-bottom:1px solid var(--border);">
                <div style="flex-shrink:0; width:24px; text-align:center; font-size:20px;">
                    <?php if (stripos($item['source'], 'Yahoo') !== false || stripos($item['source'], 'FreeNews') !== false): ?>
                        <span class="news-icon yahoo" title="Yahoo Finance">📰</span>
                    <?php elseif (stripos($item['source'], 'ПРАЙМ') !== false): ?>
                        <span class="news-icon moex" title="ПРАЙМ">🏦</span>
                    <?php else: ?>
                        <span class="news-icon euler" title="<?= h($item['source']) ?>">📊</span>
                    <?php endif; ?>
                </div>
                <div style="flex:1">
                    <a href="<?= h($item['url']) ?>" target="_blank" rel="noopener"
                       style="color:var(--accent); text-decoration:none; font-weight:600; font-size:15px;">
                        <?= h($item['title']) ?>
                    </a>
                    <div class="muted" style="font-size:12px; margin-top:4px;">
                        <?= h($item['source']) ?>
                        <?php if ($item['date']): ?>
                            · <?= date('d.m.Y H:i', strtotime($item['date'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<script src="https://unpkg.com/lightweight-charts@4.1.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
const MARKET = <?= json_encode($market) ?>;
const TICKER = <?= json_encode($ticker) ?>;
const RSI_PERIOD = <?= (int)$rsiPeriod ?>;
const RSI_COLOR = '#ba68c8';
const MA_COLOR  = '#ffd54f';
const BB_COLOR  = '#64b5f6';

const chartEl    = document.getElementById('chart');
const rsiChartEl = document.getElementById('rsi-chart');
const statusEl   = document.getElementById('status');
const maHintEl   = document.getElementById('ma-hint');

const DEFAULT_CHART_TYPE = '<?= $defaultChartType ?>';
let chartType = localStorage.getItem('sf_chart_type') || DEFAULT_CHART_TYPE;

// Все переменные графиков и серий — глобальные, чтобы их можно было сбросить
let chart = null;
let rsiChart = null;
let priceSeries = null;
let rsiSeries = null;
let forecastSeries = [];
let maSeries = null;
let bbSeries = [];
let macdSeries = [];
let compareForecastsChart = null;

const legendState = { price: 0, lines: [], ma: null, bb: null, lower: 0 };

function getColors() {
    const style = getComputedStyle(document.body);
    return {
        bg: style.getPropertyValue('--bg-card').trim() || '#131722',
        text: style.getPropertyValue('--text').trim() || '#d1d4dc',
        border: style.getPropertyValue('--border').trim() || '#2a2e39',
        grid: style.getPropertyValue('--border').trim() || '#1e222d',
    };
}

function chartOptions() {
    const c = getColors();
    return {
        layout: { background: { color: c.bg }, textColor: c.text },
        grid: { vertLines: { color: c.grid }, horzLines: { color: c.grid } },
        crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
        leftPriceScale:  { visible: true, borderColor: c.border, scaleMargins: { top: 0.1, bottom: 0.1 } },
        rightPriceScale: { visible: true, borderColor: c.border, scaleMargins: { top: 0.1, bottom: 0.1 } },
        timeScale: { borderColor: c.border, visible: true },
    };
}

// Полный сброс графиков
function destroyCharts() {
    if (chart) { try { chart.remove(); } catch(e){} chart = null; }
    if (rsiChart) { try { rsiChart.remove(); } catch(e){} rsiChart = null; }
    if (compareForecastsChart) { try { compareForecastsChart.remove(); } catch(e){} compareForecastsChart = null; }
    chartEl.innerHTML = '';
    rsiChartEl.innerHTML = '';
    forecastSeries = [];
    bbSeries = [];
    macdSeries = [];
    maSeries = null;
    priceSeries = null;
    rsiSeries = null;
}

function initCharts() {
    const opts = chartOptions();
    chart = LightweightCharts.createChart(chartEl, Object.assign({}, opts, {
        width: chartEl.clientWidth, height: 480
    }));
    window.chart = chart;

    rsiChart = LightweightCharts.createChart(rsiChartEl, Object.assign({}, opts, {
        width: rsiChartEl.clientWidth, height: 160
    }));
    window.rsiChart = rsiChart;

    rsiSeries = rsiChart.addLineSeries({
        color: RSI_COLOR, lineWidth: 2, title: 'RSI(' + RSI_PERIOD + ')',
        priceLineVisible: false, lastValueVisible: true,
    });
    try {
        rsiSeries.createPriceLine({ price: 70, color: '#ef5350', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed });
        rsiSeries.createPriceLine({ price: 30, color: '#26a69a', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed });
    } catch (e) {}

    // Синхронизация осей
    let busy = false;
    const link = (a, b) => a.timeScale().subscribeVisibleLogicalRangeChange(r => {
        if (busy || !r) return;
        busy = true;
        try { b.timeScale().setVisibleLogicalRange(r); } catch (e) {}
        busy = false;
    });
    link(chart, rsiChart);
    link(rsiChart, chart);
}

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
            color: legendState.ma.color, name: legendState.ma.name,
            points: legendState.ma.points, dimmed: !legendState.ma.points,
        });
    }
    if (legendState.bb) {
        items.push({
            color: legendState.bb.color, name: legendState.bb.name,
            points: legendState.bb.points, dimmed: !legendState.bb.points,
        });
    }
    document.getElementById('main-legend').innerHTML = renderLegend(items);
    document.getElementById('rsi-legend').innerHTML = renderLegend([
        { color: RSI_COLOR, name: 'Нижняя панель', points: legendState.lower, dimmed: !legendState.lower },
    ]);
}

async function loadAll() {
    const days = document.getElementById('period').value;
    const g = document.getElementById('granularity').value;
    statusEl.textContent = 'Загрузка…';

    // 1. Полный сброс графиков
    destroyCharts();
    initCharts();

    // 2. Данные цены
    try {
        const url = `index.php?r=api-history&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&days=${days}&granularity=${g}`;
        const r = await fetch(url); const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'API error');
        
        if (chartType === 'candles') {
            priceSeries = chart.addCandlestickSeries({
                upColor: '#26a69a', downColor: '#ef5350',
                borderVisible: false, wickUpColor: '#26a69a', wickDownColor: '#ef5350',
                priceScaleId: 'left',
            });
            priceSeries.setData(j.data);
        } else {
            priceSeries = chart.addLineSeries({
                color: '#26a69a', lineWidth: 2, title: 'Close',
                priceScaleId: 'left', priceLineVisible: false,
            });
            priceSeries.setData(j.data.map(d => ({ time: d.time, value: d.value })));
        }
        legendState.price = (j.data || []).length;
    } catch (e) {
        statusEl.textContent = 'Ошибка загрузки цен: ' + e.message;
        return;
    }

    // 3. Прогнозы
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

    // 4. Индикаторы (MA, Bollinger)
    await loadMA();
    await loadBollinger();
    await loadLowerPanel();
    
    rebuildLegend();
    chart.timeScale().fitContent();
    rsiChart.timeScale().fitContent();
    statusEl.textContent = 'Готово';

    // 5. Верификация и сравнение
    loadVerification();
    loadCompareForecasts();
}

async function loadMA() {
    const type = document.getElementById('ma-type').value;
    const wrap = document.getElementById('ma-period-wrap');
    if (maSeries) { try { chart.removeSeries(maSeries); } catch(e){} maSeries = null; }
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

async function loadBollinger() {
    const on = document.getElementById('bb-toggle').checked;
    bbSeries.forEach(s => { try { chart.removeSeries(s); } catch(e){} });
    bbSeries = [];
    legendState.bb = null;
    if (!on) { rebuildLegend(); return; }

    const days = document.getElementById('period').value;
    const g    = document.getElementById('granularity').value;
    const period = 20, mult = 2.0;

    try {
        const url = `index.php?r=api-bollinger&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&days=${days}&period=${period}&mult=${mult}&granularity=${g}`;
        const r = await fetch(url); const j = await r.json();
        if (!j.ok) return;
        const d = j.data || {};
        const mk = (data, color, title, dash) => {
            if (!data || !data.length) return;
            const s = chart.addLineSeries({
                color, lineWidth: 1, title, priceScaleId: 'left',
                priceLineVisible: false, lastValueVisible: false,
                lineStyle: dash ? LightweightCharts.LineStyle.Dashed : LightweightCharts.LineStyle.Solid,
            });
            s.setData(data);
            bbSeries.push(s);
        };
        mk(d.upper, BB_COLOR, 'BB upper', true);
        mk(d.sma,   '#ffffff', 'BB mid', false);
        mk(d.lower, BB_COLOR, 'BB lower', true);
        if (d.sma && d.sma.length) {
            legendState.bb = { color: BB_COLOR, name: 'Bollinger(' + period + ',' + mult + ')', points: d.sma.length };
        }
        rebuildLegend();
    } catch (e) { console.warn('bb:', e); }
}

async function loadLowerPanel() {
    const panel = document.getElementById('lower-panel').value;
    const days = document.getElementById('period').value;
    const g    = document.getElementById('granularity').value;

    // Очищаем нижнюю панель
    macdSeries.forEach(s => { try { rsiChart.removeSeries(s); } catch(e){} });
    macdSeries = [];
    if (rsiSeries) { try { rsiSeries.setData([]); } catch(e){} }
    legendState.lower = 0;

    if (panel === 'rsi') {
        document.getElementById('lower-title').textContent = 'RSI(' + RSI_PERIOD + ')';
        document.getElementById('lower-subtitle').textContent = '— индикатор (не прогноз). Уровни 70 / 30 — перекупленность / перепроданность.';
        try {
            const url = `index.php?r=api-rsi&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&days=${days}&period=${RSI_PERIOD}&granularity=${g}`;
            const r = await fetch(url); const j = await r.json();
            if (j.ok && rsiSeries) {
                rsiSeries.setData(j.data || []);
                legendState.lower = (j.data || []).length;
            }
        } catch (e) { console.warn('rsi:', e); }
    } else {
        document.getElementById('lower-title').textContent = 'MACD(12,26,9)';
        document.getElementById('lower-subtitle').textContent = '— разница EMA(12) и EMA(26), сглаженная EMA(9). Гистограмма — MACD − Signal.';
        try {
            const url = `index.php?r=api-macd&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&days=${days}&granularity=${g}`;
            const r = await fetch(url); const j = await r.json();
            if (j.ok && j.data) {
                const d = j.data;
                const macdLine = rsiChart.addLineSeries({ color: '#42a5f5', lineWidth: 1, title: 'MACD' });
                macdLine.setData(d.macd || []);
                macdSeries.push(macdLine);
                const sigLine = rsiChart.addLineSeries({ color: '#ffa726', lineWidth: 1, title: 'Signal' });
                sigLine.setData(d.signal || []);
                macdSeries.push(sigLine);
                if (d.histogram && d.histogram.length) {
                    const hist = rsiChart.addHistogramSeries({
                        color: '#26a69a',
                        priceFormat: { type: 'volume' },
                        priceScaleId: 'right',
                    });
                    hist.setData(d.histogram.map(p => ({
                        time: p.time,
                        value: p.value,
                        color: p.value >= 0 ? '#26a69a' : '#ef5350',
                    })));
                    macdSeries.push(hist);
                }
                legendState.lower = (d.macd || []).length;
            }
        } catch (e) { console.warn('macd:', e); }
    }
    rebuildLegend();
}

async function loadVerification() {
    const box = document.getElementById('verify-container');
    box.innerHTML = '<p class="muted">Загрузка верификации…</p>';
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

async function loadCompareForecasts() {
    const el = document.getElementById('compare-forecasts-chart');
    const legendEl = document.getElementById('compare-forecasts-legend');
    if (!el) return;

    if (compareForecastsChart) { try { compareForecastsChart.remove(); } catch(e){} }
    
    const opts = chartOptions();
    compareForecastsChart = LightweightCharts.createChart(el, Object.assign({}, opts, {
        width: el.clientWidth, height: 360
    }));
    window.compareForecastsChart = compareForecastsChart;

    const url = `index.php?r=api-forecast&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}`;
    const res = await fetch(url);
    const data = await res.json();

    const lines = data.lines || [];
    const colors = { 1: '#ef5350', 2: '#42a5f5', 3: '#ffa726' };
    const legendItems = [];

    for (const line of lines) {
        if (!line.data || line.data.length === 0) continue;
        const base = line.data[0].value;
        if (base === 0) continue;
        const normalized = line.data.map(p => ({
            time: p.time,
            value: (p.value / base) * 100,
        }));

        const series = compareForecastsChart.addLineSeries({
            color: colors[line.line_num] || '#888',
            lineWidth: 2,
            title: line.name,
        });
        series.setData(normalized);
        legendItems.push({
            color: colors[line.line_num],
            name: line.name,
            points: normalized.length,
        });
    }

    legendEl.innerHTML = legendItems.map(i =>
        `<span class="legend-item"><i style="background:${i.color}"></i> ${i.name} (${i.points})</span>`
    ).join('');

    compareForecastsChart.timeScale().fitContent();
}

document.getElementById('btn-export-png').addEventListener('click', function () {
    try {
        const mainCanvas = chart.takeScreenshot();
        const lowerCanvas = rsiChart.takeScreenshot();
        const outCanvas = document.createElement('canvas');
        const ctx = outCanvas.getContext('2d');
        const gap = 10;
        outCanvas.width = Math.max(mainCanvas.width, lowerCanvas.width);
        outCanvas.height = mainCanvas.height + lowerCanvas.height + gap;
        ctx.fillStyle = getColors().bg;
        ctx.fillRect(0, 0, outCanvas.width, outCanvas.height);
        ctx.drawImage(mainCanvas, 0, 0);
        ctx.drawImage(lowerCanvas, 0, mainCanvas.height + gap);
        const link = document.createElement('a');
        const today = new Date().toISOString().slice(0, 10);
        link.download = `chart_${MARKET.toUpperCase()}_${TICKER}_${today}.png`;
        link.href = outCanvas.toDataURL('image/png');
        link.click();
    } catch (e) {
        console.error('Ошибка при экспорте PNG:', e);
        alert('Не удалось сохранить график как изображение.');
    }
});

document.getElementById('period').addEventListener('change', loadAll);
document.getElementById('granularity').addEventListener('change', loadAll);
document.getElementById('ma-type').addEventListener('change', loadMA);
document.getElementById('bb-toggle').addEventListener('change', loadBollinger);
document.getElementById('lower-panel').addEventListener('change', loadLowerPanel);

document.getElementById('chart-type').addEventListener('change', function(e) {
    chartType = e.target.value;
    localStorage.setItem('sf_chart_type', chartType);
    loadAll();
});

let maDebounce = null;
document.getElementById('ma-period').addEventListener('input', function () {
    clearTimeout(maDebounce);
    maDebounce = setTimeout(loadMA, 400);
});

window.addEventListener('resize', function () {
    if (chart) chart.applyOptions({ width: chartEl.clientWidth });
    if (rsiChart) rsiChart.applyOptions({ width: rsiChartEl.clientWidth });
    if (compareForecastsChart) compareForecastsChart.applyOptions({ width: document.getElementById('compare-forecasts-chart').clientWidth });
});

// Инициализация
document.getElementById('chart-type').value = chartType;
loadAll();
</script>
<script src="static/theme.js"></script>
</main>
<footer>© StockForecast</footer>
</body>
</html>