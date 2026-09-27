<?php
declare(strict_types=1);

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = dirname(__DIR__) . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('config.php fehlt – bitte aus config.sample.php anlegen.');
        }
        $cfg = require $file;
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = config();
    $dsn = $cfg['db_dsn'];
    if (str_starts_with($dsn, 'sqlite:')) {
        $dir = dirname(substr($dsn, 7));
        if (!is_dir($dir)) mkdir($dir, 0700, true);
    }
    $pdo = new PDO($dsn, $cfg['db_user'] ?? null, $cfg['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    ensure_schema($pdo);
    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM login_attempts LIMIT 1');
        return;
    } catch (PDOException) {
        // Tabellen fehlen -> anlegen
    }

    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $stmts = [
        "CREATE TABLE IF NOT EXISTS users (
            id $pk,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL
        )$tail",
        "CREATE TABLE IF NOT EXISTS trips (
            id $pk,
            owner_user_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
        )$tail",
        "CREATE TABLE IF NOT EXISTS participants (
            id $pk,
            trip_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            nights INT NOT NULL DEFAULT 0,
            token CHAR(32) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            is_owner SMALLINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
        )$tail",
        "CREATE TABLE IF NOT EXISTS expenses (
            id $pk,
            trip_id INT NOT NULL,
            participant_id INT NOT NULL,
            description VARCHAR(200) NOT NULL,
            amount_cents INT NOT NULL,
            expense_date DATE NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
            FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
        )$tail",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id $pk,
            k VARCHAR(190) NOT NULL,
            ts INT NOT NULL
        )$tail",
        "CREATE INDEX idx_login_attempts_k ON login_attempts (k)",
    ];
    foreach ($stmts as $sql) {
        $pdo->exec($sql);
    }
}

function now(): string
{
    return date('Y-m-d H:i:s');
}
