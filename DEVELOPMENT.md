# DEVELOPMENT — руководство разработчика

Документ для того, кто продолжит развивать проект (в том числе AI-ассистент).
Цель — понимать архитектуру без чтения всего кода.

---

## 1. Архитектура

### Поток запроса

1. Все запросы идут через `index.php` (front controller).
2. Роутинг по параметру `?r=...`:
   - `home` (по умолчанию) → `pages/home.php`
   - `stock` → `pages/stock.php`
   - `news` → `pages/news.php`
   - `screener` → `pages/screener.php`
   - `accuracy` → `pages/accuracy.php`
   - `compare` → `pages/compare.php`
   - `admin` / `admin-login` / `admin-settings` → `pages/admin*.php`
3. API-запросы (`?r=api-*`) обрабатываются в `index.php` и отдают JSON.
4. Миграции — `bootstrap.php`. Фоновые задачи — `cron/`.
5. Конфиг — `config.php` (копируется из `config.example.php`).

### Структура каталогов

```
/
├── index.php              # front controller + роутинг + API
├── bootstrap.php          # миграции БД (history_bars, settings, announcements.image)
├── config.php             # конфиг (DB, Telegram, API-ключи) — не в git
├── config.example.php     # шаблон конфига
├── pages/                 # home, stock, news, screener, accuracy, compare, admin*
├── lib/
│   ├── api.php            # загрузка истории (MOEX ISS, Yahoo, Stooq)
│   ├── verify.php         # forecast_to_price(), verify_forecast() (cumprod)
│   ├── screener.php       # screener_data()
│   ├── news.php           # get_financial_news()
│   ├── settings.php       # get_setting() / set_setting()
│   └── helpers.php        # h(), url(), is_admin()
├── cron/                  # бэкфилл истории, проверка попаданий
├── static/
│   ├── style.css
│   ├── theme.js
│   ├── legend.svg
│   └── uploads/           # картинки к анонсам (.gitignore)
├── data/                  # кэши (news_cache.json и т.п.), права 775
├── VERSION
├── CHANGELOG.md
├── DEVELOPMENT.md
├── PROJECT_HISTORY.md
├── SECURITY.md
└── README.md
```

### Стек

