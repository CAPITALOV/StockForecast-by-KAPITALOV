<?php
$market = $_GET['market'] ?? 'moex';
$ticker = $_GET['ticker'] ?? '';
$rsiPeriod = max(2, min(100, (int)($_GET['rsi'] ?? 14)));
if ($ticker === '') { header('Location: ' . url('home')); exit; }

require_once __DIR__ . '/../lib/news.php';

if (!function_exists('lines_meta')) {
    function lines_meta(): array {
        return [
            1 => ['name' => 'Delta Close — направление тренда Close', 'color' => '#e11d48', 'public' => true],
            2 => ['name' => 'Synthetic Average',                       'color' => '#f59e0b', 'public' => true],
            3 => ['name' => 'RPO — Weighted average price',            'color' => '#2563eb', 'public' => true],
        ];
    }
}

$news = get_financial_news($ticker, 5);
$defaultChartType = get_setting('chart_type', 'line');

$linesConfigForJs = [];
foreach (lines_meta() as $num => $l) {
    $linesConfigForJs[$num] = [
        'color'  => $l['color'],
        'public' => $l['public'],
        'label'  => $l['name'] ?? ($l['label'] ?? ''),
    ];
}

$goldenStats = [
    'us:C'    => ['line' => 3, 'n' => 277, 'win' => 60.6, 'pf' => 1.59, 'exp' => 0.336],
    'us:RTX'  => ['line' => 3, 'n' => 266, 'win' => 60.2, 'pf' => 1.49, 'exp' => 0.216],
    'us:T'    => ['line' => 3, 'n' => 205, 'win' => 57.1, 'pf' => 1.55, 'exp' => 0.218],
    'us:IBM'  => ['line' => 3, 'n' => 206, 'win' => 56.3, 'pf' => 1.67, 'exp' => 0.268],
    'us:ZTS'  => ['line' => 3, 'n' =>  62, 'win' => 71.0, 'pf' => 2.03, 'exp' => 0.445],
    'us:KO'   => ['line' => 3, 'n' => 205, 'win' => 53.2, 'pf' => 1.32, 'exp' => 0.121],
    'moex:PLZL' => ['line' => 1, 'n' => 137, 'win' => 47.4, 'pf' => 1.23, 'exp' => 0.172],
    'moex:ALRS' => ['line' => 1, 'n' =>  97, 'win' => 59.8, 'pf' => 2.06, 'exp' => 0.522],
];
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title><?= h($ticker) ?> — прогноз и верификация</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preload" href="static/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="static/style.css?v=28">
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
            <option value="365">1 год</option>
            <option value="730">2 года</option>
            <option value="1825" selected>5 лет</option>
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
    <?php if (is_admin()): ?>
    <label title="Synthetic Average — временно скрыть линию">
        <input type="checkbox" id="line2-toggle" checked> Synthetic
    </label>
    <?php endif; ?>
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
<p class="muted">
    Каждая строка — проверка направления сигнала (вектора) на конкретную дату.
    <b>Тренд дельты</b>: <span style="color:#2563eb;font-weight:700">↑ синий</span> — ускорение сигнала,
    <span style="color:#e11d48;font-weight:700">↓ красный</span> — замедление/спад.
    В будущих датах (голубой фон) стрелки показывают ожидаемое направление на горизонте планирования.
</p>
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

<!-- amCharts 5 -->
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

const IS_ADMIN = <?= is_admin() ? 'true' : 'false' ?>;
const LINES_CONFIG = <?= json_encode($linesConfigForJs, JSON_UNESCAPED_UNICODE) ?>;

const GOLDEN_STATS = <?= json_encode($goldenStats, JSON_UNESCAPED_UNICODE) ?>;
const GOLDEN_KEY = MARKET + ':' + TICKER.toUpperCase();
const GOLDEN_INFO = GOLDEN_STATS[GOLDEN_KEY] || null;

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

let marketRegime = null;
let marketRegimeData = null;

let globalMinDate = 0;
let globalMaxDate = 0;

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

