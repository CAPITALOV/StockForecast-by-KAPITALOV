# DEVELOPMENT — руководство разработчика

Документ для того, кто продолжит развивать проект (в том числе AI-ассистент).
Цель — понимать архитектуру без чтения всего кода.

## 1. Архитектура

### Поток запроса

Все запросы идут через `index.php` (front controller).

Роутинг по параметру `?r=...`:

- `home` (по умолчанию) — `pages/home.php`
- `stock` — `pages/stock.php`
- `news` — `pages/news.php`
- `screener` — `pages/screener.php`
- `accuracy` — `pages/accuracy.php`
- `compare` — `pages/compare.php`
- `admin` / `admin-login` / `admin-settings` — `pages/admin*.php`

API-запросы (`?r=api-*`) обрабатываются в `index.php` и отдают JSON.
Миграции — `bootstrap.php`. Фоновые задачи — `cron/`.
Конфиг — `config.php` (копируется из `config.example.php`).

### Структура каталогов

- `index.php` — front controller, роутинг, API
- `bootstrap.php` — миграции БД
- `config.php` — конфиг (не в git)
- `config.example.php` — шаблон конфига
- `pages/` — home, stock, news, screener, accuracy, compare, admin
- `lib/api.php` — загрузка истории (MOEX ISS, Yahoo, Stooq)
- `lib/verify.php` — `forecast_to_price()`, `verify_forecast()` (cumprod)
- `lib/screener.php` — `screener_data()`
- `lib/news.php` — `get_financial_news()`
- `lib/settings.php` — `get_setting()` / `set_setting()`
- `lib/helpers.php` — `h()`, `url()`, `is_admin()`
- `cron/` — бэкфилл истории, проверка попаданий
- `static/style.css` — общие стили и `@font-face` Inter
- `static/theme.js` — переключатель тем (`data-theme`)
- `static/legend.svg` — SVG-легенда для README
- `static/fonts/InterVariable.woff2` — локальный вариативный Inter
- `static/uploads/` — картинки к анонсам (`.gitignore`)
- `data/` — кэши, права 775
- `VERSION`, `CHANGELOG.md`, `DEVELOPMENT.md`, `PROJECT_HISTORY.md`, `SECURITY.md`, `README.md`
- `favicon.ico`, `favicon-16x16.png`, `favicon-32x32.png`, `apple-touch-icon.png`, `android-chrome-*.png`

### Стек

Backend: PHP 7.4+ (без фреймворка), PDO/MySQL 5.7+ / MariaDB 10.3+.
Расширения PHP: `pdo_mysql`, `mbstring`, `curl`, `fileinfo`.

Frontend, основной график: amCharts 5 Stock Chart (`index.js`, `xy.js`, `stock.js`, `plugins/exporting.js`, темы `Animated` + `Dark`) — CDN `cdn.amcharts.com`.

Frontend, нижняя панель и сравнение: lightweight-charts 4.1.0 (CDN `unpkg.com`) — POC, см. §6.

Шрифт: Inter Variable, локальный (`static/fonts/InterVariable.woff2`), подключён через `@font-face` в `style.css` и `preload` в `<head>`. Google Fonts не используется.

Интеграции: MOEX ISS API, Yahoo Finance (FreeNewsApi.ai + Stooq), Telegram Bot API, RSS (ПРАЙМ, Банки.ру).

Графики: свечи/линии (amCharts), RSI(14), MACD, Bollinger, SMA/EMA, прогнозные линии; сглаживание `tension: 0.8`; рендер SVG/canvas.

Экспорт: CSV (server-side), PNG (amCharts `am5plugins_exporting`) и PNG нижней панели (lightweight-charts).

## 2. API-эндпоинты

Все API возвращают JSON: `{ ok: true, ... }` или `{ ok: false, error: "..." }`.

Список роутов:

- `api-history` — параметры `market`, `ticker`, `days`, `granularity` — OHLCV / close из `history_bars`
- `api-forecast` — параметры `market`, `ticker`, `granularity` — прогнозные линии (`lines[]`)
- `api-ma` — параметры `market`, `ticker`, `days`, `period`, `type`, `granularity` — SMA / EMA
- `api-bollinger` — параметры `market`, `ticker`, `days`, `period`, `mult`, `granularity` — Bollinger Bands
- `api-rsi` — параметры `market`, `ticker`, `days`, `period`, `granularity` — RSI
- `api-macd` — параметры `market`, `ticker`, `days`, `granularity` — MACD + Signal + Histogram
- `api-verify` — параметры `market`, `ticker` — верификация прогнозов (см. §4)
- `export-verify` — параметры `market`, `ticker` — CSV с верификацией
- `admin-settings` — POST — тип графика по умолчанию и др.