- **Backend:** PHP 7.4+ (без фреймворка), PDO/MySQL 5.7+ / MariaDB 10.3+.
- **Расширения PHP:** `pdo_mysql`, `mbstring`, `curl`, `fileinfo`.
- **Frontend:** чистый JS + [lightweight-charts 4.1.0](https://github.com/tradingview/lightweight-charts) (CDN: unpkg).
- **Интеграции:** MOEX ISS API, Yahoo Finance (FreeNewsApi.ai + Stooq), Telegram Bot API, RSS (ПРАЙМ, Банки.ру).
- **Графики:** line / candlestick, RSI(14), MACD, Bollinger, SMA/EMA, прогнозные линии.
- **Экспорт:** CSV (server-side), PNG (client-side через `takeScreenshot()`).

---

## 2. API-эндпоинты

Все API возвращают JSON вида `{ ok: true, ... }` или `{ ok: false, error: "..." }`.

| Роут | Параметры | Назначение |
|---|---|---|
| `api-history` | `market`, `ticker`, `days`, `granularity` | OHLCV / close (`history_bars`) |
| `api-forecast` | `market`, `ticker`, `granularity` | Прогнозные линии (`lines[]`) |
| `api-ma` | `market`, `ticker`, `days`, `period`, `type`, `granularity` | SMA / EMA |
| `api-bollinger` | `market`, `ticker`, `days`, `period`, `mult`, `granularity` | Bollinger Bands |
| `api-rsi` | `market`, `ticker`, `days`, `period`, `granularity` | RSI |
| `api-macd` | `market`, `ticker`, `days`, `granularity` | MACD + Signal + Histogram |
| `api-verify` | `market`, `ticker` | Верификация прогнозов (см. §4) |
| `export-verify` | `market`, `ticker` | CSV с верификацией |
| `admin-settings` | POST | Тип графика по умолчанию и др. |

**Формат прогнозной линии:**

```json
{
  "line_num": 1,
  "name": "Прогноз 1",
  "color": "#ef5350",
  "value_type": "price",
  "computed": false,
  "data": [{ "time": "2026-10-01", "value": 123.45 }]
}
```

- `value_type === "price"` → левая ось.
- `value_type === "delta"` → правая ось.

---

## 3. Семантика `delta` (зафиксирована)

**Вариант A — относительная дельта с восстановлением через cumprod** (реализовано с v1.2).

- Прогноз хранится как **относительное изменение**:
  ```
  delta_t = p_t / p_{t-1} - 1
  ```
- Восстановление цены из `delta` — накопленное произведение (cumprod):
  ```
  p_t = p_0 * ∏_{i=1}^{t} (1 + delta_i)
  ```
- `p_0` — последняя фактическая цена на дату начала прогноза.

Реализация — в `lib/verify.php` (`forecast_to_price()`). Эта семантика используется и в верификации, и для отображения `delta`-линий на правой оси.

---

## 4. Верификация прогнозов (реализована)

### Что считается

- **MAPE** — средняя абсолютная процентная ошибка.
- **Hit-rate** — доля точек, где |отклонение| в пределах порога «сбылся» (≤ 2%).
- **Статус** по каждой точке:
  - `сбылся` — |deviation| ≤ 2%
  - `частично` — 2% < |deviation| ≤ 5%
  - `не сбылся` — |deviation| > 5%

### Схема

1. `api-verify` берёт все прогнозные линии из `api-forecast`.
2. Для каждой точки прогноза ищет фактическую цену на ту же дату в `history_bars`.
3. Пересчитывает `delta → price` через `forecast_to_price()` (cumprod, §3).
4. Считает `deviation_pct = (forecast - fact) / fact * 100`.
5. Определяет `status` по порогам выше.
6. Возвращает JSON:

```json
{
  "ok": true,
  "lines": [{
    "line_num": 1,
    "name": "Прогноз 1",
    "color": "#ef5350",
    "verify": {
      "mape": 3.21,
      "hit_rate": 0.78,
      "count": 42,
      "rows": [{
        "time": "2026-09-01",
        "forecast": 123.4,
        "fact": 120.1,
        "deviation_pct": 2.75,
        "status": "сбылся"
      }]
    }
  }]
}
```

### Файлы

- `lib/verify.php` — `forecast_to_price()`, `verify_forecast()`.
- `pages/stock.php` — `loadVerification()` / `renderVerification()`.
- `pages/accuracy.php` — сводная страница.
- `lib/screener.php` — `screener_data()` для screener'а.

---

## 5. База данных

- **`history_bars`** — `ticker_id`, `date`, `open`, `high`, `low`, `close`, `volume`. Уникальный `(ticker_id, date)`. Миграция — `bootstrap.php` (v1.19).
- **`forecasts`** — `ticker_id`, `line_num`, `date`, `value`, `value_type`. Уникальный `(ticker_id, line_num, date)`.
- **`tickers`** — `id`, `market`, `ticker`, `name`.
- **`settings`** — `key`, `value` (v1.19; тип графика по умолчанию и др.).
- **`announcements`** — новости/анонсы, с колонкой `image` (v1.20).
- **`users`** — `id`, `login`, `password_hash`, `is_admin`.

Кэши (не в БД): `data/news_cache.json` — новости, TTL 15 минут.

---

## 6. Frontend (`pages/stock.php`)

### Основные компоненты

- `LightweightCharts.createChart()` — основной график (480px) + нижняя панель (160px).
- Синхронизация `timeScale` между графиками через `subscribeVisibleLogicalRangeChange`.
- Тултип на `subscribeCrosshairMove` — факт, прогноз, отклонение, статус.
- Переключатели: тип графика (линии/свечи), период, детализация, MA, Bollinger, нижняя панель (RSI/MACD).
- `hide-lower` на `<body>` скрывает `#rsi-chart`, `#rsi-legend`, `.rsi-head`.

### Важные нюансы

- **`applyOptions({ width })` нельзя вызывать при `clientWidth === 0`** — иначе падение. Защита стоит в `toggle-lower` и `window.resize` (v1.20.1).
- **`destroyCharts()` перед каждым рендером** — иначе верификация пропадает при смене типа графика (фикс v1.19).
- `style.css?v=N` — cache-bust; при изменении CSS увеличивать `N`.
- `localStorage.sf_chart_type` — перекрывает серверный `chart_type` из `get_setting()`.

---

## 7. Деплой

```bash
cd /var/www/sotis.org/data/www/forecast.sotis.org
git pull
php bootstrap.php          # миграции, если есть новые
chmod 775 data static/uploads
# cron: см. crontab -l
```

Требования:
- PHP 7.4+, MySQL 5.7+ / MariaDB 10.3+.
- Расширения: `pdo_mysql`, `mbstring`, `curl`, `fileinfo`.
- Права на `data/` и `static/uploads/` — 775, владелец = PHP-процесс.
- SSH-ключ для `git@github.com:CAPITALOV/StockForecast-by-KAPITALOV.git`.
- Исходящие запросы к `iss.moex.com`, `query1.finance.yahoo.com`, `stooq.com`.

Ветка `main` защищена: force-push запрещён. Только fast-forward / rebase + push.

---

## 8. Соглашения

- **Коммиты:** Conventional Commits (`feat:`, `fix:`, `docs:`, `chore:`).
- **Версии:** SemVer (`VERSION`, `CHANGELOG.md`, тег `vX.Y.Z`).
- **PHP:** без фреймворка, PDO. `declare(strict_types=1)` — добавить в новые файлы.
- **JS:** без сборки, ES2020+, глобальный `LightweightCharts`.
- **CSS:** переменные `--bg-card`, `--text`, `--border` для тем.

---

## 9. Известные задачи

- [x] Зафиксировать семантику `delta` (§3, cumprod).
- [x] Реализовать `lib/verify.php` и роут `api-verify` (§4).
- [x] Миграция `history_bars` с OHLC (v1.19).
- [x] Миграция `announcements.image` (v1.20).
- [x] Исправить таймауты Yahoo/Stooq в `lib/api.php` (504).
- [ ] `declare(strict_types=1)` во всех PHP-файлах.
- [ ] Сверить `pages/accuracy.php` с текущим форматом `api-verify`.
- [ ] Документировать `cron/` в §7 (расписание задач).

---

## 10. Полезные ссылки

- Репозиторий: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV
- Релизы: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV/releases
- lightweight-charts: https://tradingview.github.io/lightweight-charts/
- PROJECT_HISTORY.md — история вех и критических багов.
- SECURITY.md — политика безопасности.