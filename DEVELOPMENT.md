# \# DEVELOPMENT — руководство разработчика

# 

# Документ для того, кто продолжит развивать проект (в том числе AI-ассистент).

# Цель — понимать архитектуру без чтения всего кода.

# 

# Легенда: \*\*⚠️\*\* — требует сверки с фактическим кодом/БД.

# 

# \---

# 

# \## 1. Архитектура

# 

# \### Поток запроса

# 

# 1\. Все запросы идут через `index.php` (front controller).

# 2\. Роутинг по параметру `?r=...`:

# &#x20;  - `home` (по умолчанию) → `pages/home.php`

# &#x20;  - `stock` → `pages/stock.php`

# &#x20;  - `news` → `pages/news.php`

# &#x20;  - `screener` → `pages/screener.php`

# &#x20;  - `accuracy` → `pages/accuracy.php`

# &#x20;  - `compare` → `pages/compare.php`

# &#x20;  - `admin` / `admin-login` → `pages/admin\\\*.php`

# 3\. API-запросы (`?r=api-\\\*`) обрабатываются в `index.php` и отдают JSON.

# 4\. Общие функции: `lib/` (хелперы, БД, настройки, новости, верификация).

# 

# \### Структура каталогов

/

├── index.php # front controller + роутинг + API

├── pages/ # страницы (home, stock, news, screener, accuracy, compare, admin)

├── lib/ # библиотеки

│ ├── db.php # подключение к БД ⚠️ уточнить имя

│ ├── settings.php # get\_setting() / set\_setting() ⚠️ уточнить имя

│ ├── news.php # get\_financial\_news()

│ ├── verify.php # forecast\_to\_price(), verify\_forecast() ⚠️ создать

│ └── helpers.php # h(), url(), is\_admin() ⚠️ уточнить имя

├── static/

│ ├── style.css

│ └── theme.js

├── data/ # кэш / сырые данные (если есть) ⚠️

├── VERSION

├── CHANGELOG.md

└── DEVELOPMENT.md



\### Стек



\- \*\*Backend:\*\* PHP 8.x (без фреймворка), PDO/MySQL ⚠️ уточнить.