Формат прогнозной линии в JSON: поля `line_num` (число), `name` (строка), `color` (hex), `value_type` (`price` или `delta`), `computed` (boolean), `data` (массив точек `{ time, value }`).

- `value_type: "price"` — левая ось
- `value_type: "delta"` — правая ось

## 3. Семантика delta (зафиксирована)

Вариант A — относительная дельта с восстановлением через cumprod (реализовано с v1.2).

Прогноз хранится как относительное изменение: `delta_t = p_t / p_{t-1} - 1`.

Восстановление цены из delta — накопленное произведение: `p_t = p_0 * ∏(1 + delta_i)` для `i` от 1 до `t`.

`p_0` — последняя фактическая цена на дату начала прогноза.

Реализация — `lib/verify.php`, функция `forecast_to_price()`. Эта семантика используется и в верификации, и для отображения delta-линий на правой оси.

## 4. Верификация прогнозов (реализована)

### Что считается

- MAPE — средняя абсолютная процентная ошибка
- Hit-rate — доля точек, где |отклонение| в пределах порога «сбылся» (≤ 2%)
- Статус по каждой точке: «сбылся» при |deviation| ≤ 2%, «частично» при 2% < |deviation| ≤ 5%, «не сбылся» при |deviation| > 5%

### Схема

1. `api-verify` берёт все прогнозные линии из `api-forecast`
2. Для каждой точки прогноза ищет фактическую цену на ту же дату в `history_bars`
3. Пересчитывает `delta` в `price` через `forecast_to_price()` (cumprod, §3)
4. Считает `deviation_pct = (forecast - fact) / fact * 100`
5. Определяет `status` по порогам выше
6. Возвращает JSON: `{ ok: true, lines: [{ line_num, name, color, verify: { mape, hit_rate, count, rows: [{ time, forecast, fact, deviation_pct, status }] } }] }`

### Файлы

- `lib/verify.php` — `forecast_to_price()`, `verify_forecast()`
- `pages/stock.php` — `loadVerification()` / `renderVerification()`
- `pages/accuracy.php` — сводная страница
- `lib/screener.php` — `screener_data()` для screener'а

## 5. База данных

- `history_bars` — `ticker_id`, `date`, `open`, `high`, `low`, `close`, `volume`. Уникальный `(ticker_id, date)`. Миграция — `bootstrap.php` (v1.19)
- `forecasts` — `ticker_id`, `line_num`, `date`, `value`, `value_type`. Уникальный `(ticker_id, line_num, date)`
- `tickers` — `id`, `market`, `ticker`, `name`
- `settings` — `key`, `value` (v1.19; тип графика по умолчанию и др.)
- `announcements` — новости/анонсы, с колонкой `image` (v1.20)
- `users` — `id`, `login`, `password_hash`, `is_admin`

Кэши вне БД: `data/news_cache.json` — новости, TTL 15 минут.

## 6. Frontend (pages/stock.php)

### Стек графиков

Основной график — amCharts 5 Stock Chart (`am5stock.StockChart`, `StockPanel`, `CandlestickSeries`, `SmoothedXLineSeries`, `LineSeries`).

- Сглаживание: `tension: 0.8` для линий цены и прогнозов
- Рендер: SVG при `days ≤ 365`, canvas при больших периодах (`pickRenderer(days)`)
- Тултипы, легенда, экспорт PNG — встроенные средствами amCharts (`plugins/exporting.js`, `am5plugins_exporting.Exporting`)
- Прогнозные линии: `value_type: delta` — правая ось, `price` — левая
- Тема: `am5themes_Animated` + `am5themes_Dark` при тёмной теме; при переключении темы график пересоздаётся
- Шрифт: `amRoot.fontFamily = "Inter, ..."` — единый с сайтом
- Лицензия: linkware — на графике видна атрибуция amCharts

Нижняя панель (RSI/MACD) — lightweight-charts 4.1.0 (POC; синхронизация с основным графиком отсутствует, планируется перенос на amCharts — Этап 2).

Сравнение моделей — lightweight-charts (POC).

Верификация — HTML-таблица (`renderVerification()`), без изменений.

### Типографика

- Шрифт: Inter Variable, self-hosted (`static/fonts/InterVariable.woff2`), подключён через `@font-face` в `style.css` и `preload` в `<head>`. Никаких запросов к Google Fonts
- Сглаживание: `-webkit-font-smoothing: antialiased`, `-moz-osx-font-smoothing: grayscale`, `text-rendering: optimizeLegibility` на `body`
- Цифры: `font-variant-numeric: tabular-nums` на `.stock-table`, `.chart-tooltip`, `.legend`, `.hero-stats .stat b` — цены выровнены по разрядам
- Иконки: inline SVG в `stock.php` (логотип, тема, панель, скачивание), классы `.icon` и `.icon-only` в `style.css`. Emoji не используются для UI-иконок (только декоративные иконки новостей)

