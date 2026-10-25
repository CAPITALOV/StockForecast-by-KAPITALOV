# ���� StockForecast

> ��Векторная система верификации прогнозов и индикатор импульса для акций MOEX и US-рынка.

[![Version](https://img.shields.io/badge/version-2.2.0-blue.svg)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://mysql.com)

## ���� ��Обновление v2.2.0: Кэширование, Бэктест и Market Breadth

В версии 2.2.0 проект избавился от зависаний (504) и получил несколько мощных аналитических инструментов.

### Новые возможности:
- **История сигналов (Бэктест)**: Для каждого тикера доступна страница `?r=signals`, где показана реальная доходность после исторических сигналов `BUY`/`SELL` (через 5/10/20 дней).
- **Ширина рынка (Market Breadth)**: На главной странице появился индикатор, показывающий процент акций в зоне покупки и продажи.
- **Избранное (Watchlist)**: Возможность сохранять акции в личный список (localStorage).
- **Тултипы**: На графике при наведении на сигналы показывается уверенность и веса линий.

### Архитектурные изменения (Важно для разработчика):
1. **Кэширование**: Страницы Скринера и Точности больше не делают тяжелых запросов в БД. Они читают JSON-кэши из папки `data/`. Если данные не обновляются, проверьте Cron!
2. **Новые Cron-задачи** (добавьте в `crontab`):
   ```bash
   # Пересчет синтетических сигналов (ночью)
   0 3 * * * /usr/bin/php /path/to/project/scripts/cron/refresh_synthetic.php
   # Обновление кэша Скринера (каждый час)
   0 * * * * /usr/bin/php /path/to/project/scripts/cron/refresh_screener.php
   # Обновление кэша Точности (каждый час)
   0 * * * * /usr/bin/php /path/to/project/scripts/cron/refresh_accuracy.php
cat << 'EOF' > /var/www/sotis.org/data/www/forecast.sotis.org/README.md
# ���� StockForecast

> ��Векторная система верификации прогнозов и индикатор импульса для акций MOEX и US-рынка.

[![Version](https://img.shields.io/badge/version-2.2.0-blue.svg)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://mysql.com)

## ���� ��Обновление v2.2.0: Кэширование, Бэктест и Market Breadth

В версии 2.2.0 проект избавился от зависаний (504) и получил несколько мощных аналитических инструментов.

### Новые возможности:
- **История сигналов (Бэктест)**: Для каждого тикера доступна страница `?r=signals`, где показана реальная доходность после исторических сигналов `BUY`/`SELL` (через 5/10/20 дней).
- **Ширина рынка (Market Breadth)**: На главной странице появился индикатор, показывающий процент акций в зоне покупки и продажи.
- **Избранное (Watchlist)**: Возможность сохранять акции в личный список (localStorage).
- **Тултипы**: На графике при наведении на сигналы показывается уверенность и веса линий.

### Архитектурные изменения (Важно для разработчика):
1. **Кэширование**: Страницы Скринера и Точности больше не делают тяжелых запросов в БД. Они читают JSON-кэши из папки `data/`. Если данные не обновляются, проверьте Cron!
2. **Новые Cron-задачи** (добавьте в `crontab`):
   ```bash
   # Пересчет синтетических сигналов (ночью)
   0 3 * * * /usr/bin/php /path/to/project/scripts/cron/refresh_synthetic.php
   # Обновление кэша Скринера (каждый час)
   0 * * * * /usr/bin/php /path/to/project/scripts/cron/refresh_screener.php
   # Обновление кэша Точности (каждый час)
   0 * * * * /usr/bin/php /path/to/project/scripts/cron/refresh_accuracy.php

cat << 'EOF' > /var/www/sotis.org/data/www/forecast.sotis.org/PROJECT_HISTORY.md
# StockForecast — История проекта и руководство по поддержке

## 1. Общее описание
**StockForecast** (by KAPITALOV) — аналитическая платформа для прогнозирования трендов акций и верификации прогнозов. 
Проект построен на PHP (роутинг, бэкенд) + MySQL + amCharts 5 (фронтенд).
Версия на момент документа: **2.2.0**.

## 2. Ключевые вехи и внедренные фичи
1. **Базовый каркас (v1.0):** Загрузка прогнозов (3 линии), парсер TXT/CSV, кэширование истории в `history_bars`.
2. **Верификация (v1.2):** Пересчёт `delta` в цену через cumprod, расчет MAPE и Hit-rate. 
3. **Графики (v1.3–v1.6):** Левая ось (цена), правая (дельта). RSI (14), SMA, EMA.
4. **Screener (v1.17):** Сводная таблица точности по всем тикерам с фильтрами и экспортом.
5. **Переход на amCharts 5 (v1.21.0):** Основной график перенесен на `am5stock.StockChart`. Автовыбор рендера: SVG и Canvas.
6. **Верификация 2.0 (v1.23.0):** Внедрено «Правило 50%» и корреляция Пирсона.
7. **Адаптивная Линия 2 (v2.1.0):** Замена простого среднего на математическую модель (SMA-5, Z-score, динамические веса). Добавлены торговые сигналы `BUY`/`SELL` на графики.
8. **Кэширование и Бэктест (v2.2.0):** 
   - Страницы Скринера и Точности переведены на JSON-кэши (`data/*.json`). Это решило проблему 504 Timeout.
   - Добавлена страница «История сигналов» (`pages/signals.php`) с расчетом реальной доходности сигналов.
   - Добавлен индикатор «Ширина рынка» (Market Breadth) на главную.
   - Добавлено «Избранное» (Watchlist) на базе localStorage.

## 3. Критические баги и «подводные камни»
- **504 Gateway Time-out (v2.2.0):** Тяжелые SQL-запросы вешали сервер. Решено внедрением фонового кэширования (Cron обновляет JSON, страницы читают JSON).
- **Ошибка `Unknown column 'signal'`:** В MySQL `signal` — зарезервированное слово. В таблице `synthetic_components` колонка называется `trade_action`.
- **Ошибка `Unknown column 'hb.date'`:** В таблице `history_bars` колонка с датой называется `ts`.
- **Ошибка `Illegal mix of collations`:** Все таблицы БД должны иметь кодировку `utf8mb4_unicode_ci`.
- **Сдвиг дат:** Даты из API нормализуются через `slice(0,10)`, чтобы избежать сдвига часовых поясов.
- **OPcache:** Если изменения в PHP-файлах не применяются, нужно перезапустить PHP-FPM (`sudo systemctl restart php*-fpm`).

## 4. Релизный процесс
1. Обновить `VERSION`, `CHANGELOG.md`, при необходимости `README.md`.
2. Коммит: `docs: bump version to X.Y.Z`.
3. `git push origin main`.
4. Тег: `git tag -a vX.Y.Z -m "..."` → `git push origin main --tags`.
5. GitHub Release (Title + описание из CHANGELOG).

---

## Скрипты развертывания и диагностики

В проекте есть два универсальных bash-скрипта, которые работают на любом сервере:

| Скрипт | Назначение | Как запустить |
|---|---|---|
| **restore_stockforecast.sh** | Восстановление проекта с нуля (код, права, Cron, кэши) | \`sudo bash <(curl -s https://raw.githubusercontent.com/CAPITALOV/StockForecast-by-KAPITALOV/main/scripts/deploy/restore_stockforecast.sh)\` |
| **check_health.sh** | Проверка «здоровья» сайта (файлы, БД, кэши, Cron, HTTP) | \`sudo bash <(curl -s https://raw.githubusercontent.com/CAPITALOV/StockForecast-by-KAPITALOV/main/scripts/deploy/check_health.sh)\` |

Подробная инструкция: [scripts/deploy/README.md](scripts/deploy/README.md)