\- \*\*Frontend:\*\* чистый JS + \[lightweight-charts 4.1.0](https://github.com/tradingview/lightweight-charts) (CDN: unpkg).

\- \*\*Графики:\*\* line / candlestick, RSI, MACD, Bollinger, MA (SMA/EMA), прогнозные линии.

\- \*\*Экспорт:\*\* CSV (server-side), PNG (client-side через `takeScreenshot()`).



\---



\## 2. API-эндпоинты



Все API возвращают JSON вида `{ ok: true, ... }` или `{ ok: false, error: "..." }`.



| Роут | Параметры | Назначение |

|---|---|---|

| `api-history` | `market`, `ticker`, `days`, `granularity` | OHLCV / close для основного графика |

| `api-forecast` | `market`, `ticker`, `granularity` | Прогнозные линии (`lines\\\[]`) |

| `api-ma` | `market`, `ticker`, `days`, `period`, `type`, `granularity` | SMA / EMA |

| `api-bollinger` | `market`, `ticker`, `days`, `period`, `mult`, `granularity` | Bollinger Bands |

| `api-rsi` | `market`, `ticker`, `days`, `period`, `granularity` | RSI |

| `api-macd` | `market`, `ticker`, `days`, `granularity` | MACD + Signal + Histogram |

| `api-verify` | `market`, `ticker` | Верификация прогнозов (см. §4) |

| `export-verify` | `market`, `ticker` | CSV с верификацией |



\*\*Формат прогнозной линии:\*\*



```json

{

\&#x20; "line\\\_num": 1,

\&#x20; "name": "Прогноз 1",

\&#x20; "color": "#ef5350",

\&#x20; "value\\\_type": "price",

\&#x20; "computed": false,

\&#x20; "data": \\\[{ "time": "2026-10-01", "value": 123.45 }]

}



value\\\_type === "price" → левая ось.



value\\\_type === "delta" → правая ось.



3\\. Семантика delta ⚠️ зафиксировать

Сейчас в stock.php для delta используется правая ось, но формула не зафиксирована. Нужно выбрать и записать:



Вариант A (относительная): delta\\\_t = p\\\_t / p\\\_{t-1} - 1 (в % или долях).



Вариант B (абсолютная): delta\\\_t = p\\\_t - p\\\_{t-1} (в валюте).



От этого зависит forecast\\\_to\\\_price() в lib/verify.php:



// Вариант A

$price = $base \\\* (1 + $delta);

// Вариант B

$price = $base + $delta;

Решение: ⚠️ записать выбранный вариант здесь и продублировать в CHANGELOG.md.



4\\. Верификация прогнозов

Цель

Сравнить прогнозные значения с фактическими ценами за прошедшие даты и посчитать:



MAPE — средняя абсолютная процентная ошибка.



Hit-rate — доля точек, где отклонение в пределах порога.



Статус по каждой точке: сбылся / не сбылся / —.



Схема

api-verify берёт все прогнозные линии из api-forecast.



Для каждой точки прогноза ищет фактическую цену на ту же дату в истории.



Пересчитывает delta → price (если нужно) через forecast\\\_to\\\_price().



Считает deviation\\\_pct = (forecast - fact) / fact \\\* 100.



Определяет status по порогу ⚠️ (например, |deviation| ≤ 5%).



Возвращает:

{

\&#x20; "ok": true,

\&#x20; "lines": \\\[{

\&#x20;   "line\\\_num": 1,

\&#x20;   "name": "Прогноз 1",

\&#x20;   "color": "#ef5350",

\&#x20;   "verify": {

\&#x20;     "mape": 3.21,

\&#x20;     "hit\\\_rate": 0.78,

\&#x20;     "count": 42,

\&#x20;     "rows": \\\[{

\&#x20;       "time": "2026-09-01",

\&#x20;       "forecast": 123.4,

\&#x20;       "fact": 120.1,

\&#x20;       "deviation\\\_pct": 2.75,

\&#x20;       "status": "сбылся"

\&#x20;     }]

\&#x20;   }

\&#x20; }]

}

Файлы

lib/verify.php — forecast\\\_to\\\_price(), verify\\\_forecast() ⚠️ создать.



pages/stock.php — уже ожидает этот формат (loadVerification() / renderVerification()).



pages/accuracy.php — сводная страница по всем тикерам ⚠️ проверить.



5\\. База данных ⚠️ уточнить

Предположительная схема (сверить с lib/db.php):



tickers — id, market, ticker, name.



prices — ticker\\\_id, date, open, high, low, close, volume.



forecasts — ticker\\\_id, line\\\_num, date, value, value\\\_type.



settings — key, value.



users — id, login, password\\\_hash, is\\\_admin.



Индексы:



prices(ticker\\\_id, date) — уникальный.



forecasts(ticker\\\_id, line\\\_num, date) — уникальный.



6\\. Frontend (pages/stock.php)

Основные компоненты

LightweightCharts.createChart() — основной график (480px) + нижняя панель (160px).



Синхронизация timeScale между графиками через subscribeVisibleLogicalRangeChange.



Тултип на subscribeCrosshairMove — показывает факт, прогноз, отклонение, статус.



Переключатели: тип графика, период, детализация, MA, Bollinger, нижняя панель (RSI/MACD).



hide-lower на <body> скрывает #rsi-chart, #rsi-legend, .rsi-head.



Важные нюансы

applyOptions({ width }) нельзя вызывать при clientWidth === 0 — иначе падение. Защита уже стоит в toggle-lower и window.resize.



style.css?v=N — cache-bust; при изменении CSS увеличивать N.



localStorage.sf\\\_chart\\\_type — перекрывает серверный chart\\\_type из get\\\_setting().



7\\. Деплой

cd /var/www/sotis.org/data/www/forecast.sotis.org

git pull

\\# миграции БД, если есть

\\# очистка кэша (если есть)

Требования:



PHP 8.x, MySQL/MariaDB ⚠️ уточнить версии.



Права на data/ (если используется).



SSH-ключ для git@github.com:CAPITALOV/StockForecast-by-KAPITALOV.git.



Ветка main защищена: force-push запрещён. Только fast-forward / rebase + push.



8\\. Соглашения

Коммиты: Conventional Commits (feat:, fix:, docs:, chore:).



Версии: SemVer (VERSION, CHANGELOG.md, тег vX.Y.Z).



PHP: declare(strict\\\_types=1); ⚠️ если ещё нет — добавить.



JS: без сборки, ES2020+, глобальный LightweightCharts.



CSS: переменные --bg-card, --text, --border для тем.



9\\. Известные задачи

□ Зафиксировать семантику delta (§3).

□ Создать lib/verify.php и роут api-verify (§4).

□ Сверить схему БД (§5).

□ Добавить declare(strict\\\_types=1) во все PHP-файлы.

□ Страница accuracy — сводка MAPE / hit-rate по всем тикерам.

10\\. Полезные ссылки

Репозиторий: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV



Релизы: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV/releases



lightweight-charts: https://tradingview.github.io/lightweight-charts/