function isLineVisible(lineNum) {
    const cfg = LINES_CONFIG[lineNum];
    if (!cfg) return true;
    return cfg.public || IS_ADMIN;
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
            wheelY: "zoomX", panX: true, panY: true,
        })
    );

    const valueAxisRenderer = am5xy.AxisRendererY.new(amRoot, {});
    valueAxisRenderer.labels.template.setAll({ fontSize: 12, fill: am5.color(isDarkTheme() ? "#d1d4dc" : "#333333") });
    valueAxis = mainPanel.yAxes.push(
        am5xy.ValueAxis.new(amRoot, { renderer: valueAxisRenderer })
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
        behavior: "zoomX", yAxis: valueAxis, xAxis: dateAxis,
    }));

    mainPanel.children.push(
        am5.Legend.new(amRoot, { centerX: am5.p50, x: am5.p50 })
    );

    amRoot.exporting = am5plugins_exporting.Exporting.new(amRoot, {
        dataSource: stockChart,
        menu: am5plugins_exporting.ExportingMenu.new(amRoot, { align: "right", verticalAlign: "top" }),
    });
}

function renderPriceSeries(historyData, type) {
    if (!mainPanel) return;
    if (priceSeries) { mainPanel.series.removeValue(priceSeries); priceSeries = null; }

    if (type === 'candles') {
        priceSeries = mainPanel.series.push(
            am5xy.CandlestickSeries.new(amRoot, {
                name: "Факт",
                xAxis: dateAxis, yAxis: valueAxis,
                valueXField: "Date",
                openValueYField: "open", highValueYField: "high", lowValueYField: "low",
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
                xAxis: dateAxis, yAxis: valueAxis,
                valueXField: "Date", valueYField: "value",
                tension: 0.8,
                stroke: am5.color("#26a69a"), strokeWidth: 2,
                tooltip: am5.Tooltip.new(amRoot, { labelText: "Факт: {valueY.formatNumber('#.00')}" }),
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
        am5xy.ValueAxis.new(amRoot, { renderer: renderer })
    );
    return valueAxisRight;
}

function renderForecastLines(lines) {
    if (!mainPanel) return;
    forecastSeries.forEach(s => mainPanel.series.removeValue(s));
    forecastSeries = [];

    lines.forEach(line => {
        if (!line.data || !line.data.length) return;
        if (!isLineVisible(line.line_num)) return;

        const yAxis = line.value_type === 'delta' ? getRightAxis() : valueAxis;
        const cfg   = LINES_CONFIG[line.line_num] || {};
        const color = cfg.color || line.color || "#888";

        const s = mainPanel.series.push(
            am5xy.SmoothedXLineSeries.new(amRoot, {
                name: line.name,
                xAxis: dateAxis, yAxis: yAxis,
                valueXField: "Date", valueYField: "value",
                tension: 0.8,
                stroke: am5.color(color), strokeWidth: 2,
                strokeDasharray: line.computed ? [4, 4] : undefined,
                tooltip: am5.Tooltip.new(amRoot, { labelText: line.name + ": {valueY.formatNumber('#.0000')}" }),
            })
        );

        // ── Отрисовка сигналов BUY/SELL с Тултипами ──
        if (line.line_num === 2) {
            s.bullets.push(function(root, series, dataItem) {
                const ctx = dataItem.dataContext;
                if (!ctx || (ctx.signal !== 'buy' && ctx.signal !== 'sell')) {
                    return am5.Bullet.new(root, { sprite: am5.Circle.new(root, { radius: 0 }) });
                }
                
                const isBuy = ctx.signal === 'buy';
                const fillColor = isBuy ? am5.color("#26a69a") : am5.color("#ef5350");
                
                const circle = am5.Circle.new(root, {
                    radius: 6,
                    fill: fillColor,
                    stroke: am5.color(isDarkTheme() ? "#131722" : "#ffffff"),
                    strokeWidth: 2
                });

                const confPct = Math.round((ctx.confidence || 0) * 100);
                const w1Pct = Math.round((ctx.w1 || 0) * 100);
                const w3Pct = 100 - w1Pct;
                const actionText = isBuy ? "🟢 СИГНАЛ: КУПИТЬ" : "🔴 СИГНАЛ: ПРОДАТЬ";

                circle.set("tooltipText", 
                    `[bold]${actionText}[/]\n` +
                    `Уверенность: [bold]${confPct}%[/]\n` +
                    `Вес L1: ${w1Pct}% | Вес L3: ${w3Pct}%\n` +
                    `Значение: ${ctx.value.toFixed(4)}`
                );

                return am5.Bullet.new(root, { sprite: circle });
            });
        }

        s.data.setAll(line.data.map(p => ({ 
            Date: toTs(p.time), 
            value: p.value,
            signal: p.signal,
            confidence: p.confidence,
            w1: p.w1
        })));
        forecastSeries.push(s);
    });

    const l2t = document.getElementById('line2-toggle');
    if (l2t && !l2t.checked) {
        forecastSeries.forEach(s => {
            const name = s.get('name') || '';
            if (name.indexOf('Synthetic') !== -1) s.hide();
        });
    }
}

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
                stockChart: stockChart, stockSeries: priceSeries, period: RSI_PERIOD
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
                stockChart: stockChart, stockSeries: priceSeries,
                fastPeriod: 12, slowPeriod: 26, signalPeriod: 9
            })
        );
    }

    if (document.body.classList.contains('hide-lower') && lowerIndicator.panel) {
        lowerIndicator.panel.set("visible", false);
    }
}

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
        if (!pts.length) { maHintEl.textContent = 'недостаточно баров'; return; }
        maSeries = mainPanel.series.push(
            am5xy.LineSeries.new(amRoot, {
                name: name, xAxis: dateAxis, yAxis: valueAxis,
                valueXField: "Date", valueYField: "value",
                stroke: am5.color(MA_COLOR), strokeWidth: 2,
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
                    name: title, xAxis: dateAxis, yAxis: valueAxis,
                    valueXField: "Date", valueYField: "value",
                    stroke: am5.color(color), strokeWidth: 1,
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

async function loadMarketRegime() {
    const idxTicker = MARKET === 'moex' ? 'IMOEX' : 'SPY';
    try {
        const r = await fetch(`index.php?r=api-history&market=${MARKET}&ticker=${idxTicker}&days=7300&granularity=day`);
        const j = await r.json();
        if (!j.ok || !j.data || j.data.length < 200) return;

        const closes = j.data.map(p => parseFloat(p.value !== undefined ? p.value : p.close));
        const n = closes.length;
        const last = closes[n - 1];

        let sum = 0;
        for (let i = n - 200; i < n; i++) sum += closes[i];
        const ma200 = sum / 200;

        marketRegime = last > ma200;
        marketRegimeData = {
            index: idxTicker,
            last: last,
            ma200: ma200,
            diffPct: ((last - ma200) / ma200 * 100).toFixed(2),
        };
    } catch (e) {
        console.warn('loadMarketRegime:', e);
    }
}

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
    await loadMarketRegime();

    statusEl.textContent = 'Готово';
    loadVerification();
    loadCompareForecasts(days);
}

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

function computeEntrySignalJs(rows, streakMin = 2) {
    if (!rows.length) {
        return { state: 'wait', label: 'Нет данных', reason: 'Мало точек для сигнала',
                 streak: 0, last_trend: '→', last_delta: null };
    }
    const lastRow = rows[rows.length - 1];
    const lastTrend = lastRow.delta_trend || '→';
    const lastDelta = lastRow.delta;

    if (lastTrend === '→') {
        return { state: 'wait', label: 'Ждать',
                 reason: 'Дельта без изменений — нет чёткого сигнала',
                 streak: 0, last_trend: '→', last_delta: lastDelta };
    }

    let streak = 0;
    for (let i = rows.length - 1; i >= 0; i--) {
        if ((rows[i].delta_trend || '→') === lastTrend) streak++;
        else break;
    }

    let prevTrend = null, prevStreak = 0;
    if (streak < rows.length) {
        const prevIdx = rows.length - 1 - streak;
        if (prevIdx >= 0) {
            prevTrend = rows[prevIdx].delta_trend || '→';
            for (let i = prevIdx; i >= 0; i--) {
                if ((rows[i].delta_trend || '→') === prevTrend) prevStreak++;
                else break;
            }
        }
    }

    if (streak >= streakMin && lastTrend === '↑') {
        return { state: 'buy', label: 'Покупать',
                 reason: `Тренд дельты ↑ ${streak} дн. подряд — импульс роста`,
                 streak, last_trend: '↑', last_delta: lastDelta };
    }
    if (streak >= streakMin && lastTrend === '↓') {
        return { state: 'avoid', label: 'Не покупать',
                 reason: `Тренд дельты ↓ ${streak} дн. подряд — импульс падает`,
                 streak, last_trend: '↓', last_delta: lastDelta };
    }
    if (streak === 1 && prevTrend && prevStreak >= streakMin
        && prevTrend !== lastTrend && prevTrend !== '→') {
        const nextUp = lastTrend === '↑';
        return {
            state: nextUp ? 'buy' : 'wait',
            label: nextUp ? 'Разворот вверх' : 'Разворот вниз',
            reason: nextUp
                ? `После ${prevStreak} дн. ↓ — первая ↑. Возможная точка входа`
                : `После ${prevStreak} дн. ↑ — первая ↓. Признак ослабления`,
            streak, last_trend: lastTrend, last_delta: lastDelta,
        };
    }
    return { state: 'wait', label: 'Ждать',
             reason: `Тренд дельты ${lastTrend}, ${streak} дн. — недостаточно для сигнала`,
             streak, last_trend: lastTrend, last_delta: lastDelta };
}

function renderVerification(lines) {
    const box = document.getElementById('verify-container');
    if (!lines || !lines.length) {
        box.innerHTML = '<p class="warn">Прогнозов для верификации нет.</p>';
        return;
    }

    const visible = lines.filter(l => isLineVisible(l.line_num) && l.verify);

    if (visible.length === 0) {
        box.innerHTML = '<p class="warn">Нет видимых линий для верификации.</p>';
        return;
    }

    let html = '';

    if (visible.length >= 2) {
        const withData = visible.filter(l => (l.verify.count || 0) > 0);
        let bestLineNum = null;
        let bestHit = -1;
        for (const l of withData) {
            const h = l.verify.hit_rate || 0;
            if (h > bestHit) { bestHit = h; bestLineNum = l.line_num; }
        }

        html += '<div style="margin:16px 0 28px;padding:16px 20px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px">';
        html += '<h3 style="margin:0 0 12px;font-size:16px">Сравнение линий</h3>';
        html += '<table class="stock-table" style="margin:0"><thead><tr>';
        html += '<th>Линия</th>';
        html += '<th style="text-align:right">Успешных</th>';
        html += '<th style="text-align:right">Средний сдвиг, п.п.</th>';
        html += '<th style="text-align:right">Корреляция</th>';
        html += '<th style="text-align:right">Проверено</th>';
        html += '</tr></thead><tbody>';

        for (const line of visible) {
            const v = line.verify;
            const hasData = (v.count || 0) > 0;
            const isBest = hasData && line.line_num === bestLineNum;

            const hitPct = hasData ? ((v.hit_rate || 0) * 100).toFixed(1) + '%' : '—';
            const avgDev = hasData
                ? ((v.avg_vector_dev !== null && v.avg_vector_dev !== undefined)
                    ? v.avg_vector_dev
                    : (v.mape !== null && v.mape !== undefined ? v.mape : '—'))
                : '—';

            let corrStr = '—';
            if (hasData && v.pearson !== null && v.pearson !== undefined) {
                let quality = '';
                if (Math.abs(v.pearson) > 0.05) quality = ' ✔ проверенный';
                else if (v.pearson > 0.7)       quality = ' (сильная)';
                else if (v.pearson > 0.4)       quality = ' (умеренная)';
                else if (v.pearson < -0.05)     quality = ' (обратная!)';
                else                            quality = ' (слабая)';
                corrStr = v.pearson + quality;
            }

            const hitColor = !hasData ? '#888'
                           : (v.hit_rate > 0.5 ? '#26a69a'
                           : (v.hit_rate < 0.4 ? '#ef5350' : 'var(--text)'));

            const noDataTag = !hasData ? ' <span class="muted" style="font-size:11px">(нет данных)</span>' : '';

            html += '<tr' + (isBest ? ' style="background:rgba(38,166,154,0.08)"' : '') + '>';
            html += '<td style="color:' + line.color + ';font-weight:600">'
                  + line.name
                  + (isBest ? ' <span style="color:#26a69a;font-size:12px">★ лучшая</span>' : '')
                  + noDataTag
                  + '</td>';
            html += '<td style="text-align:right;font-weight:600;color:' + hitColor + '">' + hitPct + '</td>';
            html += '<td style="text-align:right">' + avgDev + '</td>';
            html += '<td style="text-align:right">' + corrStr + '</td>';
            html += '<td style="text-align:right">' + (hasData ? v.count : '—') + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';
    }

    const signalLineNum = GOLDEN_INFO ? GOLDEN_INFO.line : 1;
    const signalLine = visible.find(l => l.line_num === signalLineNum);

    if (signalLine && signalLine.verify && signalLine.verify.rows && signalLine.verify.rows.length) {
        const allRows = signalLine.verify.rows;
        const signal = computeEntrySignalJs(allRows);

        if (signal.state === 'wait') {
            let lastRealIndex = -1;
            for (let i = allRows.length - 1; i >= 0; i--) {
                if (allRows[i].fact !== null) { lastRealIndex = i; break; }
            }
            if (lastRealIndex >= 0) {
                const futureRows = allRows.slice(lastRealIndex + 1);
                for (let i = 1; i < futureRows.length; i++) {
                    const prev = futureRows[i - 1].delta_trend;
                    const cur  = futureRows[i].delta_trend;
                    if (prev === '↓' && cur === '↑') {
                        signal.reason += `. Ближайший разворот вверх: ${futureRows[i].time}`;
                        break;
                    }
                    if (prev === '↑' && cur === '↓') {
                        signal.reason += `. Ближайший разворот вниз: ${futureRows[i].time}`;
                        break;
                    }
                }
            }
        }

        if (signal.state === 'buy' && marketRegime === false) {
            signal.state = 'wait';
            signal.label = 'Ждать (индекс в даунтренде)';
            signal.reason = 'Импульс на тикере растёт, но '
                + (marketRegimeData ? `${marketRegimeData.index} ниже MA200 на ${marketRegimeData.diffPct}%` : 'индекс ниже MA200')
                + '. Фильтр блокирует вход. ' + signal.reason;
            signal.filterApplied = true;
        }

        const emoji = signal.state === 'buy' ? '🟢'
                    : signal.state === 'avoid' ? '🔴' : '🟡';
        const bgColor     = signal.state === 'buy'   ? 'rgba(38,166,154,0.08)'
                          : signal.state === 'avoid' ? 'rgba(239,83,80,0.08)'
                          : 'rgba(255,167,38,0.08)';
        const borderColor = signal.state === 'buy'   ? '#26a69a'
                          : signal.state === 'avoid' ? '#ef5350'
                          : '#ffa726';
        const textColor   = signal.state === 'buy'   ? '#26a69a'
                          : signal.state === 'avoid' ? '#ef5350'
                          : '#ffa726';

        let bannerTitle = signal.label;
        if (GOLDEN_INFO) {
            const lineName = signalLineNum === 3 ? 'RPO' : (signalLineNum === 2 ? 'Synthetic' : 'Delta Close');
            bannerTitle += ` · сигнал по ${lineName}`;
        }

        html += `<div style="margin:16px 0 28px;padding:20px 24px;background:${bgColor};border:2px solid ${borderColor};border-radius:8px">`;
        html += `<div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap">`;
        html += `<div style="font-size:36px;line-height:1">${emoji}</div>`;
        html += `<div style="flex:1;min-width:200px">`;
        html += `<div style="font-size:20px;font-weight:700;color:${textColor}">${bannerTitle}</div>`;
        html += `<div style="font-size:13px;color:var(--text-muted);margin-top:6px">${signal.reason}</div>`;
        html += `<div style="font-size:11px;color:var(--text-muted);margin-top:8px">`;
        html += `Стрелка: ${signal.last_trend} · Streak: ${signal.streak} дн.`;
        if (signal.last_delta !== null && signal.last_delta !== undefined) {
            html += ` · Последняя дельта: ${Number(signal.last_delta).toFixed(4)}`;
        }
        html += `</div>`;

        if (GOLDEN_INFO) {
            const gi = GOLDEN_INFO;
            const lineName = gi.line === 3 ? 'RPO' : (gi.line === 2 ? 'Synthetic' : 'Delta Close');
            const expSign = gi.exp >= 0 ? '+' : '';
            const badgeColor = gi.pf >= 1.5 ? '#26a69a' : (gi.pf >= 1.3 ? '#ffa726' : '#888');

            html += `<div style="margin-top:14px;padding:14px 16px;background:rgba(38,166,154,0.08);border-left:3px solid ${badgeColor};border-radius:6px">`;
            html += `<div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;font-weight:600;margin-bottom:8px">📊 Историческая точность паттерна</div>`;
            html += `<div style="display:flex;gap:20px;flex-wrap:wrap;align-items:baseline">`;
            html += `<div>`;
            html += `<span style="font-size:22px;font-weight:700;color:${badgeColor}">${gi.win.toFixed(1)}%</span>`;
            html += `<span style="font-size:12px;color:var(--text-muted);margin-left:4px">успешных</span>`;
            html += `</div>`;
            html += `<div>`;
            html += `<span style="font-size:16px;font-weight:600;color:${badgeColor}">PF ${gi.pf.toFixed(2)}</span>`;
            html += `</div>`;
            html += `<div>`;
            html += `<span style="font-size:14px;font-weight:600;color:var(--text)">${expSign}${gi.exp.toFixed(3)}%</span>`;
            html += `<span style="font-size:11px;color:var(--text-muted);margin-left:4px">на сделку</span>`;
            html += `</div>`;
            html += `<div>`;
            html += `<span style="font-size:14px;color:var(--text-muted)">n = ${gi.n}</span>`;
            html += `</div>`;
            html += `</div>`;
            html += `<div style="font-size:11px;color:var(--text-muted);margin-top:8px">`;
            html += `Линия: <b>${lineName}</b> · Паттерн: первая ↑ после 2+ дней ↓ · Данные без фильтра MA200 (с фильтром — ещё лучше)`;
            html += `</div>`;
            html += `</div>`;
        }

        if (marketRegimeData) {
            const regimeColor = marketRegime ? '#26a69a' : '#ef5350';
            const regimeLabel = marketRegime ? '↑ индекс выше MA200' : '↓ индекс ниже MA200';
            const diffSign = parseFloat(marketRegimeData.diffPct) > 0 ? '+' : '';
            html += `<div style="font-size:11px;color:${regimeColor};margin-top:6px;font-weight:600">`;
            html += `Рыночный режим: ${regimeLabel} · `;
            html += `${marketRegimeData.index} ${marketRegimeData.last.toFixed(2)} `;
            html += `vs MA200 ${marketRegimeData.ma200.toFixed(2)} (${diffSign}${marketRegimeData.diffPct}%)`;
            html += `</div>`;
        }

        html += `</div></div></div>`;
    }

    for (const line of visible) {
        const cfg = LINES_CONFIG[line.line_num];
        const autoTag = line.computed ? ' <span class="muted">· авто</span>' : '';

        const v = line.verify || {};
        html += `<h3 style="margin-top:28px;color:${line.color}">${line.name}${autoTag}</h3>`;

        if (!v.rows || !v.rows.length) {
            html += '<p class="muted">Нет данных за выбранный период.</p>';
            continue;
        }

        if (v.count > 0) {
            let corrText = '';
            if (v.pearson !== null && v.pearson !== undefined) {
                let quality = 'слабая';
                if (Math.abs(v.pearson) > 0.05) quality = 'значимая';
                else if (v.pearson > 0.7)       quality = 'сильная';
                else if (v.pearson > 0.4)       quality = 'умеренная';
                else if (v.pearson < -0.05)     quality = 'обратная!';
                corrText = ` · Корреляция (delta ↔ факт): <b>${v.pearson}</b> <span class="muted">(${quality})</span>`;
            }
            const avgDev = (v.avg_vector_dev !== null && v.avg_vector_dev !== undefined)
                ? v.avg_vector_dev
                : (v.mape !== null && v.mape !== undefined ? v.mape : '—');
            html += `<p class="muted">Успешных векторов (≥50% движения): <b>${(v.hit_rate * 100).toFixed(1)}%</b>`
                  + ` · Средний сдвиг вектора: <b>${avgDev}</b> п.п.${corrText}`
                  + ` · Проверено точек: ${v.count}</p>`;
        } else {
            html += `<p class="muted">Все прогнозы на будущие даты (проверка ещё не началась).</p>`;
        }

        html += '<table class="stock-table"><thead><tr>'
             +  '<th>Дата</th>'
             +  '<th style="text-align:right">Цена закрытия</th>'
             +  '<th style="text-align:right">Дельта, %</th>'
             +  '<th title="Тренд дельты: ↑ синий — ускорение, ↓ красный — замедление">Тренд дельты</th>'
             +  '<th style="text-align:right">Факт. изменение, %</th>'
             +  '<th style="text-align:right">Сдвиг вектора, п.п.</th>'
             +  '<th>Статус / Вектор</th>'
             +  '</tr></thead><tbody>';

        for (const row of v.rows) {
            const hasFact  = row.fact !== null && row.fact !== undefined;
            const isFuture = !hasFact || (row.status || '').indexOf('ожидается') !== -1;

            const factStr = hasFact
                ? Number(row.fact).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : '—';
            const factStyle = isFuture
                ? 'color:#888;font-style:italic;text-align:right'
                : 'color:var(--text);font-weight:600;text-align:right';

            const trend = row.delta_trend || (row.delta > 0 ? '↑' : (row.delta < 0 ? '↓' : '→'));
            let trendStyle = 'color:#888;font-weight:700;font-size:18px;text-align:center';
            if (trend === '↑') trendStyle = 'color:#2563eb;font-weight:700;font-size:18px;text-align:center';
            else if (trend === '↓') trendStyle = 'color:#e11d48;font-weight:700;font-size:18px;text-align:center';

            let trendTooltip = '';
            if (row.delta_change !== null && row.delta_change !== undefined) {
                const sign = Number(row.delta_change) > 0 ? '+' : '';
                trendTooltip = `title="Δ ${sign}${Number(row.delta_change).toFixed(4)} (было ${row.prev_delta})"`;
            } else {
                trendTooltip = `title="Первая точка — стрелка по знаку дельты"`;
            }

            const act = (row.actual_return !== null && row.actual_return !== undefined)
                ? Number(row.actual_return).toFixed(2)
                : '—';
            let actStyle = 'color:#888;text-align:right';
            if (row.actual_return !== null && row.actual_return !== undefined) {
                actStyle = row.actual_return > 0
                    ? 'color:#26a69a;font-weight:600;text-align:right'
                    : (row.actual_return < 0 ? 'color:#ef5350;font-weight:600;text-align:right' : 'color:#888;text-align:right');
            }

            const dev = (row.vector_dev !== null && row.vector_dev !== undefined)
                ? Number(row.vector_dev).toFixed(2)
                : ((row.deviation_pct !== null && row.deviation_pct !== undefined)
                    ? Number(row.deviation_pct).toFixed(2)
                    : '—');

            let cls = 'muted';
            let style = '';
            if ((row.status || '').indexOf('сбылся (успех)') !== -1) { cls = 'status-ok'; }
            else if ((row.status || '').indexOf('не сбылся') !== -1) { cls = 'status-no'; }
            else if ((row.status || '').indexOf('частично') !== -1)  { cls = 'status-warn'; style = 'color:#ffa726;'; }

            const rowStyle = isFuture ? 'background:rgba(37,99,235,0.05);' : '';

            html += `<tr style="${rowStyle}">`
                 +  `<td>${row.time}</td>`
                 +  `<td style="${factStyle}">${factStr}</td>`
                 +  `<td style="text-align:right;color:#888">${row.delta}</td>`
                 +  `<td style="${trendStyle}" ${trendTooltip}>${trend}</td>`
                 +  `<td style="${actStyle}">${act}</td>`
                 +  `<td class="${cls}" style="${style};text-align:right">${dev}</td>`
                 +  `<td class="${cls}" style="${style}">${row.status}</td>`
                 +  `</tr>`;
        }
        html += '</tbody></table>';
    }

    box.innerHTML = html;
}

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
            wheelY: "zoomX", panX: true, panY: true,
            layout: compareRoot.verticalLayout
        })
    );

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

    chart.set("cursor", am5xy.XYCursor.new(compareRoot, { behavior: "zoomX", xAxis: dateAxis }));

    chart.children.push(am5.Legend.new(compareRoot, { centerX: am5.p50, x: am5.p50, marginTop: 10 }));

    const colors = {};
    Object.keys(LINES_CONFIG).forEach(num => { colors[num] = LINES_CONFIG[num].color; });

    let lastKnownDate = Date.now();
    let maxDate = Date.now() + (days * 24 * 60 * 60 * 1000);

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
                xAxis: dateAxis, yAxis: valueAxisPrice,
                valueXField: "Date", valueYField: "value",
                stroke: am5.color("#26a69a"), strokeWidth: 3,
                tooltip: am5.Tooltip.new(compareRoot, { labelText: "Цена: {valueY.formatNumber('#.00')}" })
            }));
            factSeries.data.setAll(priceData);
        }
    } catch (e) { console.warn('compare history:', e); }

    try {
        const verRes = await fetch(`index.php?r=api-verify&market=${encodeURIComponent(MARKET)}&ticker=${encodeURIComponent(TICKER)}`);
        const verJson = await verRes.json();
        const verifyMaps = {};

        if (verJson.ok && verJson.lines) {
            for (const line of verJson.lines) {
                if (line.verify && line.verify.rows) {
                    const map = {};
                    for (const row of line.verify.rows) {
                        map[normalizeDate(row.time)] = row.status;
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
            if (!isLineVisible(line.line_num)) continue;

            const filtered = line.data.filter(p => {
                const ts = toTs(p.time);
                return ts >= (lastKnownDate - (days * 24 * 60 * 60 * 1000)) && ts <= maxDate;
            });
            if (!filtered.length) continue;

            const lineMap = verifyMaps[line.line_num] || {};
            const cfg = LINES_CONFIG[line.line_num] || {};
            const lineColor = cfg.color || colors[line.line_num] || "#888";

            const seriesData = filtered.map(p => {
                const dateStr = normalizeDate(p.time);
                return {
                    Date: toTs(p.time),
                    value: p.value,
                    status: lineMap[dateStr] || 'ожидается'
                };
            });

            const series = chart.series.push(am5xy.LineSeries.new(compareRoot, {
                name: line.name,
                xAxis: dateAxis, yAxis: valueAxisDelta,
                valueXField: "Date", valueYField: "value",
                stroke: am5.color(lineColor), strokeWidth: 2,
                strokeDasharray: line.computed ? [4, 4] : undefined,
                tooltip: am5.Tooltip.new(compareRoot, { labelText: line.name + ": {valueY.formatNumber('#.0000')} ({status})" })
            }));

            series.bullets.push(function(root, series, dataItem) {
                let status = (dataItem.dataContext.status || '').toString().trim();
                if (!status || status.indexOf('ожидается') !== -1) {
                    return am5.Bullet.new(root, { sprite: am5.Circle.new(root, { radius: 0 }) });
                }
                let color = am5.color("#888888");
                if (status.indexOf('сбылся') !== -1)      color = am5.color("#26a69a");
                else if (status.indexOf('не сбылся') !== -1) color = am5.color("#ef5350");
                else if (status.indexOf('частично') !== -1)  color = am5.color("#ffa726");

                return am5.Bullet.new(root, {
                    sprite: am5.Circle.new(root, {
                        radius: 4, fill: color,
                        stroke: am5.color(isDarkTheme() ? "#131722" : "#ffffff"), strokeWidth: 1
                    })
                });
            });

            series.data.setAll(seriesData);
        }
    } catch (e) { console.warn('compare forecast:', e); }

    chart.appear(800, 100);
}

document.getElementById('btn-export-png').addEventListener('click', function () {
    try {
        if (amRoot && amRoot.exporting) amRoot.exporting.export("png");
        else alert('Экспорт недоступен.');
    } catch (e) { console.error(e); alert('Не удалось сохранить график.'); }
});

document.getElementById('toggle-lower').addEventListener('click', function () {
    document.body.classList.toggle('hide-lower');
    const hidden = document.body.classList.contains('hide-lower');
    if (lowerIndicator && lowerIndicator.panel) lowerIndicator.panel.set("visible", !hidden);
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

const line2Toggle = document.getElementById('line2-toggle');
if (line2Toggle) {
    line2Toggle.addEventListener('change', function(e) {
        forecastSeries.forEach(s => {
            const name = s.get('name') || '';
            if (name.indexOf('Synthetic') !== -1) {
                if (e.target.checked) s.show(); else s.hide();
            }
        });
    });
}

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