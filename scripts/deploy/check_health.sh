#!/usr/bin/env bash
# ============================================================
# Универсальный скрипт проверки «здоровья» StockForecast
# Запуск: sudo bash check_health.sh (из любой папки)
# ============================================================

# Умное автоопределение корня проекта:
find_project_dir() {
    local dir="$1"
    while [[ "$dir" != "/" ]]; do
        if [[ -f "$dir/index.php" && -f "$dir/lib/synthetic.php" ]]; then
            echo "$dir"
            return 0
        fi
        dir="$(dirname "$dir")"
    done
    return 1
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR=""
PROJECT_DIR=$(find_project_dir "$SCRIPT_DIR") || true
if [[ -z "$PROJECT_DIR" ]]; then
    PROJECT_DIR=$(find_project_dir "$PWD") || true
fi
if [[ -z "$PROJECT_DIR" ]]; then
    echo "❌ Не удалось найти корень проекта (нет index.php или lib/synthetic.php)."
    echo "   Запустите скрипт из корня сайта или из scripts/deploy/."
    exit 1
fi

# Автоопределение владельца проекта
SITE_USER=$(stat -c '%U' "$PROJECT_DIR")
SITE_GROUP=$(stat -c '%G' "$PROJECT_DIR")

# Автоопределение IP (первый не-loopback IP)
SERVER_IP=$(hostname -I | awk '{print $1}')
SITE_DOMAIN="sotis.org"

# Автоопределение PHP
PHP_BIN=""
for p in "/opt/php84/bin/lsphp" "/opt/php83/bin/lsphp" "/usr/local/lsws/lsphp84/bin/lsphp" "/usr/local/bin/php" "/usr/bin/php"; do
    if [[ -x "$p" ]]; then
        PHP_BIN="$p"
        break
    fi
done
[[ -z "$PHP_BIN" ]] && PHP_BIN="php"

# Настройки БД
DB_NAME="${DB_NAME:-stockforecast}"
DB_USER="${DB_USER:-sf_user}"
DB_PASS="${DB_PASS:-MyStrongPass123!}"

# Цвета
C_OK="\033[32m"; C_WARN="\033[33m"; C_ERR="\033[31m"; C_INFO="\033[36m"; C_RST="\033[0m"
ERRORS=0; WARNINGS=0

ok()   { echo -e "  ${C_OK}✅ $1${C_RST}"; }
warn() { echo -e "  ${C_WARN}⚠️  $1${C_RST}"; WARNINGS=$((WARNINGS+1)); }
err()  { echo -e "  ${C_ERR}❌ $1${C_RST}"; ERRORS=$((ERRORS+1)); }
info() { echo -e "  ${C_INFO}ℹ️  $1${C_RST}"; }
head_section() { echo ""; echo -e "${C_INFO}── $1 ──${C_RST}"; }

echo "═══════════════════════════════════════════════════════════"
echo "  Проверка здоровья StockForecast (универсальная)"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "═══════════════════════════════════════════════════════════"
info "PROJECT_DIR: $PROJECT_DIR"
info "SITE_USER:   $SITE_USER"
info "SERVER_IP:   $SERVER_IP"
info "PHP_BIN:     $PHP_BIN"
echo ""

# ─── 1. Файлы и структура ───
head_section "1. Файлы и структура"
REQUIRED_FILES=(
    "index.php" "bootstrap.php" "config.php"
    "lib/synthetic.php" "lib/verify.php" "lib/screener.php"
    "pages/stock.php" "pages/home.php" "pages/screener.php"
    "pages/accuracy.php" "pages/signals.php"
    "scripts/cron/refresh_synthetic.php"
    "scripts/cron/refresh_screener.php"
    "scripts/cron/refresh_accuracy.php"
)
for f in "${REQUIRED_FILES[@]}"; do
    [[ -f "$PROJECT_DIR/$f" ]] && ok "$f" || err "$f — ОТСУТСТВУЕТ!"
done

# ─── 2. Права доступа ───
head_section "2. Права доступа"
OWNER=$(stat -c '%U' "$PROJECT_DIR")
[[ "$OWNER" == "$SITE_USER" ]] && ok "Владелец корня: $OWNER" || err "Владелец корня: $OWNER (ожидается $SITE_USER)"
for d in "data" "static/uploads"; do
    if [[ -d "$PROJECT_DIR/$d" ]]; then
        [[ -w "$PROJECT_DIR/$d" ]] && ok "$d доступна для записи" || err "$d НЕ доступна для записи"
    else
        warn "$d не существует"
    fi
done
[[ -f "$PROJECT_DIR/config.php" ]] && ok "config.php: владелец $(stat -c '%U' "$PROJECT_DIR/config.php")"

# ─── 3. Git-версия ───
head_section "3. Git-версия"
if [[ -d "$PROJECT_DIR/.git" ]]; then
    git config --global --add safe.directory "$PROJECT_DIR" 2>/dev/null || true
    cd "$PROJECT_DIR"
    COMMIT=$(git log -1 --format='%h — %s' 2>/dev/null || echo "нет коммитов")
    info "Последний коммит: $COMMIT"
    if git status --porcelain 2>/dev/null | grep -q .; then
        warn "Есть незакоммиченные изменения"
    else
        ok "Рабочее дерево чистое"
    fi
    git fetch origin main -q 2>/dev/null || true
    LOCAL_HASH=$(git rev-parse HEAD 2>/dev/null)
    REMOTE_HASH=$(git rev-parse origin/main 2>/dev/null)
    if [[ -n "$LOCAL_HASH" && -n "$REMOTE_HASH" && "$LOCAL_HASH" != "$REMOTE_HASH" ]]; then
        warn "Локальная версия отличается от GitHub"
    else
        ok "Версия соответствует GitHub"
    fi
else
    info "Папка .git отсутствует (развернуто через панель, это норма для тестового сервера)"
fi

# ─── 4. База данных ───
head_section "4. База данных"
DB_CHECK=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT 1;" 2>/dev/null || echo "FAIL")
if [[ "$DB_CHECK" == "1" ]]; then
    ok "Подключение к БД успешно"
    for t in "history_bars" "forecast_lines" "synthetic_components" "stock_map"; do
        COUNT=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT COUNT(*) FROM $t;" 2>/dev/null || echo "ERR")
        if [[ "$COUNT" == "ERR" ]]; then
            err "Таблица $t не найдена"
        elif [[ "$COUNT" -gt 0 ]]; then
            ok "Таблица $t: $COUNT записей"
        else
            warn "Таблица $t пуста"
        fi
    done
    COLLATION=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='synthetic_components';" 2>/dev/null || echo "ERR")
    [[ "$COLLATION" == "utf8mb4_unicode_ci" ]] && ok "Кодировка: $COLLATION" || warn "Кодировка: $COLLATION"
else
    err "Не удалось подключиться к БД!"
fi

# ─── 5. Кэши ───
head_section "5. Кэш-файлы"
for cf in "screener_cache.json" "accuracy_cache.json"; do
    FP="$PROJECT_DIR/data/$cf"
    if [[ -f "$FP" ]]; then
        SIZE=$(du -h "$FP" | awk '{print $1}')
        AGE_HOURS=$(( ($(date +%s) - $(stat -c %Y "$FP")) / 3600 ))
        if [[ "$AGE_HOURS" -gt 24 ]]; then
            warn "$cf ($SIZE, ${AGE_HOURS}ч назад — устарел)"
        else
            ok "$cf ($SIZE, ${AGE_HOURS}ч назад)"
        fi
    else
        err "$cf — ОТСУТСТВУЕТ"
    fi
done

# ─── 6. Cron-задачи ───
head_section "6. Cron-задачи"
if [[ -f /etc/cron.d/stockforecast ]]; then
    ok "Файл /etc/cron.d/stockforecast найден"
    TASKS=$(grep -cE "refresh_(synthetic|screener|accuracy)" /etc/cron.d/stockforecast 2>/dev/null || echo 0)
    [[ "$TASKS" -ge 3 ]] && ok "Настроено $TASKS задач" || warn "Найдено только $TASKS задач"
elif crontab -l 2>/dev/null | grep -q "refresh_synthetic"; then
    ok "Cron-задачи найдены в crontab пользователя"
else
    warn "Cron-задачи не найдены (ни в /etc/cron.d/, ни в crontab)"
fi

# ─── 7. Веб-сервер ───
head_section "7. Веб-сервер"
if systemctl is-active --quiet lsws 2>/dev/null; then
    ok "OpenLiteSpeed запущен"
elif systemctl is-active --quiet nginx 2>/dev/null; then
    ok "Nginx запущен (OLS не используется)"
elif systemctl is-active --quiet apache2 2>/dev/null; then
    ok "Apache2 запущен (OLS не используется)"
else
    err "Ни один веб-сервер не запущен!"
fi

# ─── 8. HTTP-проверка сайта ───
head_section "8. HTTP-проверка сайта"
if [[ -n "$SERVER_IP" ]]; then
    HTTP_CODE=$(curl -k -s -o /dev/null -w "%{http_code}" -H "Host: $SITE_DOMAIN" "https://$SERVER_IP/index.php" 2>/dev/null || echo "000")
else
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/index.php" 2>/dev/null || echo "000")
fi
if [[ "$HTTP_CODE" == "200" ]]; then
    ok "Главная страница: HTTP $HTTP_CODE"
elif [[ "$HTTP_CODE" == "000" ]]; then
    err "Главная страница: не отвечает"
else
    warn "Главная страница: HTTP $HTTP_CODE"
fi

# ─── 9. Логи ───
head_section "9. Логи ошибок"
for LOG in "/usr/local/lsws/logs/error.log" "/var/log/nginx/error.log" "/var/log/apache2/error.log"; do
    if [[ -f "$LOG" ]]; then
        RECENT=$(grep -iE "error|critical" "$LOG" 2>/dev/null | tail -3)
        if [[ -z "$RECENT" ]]; then
            ok "Ошибок в $LOG не найдено"
        else
            warn "В $LOG найдены записи:"
            echo "$RECENT" | while read -r line; do echo "     $line"; done
        fi
        break
    fi
done

# ─── Итог ───
echo ""
echo "═══════════════════════════════════════════════════════════"
if [[ "$ERRORS" -eq 0 && "$W
