# StockForecast (by KAPITALOV)

Проект сайта для размещения и публикации прогнозов, созданных компанией **KAPITALOV** для прогнозирования движения трендов акций, индексов и эмитентов для дальнейшей торговой стратегии.

Три независимые линии прогноза на каждый тикер. Автоматическая верификация с фактической историей цен (до 20 лет) с бирж MOEX ISS и Yahoo Finance. Прозрачный расчёт точности — без «подгонки» задним числом. Расчёт MAPE и hit-rate, screener, сравнение тикеров, экспорт CSV и PNG, RSI, MACD, Bollinger Bands, скользящие средние (SMA/EMA), фоновые задачи (Cron) и Telegram-уведомления.

---

## 📋 Требования

- PHP 7.4+ с расширениями `pdo_mysql`, `mbstring`
- MySQL 5.7+ / MariaDB 10.3+
- Исходящие HTTP-запросы к `iss.moex.com`, `query1.finance.yahoo.com`, `stooq.com`
- Для Cron: доступ к CLI (запуск `php` из консоли)

## 🚀 Быстрый старт

```bash
# 1. Клонировать
git clone git@github.com:CAPITALOV/StockForecast.git
cd StockForecast

# 2. Настроить БД
cp config.example.php config.php
# отредактировать config.php (DB_HOST, DB_NAME, DB_USER, DB_PASS)

# 3. Создать папку для кэша
mkdir -p data
chmod 775 data

# 4. Открыть в браузере: http://ваш-домен/index.php
# 5. Зайти в ?r=admin-login (admin / admin) и сменить пароль