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
- `signals` — `pages/signals.php` (новое в v2.2.0)
- `admin` / `admin-login` / `admin-settings` — `pages/admin*.php`

API-запросы (`?r=api-*`) обрабатываются в `index.php` и отдают JSON.
Миграции — `bootstrap.php`. Фоновые задачи — `scripts/cron/`. Конфиг — `config.php` (копируется из `config.example.php`).

### Структура каталогов

- `index.php` — front controller, роутинг, API
- `bootstrap.php` — миграции БД
- `config.php` — конфиг (не в git)
- `config.example.php` — шаблон конфига
- `pages/` — home, stock, news, screener, accuracy, compare, signals, admin
- `lib/api.php` — загрузка истории (MOEX ISS, Yahoo, Stooq)
- `lib/verify.php` — `forecast_to_price()`, `verify_forecast()`
- `lib/screener.php` — `screener_data()`
- `lib/synthetic.php` — адаптивная Линия 2 (Z-score, SMA-5, веса)
- `lib/news.php` — `get_financial_news()`
- `lib/settings.php` — `get_setting()` / `set_setting()`
- `lib/helpers.php` — `h()`, `url()`, `is_admin()`
- `scripts/cron/` — `refresh_synthetic.php`, `refresh_screener.php`, `refresh_accuracy.php`
- `scripts/deploy/` — `restore_stockforecast.sh`, `check_health.sh`, `README.md`
- `static/style.css` — общие стили и `@font-face` Inter
- `static/theme.js` — переключатель тем (`data-theme`)
- `static/legend.svg` — SVG-легенда для README
- `static/fonts/InterVariable.woff2` — локальный вариативный Inter
- `static/uploads/` — картинки к анонсам (`.gitignore`)
- `data/` — кэши (`screener_cache.json`, `accuracy_cache.json`), права 775
- `VERSION`, `CHANGELOG.md`, `DEVELOPMENT.md`, `PROJECT_HISTORY.md`, `SECURITY.md`, `README.md`, `AI_CONTEXT.md`

### Стек

Backend: **PHP 8.4+** (рекомендуется 8.4 или 8.5), без фреймворка, PDO/MySQL **8.0+**.
Расширения PHP: `pdo_mysql`, `mbstring`, `curl`, `fileinfo`.

Frontend, основной график: amCharts 5 Stock Chart (`index.js`, `xy.js`, `stock.js`, `plugins/exporting.js`, темы `Animated` + `Dark`) — CDN `cdn.amcharts.com`.

Frontend, нижняя панель и сравнение: amCharts 5 (RSI, MACD встроены, сравнение на `XYChart`).

Шрифт: Inter Variable, локальный (`static/fonts/InterVariable.woff2`), подключён через `@font-face` в `style.css` и `preload` в `<head>`. Google Fonts не используется.

Интеграции: MOEX ISS API, Yahoo Finance (FreeNewsApi.ai + Stooq), Telegram Bot API, RSS (ПРАЙМ, Банки.ру).

Графики: свечи/линии (amCharts), RSI(14), MACD, Bollinger, SMA/EMA, прогнозные линии; сглаживание `tension: 0.8`; рендер SVG/canvas.

Экспорт: CSV (server-side), PNG (amCharts `am5plugins_exporting`).

**Важно (v2.2.0):** Для предотвращения 504 Timeout страницы Скринера и Точности переведены на чтение JSON-кэшей (`data/screener_cache.json`, `data/accuracy_cache.json`). Кэши генерируются скриптами `scripts/cron/refresh_screener.php` и `scripts/cron/refresh_accuracy.php` (запускаются через Cron). Прямые тяжелые SQL-запросы на этих страницах больше не выполняются.

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

## 3. Семантика delta

Вариант A — относительная дельта с восстановлением через cumprod (реализовано с v1.2).

Прогноз хранится как относительное изменение: `delta_t = p_t / p_{t-1} - 1` (в процентах).

Восстановление цены из delta — накопленное произведение: `p_t = p_0 * ∏(1 + delta_i / 100)` для `i` от 1 до `t`.

`p_0` — последняя фактическая цена на дату начала прогноза.

Реализация — `lib/verify.php`, функция `forecast_to_price()`.

## 4. Верификация прогнозов (v2.2.0)

### Логика проверки (Правило 50%)

Функция `verify_forecast($market, $ticker, $points, $value_type)` анализирует окно от -90 дней до +90 дней от последней даты истории.

Для прошедших дат (где есть факт):

1. Считается изменение факта (`actual_delta`) и прогноза (`forecast_delta`).
2. Проверяется направление: оба > 0 или оба < 0.
3. Проверяется амплитуда: `abs(actual_delta) >= abs(forecast_delta) * 0.5`.
4. Если оба условия выполнены — статус `сбылся (успех)`. Если только первое — `частично (слабый)`. Иначе — `не сбылся (убыток)`.

### Метрики

- Hit-rate — доля успешных векторов.
- Pearson — корреляция Пирсона между прогнозной ценой и фактом.

### Формат ответа `api-verify`

