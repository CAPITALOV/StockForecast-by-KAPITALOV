# 📈 StockForecast

> Векторная система верификации прогнозов и индикатор импульса для акций MOEX и US-рынка.

[![Version](https://img.shields.io/badge/version-2.2.0-blue.svg)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-purple.svg)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-orange.svg)](https://mysql.com)

## 🚀 Обновление v2.2.0: Кэширование, Бэктест и Market Breadth

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
   ```

### ⚠️ Известные «подводные камни» (Troubleshooting v2.2.0):
- **Ошибка `Unknown column 'signal'`**: В таблице `synthetic_components` колонка называется **`trade_action`** (слово `signal` зарезервировано в MySQL).
- **Ошибка `Unknown column 'hb.date'`**: В таблице `history_bars` колонка с датой называется **`ts`**. Используйте `hb.ts` в SQL.
- **Ошибка `Illegal mix of collations`**: Убедитесь, что все таблицы имеют кодировку `utf8mb4_unicode_ci`.
- **Код не обновляется**: После правки PHP-файлов может потребоваться сброс OPcache: `sudo systemctl restart php*-fpm`.

---

## 🎯 Что это

StockForecast — система анализа **импульса тренда** на основе трёх независимых индикаторов (Delta Close, Synthetic Adaptive, RPO). Показывает не «прогноз цены», а **направление и силу текущего движения**, плюс — историческую точность этих сигналов на 13-летней истории.

**Главное отличие от обычных прогнозных сервисов:** мы честно измеряем качество. Модель может ошибаться — и вы это увидите (Pearson, PF, win-rate). Не обещаем «золотых гор», показываем **статистику** и **контекст**.

---

## 📊 Что умеет

- **Три линии прогноза** на графике цены: Delta Close (красная), Synthetic Adaptive (оранжевая), RPO (синяя).
- **Векторная верификация** — сравнение прогноза с реальным движением, а не с ценой.
- **Тренд дельты** (стрелки ↑/↓) — ускорение или замедление сигнала.
- **Баннер-светофор** на странице тикера: 🟢 Покупать / 🟡 Ждать / 🔴 Не покупать.
- **MA200-фильтр** — блокирует BUY, если рынок в даунтренде.
- **Плашка «Историческая точность»** для 8 «золотых» тикеров.
- **CLI-аудит** всех тикеров с Money Metrics (PF, Exp, WinRate).
- **Screener** и **CSV-экспорт** для внешнего анализа.
- **История сигналов (Бэктест)** — просмотр доходности после каждого сигнала.
- **Ширина рынка (Market Breadth)** — глобальный индикатор «температуры» рынка.
- **Избранное** — сохранение интересных тикеров в личный список.

---

## 🚀 Развертывание и обновление

### Требования

- PHP **8.4+** (рекомендуется 8.4 или 8.5)
- MySQL **8.0+**
- Composer не нужен — чистый PHP без зависимостей

### Развёртывание

```bash
# 1. Клонировать
git clone https://github.com/CAPITALOV/StockForecast-by-KAPITALOV.git
cd StockForecast-by-KAPITALOV

# 2. Настроить БД
cp config.example.php config.php
# отредактировать config.php: DB_HOST, DB_NAME, DB_USER, DB_PASS

# 3. Применить миграции (если БД новая)
mysql -u USER -p DBNAME < migrations/001_initial.sql

# 4. Права на запись
chmod -R 775 data/ static/uploads/
chown -R www-data:www-data data/ static/uploads/
```

### Восстановление после сбоя

Используйте универсальный скрипт (сам определит пути и окружение):
```bash
sudo bash <(curl -s https://raw.githubusercontent.com/CAPITALOV/StockForecast-by-KAPITALOV/main/scripts/deploy/restore_stockforecast.sh)
```

### Проверка здоровья

```bash
sudo bash <(curl -s https://raw.githubusercontent.com/CAPITALOV/StockForecast-by-KAPITALOV/main/scripts/deploy/check_health.sh)
```

---

## 🛠️ Скрипты развертывания и диагностики

В проекте есть два универсальных bash-скрипта, которые работают на любом сервере:

| Скрипт | Назначение | Как запустить |
|---|---|---|
| **restore_stockforecast.sh** | Восстановление проекта с нуля (код, права, Cron, кэши) | `sudo bash <(curl -s https://raw.githubusercontent.com/CAPITALOV/StockForecast-by-KAPITALOV/main/scripts/deploy/restore_stockforecast.sh)` |
| **check_health.sh** | Проверка «здоровья» сайта (файлы, БД, кэши, Cron, HTTP) | `sudo bash <(curl -s https://raw.githubusercontent.com/CAPITALOV/StockForecast-by-KAPITALOV/main/scripts/deploy/check_health.sh)` |

📖 **Подробная инструкция:** [scripts/deploy/README.md](scripts/deploy/README.md)

---

## 📚 Документация

- [AI_CONTEXT.md](AI_CONTEXT.md) — контекст для AI-ассистентов и новых разработчиков.
- [DEVELOPMENT.md](DEVELOPMENT.md) — руководство разработчика (архитектура, API, подводные камни).
- [PROJECT_HISTORY.md](PROJECT_HISTORY.md) — история проекта и ключевые вехи.
- [CHANGELOG.md](CHANGELOG.md) — список изменений по версиям.
- [SECURITY.md](SECURITY.md) — политика безопасности.

---

## 📄 Лицензия

Свободное использование в личных и образовательных целях. Не является финансовой рекомендацией.

Автор не несёт ответственности за потери от использования сигналов. Модель — индикатор, не «гарантия прибыли».
```

### 🚀 Как применить

1. Откройте файл `README.md` в режиме редактирования на GitHub (по вашей ссылке).
2. Выделите всё содержимое (`Ctrl+A`) и замените его на этот полный текст.
3. Внизу страницы в поле «Commit changes» напишите сообщение (например, `docs: финальное исправление README для v2.2.0`) и нажмите **Commit changes**.

После этого README будет отображаться корректно: с бейджами, заголовками и без артефактов. 🚀
