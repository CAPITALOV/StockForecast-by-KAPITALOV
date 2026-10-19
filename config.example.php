<?php
// ================== БАЗА ДАННЫХ ==================
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'stockforecast');
define('DB_USER', 'sf_user');
define('DB_PASS', 'change-me');
define('DB_CHARSET', 'utf8mb4');

// ================== ПРОЧЕЕ ==================
define('SESSION_NAME', 'sf_session');
define('DEFAULT_DAYS', 365);
define('MOEX_CACHE_TTL', 86400);

// ================== TELEGRAM ==================
// Оставьте пустыми, если уведомления не нужны
define('TELEGRAM_BOT_TOKEN', '');
define('TELEGRAM_CHAT_ID', '');

// ================== NEWS ==================
// Ключ Eulerpool (бесплатный, 100k запросов/мес).
// Регистрация: https://eulerpool.com/
// Если оставить пустым — будут только новости Yahoo Finance.
define('EULERPOOL_KEY', '');