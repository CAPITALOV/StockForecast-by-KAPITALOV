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

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM forecast_lines")
                    ->fetchAll(PDO::FETCH_COLUMN);
        if ($cols && in_array('line_num', $cols)
                   && !in_array('ticker', $cols)) {
            $pdo->exec("DROP TABLE forecast_lines");
            $pdo->exec("
                CREATE TABLE forecast_lines (
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
        } else {
            if (!in_array('value_type', $cols)) {
                $pdo->exec("ALTER TABLE forecast_lines
                            ADD COLUMN value_type VARCHAR(10) NOT NULL DEFAULT 'price'
                            AFTER line_num");
            }
        }
    } catch (PDOException $e) {}

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM stock_map")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('history_skip_until', $cols)) {
            $pdo->exec("ALTER TABLE stock_map ADD COLUMN history_skip_until DATETIME NULL");
        }
    } catch (PDOException $e) {}

    $c = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    if ($c === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO admins (username, password_hash) VALUES (?, ?)"
        );
        $stmt->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);
    }
}

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