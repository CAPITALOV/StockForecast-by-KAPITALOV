# AI_CONTEXT.md — Контекст проекта StockForecast

> Этот файл создан, чтобы любой новый разработчик или AI-ассистент мог быстро войти в курс дела и продолжить работу без потери знаний.

## 1. Текущее состояние (v2.2.0)

- **Стек**: PHP 8.4 + MySQL 8 + amCharts 5 (frontend) + OpenLiteSpeed / Nginx.
- **Что работает**:
  - Три линии прогноза (Delta Close, Synthetic Adaptive, RPO).
  - Адаптивная Линия 2 (Z-score + SMA-5 + динамические веса).
  - Сигналы BUY/SELL на графике с тултипами.
  - Скринер с фильтрацией по сигналам и реальным Hit-rate.
  - Страница «История сигналов» (Бэктест доходности).
  - Индикатор «Ширина рынка» на главной.
  - «Избранное» (Watchlist) на localStorage.
  - JSON-кэширование для Скринера и Точности (решает 504).
  - Cron-задачи для автообновления.
- **Что отключено/отложено**:
  - Telegram-уведомления (сервер блокирует api.telegram.org).
  - Полноценная ML-модель (пока только эвристики).

## 2. Архитектура (кратко)

- **Front controller**: index.php (роутинг + API).
- **Математика**: lib/synthetic.php (Линия 2), lib/verify.php (верификация), lib/screener.php (кэш Скринера).
- **База**: history_bars (цены), forecast_lines (прогнозы), synthetic_components (сигналы), stock_map (справочник).
- **Кэши**: data/screener_cache.json, data/accuracy_cache.json (генерируются Cron).
- **Скрипты**: scripts/deploy/restore_stockforecast.sh (восстановление), scripts/deploy/check_health.sh (диагностика).

Подробнее: [DEVELOPMENT.md](DEVELOPMENT.md), [PROJECT_HISTORY.md](PROJECT_HISTORY.md).

## 3. Известные баги и ограничения

1. **Unknown column 'signal'** — в MySQL слово signal зарезервировано. В synthetic_components колонка называется trade_action.
2. **Unknown column 'hb.date'** — в history_bars колонка даты называется ts.
3. **Illegal mix of collations** — все таблицы должны быть в utf8mb4_unicode_ci.
4. **OPcache** — после правки PHP может потребоваться systemctl restart php*-fpm.
5. **Токсичные тикеры** (Pearson < -0.05): GOOGL, AAPL, BNB-USD, TSLA, INTC, CRM, SONY — модель на них ошибается систематически. В UI не блокируются.
6. **Long-only** — модель сигналит только рост (отрицательных дельт очень мало).
7. **Telegram** — заблокирован на уровне сервера (нужен прокси).

## 4. Roadmap v3.0 (идеи)

- **Рефакторинг**: вынести бизнес-логику в отдельные классы/сервисы, убрать смесь PHP и HTML.
- **Фронтенд**: перевести pages/stock.php на React или Vue (сейчас там 1000+ строк JS).
- **ML-модель**: добавить простую модель (например, логистическую регрессию на дельтах + RSI + MACD) для прогноза направления.
- **Telegram через прокси**: вернуть уведомления.
- **Портфельный подход**: не отдельные тикеры, а корзина с ребалансировкой.
- **Sharpe ratio** в аудите.
- **API-токены** для внешних клиентов.

## 5. Как запустить / восстановить

- **Восстановление с нуля**:
  sudo bash <(curl -s https://raw.githubusercontent.com/CAPITALOV/StockForecast-by-KAPITALOV/main/scripts/deploy/restore_stockforecast.sh)

- **Проверка здоровья**:
  sudo bash <(curl -s https://raw.githubusercontent.com/CAPITALOV/StockForecast-by-KAPITALOV/main/scripts/deploy/check_health.sh)

## 6. Где взять данные

- **База**: дамп MySQL (93 МБ) — хранится у владельца проекта (не в Git).
- **История цен**: загружается скриптом cron/update_history.php (MOEX ISS / Yahoo Finance).
- **Прогнозы**: загружаются через админку или CSV.

## 7. Пароли и доступы

- **НЕ ХРАНЯТСЯ В GIT**. Все секреты — только в config.php на сервере (он в .gitignore).
- Для нового разработчика: попросите владельца проекта создать отдельного пользователя MySQL и выдать доступы.

---
*Последнее обновление: 2026-10-25. Версия: v2.2.0.*
