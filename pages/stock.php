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
<link rel="preload" href="static/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="static/style.css?v=19">
</head>
<body>
<header class="topbar">
    <a class="logo" href="index.php">
        <svg class="icon icon-only" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="3 17 9 11 13 15 21 7"/>
            <polyline points="14 7 21 7 21 14"/>
        </svg>
        StockForecast
    </a>
    <nav>
        <a href="index.php?market=<?= h($market) ?>">← К списку</a>
        <a href="index.php?r=news">Новости</a>
        <a href="index.php?r=screener">Screener</a>
        <a href="index.php?r=accuracy">Точность</a>
        <a href="<?= is_admin() ? url('admin') : url('admin-login') ?>">Админка</a>
        <button type="button" id="theme-toggle" class="btn" title="Переключить тему">
            <svg class="icon icon-only" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
        </button>
    </nav>
</header>
<main>

<nav class="breadcrumbs" aria-label="Хлебные крошки">
    <a href="index.php">Главная</a>
    <span class="sep">/</span>
    <a href="index.php?market=<?= h($market) ?>"><?= strtoupper(h($market)) ?></a>
    <span class="sep">/</span>
    <span class="current"><?= h($ticker) ?></span>
</nav>

<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:12px">
    <h1 style="margin-bottom:8px"><?= h($ticker) ?> <small>(<?= strtoupper(h($market)) ?>)</small></h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
        <a class="btn" href="index.php?r=compare&market=<?= h($market) ?>&tickers=<?= h($ticker) ?>">Сравнить с другими</a>
        <a class="btn" href="index.php?r=export-verify&market=<?= h($market) ?>&ticker=<?= h($ticker) ?>">
            <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Скачать CSV
        </a>
        <button type="button" id="btn-export-png" class="btn" title="Экспорт основного графика">
            <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21 15 16 10 5 21"/>
            </svg>
            Скачать PNG
        </button>
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
    <button type="button" id="toggle-lower" class="btn" title="Скрыть/показать нижнюю панель">
        <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <line x1="12" y1="20" x2="12" y2="10"/>
            <line x1="18" y1="20" x2="18" y2="4"/>
            <line x1="6" y1="20" x2="6" y2="16"/>
        </svg>
        Панель
    </button>
    <span id="status" class="status"></span>
</div>

<div id="chart" class="chart" style="height:520px"></div>
<div id="main-legend" class="legend" style="display:none"></div>

<div class="rsi-head">
    <b id="lower-title">RSI(<?= (int)$rsiPeriod ?>)</b>
    <span class="muted" id="lower-subtitle">— индикатор (не прогноз).</span>
</div>

<h2 style="margin-top:48px">Верификация прогнозов</h2>
<p class="muted">Наведите курсор на график — увидите прогноз, факт, отклонение и статус по каждой точке.</p>
<div id="verify-container"><p class="muted">Загрузка верификации…</p></div>

<section class="section" style="margin-top:48px">
    <div class="section-head">
        <h2>Сравнение моделей прогноза (Дельта и Цена)</h2>
        <p class="muted">Сырые значения дельт (левая ось) наложены на реальную цену закрытия (правая ось). Цветные точки показывают статус прогноза.</p>
    </div>
    <div id="compare-forecasts-chart" class="chart" style="height:380px"></div>
</section>

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

<!-- amCharts 5: core + xy + stock + exporting (plugins!) + темы -->
<script src="https://cdn.amcharts.com/lib/5/index.js"></script>
<script src="https://cdn.amcharts.com/lib/5/xy.js"></script>
<script src="https://cdn.amcharts.com/lib/5/stock.js"></script>
<script src="https://cdn.amcharts.com/lib/5/plugins/exporting.js"></script>
<script src="https://cdn.amcharts.com/lib/5/themes/Animated.js"></script>
<script src="https://cdn.amcharts.com/lib/5/themes/Dark.js"></script>

<script>
const MARKET = <?= json_encode($market) ?>;
const TICKER = <?= json_encode($ticker) ?>;
const RSI_PERIOD = <?= (int)$rsiPeriod ?>;
const RSI_COLOR = '#ba68c8';
const MA_COLOR  = '#ffd54f';
const BB_COLOR  = '#64b5f6';

