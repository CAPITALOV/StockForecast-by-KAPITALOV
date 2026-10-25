#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# Скрипт полного восстановления StockForecast после переустановки
# Все правки, которые мы вносили (v2.2.0)
# Запуск: sudo bash restore_stockforecast.sh
# ============================================================

# ─────── ПЕРЕМЕННЫЕ (при необходимости измените) ───────
PROJECT_DIR="/var/www/www-root/data/www/sotis.org"
SITE_USER="www-root"
SITE_GROUP="www-root"
SITE_DOMAIN="sotis.org"
SERVER_IP="109.172.115.161"
PHP_BIN="/opt/php84/bin/lsphp"
PHP_INI_PATH="/opt/php84/etc/php.ini"
GITHUB_REPO="https://github.com/CAPITALOV/StockForecast-by-KAPITALOV.git"

DB_NAME="stockforecast"
DB_USER="sf_user"
DB_PASS="MyStrongPass123!"

# Слушатели OpenLiteSpeed для этого IP
LISTENER_HTTP="/usr/local/lsws/conf/listeners/${SERVER_IP}.conf"
LISTENER_SSL="/usr/local/lsws/conf/listeners/${SERVER_IP}-ssl.conf"

echo "═══════════════════════════════════════════════════════════"
echo "  Восстановление StockForecast (v2.2.0)"
echo "═══════════════════════════════════════════════════════════"

# ─────── 1. Проверка прав ───────
if [[ $EUID -ne 0 ]]; then
    echo "❌ Запустите скрипт от имени root (sudo)."
    exit 1
fi

# ─────── 2. Проверка зависимостей ───────
echo ""
echo "→ Проверяем наличие PHP и OpenLiteSpeed..."
if [[ ! -x "$PHP_BIN" ]]; then
    echo "❌ Не найден $PHP_BIN"
    echo "   Установите PHP 8.4 через ISPmanager (Модули PHP для OpenLiteSpeed)"
    exit 1
fi
if ! systemctl is-active --quiet lsws; then
    echo "⚠️  OpenLiteSpeed не запущен. Пытаемся запустить..."
    systemctl start lsws || { echo "❌ Не удалось запустить OLS"; exit 1; }
fi
echo "✅ PHP и OpenLiteSpeed в порядке."

# ─────── 3. Клонирование репозитория ───────
echo ""
echo "→ Проверяем исходники в $PROJECT_DIR..."
if [[ ! -d "$PROJECT_DIR/.git" ]]; then
    echo "→ Git-репозиторий не найден, клонируем..."
    if [[ -f "$PROJECT_DIR/config.php" ]]; then
        cp "$PROJECT_DIR/config.php" /tmp/config.php.restore-backup
        echo "   → config.php сохранен в /tmp/"
    fi
    mkdir -p "$PROJECT_DIR"
    find "$PROJECT_DIR" -mindepth 1 -maxdepth 1 ! -name '.git' -exec rm -rf {} + 2>/dev/null || true
    git clone "$GITHUB_REPO" "$PROJECT_DIR"
    if [[ -f /tmp/config.php.restore-backup ]]; then
        cp /tmp/config.php.restore-backup "$PROJECT_DIR/config.php"
    fi
else
    echo "→ Git-репозиторий уже есть, обновляем из GitHub..."
    cd "$PROJECT_DIR"
    git fetch origin main
    git reset --hard origin/main
fi

# ─────── 4. Создание config.php, если его нет ───────
echo ""
echo "→ Проверяем config.php..."
if [[ ! -f "$PROJECT_DIR/config.php" ]]; then
    cat > "$PROJECT_DIR/config.php" <<PHPEOF
<?php
// ================== БАЗА ДАННЫХ ==================
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', '${DB_NAME}');
define('DB_USER', '${DB_USER}');
define('DB_PASS', '${DB_PASS}');
define('DB_CHARSET', 'utf8mb4');

// ================== ПРОЧЕЕ ==================
define('SESSION_NAME', 'sf_session');
define('DEFAULT_DAYS', 365);
define('MOEX_CACHE_TTL', 86400);

// ================== TELEGRAM ==================
define('TELEGRAM_BOT_TOKEN', '');
define('TELEGRAM_CHAT_ID', '');

// ================== NEWS ==================
define('EULERPOOL_KEY', '');
PHPEOF
    echo "✅ config.php создан."
else
    echo "→ config.php уже существует, не трогаем."
fi

# ─────── 5. Права доступа ───────
echo ""
echo "→ Устанавливаем права..."
chown -R "${SITE_USER}:${SITE_GROUP}" "$PROJECT_DIR"
mkdir -p "$PROJECT_DIR/data" "$PROJECT_DIR/static/uploads"
chmod -R 775 "$PROJECT_DIR/data" "$PROJECT_DIR/static/uploads"
find "$PROJECT_DIR" -type d -exec chmod 755 {} \;
find "$PROJECT_DIR" -type f -exec chmod 644 {} \;
chmod 775 "$PROJECT_DIR/data" "$PROJECT_DIR/static/uploads"
echo "✅ Права для ${SITE_USER}:${SITE_GROUP} установлены."

# ─────── 6. Кодировка MySQL ───────
echo ""
echo "→ Выравниваем кодировку synthetic_components..."
mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -e "
    ALTER TABLE synthetic_components CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
