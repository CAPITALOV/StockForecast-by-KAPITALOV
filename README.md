# 📈 StockForecast

> Векторная система верификации прогнозов и индикатор импульса для акций MOEX и US-рынка.

[![Version](https://img.shields.io/badge/version-2.0.0-blue.svg)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://mysql.com)

---

## 🎯 Что это

StockForecast — система анализа **импульса тренда** на основе трёх независимых индикаторов (Delta Close, Synthetic Average, RPO). Показывает не «прогноз цены», а **направление и силу текущего движения**, плюс — историческую точность этих сигналов на 13-летней истории.

**Главное отличие от обычных прогнозных сервисов:** мы честно измеряем качество. Модель может ошибаться — и вы это увидите (Pearson, PF, win-rate). Не обещаем «золотых гор», показываем **статистику** и **контекст**.

---

## 📊 Что умеет

- **Три линии прогноза** на графике цены: Delta Close (красная), Synthetic Average (оранжевая), RPO (синяя)
- **Векторная верификация** — сравнение прогноза с реальным движением, а не с ценой
- **Тренд дельты** (стрелки ↑/↓) — ускорение или замедление сигнала
- **Баннер-светофор** на странице тикера: 🟢 Покупать / 🟡 Ждать / 🔴 Не покупать
- **MA200-фильтр** — блокирует BUY, если рынок в даунтренде
- **Плашка «Историческая точность»** для 8 «золотых» тикеров
- **CLI-аудит** всех тикеров с Money Metrics (PF, Exp, WinRate)
- **Screener** и **CSV-экспорт** для внешнего анализа

---

## 🚀 Установка

### Требования

- PHP **7.4+** (рекомендуется 8.1+)
- MySQL **5.7+** / MariaDB **10.3+**
- Composer не нужен — чистый PHP без зависимостей

### Развёртывание

```bash
# 1. Клонировать
git clone https://github.com/CAPITALOV/StockForecast-by-KAPITALOV.git
cd StockForecast-by-KAPITALOV

# 2. Настроить БД
cp config.example.php config.php
# отредактировать config.php: DB_HOST, DB_NAME, DB_USER, DB_PASS

# 3. Применить миграции
mysql -u USER -p DBNAME < migrations/001_initial.sql
# (если у вас уже есть БД из прошлых версий — миграции НЕ нужны)

# 4. Права на запись
chmod -R 755 data/ static/uploads/
chown -R www-data:www-data data/ static/uploads/

# 5. Проверить
curl -s "https://your-domain/index.php?r=api-tickers&market=moex" | head -c 200

cp lib/forecast.php lib/forecast.php.bak
cp lib/history.php  lib/history.php.bak
cp lib/verify.php   lib/verify.php.bak
cp lib/indicators.php lib/indicators.php.bak
cp lib/screener.php lib/screener.php.bak
cp index.php        index.php.bak
cp pages/stock.php  pages/stock.php.bak

# загрузить новые файлы из релиза v2.0.0

# сбросить OPcache (обязательно!)
touch lib/*.php index.php pages/stock.php

# проверить синтаксис
php -l lib/forecast.php
php -l lib/history.php
php -l lib/verify.php
php -l index.php
php -l pages/stock.php
Миграций БД нет. Всё работает на существующей схеме.

🧠 Как это работает
Три линии прогноза
На каждом графике — три независимых индикатора. Все показывают дельту (в процентах), но считаются по-разному:

Линия	Формула	Что показывает
Delta Close (🔴)	WMA(pct_change, 14)	Сглаженное направление изменения цены закрытия
Synthetic Average (🟠)	avg(Line1, Line3)	Усреднение двух крайних линий (пока не адаптив)
RPO (🔵)	(MA(14) − MA(50)) / MA(50) × 100	Относительный осциллятор, циклическая структура
Как читать: положительная дельта = сигнал на рост, отрицательная = на падение. Сила сигнала (magnitude) — это «уверенность» модели, но она не калибрована (дельта 0.5 не значит «в 5 раз сильнее роста, чем при 0.1»).

Векторная верификация
Каждая строка в таблице верификации — это проверка направления сигнала на конкретную дату:

text
Дельта модели: 0.2114% (прогноз: ↑ на +0.21%)
Факт (изменение цены): +1.52% (реально ↑ на 1.52%)
Статус: сбылся (успех) — совпал знак и движение ≥50% от ожидаемого
Правило зачёта:

✅ Сбылся — знак совпал И реальное движение ≥ 50% от прогнозного

🟡 Частично — знак совпал, но движение слабее 50%

❌ Не сбылся — знак противоположный

Ключевые метрики в сводке:

Успешных — процент сбывшихся сигналов

Средний сдвиг — среднее |actual_return − delta| в п.п. (чем меньше, тем точнее)

Корреляция (Pearson) — связь между delta и фактическим движением. |Pearson| > 0.05 = «✔ проверенный», реальный сигнал

Проверено — количество точек в верификации

Баннер-светофор
Над детальными таблицами — крупный баннер с текущим сигналом:

🟢 Покупать — тренд дельты ↑ 2+ дня подряд (импульс растёт)
🔴 Не покупать — тренд дельты ↓ 2+ дня подряд (импульс падает)
🟡 Ждать — смешанные стрелки или смена направления
🟢 Разворот вверх — первая ↑ после 2+ дней ↓ (возможная точка входа)

Логика в computeEntrySignalJs() (клиент) и compute_entry_signal() (сервер, если нужно для cron).

MA200-фильтр
Идея: покупаем только когда и импульс растёт, и рынок в аптренде. Если индекс ниже MA200 — фильтр блокирует вход.

Как работает:

При загрузке страницы loadMarketRegime() подтягивает SPY (для US) или IMOEX (для MOEX)

Считает MA200 по последним 200 барам

Если индекс выше MA200 → сигналы «Покупать» разрешены

Если индекс ниже MA200 → сигнал меняется на 🟡 «Ждать (индекс в даунтренде)»

A/B-тест эффективности (9 «золотых» тикеров, 2021–2026, n=1303 → 1005):

Метрика	Без фильтра	С MA200	Δ
Trades	1303	1005	−23%
Win%	54.2%	55.9%	+1.7 п.п.
Profit Factor	1.28	1.53	+0.25
Exp%	+0.142	+0.218	+53%
PF 1.53 — это уже торгуемо после комиссий. Без фильтра — на грани случайности.

Где код:

loadMarketRegime() в pages/stock.php — клиент, fetch api-history по SPY/IMOEX

Логика применения в renderVerification() — там проверка marketRegime === false

«Золотые» тикеры
Что это: 8 акций, где паттерн разворота («первая ↑ после 2+ дней ↓») исторически прибылен (PF > 1.2 на 13-летней истории).

Список (данные без MA200-фильтра — консервативно):

Тикер	Линия	Win%	PF	Exp%	n
ZTS	RPO	71.0%	2.03	+0.445	62
ALRS	Delta	59.8%	2.06	+0.522	97
C (Citigroup)	RPO	60.6%	1.59	+0.336	277
RTX	RPO	60.2%	1.49	+0.216	266
T (AT&T)	RPO	57.1%	1.55	+0.218	205
IBM	RPO	56.3%	1.67	+0.268	206
KO	RPO	53.2%	1.32	+0.121	205
PLZL	Delta	47.4%	1.23	+0.172	137
Как выбраны:

Прогнали golden-тест на 14 тикерах с |Pearson| > 0.04 (реальная корреляция)

Проверили паттерн разворота на каждом (BUY при первой ↑ после 2+ ↓)

Отобрали 8 с PF > 1.2 (остальные 6 — MCD, JNJ, VZ, JPM, ADBE, DG — оказались убыточны на паттерне)

Проверили устойчивость по годам — работает стабильно с 2020

Где используется:

На странице «золотого» тикера в баннере появляется плашка «📊 Историческая точность» с цифрами win% / PF / exp / n

Для US-тикеров сигнал берётся с RPO (Line 3), для MOEX — с Delta Close (Line 1)

В $goldenStats (PHP) и GOLDEN_STATS (JS) в pages/stock.php

Как добавить новый: найдите $goldenStats в начале pages/stock.php:
$goldenStats = [
    // ...
    'us:YOUR_TICKER' => [
        'line' => 3,      // 1=Delta, 2=Synthetic, 3=RPO
        'n'    => 200,    // сколько сделок в тесте
        'win'  => 55.0,   // win% из теста
        'pf'   => 1.35,   // profit factor
        'exp'  => 0.15,   // expectancy % на сделку
    ],
];

⚠️ Включайте только те тикеры, где паттерн проверен через bin/audit_lines.php и показывает PF > 1.2. Не по hit-rate модели, а именно по паттерну разворота. Иначе это самообман.

🔧 CLI: bin/audit_lines.php
Скрипт для аудита всех тикеров в БД: прогоняет верификацию, считает метрики, ищет «золотые» тикеры.

Запуск
cd /path/to/project

# Полный аудит (все тикеры)
php bin/audit_lines.php

# С параметрами
php bin/audit_lines.php --market=moex --min-count=50 --top=10 --csv=/tmp/audit.csv

# Только конкретные тикеры
php bin/audit_lines.php --tickers=LKOH,SBER,GAZP,IMOEX

cd /path/to/project

# Полный аудит (все тикеры)
php bin/audit_lines.php

# С параметрами
php bin/audit_lines.php --market=moex --min-count=50 --top=10 --csv=/tmp/audit.csv

# Только конкретные тикеры
php bin/audit_lines.php --tickers=LKOH,SBER,GAZP,IMOEX

Параметры
Флаг	По умолчанию	Что делает
--market=moex|us	все	Фильтр по рынку
--min-count=10	10	Минимум точек верификации для учёта тикера
--top=5	5	Сколько лучших/худших показать на линию
--csv=/path/file.csv	—	Сохранить результат в CSV (для Excel)
--tickers=LKOH,SBER	—	Только конкретные тикеры
Что покажет

Аудит 101 тикеров...

╔══════════════════════════════════════════════════════════════════╗
║                       СВОДКА: HIT-RATE И PEARSON                 ║
╠══════════════════════════════════════════════════════════════════╣
║ Линия            │ Успешных │ Pearson  │ Ср.сдвиг │ Тикеров │ Точек
╟──────────────────┼──────────┼──────────┼────────────┼─────────┼────────
║ Delta Close      │   45.4%  │   0.004  │   1.68   │   85    │ 100019
║ Synthetic        │   44.1%  │   0.005  │   1.69   │   85    │  99976
║ RPO              │   42.9%  │   0.008  │   1.71   │   85    │ 100019
╚══════════════════╧══════════╧══════════╧════════════╧═════════╧════════

╔══════════════════════════════════════════════════════════════════╗
║                  СВОДКА: MONEY METRICS (PnL, Profit Factor)      ║
╠══════════════════════════════════════════════════════════════════╣
║ Линия            │ Trades │ Wins │ Losses │ P.Factor │ WinRate │ Exp%
╟──────────────────┼────────┼──────┼────────┼──────────┼─────────┼──────
║ Delta Close      │ 100005 │ 50741│  49264 │   1.09   │ 50.7%   │ +0.063
║ Synthetic        │  99973 │ 50713│  49260 │   1.09   │ 50.7%   │ +0.062
║ RPO              │ 100017 │ 50743│  49274 │   1.09   │ 50.7%   │ +0.062
╚══════════════════╧════════╧══════╧════════╧══════════╧═════════╧══════

── Delta Close ──────────────────────────────────────
  Лучшие 5:
    us:NVDA         hit  52.7%  pearson -0.027  n=1544  mape  2.33
    us:AAPL         hit  50.5%  pearson -0.096  n= 394  mape  1.24
    ...
  Худшие 5:
    moex:VTBR       hit  30.5%  pearson  0.142  n=  95  mape  0.91
    ...

── Лучшая линия по каждому тикеру ──────────────────
  Delta Close : 70 тикеров
  Synthetic   :  5 тикеров
  RPO         : 10 тикеров

  Как читать метрики
Hit-rate (Успешных):

> 55% — хорошо, модель реально предсказывает

50–55% — на грани, есть слабый сигнал

< 50% — хуже случайности, модель ошибается

Pearson:

> +0.05 — ✔ реальный сигнал (прямой)

-0.05…+0.05 — шум, статистически нулевая связь

< -0.05 — ⚠️ модель контрарна (сигнал противоположен реальности)

Profit Factor (PF):

> 1.20 — прибыльно

1.05–1.20 — на грани, комиссии съедят

< 1.05 — убыточно

Expectancy (Exp%):

> +0.10% — прибыль на сделку после комиссий

0…+0.10% — в минусе после комиссий

< 0 — убыточно

CSV-формат
Файл audit_full_YYYYMMDD.csv содержит (разделитель ;, BOM UTF-8 для Excel):

market_ticker;line;name;hit_rate;pearson;count;mape;trades;wins;losses;profit_factor;win_rate;expectancy;gross_profit;gross_loss;avg_win;avg_loss
us:C;3;RPO;0.4307;0.112;2157;1.56;1234;612;622;1.05;...
us:NVDA;1;Delta Close;0.5272;-0.027;1544;2.33;...
moex:ALRS;1;Delta Close;0.4652;0.061;733;1.66;...

Пример: найти «золотые» тикеры

# 1. Запустить аудит
php bin/audit_lines.php --csv=/tmp/audit.csv

# 2. Найти тикеры с реальной корреляцией (Pearson > 0.05) и достаточной выборкой
awk -F';' 'NR>1 && $5>0.05 && $6>200 {print $5"\t"$1"\t"$2"\t"$4}' /tmp/audit.csv | sort -rn | head -20

# 3. Проверить паттерн разворота на кандидатах через /tmp/reversal_golden.php
# (скрипт из истории разработки — сохраняйте для повторного использования)

Требования для CLI
Скрипт делает require_once на: bootstrap.php, lib/api.php, lib/history.php, lib/verify.php, lib/forecast.php. Если запускается на сервере с другим путём — поправьте пути в bin/audit_lines.php.

🌐 API
Все эндпоинты — через index.php?r=<route>:

Прогнозы и верификация
GET /index.php?r=api-forecast&market=moex&ticker=ALRS&granularity=day

Ответ — линии прогнозов на график:

{
  "lines": [
    { "line_num": 1, "name": "Delta Close — направление тренда Close",
      "color": "#e11d48", "value_type": "delta", "computed": false,
      "data": [{"time": "2024-01-15", "value": 0.15}, ...] },
    { "line_num": 2, "name": "Synthetic Average · авто",
      "color": "#f59e0b", "computed": true, "data": [...] },
    { "line_num": 3, "name": "RPO — Weighted average price",
      "color": "#2563eb", "computed": false, "data": [...] }
  ]
}
GET /index.php?r=api-verify&market=moex&ticker=ALRS

Ответ — верификация всех линий:
{
  "ok": true,
  "isAdmin": false,
  "lines": [
    { "line_num": 1, "name": "Delta Close",
      "color": "#e11d48",
      "computed": false,
      "verify": {
        "count": 733,
        "hit_rate": 0.4652,
        "pearson": 0.061,
        "avg_vector_dev": 1.66,
        "rows": [
          { "time": "2020-03-18", "delta": 0.2114,
            "expected_dir": "↑", "delta_trend": "↑",
            "delta_change": 0.0215, "prev_delta": 0.1899,
            "actual_return": 1.52, "vector_dev": 1.31,
            "fact": 4717, "status": "сбылся (успех)" }, ...
        ]
      }
    }, ...
  ]
}
История и индикаторы
Endpoint	Что отдаёт
api-history	Свечи / линия цены (бары)
api-tickers	Список тикеров для выбранного рынка
api-ma	SMA / EMA по баров
api-rsi	RSI-индикатор
api-macd	MACD-индикатор
api-bollinger	Bollinger Bands
api-compare	Сравнение нескольких тикеров
api-recent-hits	Свежие попадания (screener)
Экспорт
Endpoint	Что отдаёт
export-verify&market=&ticker=	CSV всех прогнозов и их верификации
export-accuracy	CSV точности по всем тикерам
export-screener	CSV скринера (сводная таблица по тикерам)
📅 Ежедневное обновление
1. Загрузить новые прогнозы
Через админку (?r=admin) — формы «Ручная загрузка» и «Импорт прогнозов». Формат content:
2024-01-15 0.2114
2024-01-16 0.2329
2024-01-17 0.2442
Разделитель — пробел, точка с запятой, запятая или таб. parse_forecast() распознаёт все.

2. Бэкфилл истории (если нужно)
# Через админку
curl -b "PHPSESSID=..." "https://your-domain/index.php?r=backfill-history&days=3650"

# Или CLI
php -r '
require "bootstrap.php";
require "lib/api.php";
require "lib/history.php";
$pdo = db();
foreach ($pdo->query("SELECT DISTINCT market, ticker FROM forecast_lines") as $r) {
    $res = history_get($r["market"], $r["ticker"], 3650, "live");
    echo "{$r["ticker"]}: " . count($res["data"] ?? []) . " баров\n";
}'

3. Запустить аудит для мониторинга качества
php bin/audit_lines.php --csv=/tmp/audit_$(date +%Y%m%d).csv
Рекомендую cron раз в неделю:
0 3 * * 1 cd /path/to/project && php bin/audit_lines.php --csv=/path/to/reports/audit_$(date +\%Y\%m\%d).csv 2>&1 | mail -s "StockForecast audit" admin@your-domain

🐞 Troubleshooting
Line 2 не отображается на графике
Причина: parse_forecast() не может разобрать content.

Проверка:
SELECT LEFT(content, 100) FROM forecast_lines WHERE market='moex' AND ticker='ALRS' AND line_num=2;
Фикс: формат должен быть 2024-01-15 0.15 или 2024-01-15;0.15. Если что-то другое — обновите _fc_split_row() в lib/forecast.php.

Cannot redeclare average_forecast_lines()
Причина: функция объявлена в двух файлах (lib/forecast.php и lib/indicators.php).

Фикс: обе версии должны быть обёрнуты if (!function_exists(...)). Проверьте:
grep -n "function_exists('average_forecast_lines')" lib/*.php
# Должно быть в обоих файлах
График «20 лет» показывает 1 год
Причина: history_get() игнорирует $days (баг из v1.x).

Проверка:
php -r '
require "bootstrap.php";
require "lib/history.php";
foreach ([365, 7300] as $d) {
    $r = history_get("moex", "SBER", $d, "auto");
    echo "days=$d: " . count($r["data"] ?? []) . " баров\n";
}'
Фикс: обновите lib/history.php до v2.0.0.

Баннер показывает дату разворота из 2020 года
Причина: старая логика ищет fact === null по всем строкам, а не только после последней реальной даты.

Фикс: обновите pages/stock.php до v2.0.0 (там lastRealIndex логика).

«Ошибка 500» после деплоя
Проверка:
php -l pages/stock.php
php -l lib/forecast.php
php -l index.php

Сброс OPcache:
touch lib/*.php pages/*.php index.php
history_get() не сохраняет в БД при mode='live'
Было в v1.x: save_bars() вызывался только в mode='auto'.

Фикс в v2.0.0: сохраняется всегда, когда есть данные.

⚠️ Известные ограничения
Модель — индикатор импульса, не прогноз цены
На 100 019 точках верификации (85 тикеров, 13 лет):

WinRate: 50.7% — базовая вероятность (рынок растёт 50.7% дней)

Profit Factor: 1.09 — убыточно после комиссий

Pearson: 0.004–0.008 — статистически значимо, но практически ноль

Что это значит: модель не предсказывает цену. Она показывает направление импульса — этого достаточно для контекста, но не для «торговой системы».

Long-only
На 40 000 точек истории — 25 отрицательных дельт (0.06%). Модель сигналит только на рост разной силы. Падения не предсказывает.

Практический вывод: используйте баннер-светофор как «держать/не держать», а не «покупать/шортить».

Line 2 (Synthetic) = простое среднее
Не адаптивный синтез. Формула: avg(Line1, Line3). В будущем планируется заменить на адаптив с весами по rolling hit-rate.

Токсичные тикеры
7 акций с Pearson < -0.05, где модель систематически ошибается:

GOOGL, AAPL, BNB-USD, TSLA, INTC, CRM, SONY

В UI не блокируются — но не доверяйте сигналам на них. Планируется добавить в TOXIC_TICKERS для скрытия баннера.

MA200-фильтр помогает не везде
На «золотых» тикерах PF: 1.28 → 1.53 (отлично). Но на «токсичных» (AAPL, GOOGL) фильтр не спасает — там модель вообще контрарна.

🗺️ Roadmap
v2.1 (планируется)
Адаптивный Line 2 — синтез с весами w1·Z1 + w3·Z3 по rolling hit-rate (β=0.9, w∈[0.2, 0.8]). Cron-задача раз в день.

TOXIC_TICKERS в UI — скрывать баннер на AAPL, GOOGL, BNB и др.

Telegram-уведомления — раз в день пушить «🔔 ALRS: разворот вверх + индекс в аптренде. Историческая точность 59.8%».

v2.2 (идеи)
Screener с «золотыми» тикерами — отдельная страница «где сейчас покупать»

Sharpe ratio в аудите (не только PF)

Гранулярность week для сигналов (дневки шумные)

API-токены для внешних клиентов

v3.0 (далёкое)
Настоящий прогноз направления — direction-only модель с ±1 (вместо 0.2…0.6)

Портфельный подход — не отдельные тикеры, а корзина с ребалансировкой

ML-ансамбль — комбинация Delta, RPO, RSI, MACD с весами по историческому hit-rate

📚 Структура проекта
StockForecast/
├── bin/
│   └── audit_lines.php        # CLI-аудит
├── lib/
│   ├── api.php                # MOEX ISS + Yahoo Finance
│   ├── forecast.php           # 3 линии + метаданные
│   ├── history.php            # загрузка/сохранение баров
│   ├── import.php             # импорт CSV
│   ├── indicators.php         # RSI, MA, MACD, Bollinger
│   ├── news.php               # новости
│   ├── screener.php           # screener по тикерам
│   ├── telegram.php           # уведомления
│   └── verify.php             # векторная верификация
├── pages/
│   ├── home.php
│   ├── stock.php              # ← баннер, светофор, MA200
│   ├── admin.php
│   ├── admin-login.php
│   ├── accuracy.php
│   ├── compare.php
│   ├── news.php
│   └── screener.php
├── data/                      # кэши (не в git)
├── static/
│   ├── style.css
│   ├── theme.js
│   └── fonts/
├── index.php                  # роутер
├── bootstrap.php              # инициализация
├── config.php                 # настройки (не в git)
├── CHANGELOG.md
└── README.md

🤝 Вклад
Это pet-проект. PR и issue приветствуются, но без гарантий по срокам.

Особенно интересует:

Идеи по улучшению моделей (не только Delta / RPO)

Бэктесты на новых тикерах или периодах

Оптимизация адаптивного Line 2

📄 Лицензия
Свободное использование в личных и образовательных целях. Не является финансовой рекомендацией.

Автор не несёт ответственности за потери от использования сигналов. Модель — индикатор, не «гарантия прибыли».

🙏 Благодарности
amCharts 5 — графики

MOEX ISS API — данные Мосбиржи

Yahoo Finance — данные US-рынка

Inter Font — типографика

📋 Итог: что теперь в репозитории
Файл	Назначение
README.md	Общая документация (этот файл)
CHANGELOG.md	История изменений
bin/audit_lines.php	CLI-аудит тикеров
lib/history.php	Загрузка истории (с критичным фиксом)
lib/verify.php	Векторная верификация
lib/forecast.php	3 линии + парсинг
lib/indicators.php	RSI/MA/MACD/BB + guard'ы
lib/screener.php	Сводная точность
pages/stock.php	Страница тикера (баннер, MA200, плашка)
index.php	Роутер (api-verify → verify_all_lines)
README покрывает:

✅ Установку (с нуля и обновление)

✅ Как работает система (3 линии, верификация, светофор)

✅ MA200-фильтр (полная документация + A/B-результаты)

✅ «Золотые» тикеры (список, как добавлять)

✅ bin/audit_lines.php (все флаги, примеры, чтение метрик)

✅ API (эндпоинты, форматы)

✅ Ежедневное обновление (workflow)

✅ Troubleshooting (5 самых частых проблем)

✅ Известные ограничения (честно про long-only, токсичные тикеры, «не прогноз»)

✅ Roadmap (что в следующих версиях)