const chartEl    = document.getElementById('chart');
const statusEl   = document.getElementById('status');
const maHintEl   = document.getElementById('ma-hint');

const DEFAULT_CHART_TYPE = '<?= $defaultChartType ?>';
let chartType = localStorage.getItem('sf_chart_type') || DEFAULT_CHART_TYPE;

let amRoot = null;
let stockChart = null;
let mainPanel = null;
let dateAxis = null;
let valueAxis = null;
let valueAxisRight = null;
let priceSeries = null;
let forecastSeries = [];
let maSeries = null;
let bbSeries = [];

let lowerIndicator = null;
let forecastLinesData = null, verifyData = null;

let globalMinDate = 0;
let globalMaxDate = 0;

function getColors() {
    const s = getComputedStyle(document.body);
    return {
        bg: s.getPropertyValue('--bg-card').trim() || '#131722',
        text: s.getPropertyValue('--text').trim() || '#d1d4dc',
        border: s.getPropertyValue('--border').trim() || '#2a2e39',
    };
}

function isDarkTheme() {
    if (document.body.classList.contains('dark')) return true;
    if (document.body.classList.contains('light')) return false;
    return localStorage.getItem('sf_theme') === 'dark';
}

function pickRenderer(days) {
    return days <= 365 ? am5.SVGRenderer : am5.CanvasRenderer;
}

function granularityToBaseInterval(g) {
    if (g === 'week')  return { timeUnit: 'week',  count: 1 };
    if (g === 'month') return { timeUnit: 'month', count: 1 };
    return { timeUnit: 'day', count: 1 };
}

function toTs(timeStr) {
    if (typeof timeStr === 'number') return timeStr;
    return new Date(timeStr + 'T00:00:00Z').getTime();
}

function normalizeDate(timeStr) {
    if (!timeStr) return '';
    if (typeof timeStr === 'string') return timeStr.slice(0, 10);
    if (typeof timeStr === 'number') return new Date(timeStr).toISOString().slice(0, 10);
    return '';
}

function showSkeleton() {
    chartEl.innerHTML = '<div class="chart-loading"><div class="skeleton skeleton-block"></div><div class="skeleton skeleton-line short"></div></div>';
}
function hideSkeleton() {
    const a = chartEl.querySelector('.chart-loading'); if (a) a.remove();
}

function destroyCharts() {
    if (amRoot) { try { amRoot.dispose(); } catch(e){} amRoot = null; }
    stockChart = null; mainPanel = null; dateAxis = null; valueAxis = null; valueAxisRight = null;
    priceSeries = null; forecastSeries = []; maSeries = null; bbSeries = [];
    lowerIndicator = null;
    
    const cmpEl = document.getElementById('compare-forecasts-chart');
    if (cmpEl) cmpEl.innerHTML = '';

    chartEl.innerHTML = '';
}

function initAmChart(days, granularity) {
    const renderer = pickRenderer(days);
    amRoot = am5.Root.new("chart", { renderer: renderer });

    amRoot.fontFamily = "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    const themes = [am5themes_Animated.new(amRoot)];
    if (isDarkTheme()) themes.push(am5themes_Dark.new(amRoot));
    amRoot.setThemes(themes);

    stockChart = amRoot.container.children.push(
        am5stock.StockChart.new(amRoot, {})
    );

    mainPanel = stockChart.panels.push(
        am5stock.StockPanel.new(amRoot, {
            wheelY: "zoomX",
            panX: true,
            panY: true,
        })
    );

    const valueAxisRenderer = am5xy.AxisRendererY.new(amRoot, {});
    valueAxisRenderer.labels.template.setAll({ fontSize: 12, fill: am5.color(isDarkTheme() ? "#d1d4dc" : "#333333") });
    valueAxis = mainPanel.yAxes.push(
        am5xy.ValueAxis.new(amRoot, {
            renderer: valueAxisRenderer,
        })
    );

    const dateAxisRenderer = am5xy.AxisRendererX.new(amRoot, {});
    dateAxisRenderer.labels.template.setAll({ fontSize: 12, fill: am5.color(isDarkTheme() ? "#d1d4dc" : "#333333") });
    dateAxis = mainPanel.xAxes.push(
        am5xy.DateAxis.new(amRoot, {
            baseInterval: granularityToBaseInterval(granularity),
            renderer: dateAxisRenderer,
        })
    );

    mainPanel.set("cursor", am5xy.XYCursor.new(amRoot, {
        behavior: "zoomX",
        yAxis: valueAxis,
        xAxis: dateAxis,
    }));

    mainPanel.children.push(
        am5.Legend.new(amRoot, {
            centerX: am5.p50,
            x: am5.p50,
        })
    );

    amRoot.exporting = am5plugins_exporting.Exporting.new(amRoot, {
        dataSource: stockChart,
        menu: am5plugins_exporting.ExportingMenu.new(amRoot, {
            align: "right",
            verticalAlign: "top",
        }),
    });
}

