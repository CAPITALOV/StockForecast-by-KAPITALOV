# \# DEVELOPMENT — руководство разработчика

# 

# Документ для того, кто продолжит развивать проект (в том числе AI-ассистент).

# Цель — понимать архитектуру без чтения всего кода.

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

# &#x20;  - `admin` / `admin-login` / `admin-settings` → `pages/admin\*.php`

# 3\. API-запросы (`?r=api-\*`) обрабатываются в `index.php` и отдают JSON.

# 4\. Миграции — `bootstrap.php`. Фоновые задачи — `cron/`.

# 5\. Конфиг — `config.php` (копируется из `config.example.php`).

# 

# \### Структура каталогов

/

├── index.php # front controller + роутинг + API

├── bootstrap.php # миграции БД (history\_bars, settings, announcements.image)

├── config.php # конфиг (DB, Telegram, API-ключи) — не в git

├── config.example.php # шаблон конфига

├── pages/ # home, stock, news, screener, accuracy, compare, admin\*

├── lib/

│ ├── api.php # загрузка истории (MOEX ISS, Yahoo, Stooq)

│ ├── verify.php # forecast\_to\_price(), verify\_forecast() (cumprod)

│ ├── screener.php # screener\_data()

│ ├── news.php # get\_financial\_news()

│ ├── settings.php # get\_setting() / set\_setting()

│ └── helpers.php # h(), url(), is\_admin()

├── cron/ # бэкфилл истории, проверка попаданий

├── static/

│ ├── style.css # общие стили + @font-face Inter

│ ├── theme.js # переключатель тем (data-theme)

│ ├── legend.svg # SVG-легенда для README

│ ├── fonts/

│ │ └── InterVariable.woff2 # локальный вариативный Inter (self-hosted)

│ └── uploads/ # картинки к анонсам (.gitignore)

├── data/ # кэши (news\_cache.json и т.п.), права 775

├── VERSION

├── CHANGELOG.md

├── DEVELOPMENT.md

├── PROJECT\_HISTORY.md

├── SECURITY.md

├── favicon.ico + иконки # favicon-16x16, favicon-32x32, apple-touch-icon, android-chrome-\*

└── README.md



\### Стек



\- \*\*Backend:\*\* PHP 7.4+ (без фреймворка), PDO/MySQL 5.7+ / MariaDB 10.3+.

\- \*\*Расширения PHP:\*\* `pdo\_mysql`, `mbstring`, `curl`, `fileinfo`.

