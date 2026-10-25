# Восстановление StockForecast с нуля (шпаргалка)

## 1. Подготовка сервера (ISPmanager)
1. Создать сайт `sotis.org` с docRoot `/var/www/www-root/data/www/sotis.org`.
2. Установить **PHP 8.4** (или 8.5) в разделе «Конфигурация ПО».
3. Установить **MySQL 8** (если не установлен).
4. В настройках сайта выбрать обработчик **OpenLiteSpeed (LSAPI)** или **Nginx + PHP-FPM**.
5. Убедиться, что SSL-сертификат для `sotis.org` выпущен (Let's Encrypt).
6. Проверить, что в списке WWW-доменов нет лишних/удалённых сайтов.

## 2. Восстановление кода
Выполнить от `root`:

    sudo bash <(curl -s https://raw.githubusercontent.com/CAPITALOV/StockForecast-by-KAPITALOV/main/scripts/deploy/restore_stockforecast.sh)

Если подстановка процесса не поддерживается:

    curl -O https://raw.githubusercontent.com/CAPITALOV/StockForecast-by-KAPITALOV/main/scripts/deploy/restore_stockforecast.sh
    sudo bash restore_stockforecast.sh

Скрипт сам склонирует репозиторий, создаст `config.php`, настроит права, Cron, кэши и перезапустит веб-сервер.

## 3. Восстановление базы данных
Залить дамп (`stockforecast_backup_YYYY-MM-DD.sql`, ~93 МБ):

    mysql -u sf_user -p stockforecast < /путь/к/stockforecast_backup_YYYY-MM-DD.sql

Пароль: `MyStrongPass123!` (из `config.php`).

Если ошибка `Illegal mix of collations`:

    mysql -u sf_user -p stockforecast -e "ALTER TABLE synthetic_components CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

## 4. Проверка
    cd /var/www/www-root/data/www/sotis.org
    sudo bash scripts/deploy/check_health.sh

Ожидается: `[OK] ВСЁ ИДЕАЛЬНО! Ошибок: 0, предупреждений: 0`

Открыть сайт: `https://sotis.org`.

## 5. Частые проблемы
| Проблема | Решение |
|---|---|
| `Unknown column 'signal'` | `ALTER TABLE synthetic_components CHANGE signal trade_action VARCHAR(16) NULL;` |
| `Unknown column 'hb.date'` | В SQL использовать `ts` вместо `date` (в `history_bars`) |
| `Illegal mix of collations` | Переконвертировать таблицу (см. п.3) |
| Код не обновляется | `systemctl restart php*-fpm` или `systemctl restart lsws` |
| `config.php` отсутствует | `cp config.example.php config.php` |

## 6. Cron-задачи
Проверить `/etc/cron.d/stockforecast`. Если нет — создать:

    0 3 * * * www-root /usr/bin/php /var/www/www-root/data/www/sotis.org/scripts/cron/refresh_synthetic.php >> /var/log/synthetic_refresh.log 2>&1
    0 * * * * www-root /usr/bin/php /var/www/www-root/data/www/sotis.org/scripts/cron/refresh_screener.php >> /var/log/screener_refresh.log 2>&1
    0 * * * * www-root /usr/bin/php /var/www/www-root/data/www/sotis.org/scripts/cron/refresh_accuracy.php >> /var/log/accuracy_refresh.log 2>&1

Если PHP-бинарник другой (например, `/opt/php84/bin/lsphp`) — заменить путь.

## 7. Бэкап базы
    mysqldump -u sf_user -p --no-tablespaces stockforecast > /root/backup_$(date +%F).sql

Скачать файл на локальный ПК через FTP/SCP.