function renderPriceSeries(historyData, type) {
    if (!mainPanel) return;
    if (priceSeries) { mainPanel.series.removeValue(priceSeries); priceSeries = null; }

    if (type === 'candles') {
        priceSeries = mainPanel.series.push(
            am5xy.CandlestickSeries.new(amRoot, {
                name: "Факт",
                xAxis: dateAxis,
                yAxis: valueAxis,
                valueXField: "Date",
                openValueYField: "open",
                highValueYField: "high",
                lowValueYField: "low",
                valueYField: "close",
                tooltip: am5.Tooltip.new(amRoot, {
                    labelText: "O:{openValueY.formatNumber('#.00')} H:{highValueY.formatNumber('#.00')} L:{lowValueY.formatNumber('#.00')} C:{valueY.formatNumber('#.00')}",
                }),
            })
        );
        priceSeries.data.setAll(historyData);
    } else {
        priceSeries = mainPanel.series.push(
            am5xy.SmoothedXLineSeries.new(amRoot, {
                name: "Факт (close)",
                xAxis: dateAxis,
                yAxis: valueAxis,
                valueXField: "Date",
                valueYField: "value",
                tension: 0.8,
                stroke: am5.color("#26a69a"),
                strokeWidth: 2,
                tooltip: am5.Tooltip.new(amRoot, {
                    labelText: "Факт: {valueY.formatNumber('#.00')}",
                }),
            })
        );
        priceSeries.data.setAll(historyData);
    }

    stockChart.set("stockSeries", priceSeries);
    mainPanel.appear(800, 100);
}

function getRightAxis() {
    if (valueAxisRight) return valueAxisRight;
    const renderer = am5xy.AxisRendererY.new(amRoot, { opposite: true });
    renderer.labels.template.setAll({ fontSize: 12, fill: am5.color(isDarkTheme() ? "#d1d4dc" : "#333333") });
    valueAxisRight = mainPanel.yAxes.push(
        am5xy.ValueAxis.new(amRoot, {
            renderer: renderer,
        })
    );
    return valueAxisRight;
}

function renderForecastLines(lines) {
    if (!mainPanel) return;
    forecastSeries.forEach(s => mainPanel.series.removeValue(s));
    forecastSeries = [];

    lines.forEach(line => {
        if (!line.data || !line.data.length) return;

        const yAxis = line.value_type === 'delta' ? getRightAxis() : valueAxis;

        const s = mainPanel.series.push(
            am5xy.SmoothedXLineSeries.new(amRoot, {
                name: line.name,
                xAxis: dateAxis,
                yAxis: yAxis,
                valueXField: "Date",
                valueYField: "value",
                tension: 0.8,
                stroke: am5.color(line.color || "#888"),
                strokeWidth: 2,
                strokeDasharray: line.computed ? [4, 4] : undefined,
                tooltip: am5.Tooltip.new(amRoot, {
                    labelText: line.name + ": {valueY.formatNumber('#.0000')}",
                }),
            })
        );
        s.data.setAll(line.data.map(p => ({ Date: toTs(p.time), value: p.value })));
        forecastSeries.push(s);
    });
}

/* ================= Нижняя панель (Этап 2) ================= */

