# StockForecast — История проекта и руководство по поддержке

## 1. Общее описание

**StockForecast** (by KAPITALOV) — аналитическая платформа для прогнозирования
трендов акций и верификации прогнозов. Построена на PHP (роутинг, бэкенд) +
MySQL + Lightweight Charts (фронтенд).

Версия на момент документа: **1.20.1**.

## 2. Основной стек и структура

- **Бэкенд:** PHP 7.4+, MySQL 5.7+ / MariaDB 10.3+, PDO.
- **Фронтенд:** HTML5, CSS3 (переменные для тем), Vanilla JS, Lightweight Charts v4.1.0.
- **Интеграции:** MOEX ISS API, Yahoo Finance (через FreeNewsApi.ai и Stooq),
  Telegram Bot API, RSS (ПРАЙМ, Банки.ру).

### Ключевые директории

- `lib/` — бизнес-логика (API, индикаторы, верификация, новости).
- `pages/` — HTML-шаблоны (лендинг, график, точность, админка).
- `cron/` — фоновые задачи (авто-загрузка истории, проверка попаданий).
- `data/` — кэши (JSON). **Важно: права 775, владелец = PHP-процесс.**
- `static/uploads/` — картинки к анонсам (v1.20.0, в `.gitignore`).

## 3. Ключевые вехи и внедрённые фичи

1. **Базовый каркас (v1.0):** загрузка прогнозов (3 линии), парсер TXT/CSV,
   кэширование истории в `history_bars`.
2. **Верификация (v1.2):** пересчёт `delta` в цену через cumprod, расчёт MAPE
   и Hit-rate. Статусы: сбылся / частично / не сбылся.
3. **Графики (v1.3–v1.6):** левая ось (цена), правая (дельта). RSI (14), SMA, EMA,
   уровни перекупленности.
4. **Screener (v1.17):** сводная таблица точности по всем тикерам с фильтрами
   и экспортом.
5. **Новости (v1.18):** Yahoo Finance для US, RSS ПРАЙМ/Банки.ру для MOEX.
   Кэш 15 минут.
6. **Свечи и OHLC (v1.19):** переход на полноценные OHLC-данные. Переключатель
   «Линии / Свечи» в админке и на сайте. Настройка `chart_type` по умолчанию
   в таблице `settings`. Фикс пропадания верификации при смене типа графика
   (полное пересоздание графиков через `destroyCharts()`).
7. **Архив новостей и Telegram (v1.20.0):**
   - страница архива `?r=news` — на главной только 3 последних анонса;
   - загрузка картинок к анонсам (JPG/PNG/WebP/GIF) в `static/uploads/`;
   - миграция БД: в `announcements` добавлена колонка `image`;
   - Telegram-уведомления в канал при публикации новости.
8. **Фикс `.rsi-head` в Яндекс.Браузере (v1.20.1):**
   - кнопка «📊 Панель» корректно скрывает `<div class="rsi-head">` вместе
     с графиком и легендой во всех Chromium-браузерах;
   - причина — устаревший CSS в кэше; решено через `style.css?v=2` и правило
     `body.hide-lower .rsi-head { display: none; }`;
   - `toggle-lower` и `window.resize` больше не вызывают `applyOptions` при
     `clientWidth === 0` — исчезли ошибки в консоли.

## 4. Критические баги, которые были исправлены

- **504 Gateway Time-out:** в `lib/api.php` таймауты Yahoo (425 с) и Stooq (2500 с)
  снижены до 10 с и 15 с. В бэкфилл добавлены `set_time_limit(55)` и `LIMIT 1`.
- **Верификация пропадала при смене графика:** исправлено полным пересозданием
  графиков (`destroyCharts()`) перед каждым рендером (v1.19).
- **Повреждение Git (`corrupt loose object`):** локальный `.git` пересобран
  с нуля клонированием с GitHub.
- **`applyOptions({ width: 0 })` ронял графики** (v1.20.1): защита добавлена
  в `toggle-lower` и `window.resize` — проверка `clientWidth > 0` и `try/catch`.
- **Заголовок RSI не скрывался в Яндекс.Браузере** (v1.20.1): устаревший CSS
  в кэше; решено cache-bust `style.css?v=2`.

## 5. Инструкция по восстановлению Git (если сломается)

Если Git выдаст `dubious ownership`:

```bash
git config --global --add safe.directory /var/www/sotis.org/data/www/forecast.sotis.org
```

Если `.git` повреждён (`corrupt loose object`, `fatal: not a git repository`,
`unable to read tree`):

```bash
cd /var/www/sotis.org/data/www
mv forecast.sotis.org forecast.sotis.org.broken.$(date +%s)
git clone git@github.com:CAPITALOV/StockForecast-by-KAPITALOV.git forecast.sotis.org
# восстановить config.php и data/ из бэкапа (они не в git):
# scp config.php root@sotis:.../forecast.sotis.org/
# scp -r data/     root@sotis:.../forecast.sotis.org/
chmod 775 data static/uploads
```

Проверка после восстановления:

```bash
cd forecast.sotis.org
git status
git log --oneline -3
php bootstrap.php      # миграции
curl -sI https://forecast.sotis.org/index.php | head -1
```

## 6. Релизный процесс (v1.20.1+)

1. Обновить `VERSION` (`X.Y.Z`), `CHANGELOG.md` (блок `[X.Y.Z]`), при необходимости — `README.md`.
2. Коммит: `docs: bump version to X.Y.Z — <краткое описание>`.
3. `git push origin main` (fast-forward; force-push в `main` запрещён защитой ветки).
4. Тег: `git tag -a vX.Y.Z -m "..."` → `git push origin vX.Y.Z`.
5. GitHub Release: Title + описание из `CHANGELOG.md` секции `[X.Y.Z]`.
6. Пост-релизная проверка на проде (см. §7).

## 7. Пост-релизная проверка (чек-лист)

- [ ] `curl -sI https://forecast.sotis.org/index.php | head -1` → `HTTP/… 200`.
- [ ] Открыть страницу тикера (`?r=stock&market=moex&ticker=AKGD`), **Ctrl+F5**.
- [ ] Переключить тип графика (линии/свечи) — верификация не пропадает.
- [ ] Нажать «📊 Панель» — `#rsi-chart`, `#rsi-legend`, `.rsi-head` скрываются
      (в т.ч. в Яндекс.Браузере).
- [ ] F12 → Console: нет красных ошибок при ресайзе и скрытии панели.
- [ ] F12 → Network: `style.css?v=N` отдаётся `200`, не `304`.
- [ ] `crontab -l` — задачи `cron/` на месте.
- [ ] `php bootstrap.php` — миграции применились без ошибок.

## 8. Полезные ссылки

- Репозиторий: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV
- Релизы: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV/releases
- `DEVELOPMENT.md` — руководство разработчика (архитектура, API, БД).
- `CHANGELOG.md` — список изменений по версиям.
- `SECURITY.md` — политика безопасности.