Каждая линия содержит `verify: { rows: [...], hit_rate, pearson, count }`.
Внутри `rows` передаются: `time`, `delta` (сырое значение из базы), `forecast`, `fact`, `deviation_pct`, `status`.

## 5. База данных

- `history_bars` — `stock_id`, `ts`, `open`, `high`, `low`, `close`, `volume`. Уникальный `(stock_id, ts)`.
- `forecast_lines` — `market`, `ticker`, `line_num`, `date`, `value`, `value_type`. Уникальный `(market, ticker, line_num)`.
- `synthetic_components` — `market`, `ticker`, `date`, `z1`, `z3`, `w1`, `w3`, `synthetic`, `dir1`, `dir3`, `trade_action`, `confidence`. Уникальный `(market, ticker, date)`.
- `stock_map` — `id`, `market`, `ticker`, `name`.
- `settings` — `key`, `value`.
- `announcements` — новости/анонсы, с колонкой `image`.
- `admins` — `id`, `login`, `password_hash`, `is_admin`.

Кэши вне БД: `data/screener_cache.json`, `data/accuracy_cache.json`, `data/news_cache.json` (TTL 15 минут).

## 6. Frontend (pages/stock.php)

### Основной график

- `am5stock.StockChart` с одной панелью.
- Сглаживание `tension: 0.8`.
- Рендер: SVG при `days ≤ 365`, canvas при больших периодах.

### Нижняя панель

Используются встроенные индикаторы `am5stock.RelativeStrengthIndex` и `am5stock.MACD`. Они автоматически синхронизируются с основным графиком по зуму и курсору. Кнопка «Панель» скрывает/показывает панель через `visible`.

### Блок «Сравнение моделей» (Двухосевой)

Используется `am5xy.XYChart`.

- Левая ось: `valueAxisDelta` (сырые значения дельт).
- Правая ось: `valueAxisPrice` (реальная цена).

Фактическая цена (зеленая линия) привязана к правой оси. Прогнозные линии (красная, оранжевая) — к левой.

### Визуальные маркеры (bullets)

Для каждой точки прогноза на двухосевом графике добавляется маркер:

- Перед отрисовкой данные прогноза обогащаются полем `status` из `api-verify` (с использованием `normalizeDate` без учета часовых поясов).
- В `series.bullets` читается `dataItem.dataContext.status`.
- Цвета: `сбылся` — зеленый, `не сбылся` — красный, `частично` — оранжевый, `ожидается` — невидимая точка (`radius: 0`).

### Типографика

- Шрифт: Inter Variable, self-hosted (`static/fonts/InterVariable.woff2`).
- Сглаживание: `-webkit-font-smoothing: antialiased`.
- Цифры: `font-variant-numeric: tabular-nums` для ровных столбцов.
- Иконки: inline SVG.

## 7. Деплой

Команды:

    cd /var/www/sotis.org/data/www/forecast.sotis.org
    git pull
    php bootstrap.php
    chmod 775 data static/uploads

Требования:

- PHP **8.4+**, MySQL **8.0+** / MariaDB 10.3+
- Расширения: `pdo_mysql`, `mbstring`, `curl`, `fileinfo`
- Права на `data/` и `static/uploads/` — 775, владелец = PHP-процесс
- Права на `static/fonts/` и `*.woff2` — 644
- SSH-ключ для `git@github.com:CAPITALOV/StockForecast-by-KAPITALOV.git`
- Клиентские CDN: `unpkg.com`, `cdn.amcharts.com`

Ветка `main` защищена: force-push запрещён.

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
- Основной график на amCharts 5 Stock Chart (v1.21.0)
- Экспорт PNG на amCharts через `am5plugins_exporting` (v1.21.0)
- Self-hosted Inter Variable + preload (v1.21.0)
- SVG-иконки в UI + tabular-nums (v1.21.0)
- Этап 2: перенос RSI/MACD на `StockPanel` с нативной синхронизацией (v1.23.0)
- Этап 3: двухосевой график сравнения Delta + Price (v1.23.0)
- Логика верификации «Правило 50%» + Корреляция Пирсона (v1.23.0)
- Визуальные маркеры статусов на графике сравнения + колонка Дельта (v1.24.0)
- Адаптивная Линия 2 (Synthetic) — Z-score, SMA-5, веса (v2.1.0)
- Торговые сигналы BUY/SELL на графике (v2.1.0)
- JSON-кэширование для Скринера и Точности (v2.2.0)
- Страница «История сигналов» (v2.2.0)
- Индикатор «Ширина рынка» (v2.2.0)
- Избранное (Watchlist) (v2.2.0)
- Универсальные скрипты `restore_stockforecast.sh` и `check_health.sh` (v2.2.0)

Осталось:

- Легенда amCharts показывает `(0)` — счётчик точек не обновляется после `data.setAll()` (косметика)
- Тултип amCharts показывает много знаков после запятой у некоторых серий — обрезать до 2 знаков
- Экспорт PNG сохраняет только основной график (без нижней панели)
- Добавить `<meta name="description">` и Open Graph на главную
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