async function loadLowerPanel() {
    if (!stockChart || !priceSeries) return;
    const panelType = document.getElementById('lower-panel').value;

    if (lowerIndicator) { 
        stockChart.indicators.removeValue(lowerIndicator); 
        lowerIndicator.dispose(); 
        lowerIndicator = null; 
    }

    document.getElementById('lower-title').textContent = panelType === 'rsi' ? 'RSI(' + RSI_PERIOD + ')' : 'MACD(12,26,9)';
    document.getElementById('lower-subtitle').textContent = panelType === 'rsi' 
        ? '— индикатор (не прогноз). Уровни 70 / 30 — перекупленность / перепроданность.'
        : '— разница EMA(12) и EMA(26), сглаженная EMA(9).';

    if (panelType === 'rsi') {
        lowerIndicator = stockChart.indicators.push(
            am5stock.RelativeStrengthIndex.new(amRoot, {
                stockChart: stockChart,
                stockSeries: priceSeries,
                period: RSI_PERIOD
            })
        );
        try {
            const rsiPanel = lowerIndicator.panel;
            if (rsiPanel) {
                rsiPanel.yAxes.values.forEach(axis => {
                    if (axis.get("renderer")) {
                        axis.get("renderer").labels.template.setAll({ fontSize: 12, fill: am5.color(isDarkTheme() ? "#d1d4dc" : "#333333") });
                    }
                });
            }
            lowerIndicator.get("series").values[0].set("stroke", am5.color(RSI_COLOR));
        } catch(e) {}
    } else {
        lowerIndicator = stockChart.indicators.push(
            am5stock.MACD.new(amRoot, {
                stockChart: stockChart,
                stockSeries: priceSeries,
                fastPeriod: 12,
                slowPeriod: 26,
                signalPeriod: 9
            })
        );
    }

    if (document.body.classList.contains('hide-lower') && lowerIndicator.panel) {
        lowerIndicator.panel.set("visible", false);
    }
}

/* ================= MA / Bollinger ================= */

async function loadMA() {
    const type = document.getElementById('ma-type').value;
    const wrap = document.getElementById('ma-period-wrap');
    if (maSeries && mainPanel) { try { mainPanel.series.removeValue(maSeries); } catch(e){} maSeries = null; }
    maHintEl.textContent = '';
    if (type === 'none') { wrap.style.display = 'none'; return; }
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
            maHintEl.textContent = 'недостаточно баров';
            return;
        }
        maSeries = mainPanel.series.push(
            am5xy.LineSeries.new(amRoot, {
                name: name,
                xAxis: dateAxis,
                yAxis: valueAxis,
                valueXField: "Date",
                valueYField: "value",
                stroke: am5.color(MA_COLOR),
                strokeWidth: 2,
                tooltip: am5.Tooltip.new(amRoot, { labelText: name + ": {valueY.formatNumber('#.00')}" }),
            })
        );
        maSeries.data.setAll(pts.map(p => ({ Date: toTs(p.time), value: p.value })));
    } catch (e) { console.warn('ma:', e); }
}

async function loadBollinger() {
    const on = document.getElementById('bb-toggle').checked;
    if (mainPanel) bbSeries.forEach(s => { try { mainPanel.series.removeValue(s); } catch(e){} });
    bbSeries = [];
    if (!on) return;

    const days = document.getElementById('period').value;
    const g    = document.getElementById('granularity').value;
    const period = 20, mult = 2.0;

    try {
        const url = `index.php?r=api-bollinger&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&days=${days}&period=${period}&mult=${mult}&granularity=${g}`;
        const r = await fetch(url); const j = await r.json();
        if (!j.ok) return;
        const d = j.data || {};
        const mk = (data, color, title, dash) => {
            if (!data || !data.length || !mainPanel) return;
            const s = mainPanel.series.push(
                am5xy.LineSeries.new(amRoot, {
                    name: title,
                    xAxis: dateAxis,
                    yAxis: valueAxis,
                    valueXField: "Date",
                    valueYField: "value",
                    stroke: am5.color(color),
                    strokeWidth: 1,
                    strokeDasharray: dash ? [4, 4] : undefined,
                    tooltip: am5.Tooltip.new(amRoot, { labelText: title + ": {valueY.formatNumber('#.00')}" }),
                })
            );
            s.data.setAll(data.map(p => ({ Date: toTs(p.time), value: p.value })));
            bbSeries.push(s);
        };
        mk(d.upper, BB_COLOR, 'BB upper', true);
        mk(d.sma,   '#ffffff', 'BB mid', false);
        mk(d.lower, BB_COLOR, 'BB lower', true);
    } catch (e) { console.warn('bb:', e); }
}