\- \*\*Frontend (основной график):\*\* amCharts 5 Stock Chart (`index.js`, `xy.js`,

&#x20; `stock.js`, `plugins/exporting.js`, темы `Animated` + `Dark`) —

&#x20; CDN `cdn.amcharts.com`.

\- \*\*Frontend (нижняя панель и сравнение):\*\* lightweight-charts 4.1.0

&#x20; (CDN `unpkg.com`) — POC, см. §6.

\- \*\*Шрифт:\*\* Inter Variable (локальный `static/fonts/InterVariable.woff2`),

&#x20; подключён через `@font-face` в `style.css` + `preload` в `<head>`.

&#x20; Google Fonts не используется.

\- \*\*Интеграции:\*\* MOEX ISS API, Yahoo Finance (FreeNewsApi.ai + Stooq),

&#x20; Telegram Bot API, RSS (ПРАЙМ, Банки.ру).

\- \*\*Графики:\*\* свечи/линии (amCharts), RSI(14), MACD, Bollinger, SMA/EMA,

&#x20; прогнозные линии; сглаживание `tension: 0.8`; рендер SVG/canvas.

\- \*\*Экспорт:\*\* CSV (server-side), PNG (amCharts `am5plugins\_exporting`)

&#x20; и PNG нижней панели (lightweight-charts).



\---



\## 2. API-эндпоинты



Все API возвращают JSON вида `{ ok: true, ... }` или `{ ok: false, error: "..." }`.



| Роут | Параметры | Назначение |

|---|---|---|

| `api-history` | `market`, `ticker`, `days`, `granularity` | OHLCV / close (`history\_bars`) |

| `api-forecast` | `market`, `ticker`, `granularity` | Прогнозные линии (`lines\[]`) |

| `api-ma` | `market`, `ticker`, `days`, `period`, `type`, `granularity` | SMA / EMA |

| `api-bollinger` | `market`, `ticker`, `days`, `period`, `mult`, `granularity` | Bollinger Bands |

| `api-rsi` | `market`, `ticker`, `days`, `period`, `granularity` | RSI |

| `api-macd` | `market`, `ticker`, `days`, `granularity` | MACD + Signal + Histogram |

| `api-verify` | `market`, `ticker` | Верификация прогнозов (см. §4) |

| `export-verify` | `market`, `ticker` | CSV с верификацией |

| `admin-settings` | POST | Тип графика по умолчанию и др. |



\*\*Формат прогнозной линии:\*\*



```json

{

&#x20; "line\_num": 1,

&#x20; "name": "Прогноз 1",

&#x20; "color": "#ef5350",

&#x20; "value\_type": "price",

&#x20; "computed": false,

&#x20; "data": \[{ "time": "2026-10-01", "value": 123.45 }]

}



value\_type === "price" → левая ось.



value\_type === "delta" → правая ось.



3\. Семантика delta (зафиксирована)

Вариант A — относительная дельта с восстановлением через cumprod

(реализовано с v1.2).



Прогноз хранится как относительное изменение:



delta\_t = p\_t / p\_{t-1} - 1



Восстановление цены из delta — накопленное произведение (cumprod):



p\_t = p\_0 \* ∏\_{i=1}^{t} (1 + delta\_i)



p\_0 — последняя фактическая цена на дату начала прогноза.



Реализация — в lib/verify.php (forecast\_to\_price()). Эта семантика

используется и в верификации, и для отображения delta-линий на правой оси.



4\. Верификация прогнозов (реализована)

Что считается

MAPE — средняя абсолютная процентная ошибка.



Hit-rate — доля точек, где |отклонение| в пределах порога

«сбылся» (≤ 2%).



Статус по каждой точке:



сбылся — |deviation| ≤ 2%



частично — 2% < |deviation| ≤ 5%



не сбылся — |deviation| > 5%



Схема

api-verify берёт все прогнозные линии из api-forecast.



Для каждой точки прогноза ищет фактическую цену на ту же дату

в history\_bars.



Пересчитывает delta → price через forecast\_to\_price()

(cumprod, §3).



Считает deviation\_pct = (forecast - fact) / fact \* 100.



Определяет status по порогам выше.



Возвращает JSON:

{

&#x20; "ok": true,

&#x20; "lines": \[{

&#x20;   "line\_num": 1,

&#x20;   "name": "Прогноз 1",

&#x20;   "color": "#ef5350",

&#x20;   "verify": {

&#x20;     "mape": 3.21,

&#x20;     "hit\_rate": 0.78,

&#x20;     "count": 42,

&#x20;     "rows": \[{

&#x20;       "time": "2026-09-01",

&#x20;       "forecast": 123.4,

&#x20;       "fact": 120.1,

&#x20;       "deviation\_pct": 2.75,

&#x20;       "status": "сбылся"

&#x20;     }]

&#x20;   }

&#x20; }]

}



Файлы

lib/verify.php — forecast\_to\_price(), verify\_forecast().



pages/stock.php — loadVerification() / renderVerification().



pages/accuracy.php — сводная страница.



lib/screener.php — screener\_data() для screener'а.



5\. База данных

history\_bars — ticker\_id, date, open, high, low, close,

volume. Уникальный (ticker\_id, date). Миграция — bootstrap.php (v1.19).



forecasts — ticker\_id, line\_num, date, value, value\_type.

Уникальный (ticker\_id, line\_num, date).



tickers — id, market, ticker, name.



settings — key, value (v1.19; тип графика по умолчанию и др.).



announcements — новости/анонсы, с колонкой image (v1.20).



users — id, login, password\_hash, is\_admin.



Кэши (не в БД): data/news\_cache.json — новости, TTL 15 минут.



6\. Frontend (pages/stock.php)

Стек графиков

Основной график — amCharts 5 Stock Chart

(am5stock.StockChart, StockPanel, CandlestickSeries,

SmoothedXLineSeries, LineSeries).



Сглаживание: tension: 0.8 для линий цены и прогнозов.



Рендер: SVG при days ≤ 365, canvas при больших периодах

(pickRenderer(days)).



Тултипы, легенда, экспорт PNG — встроенные средствами amCharts

(plugins/exporting.js, am5plugins\_exporting.Exporting).



Прогнозные линии: value\_type: delta → правая ось, price → левая.



Тема: am5themes\_Animated + am5themes\_Dark при тёмной теме;

при переключении темы график пересоздаётся.



Шрифт: amRoot.fontFamily = "Inter, ..." — единый с сайтом.



Лицензия: linkware — на графике видна атрибуция amCharts.



Нижняя панель (RSI/MACD) — lightweight-charts 4.1.0 (POC;

синхронизация с основным графиком отсутствует, планируется перенос

на amCharts — Этап 2).



Сравнение моделей — lightweight-charts (POC).



Верификация — HTML-таблица (renderVerification()), без изменений.



Типографика

Шрифт: Inter Variable, self-hosted (static/fonts/InterVariable.woff2),

подключён через @font-face в style.css и preload в <head>.

Никаких запросов к Google Fonts.



Сглаживание: -webkit-font-smoothing: antialiased +

\-moz-osx-font-smoothing: grayscale + text-rendering: optimizeLegibility

на body.



Цифры: font-variant-numeric: tabular-nums на .stock-table,

.chart-tooltip, .legend, .hero-stats .stat b — цены выровнены

по разрядам.



Иконки: inline SVG в stock.php (логотип, тема, панель, скачивание),

классы .icon / .icon-only в style.css. Emoji не используются

для UI-иконок (только декоративные иконки новостей).



Важные нюансы

am5.Root создаётся заново при каждом loadAll() (смена периода,

granularity, типа графика, темы). destroyCharts() вызывает

amRoot.dispose().



applyOptions({ width }) у lightweight-charts нельзя вызывать при

clientWidth === 0 — защита стоит в toggle-lower и window.resize

(v1.20.1).



destroyCharts() перед каждым рендером — иначе верификация пропадает

при смене типа графика (фикс v1.19).



style.css?v=N — cache-bust; при изменении CSS увеличивать N

(актуально v=4 с v1.21.0).



localStorage.sf\_chart\_type — перекрывает серверный chart\_type

из get\_setting().



Даты для amCharts — timestamp в миллисекундах (toTs()),

для lightweight — строка YYYY-MM-DD.



7\. Деплой

cd /var/www/sotis.org/data/www/forecast.sotis.org

git pull

php bootstrap.php          # миграции, если есть новые

chmod 775 data static/uploads

\# cron: см. crontab -l

Требования:



PHP 7.4+, MySQL 5.7+ / MariaDB 10.3+.



Расширения: pdo\_mysql, mbstring, curl, fileinfo.



Права на data/ и static/uploads/ — 775, владелец = PHP-процесс.



Права на static/fonts/ и \*.woff2 — 644 (читается веб-сервером).



SSH-ключ для git@github.com:CAPITALOV/StockForecast-by-KAPITALOV.git.



Исходящие запросы к iss.moex.com, query1.finance.yahoo.com,

stooq.com.



Клиентские CDN: unpkg.com (lightweight-charts), cdn.amcharts.com

(amCharts 5). Шрифт локальный, без CDN.



Ветка main защищена: force-push запрещён. Только fast-forward / rebase + push.



8\. Соглашения

Коммиты: Conventional Commits (feat:, fix:, docs:, chore:,

perf:, style:).



Версии: SemVer (VERSION, CHANGELOG.md, тег vX.Y.Z).



PHP: без фреймворка, PDO. declare(strict\_types=1) — добавить

в новые файлы.



JS: без сборки, ES2020+, глобальные LightweightCharts и am5.



CSS: переменные --bg-card, --text, --border для тем;

тема переключается через \[data-theme="light"] на html (см. theme.js).



Шрифт: Inter Variable (локальный), fallback на -apple-system,

BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial.



9\. Известные задачи

☑ Зафиксировать семантику delta (§3, cumprod).

☑ Реализовать lib/verify.php и роут api-verify (§4).

☑ Миграция history\_bars с OHLC (v1.19).

☑ Миграция announcements.image (v1.20).

☑ Исправить таймауты Yahoo/Stooq в lib/api.php (504).

☑ Основной график на amCharts 5 Stock Chart (v1.21.0, §6).

☑ Экспорт PNG на amCharts через am5plugins\_exporting (v1.21.0).

☑ Self-hosted Inter Variable + preload, без Google Fonts (v1.21.0).

☑ SVG-иконки в UI + tabular-nums для цифр (v1.21.0).

□ Этап 2: перенести RSI/MACD на StockPanel с нативной

синхронизацией зума и курсора.

□ Этап 3: перенести блок «Сравнение моделей» на amCharts.

□ Легенда amCharts показывает (0) — счётчик точек не обновляется

после data.setAll() (косметика).

□ Тултип amCharts показывает много знаков после запятой у некоторых

серий — обрезать до 2 знаков.

□ Экспорт PNG сохраняет только основной график (без нижней панели) —

после Этапа 2 можно будет экспортировать весь дашборд.

□ Добавить <meta name="description"> и Open Graph на главную

(превью при шаринге в Telegram/Twitter).

□ declare(strict\_types=1) во всех PHP-файлах.

□ Сверить pages/accuracy.php с текущим форматом api-verify.

□ Документировать cron/ в §7 (расписание задач).

10\. Полезные ссылки

Репозиторий: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV



Релизы: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV/releases



lightweight-charts: https://tradingview.github.io/lightweight-charts/



amCharts 5 Stock Chart: https://www.amcharts.com/docs/v5/charts/stock/



Inter (официальный): https://rsms.me/inter/



Inter (GitHub): https://github.com/rsms/inter



PROJECT\_HISTORY.md — история вех и критических багов.



SECURITY.md — политика безопасности.



\---