" 2>/dev/null && echo "✅ Кодировка выровнена." || echo "⚠️  Таблица не найдена или уже в нужной кодировке."

# ─────── 7. Настройка слушателя OpenLiteSpeed ───────
echo ""
echo "→ Проверяем настройки слушателя OLS..."
for CFG in "$LISTENER_HTTP" "$LISTENER_SSL"; do
    if [[ -f "$CFG" ]]; then
        if ! grep -q "$SERVER_IP" "$CFG" 2>/dev/null; then
            sed -i "s|map ${SITE_DOMAIN}-ssl ${SITE_DOMAIN},\*.${SITE_DOMAIN},www.${SITE_DOMAIN}|map ${SITE_DOMAIN}-ssl ${SITE_DOMAIN},*.${SITE_DOMAIN},www.${SITE_DOMAIN},${SERVER_IP}|g" "$CFG"
            sed -i "s|map ${SITE_DOMAIN}-http ${SITE_DOMAIN},\*.${SITE_DOMAIN},www.${SITE_DOMAIN}|map ${SITE_DOMAIN}-http ${SITE_DOMAIN},*.${SITE_DOMAIN},www.${SITE_DOMAIN},${SERVER_IP}|g" "$CFG"
            sed -i "s|map ${SITE_DOMAIN}-ssl ${SITE_DOMAIN},\*.${SITE_DOMAIN},www.${SITE_DOMAIN},${SERVER_IP},${SERVER_IP}|map ${SITE_DOMAIN}-ssl ${SITE_DOMAIN},*.${SITE_DOMAIN},www.${SITE_DOMAIN},${SERVER_IP}|g" "$CFG"
            echo "  ✅ IP добавлен в $CFG"
        else
            echo "  → $CFG уже настроен."
        fi
    else
        echo "  ⚠️  Файл $CFG не найден (возможно, IP другой — поправьте переменную SERVER_IP)"
    fi
done

# ─────── 8. Часовой пояс PHP ───────
echo ""
echo "→ Настраиваем timezone в PHP..."
if [[ -f "$PHP_INI_PATH" ]] && ! grep -q "date.timezone" "$PHP_INI_PATH" 2>/dev/null; then
    echo "date.timezone = Europe/Moscow" >> "$PHP_INI_PATH"
    echo "✅ Timezone добавлен в $PHP_INI_PATH"
else
    echo "→ Timezone уже настроен или файл не найден."
fi

# ─────── 9. Генерация начальных кэшей ───────
echo ""
echo "→ Генерируем начальные кэши..."
sudo -u "$SITE_USER" "$PHP_BIN" "$PROJECT_DIR/scripts/cron/refresh_synthetic.php" 2>&1 | tail -3 || echo "⚠️  synthetic: ошибка"
sudo -u "$SITE_USER" "$PHP_BIN" "$PROJECT_DIR/scripts/cron/refresh_screener.php" 2>&1 | tail -3 || echo "⚠️  screener: ошибка"
sudo -u "$SITE_USER" "$PHP_BIN" "$PROJECT_DIR/scripts/cron/refresh_accuracy.php" 2>&1 | tail -3 || echo "⚠️  accuracy: ошибка"

# ─────── 10. Cron-задачи ───────
echo ""
echo "→ Настраиваем Cron..."
cat > /etc/cron.d/stockforecast <<CRONEOF
# Пересчёт синтетических сигналов (BUY/SELL) каждую ночь в 3:00
0 3 * * * ${SITE_USER} ${PHP_BIN} ${PROJECT_DIR}/scripts/cron/refresh_synthetic.php >> /var/log/synthetic_refresh.log 2>&1

# Обновление кэша Скринера каждый час
0 * * * * ${SITE_USER} ${PHP_BIN} ${PROJECT_DIR}/scripts/cron/refresh_screener.php >> /var/log/screener_refresh.log 2>&1

# Обновление кэша Точности каждый час
0 * * * * ${SITE_USER} ${PHP_BIN} ${PROJECT_DIR}/scripts/cron/refresh_accuracy.php >> /var/log/accuracy_refresh.log 2>&1
CRONEOF
chmod 644 /etc/cron.d/stockforecast
echo "✅ Cron-задачи добавлены в /etc/cron.d/stockforecast"

# ─────── 11. Перезапуск OLS ───────
echo ""
echo "→ Перезапускаем OpenLiteSpeed..."
systemctl restart lsws
echo "✅ OpenLiteSpeed перезапущен."

# ─────── 12. Итог ───────
echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  ✅ Восстановление завершено!"
echo "═══════════════════════════════════════════════════════════"
echo ""
echo "Что было сделано:"
echo "  1. Код развёрнут из GitHub в $PROJECT_DIR"
echo "  2. config.php создан с настройками БД"
echo "  3. Права установлены для $SITE_USER"
echo "  4. Кодировка synthetic_components выровнена"
echo "  5. Настройки слушателя OLS проверены"
echo "  6. Timezone PHP настроен"
echo "  7. Кэши сгенерированы"
echo "  8. Cron-задачи настроены"
echo "  9. OpenLiteSpeed перезапущен"
echo ""
echo "Проверьте сайт: https://${SERVER_IP}/"