/* ================= Загрузка данных ================= */

async function loadAll() {
    const days = parseInt(document.getElementById('period').value, 10);
    const g = document.getElementById('granularity').value;
    statusEl.textContent = 'Загрузка…';
    showSkeleton();
    destroyCharts();
    initAmChart(days, g);

    try {
        const url = `index.php?r=api-history&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&days=${days}&granularity=${g}`;
        const r = await fetch(url); const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'API error');

        const historyData = (j.data || []).map(d => ({
            Date: toTs(d.time),
            open: d.open, high: d.high, low: d.low,
            close: d.close !== undefined ? d.close : d.value,
            value: d.value !== undefined ? d.value : d.close,
        }));

        if (historyData.length > 0) {
            globalMinDate = historyData[0].Date;
            globalMaxDate = historyData[historyData.length - 1].Date;
        }

        renderPriceSeries(historyData, chartType);
        hideSkeleton();
    } catch (e) {
        hideSkeleton();
        statusEl.textContent = 'Ошибка загрузки цен: ' + e.message;
        return;
    }

    try {
        const fr = await fetch(`index.php?r=api-forecast&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&granularity=${g}`);
        const fj = await fr.json();
        const lines = fj.lines || [];
        forecastLinesData = lines;
        renderForecastLines(lines);
    } catch (e) { console.warn('forecast:', e); }

    await loadMA();
    await loadBollinger();
    await loadLowerPanel();
    
    statusEl.textContent = 'Готово';
    loadVerification();
    loadCompareForecasts(days); // Этап 3
}

/* ================= Верификация ================= */

async function loadVerification() {
    const box = document.getElementById('verify-container');
    box.innerHTML = '<p class="muted">Загрузка верификации…</p>';
    try {
        const r = await fetch(`index.php?r=api-verify&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}`);
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'API error');
        verifyData = j.lines;
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
            html += '<p class="muted">Нет данных за выбранный период.</p>'; 
            continue; 
        }
        
        // Метрики за прошедший период
        if (v.count > 0) {
            let corrText = '';
            if (v.pearson !== null) {
                let quality = 'слабая';
                if (v.pearson > 0.7) quality = 'сильная';
                else if (v.pearson > 0.4) quality = 'умеренная';
                else if (v.pearson < 0) quality = 'обратная';
                corrText = ` · Корреляция: <b>${v.pearson}</b> <span class="muted">(${quality})</span>`;
            }
            html += `<p class="muted">Успешных векторов (≥50% движения): <b>${(v.hit_rate*100).toFixed(1)}%</b>${corrText} · Проверено точек: ${v.count}</p>`;
        } else {
            html += `<p class="muted">Все прогнозы на будущие даты (проверка ещё не началась).</p>`;
        }
        
        // Таблица
        html += '<table class="stock-table"><thead><tr><th>Дата</th><th>Дельта</th><th>Прогноз (цена)</th><th>Факт</th><th>Отклонение, %</th><th>Статус / Вектор</th></tr></thead><tbody>';
        for (const row of v.rows) {
            const dev = row.deviation_pct !== null ? row.deviation_pct.toFixed(2) : '—';
            const factStr = row.fact !== null ? row.fact : '—';
            
            let cls = 'muted';
            let style = '';
            if (row.status.includes('сбылся (успех)')) { cls = 'status-ok'; }
            else if (row.status.includes('не сбылся')) { cls = 'status-no'; }
            else if (row.status.includes('частично')) { cls = 'status-warn'; style = 'color: #ffa726;'; }

            html += `<tr>
                <td>${row.time}</td>
                <td class="muted">${row.delta !== undefined ? row.delta : '—'}</td>
                <td>${row.forecast}</td>
                <td>${factStr}</td>
                <td class="${cls}" style="${style}">${dev}</td>
                <td class="${cls}" style="${style}">${row.status}</td>
            </tr>`;
        }
        html += '</tbody></table>';
    }
    box.innerHTML = html;
}

