<?php
require __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli') {
    ini_set('session.name', SESSION_NAME);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
        }
        db_init($pdo);
    }
    return $pdo;
}

function db_init($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS forecast_lines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            market VARCHAR(16) NOT NULL,
            ticker VARCHAR(32) NOT NULL,
            line_num TINYINT UNSIGNED NOT NULL,
            value_type VARCHAR(10) NOT NULL DEFAULT 'price',
            content MEDIUMTEXT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_market_ticker_line (market, ticker, line_num),
            KEY idx_market_ticker (market, ticker)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_map (
            id INT PRIMARY KEY,
            market VARCHAR(16) NOT NULL,
            ticker VARCHAR(32) NOT NULL,
            name VARCHAR(255) DEFAULT NULL,
            KEY idx_market_ticker (market, ticker)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS history_bars (
            stock_id INT NOT NULL,
            ts DATE NOT NULL,
            close DECIMAL(18,6) NOT NULL,
            source VARCHAR(16) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (stock_id, ts),
            KEY idx_ts (ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(64) PRIMARY KEY,
            `value` VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Таблица для анонсов и новостей проекта
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Миграции для forecast_lines
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM forecast_lines")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('value_type', $cols)) {
            $pdo->exec("ALTER TABLE forecast_lines ADD COLUMN value_type VARCHAR(10) NOT NULL DEFAULT 'price' AFTER line_num");
        }
    } catch (PDOException $e) {}

    // Миграции для stock_map
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM stock_map")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('history_skip_until', $cols)) {
            $pdo->exec("ALTER TABLE stock_map ADD COLUMN history_skip_until DATETIME NULL");
        }
    } catch (PDOException $e) {}

    // Миграция для OHLC (Свечи)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM history_bars")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('open', $cols)) {
            $pdo->exec("ALTER TABLE history_bars ADD COLUMN open DECIMAL(18,6) NULL AFTER ts");
        }
        if (!in_array('high', $cols)) {
            $pdo->exec("ALTER TABLE history_bars ADD COLUMN high DECIMAL(18,6) NULL AFTER open");
        }
        if (!in_array('low', $cols)) {
            $pdo->exec("ALTER TABLE history_bars ADD COLUMN low DECIMAL(18,6) NULL AFTER high");
        }
    } catch (PDOException $e) {}

    // Дефолтный админ
    $c = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    if ($c === 0) {
        $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash) VALUES (?, ?)");
        $stmt->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);
    }
}

// ================== HELPERS ==================
function url($r, $params = []) {
    return 'index.php?' . http_build_query(array_merge(['r' => $r], $params));
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function flash($msg) { $_SESSION['flash'] = $msg; }
function get_flash() {
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function is_admin() { return !empty($_SESSION['admin']); }
function require_admin() {
    if (!is_admin()) {
        header('Location: ' . url('admin-login'));
        exit;
    }
}

function tolower($s) {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function get_setting($key, $default = null) {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = db()->prepare("SELECT value FROM settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $cache[$key] = ($val !== false) ? $val : $default;
        return $cache[$key];
    } catch (Throwable $e) {
        return $default;
    }
}

function set_setting($key, $value) {
    try {
        $stmt = db()->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $stmt->execute([$key, $value]);
    } catch (Throwable $e) {}
}