### Важные нюансы

- `am5.Root` создаётся заново при каждом `loadAll()` (смена периода, `granularity`, типа графика, темы). `destroyCharts()` вызывает `amRoot.dispose()`
- `applyOptions({ width })` у lightweight-charts нельзя вызывать при `clientWidth === 0` — защита стоит в `toggle-lower` и `window.resize` (v1.20.1)
- `destroyCharts()` перед каждым рендером — иначе верификация пропадает при смене типа графика (фикс v1.19)
- `style.css?v=N` — cache-bust; при изменении CSS увеличивать N (актуально `v=4` с v1.21.0)
- `localStorage.sf_chart_type` — перекрывает серверный `chart_type` из `get_setting()`
- Даты для amCharts — timestamp в миллисекундах (`toTs()`), для lightweight — строка `YYYY-MM-DD`

## 7. Деплой

Команды:

    cd /var/www/sotis.org/data/www/forecast.sotis.org
    git pull
    php bootstrap.php
    chmod 775 data static/uploads

Требования:

- PHP 7.4+, MySQL 5.7+ / MariaDB 10.3+
- Расширения: `pdo_mysql`, `mbstring`, `curl`, `fileinfo`
- Права на `data/` и `static/uploads/` — 775, владелец = PHP-процесс
- Права на `static/fonts/` и `*.woff2` — 644 (читается веб-сервером)
- SSH-ключ для `git@github.com:CAPITALOV/StockForecast-by-KAPITALOV.git`
- Исходящие запросы к `iss.moex.com`, `query1.finance.yahoo.com`, `stooq.com`
- Клиентские CDN: `unpkg.com` (lightweight-charts), `cdn.amcharts.com` (amCharts 5). Шрифт локальный, без CDN

Ветка `main` защищена: force-push запрещён. Только fast-forward / rebase + push.

## 8. Соглашения

- Коммиты: Conventional Commits (`feat:`, `fix:`, `docs:`, `chore:`, `perf:`, `style:`)
- Версии: SemVer (`VERSION`, `CHANGELOG.md`, тег `vX.Y.Z`)
- PHP: без фреймворка, PDO. `declare(strict_types=1)` — добавить в новые файлы
- JS: без сборки, ES2020+, глобальные `LightweightCharts` и `am5`
- CSS: переменные `--bg-card`, `--text`, `--border` для тем; тема переключается через `[data-theme="light"]` на `html` (см. `theme.js`)
- Шрифт: Inter Variable (локальный), fallback на `-apple-system`, `BlinkMacSystemFont`, `Segoe UI`, `Roboto`, `Helvetica`, `Arial`

## 9. Известные задачи

Сделано:

- Зафиксировать семантику delta (§3, cumprod)
- Реализовать `lib/verify.php` и роут `api-verify` (§4)
- Миграция `history_bars` с OHLC (v1.19)
- Миграция `announcements.image` (v1.20)
- Исправить таймауты Yahoo/Stooq в `lib/api.php` (504)
- Основной график на amCharts 5 Stock Chart (v1.21.0, §6)
- Экспорт PNG на amCharts через `am5plugins_exporting` (v1.21.0)
- Self-hosted Inter Variable + preload, без Google Fonts (v1.21.0)
- SVG-иконки в UI + tabular-nums для цифр (v1.21.0)

Осталось:

- Этап 2: перенести RSI/MACD на `StockPanel` с нативной синхронизацией зума и курсора
- Этап 3: перенести блок «Сравнение моделей» на amCharts
- Легенда amCharts показывает `(0)` — счётчик точек не обновляется после `data.setAll()` (косметика)
- Тултип amCharts показывает много знаков после запятой у некоторых серий — обрезать до 2 знаков
- Экспорт PNG сохраняет только основной график (без нижней панели) — после Этапа 2 можно будет экспортировать весь дашборд
- Добавить `<meta name="description">` и Open Graph на главную (превью при шаринге в Telegram/Twitter)
- `declare(strict_types=1)` во всех PHP-файлах
- Сверить `pages/accuracy.php` с текущим форматом `api-verify`
- Документировать `cron/` в §7 (расписание задач)

## 10. Полезные ссылки

- Репозиторий: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV
- Релизы: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV/releases
- lightweight-charts: https://tradingview.github.io/lightweight-charts/
- amCharts 5 Stock Chart: https://www.amcharts.com/docs/v5/charts/stock/
- Inter (официальный): https://rsms.me/inter/
- Inter (GitHub): https://github.com/rsms/inter
- PROJECT_HISTORY.md — история вех и критических багов
- SECURITY.md — политика безопасности