/* ================= Сравнение моделей (Этап 3) ================= */

let compareRoot = null;

async function loadCompareForecasts(days) {
    const el = document.getElementById('compare-forecasts-chart');
    if (!el) return;
    
    if (compareRoot) { try { compareRoot.dispose(); } catch(e){} compareRoot = null; }

    compareRoot = am5.Root.new("compare-forecasts-chart");
    compareRoot.setThemes([am5themes_Animated.new(compareRoot)]);
    if (isDarkTheme()) compareRoot.setThemes([am5themes_Dark.new(compareRoot)]);
    
    compareRoot.fontFamily = "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    const chart = compareRoot.container.children.push(
        am5xy.XYChart.new(compareRoot, {
            wheelY: "zoomX",
            panX: true,
            panY: true,
            layout: compareRoot.verticalLayout
        })
    );

    // --- Оси ---
    const dateAxisRenderer = am5xy.AxisRendererX.new(compareRoot, {});
    dateAxisRenderer.labels.template.setAll({ fontSize: 12, fill: am5.color(isDarkTheme() ? "#d1d4dc" : "#333333") });
    const dateAxis = chart.xAxes.push(am5xy.DateAxis.new(compareRoot, {
        baseInterval: { timeUnit: "day", count: 1 },
        renderer: dateAxisRenderer,
    }));

    const deltaAxisRenderer = am5xy.AxisRendererY.new(compareRoot, {});
    deltaAxisRenderer.labels.template.setAll({ fontSize: 12, fill: am5.color(isDarkTheme() ? "#d1d4dc" : "#333333") });
    const valueAxisDelta = chart.yAxes.push(am5xy.ValueAxis.new(compareRoot, {
        renderer: deltaAxisRenderer,
        title: am5.Label.new(compareRoot, { text: "Дельта", fontSize: 12, fill: am5.color('#ef5350') })
    }));

    const priceAxisRenderer = am5xy.AxisRendererY.new(compareRoot, { opposite: true });
    priceAxisRenderer.labels.template.setAll({ fontSize: 12, fill: am5.color(isDarkTheme() ? "#d1d4dc" : "#333333") });
    const valueAxisPrice = chart.yAxes.push(am5xy.ValueAxis.new(compareRoot, {
        renderer: priceAxisRenderer,
        title: am5.Label.new(compareRoot, { text: "Курс", fontSize: 12, fill: am5.color('#26a69a') })
    }));

    chart.set("cursor", am5xy.XYCursor.new(compareRoot, {
        behavior: "zoomX",
        xAxis: dateAxis,
    }));

    chart.children.push(am5.Legend.new(compareRoot, {
        centerX: am5.p50,
        x: am5.p50,
        marginTop: 10
    }));

    const colors = { 1: '#ef5350', 2: '#42a5f5', 3: '#ffa726' };
    let lastKnownDate = Date.now();
    let maxDate = Date.now() + (days * 24 * 60 * 60 * 1000);

    // --- 1. Факт (Цена) ---
    try {
        const histRes = await fetch(`index.php?r=api-history&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&days=${days}&granularity=day`);
        const histData = await histRes.json();
        
        if (histData.ok && histData.data && histData.data.length) {
            lastKnownDate = toTs(histData.data[histData.data.length - 1].time);
            maxDate = lastKnownDate + (days * 24 * 60 * 60 * 1000); 

            const priceData = histData.data.map(p => ({
                Date: toTs(p.time),
                value: p.close !== undefined ? p.close : p.value
            }));

            const factSeries = chart.series.push(am5xy.LineSeries.new(compareRoot, {
                name: "Курс (Цена)",
                xAxis: dateAxis,
                yAxis: valueAxisPrice,
                valueXField: "Date",
                valueYField: "value",
                stroke: am5.color("#26a69a"),
                strokeWidth: 3,
                tooltip: am5.Tooltip.new(compareRoot, { labelText: "Цена: {valueY.formatNumber('#.00')}" })
            }));
            factSeries.data.setAll(priceData);
        }
    } catch (e) { console.warn('compare history:', e); }

    // --- 2. Прогнозы (Дельты) с маркерами статуса ---
    try {
        // Получаем данные верификации и делаем КАРТУ ДЛЯ КАЖДОЙ ЛИНИИ
        const verRes = await fetch(`index.php?r=api-verify&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}`);
        const verJson = await verRes.json();
        const verifyMaps = {}; // line_num => { dateStr => status }
        
        if (verJson.ok && verJson.lines) {
            for (const line of verJson.lines) {
                if (line.verify && line.verify.rows) {
                    const map = {};
                    for (const row of line.verify.rows) {
                        const dateStr = normalizeDate(row.time);
                        map[dateStr] = row.status;
                    }
                    verifyMaps[line.line_num] = map;
                }
            }
        }

        const fr = await fetch(`index.php?r=api-forecast&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}&granularity=day`);
        const fj = await fr.json();
        const lines = fj.lines || [];

        for (const line of lines) {
            if (!line.data || !line.data.length) continue;

            const filtered = line.data.filter(p => {
                const ts = toTs(p.time);
                return ts >= (lastKnownDate - (days * 24 * 60 * 60 * 1000)) && ts <= maxDate;
            });

            if (!filtered.length) continue;

            const lineMap = verifyMaps[line.line_num] || {};
            
            const seriesData = filtered.map(p => {
                const dateStr = normalizeDate(p.time);
                const status = lineMap[dateStr] || 'ожидается';
                return {
                    Date: toTs(p.time),
                    value: p.value,
                    status: status
                };
            });

            const series = chart.series.push(am5xy.LineSeries.new(compareRoot, {
                name: line.name,
                xAxis: dateAxis,
                yAxis: valueAxisDelta,
                valueXField: "Date",
                valueYField: "value",
                stroke: am5.color(colors[line.line_num] || "#888"),
                strokeWidth: 2,
                strokeDasharray: line.computed ? [4, 4] : undefined,
                tooltip: am5.Tooltip.new(compareRoot, { labelText: line.name + ": {valueY.formatNumber('#.0000')} ({status})" })
            }));

            series.bullets.push(function(root, series, dataItem) {
                // ИСПРАВЛЕНИЕ: используем dataContext для получения поля status
                let status = (dataItem.dataContext.status || '').toString().trim();
                
                // Если ожидается — скрываем точку
                if (!status || status.includes('ожидается')) {
                    return am5.Bullet.new(root, {
                        sprite: am5.Circle.new(root, { radius: 0 })
                    });
                }

                let color = am5.color("#888888");
                if (status.includes('сбылся')) {
                    color = am5.color("#26a69a"); // Зеленый
                } else if (status.includes('не сбылся')) {
                    color = am5.color("#ef5350"); // Красный
                } else if (status.includes('частично')) {
                    color = am5.color("#ffa726"); // Оранжевый
                }

                return am5.Bullet.new(root, {
                    sprite: am5.Circle.new(root, {
                        radius: 4,
                        fill: color,
                        stroke: am5.color(isDarkTheme() ? "#131722" : "#ffffff"),
                        strokeWidth: 1
                    })
                });
            });

            series.data.setAll(seriesData);
        }
    } catch (e) { console.warn('compare forecast:', e); }

    chart.appear(800, 100);
}

/* ================= Экспорт и UI ================= */

document.getElementById('btn-export-png').addEventListener('click', function () {
    try {
        if (amRoot && amRoot.exporting) {
            amRoot.exporting.export("png");
        } else {
            alert('Экспорт недоступен.');
        }
    } catch (e) {
        console.error(e);
        alert('Не удалось сохранить график.');
    }
});

document.getElementById('toggle-lower').addEventListener('click', function () {
    document.body.classList.toggle('hide-lower');
    const hidden = document.body.classList.contains('hide-lower');
    
    if (lowerIndicator && lowerIndicator.panel) {
        lowerIndicator.panel.set("visible", !hidden);
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

document.getElementById('theme-toggle').addEventListener('click', function () {
    setTimeout(loadAll, 150);
});

document.getElementById('chart-type').value = chartType;
loadAll();
</script>
<script src="static/theme.js"></script>
</main>
<footer>© StockForecast</footer>
</body>
</html>