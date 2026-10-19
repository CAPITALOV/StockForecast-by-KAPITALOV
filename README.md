# StockForecast — прогнозы и верификация акций MOEX и US

PHP + MySQL (PDO) проект. Работает на любом shared-хостинге с PHP 7.4+
(нужны расширения `pdo_mysql` и `allow_url_fopen`, обычно включены).

Три независимые линии прогноза на каждый тикер, автоматическая верификация
по фактической истории цен и расчёт точности (MAPE, hit-rate).

## Развёртывание

1. Загрузите папку `stock-forecast/` на сервер (например в `public_html/`).
2. Создайте базу данных MySQL в панели хостинга и пропишите доступы в `config.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_PORT', 3306);
   define('DB_NAME', 'stockforecast');
   define('DB_USER', 'sf_user');
   define('DB_PASS', 'ваш_пароль');
   define('DB_CHARSET', 'utf8mb4');