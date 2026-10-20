# StockForecast (by KAPITALOV)

Проект сайта для размещения и публикации прогнозов, созданных компанией **KAPITALOV** для прогнозирования движения трендов акций, индексов и эмитентов для дальнейшей торговой стратегии.

Три независимые линии прогноза на каждый тикер. Автоматическая верификация с фактической историей цен (до 20 лет) с бирж MOEX ISS и Yahoo Finance. Прозрачный расчёт точности — без «подгонки» задним числом. Расчёт MAPE и hit-rate, screener, сравнение тикеров, экспорт CSV и PNG, RSI, MACD, Bollinger Bands, японские свечи, скользящие средние (SMA/EMA), фоновые задачи (Cron) и Telegram-уведомления.

---

## ���� ��Легенда

![Легенда StockForecast: линии, индикаторы, статусы](static/legend.svg)

*Зелёная — факт (или свечи). Красная/синяя/оранжевая — три линии прогноза. Жёлтая — SMA/EMA. Голубая — Bollinger Bands. Фиолетовая — RSI. MACD с гистограммой. Статусы: сбылся (≤ 2%), частично (2–5%), не сбылся (> 5%).*

---

## ���� ��Требования

- PHP 7.4+ с расширениями `pdo_mysql`, `mbstring`, `curl`, `fileinfo`
- MySQL 5.7+ / MariaDB 10.3+
- Исходящие HTTP-запросы к `iss.moex.com`, `query1.finance.yahoo.com`, `stooq.com`
- Для Cron: доступ к CLI

## ���� ��Быстрый старт

```bash
git clone git@github.com:CAPITALOV/StockForecast-by-KAPITALOV.git
cd StockForecast
cp config.example.php config.php
# отредактировать config.php (DB_HOST, DB_NAME, DB_USER, DB_PASS)
mkdir -p data static/uploads
chmod 775 data static/uploads
# Открыть в браузере: http://forecast.sotis.org/index.php
