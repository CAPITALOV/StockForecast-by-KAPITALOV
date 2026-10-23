# StockForecast — История проекта и руководство по поддержке

## 1. Общее описание

**StockForecast** (by KAPITALOV) — аналитическая платформа для прогнозирования трендов акций и верификации прогнозов. 
Проект построен на PHP (роутинг, бэкенд) + MySQL + amCharts 5 (фронтенд).
Версия на момент документа: **1.24.0**.

## 2. Основной стек и структура

- **Бэкенд:** PHP 7.4+, MySQL 5.7+ / MariaDB 10.3+, PDO.
- **Фронтенд:** HTML5, CSS3 (переменные для тем), Vanilla JS, amCharts 5 Stock Chart (v5).
- **Типографика:** Шрифт Inter Variable (self-hosted, локально в `static/fonts/`).
- **Интеграции:** MOEX ISS API, Yahoo Finance (через FreeNewsApi.ai и Stooq), Telegram Bot API, RSS (ПРАЙМ, Банки.ру).

### Ключевые директории:
- `lib/` — бизнес-логика (API, индикаторы, верификация `verify.php`, новости).
- `pages/` — HTML-шаблоны (лендинг, график `stock.php`, точность, админка).
- `cron/` — фоновые задачи (авто-загрузка истории, проверка попаданий).
- `data/` — кэши (JSON). **Важно: права 775, владелец = PHP-процесс.**
- `static/uploads/` — картинки к анонсам (v1.20.0, в `.gitignore`).

## 3. Ключевые вехи и внедренные фичи

1. **Базовый каркас (v1.0):** Загрузка прогнозов (3 линии), парсер TXT/CSV, кэширование истории в `history_bars`.
2. **Верификация (v1.2):** Пересчёт `delta` в цену через cumprod, расчет MAPE и Hit-rate. 
3. **Графики (v1.3–v1.6):** Левая ось (цена), правая (дельта). RSI (14), SMA, EMA.
4. **Screener (v1.17):** Сводная таблица точности по всем тикерам с фильтрами и экспортом.
5. **Новости (v1.18):** Yahoo Finance для US, RSS ПРАЙМ/Банки.ру для MOEX. Кэш 15 минут.
6. **Свечи и OHLC (v1.19):** Переход на полноценные OHLC-данные в `history_bars`. Переключатель «Линии / Свечи».
7. **Архив новостей и Telegram (v1.20.0):** Страница `?r=news`, загрузка картинок к анонсам, уведомления в Telegram.
8. **Фикс Яндекс.Браузера (v1.20.1):** Исправлено скрытие `.rsi-head` (cache-bust CSS).
9. **Переход на amCharts 5 (v1.21.0):** 
   - Основной график перенесен на `am5stock.StockChart`.
   - Добавлено сглаживание `tension: 0.8`.
   - Автовыбор рендера: SVG (до 1 года) и Canvas (длинные периоды).
   - Self-hosted шрифт Inter Variable и SVG-иконки.
10. **Этапы 2 и 3 + Верификация 2.0 (v1.23.0):**
    - RSI/MACD перенесены на нативные панели amCharts с синхронизацией зума.
    - Блок «Сравнение моделей» стал двухосевым: Дельта (левая ось) + Цена (правая).
    - Внедрено «Правило 50%» (вектор считается успешным, если цена прошла хотя бы половину прогнозного хода).
    - Добавлена корреляция Пирсона.
11. **Визуализация статусов (v1.24.0):** 
    - На график сравнения добавлены цветные маркеры (зеленые — успех, красные — убыток, оранжевые — частично).
    - В таблицу верификации добавлена колонка «Дельта» с исходными числами.

## 4. Критические баги, которые были исправлены

- **504 Gateway Time-out:** В `lib/api.php` таймауты Yahoo (425с) и Stooq (2500с) снижены до 10с и 15с.
- **Верификация пропадала при смене графика:** Исправлено полным пересозданием графиков (`destroyCharts()`) перед каждым рендером.
- **Повреждение Git (`corrupt loose object`):** Локальный `.git` пересобран с нуля клонированием с GitHub.
- **Скрытие `.rsi-head` в Яндекс.Браузере (v1.20.1):** Устаревший CSS в кэше; решено добавлением `?v=2` и правила `body.hide-lower .rsi-head`.
- **Падение amCharts при экспорте (v1.21.0):** Ошибка `Cannot read properties of undefined (reading 'Exporting')`. Исправлено заменой `am5.exporting` на `am5plugins_exporting` (подключен `plugins/exporting.js`).
- **Сдвиг дат и пропажа маркеров (v1.24.0):** 
  - Даты из API нормализуются через строку `slice(0,10)`, чтобы избежать сдвига часовых поясов.
  - Статусы в amCharts читаются через `dataItem.dataContext.status`, иначе библиотека их не видит.

## 5. Инструкция по восстановлению Git (если сломается)

Если Git выдаст `dubious ownership`:
`git config --global --add safe.directory /var/www/sotis.org/data/www/forecast.sotis.org`

Если `.git` поврежден:
1. `cd /var/www/sotis.org/data/www`
2. `mv forecast.sotis.org forecast.sotis.org.broken.$(date +%s)`
3. `git clone git@github.com:CAPITALOV/StockForecast-by-KAPITALOV.git forecast.sotis.org`
4. Восстановить `config.php` и папку `data/` из бэкапа.
5. `chmod 775 data static/uploads`

## 6. Релизный процесс

1. Обновить `VERSION`, `CHANGELOG.md`, при необходимости `README.md`.
2. Коммит: `docs: bump version to X.Y.Z`.
3. `git push origin main`.
4. Тег: `git tag -a vX.Y.Z -m "..."` → `git push origin vX.Y.Z`.
5. GitHub Release (Title + описание из CHANGELOG).
6. Пост-релизная проверка (см. §7).

## 7. Пост-релизная проверка (чек-лист)

- `curl -sI https://forecast.sotis.org/index.php | head -1` → `HTTP/… 200`.
- Открыть страницу тикера (например, XOM), **Ctrl+F5**.
- График amCharts плавный, при периоде 1 год в Elements внутри `#chart` есть `<svg>`.
- Нижняя панель (RSI/MACD) синхронизируется с ценой при зуме.
- В блоке «Сравнение моделей» видны цветные точки (зеленые/красные).
- F12 → Console: нет красных ошибок.
- F12 → Network: запросов к `fonts.googleapis.com` нет, грузится локальный `InterVariable.woff2`.

## 8. Полезные ссылки

- Репозиторий: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV
- Релизы: https://github.com/CAPITALOV/StockForecast-by-KAPITALOV/releases
- `DEVELOPMENT.md` — руководство разработчика.
- `CHANGELOG.md` — список изменений.
- `SECURITY.md` — политика безопасности.