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
- `lib/verify.php` — `forecast_to_price()`, `verify_forecast()`
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

Frontend, нижняя панель и сравнение: amCharts 5 (RSI, MACD встроены, сравнение на `XYChart`).

Шрифт: Inter Variable, локальный (`static/fonts/InterVariable.woff2`), подключён через `@font-face` в `style.css` и `preload` в `<head>`. Google Fonts не используется.

Интеграции: MOEX ISS API, Yahoo Finance (FreeNewsApi.ai + Stooq), Telegram Bot API, RSS (ПРАЙМ, Банки.ру).

Графики: свечи/линии (amCharts), RSI(14), MACD, Bollinger, SMA/EMA, прогнозные линии; сглаживание `tension: 0.8`; рендер SVG/canvas.

Экспорт: CSV (server-side), PNG (amCharts `am5plugins_exporting`).

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

## 4. Верификация прогнозов (v1.24.0)

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

- `history_bars` — `ticker_id`, `date`, `open`, `high`, `low`, `close`, `volume`. Уникальный `(ticker_id, date)`.
- `forecasts` — `ticker_id`, `line_num`, `date`, `value`, `value_type`. Уникальный `(ticker_id, line_num, date)`.
- `tickers` — `id`, `market`, `ticker`, `name`.
- `settings` — `key`, `value`.
- `announcements` — новости/анонсы, с колонкой `image`.
- `users` — `id`, `login`, `password_hash`, `is_admin`.

Кэши вне БД: `data/news_cache.json` — новости, TTL 15 минут.

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

- PHP 7.4+, MySQL 5.7+ / MariaDB 10.3+
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

## Архитектура Линии 2 (Synthetic Adaptive)

С версии v2.1.0 Линия 2 больше не является простым средним Линий 1 и 3. 
Теперь это **адаптивный индекс уверенности**, который рассчитывается на бэкенде (PHP) и сохраняется в БД.

### Математика (`lib/synthetic.php`):
1. **Сглаживание (SMA-5)**: Убирает ежедневный рыночный шум.
2. **Z-score (окно 60 дней)**: Нормализует значения Линий 1 и 3 к единому масштабу.
3. **Адаптивные веса**: Система оценивает историческую точность (Hit-rate) каждой линии за последние 60 дней и дает больший вес той линии, которая сейчас точнее.
4. **Генерация сигнала**: Если Z-score ниже -0.5, а тренд дельты (за 5 дней) пошел вверх — генерируется сигнал `buy`. Если Z-score выше 0.5, а тренд вниз — `sell`.

### Интеграция:
- **`synthetic_components`**: Таблица в MySQL, хранит `z1`, `z3`, `w1`, `w3`, итоговый `synthetic`, `trade_action` и `confidence`.
- **`api-forecast`**: Отдает Линию 2 вместе с массивом сигналов на фронтенд.
- **`pages/stock.php`**: Использует `am5.Bullet` для отрисовки точек BUY/SELL поверх оранжевой линии.

### Как обновить данные (Cron):
Расчеты выполняются скриптом `scripts/cron/refresh_synthetic.php`. 
На сервере должна быть настроена задача Cron:
`0 3 * * * php /path/to/project/scripts/cron/refresh_synthetic.php >> /var/log/synthetic_refresh.log 2>&1`

Скопируйте этот текст в конец README.md:
markdown
## 🚀 Развертывание и обновление (v2.1.0+)

Если вы обновляете проект или разворачиваете его с нуля, выполните следующие шаги, чтобы активировать адаптивную Линию 2 и торговые сигналы.

### Шаг 1. Обновление файлов
Скачайте актуальные изменения из репозитория:
```bash
git pull origin master
Убедитесь, что на месте новые файлы:

lib/synthetic.php

scripts/cron/refresh_synthetic.php

обновленные index.php и pages/stock.php

Шаг 2. Миграция базы данных
Если таблицы synthetic_components еще нет, выполните следующий SQL-запрос в вашей базе данных (например, через phpMyAdmin):

sql
CREATE TABLE IF NOT EXISTS `synthetic_components` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `market` VARCHAR(16) NOT NULL,
  `ticker` VARCHAR(32) NOT NULL,
  `date` DATE NOT NULL,
  `z1` DOUBLE NULL,
  `z3` DOUBLE NULL,
  `w1` DOUBLE NULL,
  `w3` DOUBLE NULL,
  `synthetic` DOUBLE NULL,
  `dir1` TINYINT NULL,
  `dir3` TINYINT NULL,
  `trade_action` VARCHAR(16) NULL,
  `confidence` DOUBLE NULL,
  `computed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_ticker_date` (`market`, `ticker`, `date`),
  KEY `idx_action` (`trade_action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
Шаг 3. Первичный расчет
Запустите скрипт, чтобы заполнить базу историей (займет около 30 секунд):

bash
php scripts/cron/refresh_synthetic.php
Вы должны увидеть в терминале строки [OK] moex/LKOH — 1826 точек записано. и т.д.

Шаг 4. Настройка Cron (Автообновление)
Чтобы сигналы обновлялись каждый день, добавьте задачу в Cron:

bash
crontab -e
Добавьте строку:

text
0 3 * * * /usr/bin/php /var/www/sotis.org/data/www/forecast.sotis.org/scripts/cron/refresh_synthetic.php >> /var/log/synthetic_refresh.log 2>&1
Шаг 5. Очистка кэша
Если на сайте не видно изменений, очистите кэш в админке (admin-clear-cache) или жестко обновите страницу (Ctrl + F5).

text

---
