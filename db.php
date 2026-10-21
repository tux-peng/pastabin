<?php
declare(strict_types=1);

const DB_DRIVERS = ['turso' => 'Turso / libSQL', 'sqlite' => 'SQLite (local file)', 'mysql' => 'MySQL / MariaDB'];

/** Current config; pass an array to replace it (used by the installer to test settings before saving). */
function config(?array $set = null): array {
    static $c = null;
    if ($set !== null) { $c = $set; db_pdo(true); }
    return $c ??= require __DIR__ . '/config.php';
}
function cfg(string $key) { return config()[$key] ?? null; }
function is_installed(): bool { return isset(DB_DRIVERS[cfg('db_driver')]); }

/**
 * Run one statement against the configured database.
 * Returns ['rows' => list<assoc>, 'affected' => int, 'last_id' => ?int]
 */
function db(string $sql, array $args = []): array {
    return cfg('db_driver') === 'turso' ? db_turso($sql, $args) : db_pdo_exec($sql, $args);
}

/** Shared PDO connection for sqlite/mysql; $reset drops it (returns null). */
function db_pdo(bool $reset = false): ?PDO {
    static $pdo = null;
    if ($reset) return $pdo = null;
    if ($pdo) return $pdo;

    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
    switch (cfg('db_driver')) {
        case 'sqlite':
            $path = (string)cfg('sqlite_path');
            if ($path === '') throw new RuntimeException('SQLite path is not set');
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0770, true)) {
                throw new RuntimeException('Cannot create directory ' . dirname($path));
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $opts);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            break;
        case 'mysql':
            // Report matched (not just changed) rows, like SQLite does. Constant was renamed in PHP 8.4.
            $opts[defined('Pdo\Mysql::ATTR_FOUND_ROWS') ? constant('Pdo\Mysql::ATTR_FOUND_ROWS') : PDO::MYSQL_ATTR_FOUND_ROWS] = true;
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', cfg('mysql_host'), cfg('mysql_port'), cfg('mysql_db'));
            $pdo = new PDO($dsn, (string)cfg('mysql_user'), (string)cfg('mysql_pass'), $opts);
            break;
        default:
            throw new RuntimeException('Database is not configured');
    }
    return $pdo;
}

function db_pdo_exec(string $sql, array $args): array {
    $pdo = db_pdo();
    $st  = $pdo->prepare($sql);
    foreach (array_values($args) as $i => $v) {
        if (is_bool($v)) $v = (int)$v;
        $st->bindValue($i + 1, $v, match (true) {
            $v === null => PDO::PARAM_NULL,
            is_int($v)  => PDO::PARAM_INT,
            default     => PDO::PARAM_STR,
        });
    }
    $st->execute();
    $rows = $st->columnCount() > 0 ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    $id   = (int)$pdo->lastInsertId();
    return ['rows' => $rows, 'affected' => $st->rowCount(), 'last_id' => $id ?: null];
}

function db_arg($v): array {
    if ($v === null)  return ['type' => 'null'];
    if (is_bool($v))  return ['type' => 'integer', 'value' => (string)(int)$v];
    if (is_int($v))   return ['type' => 'integer', 'value' => (string)$v];
    if (is_float($v)) return ['type' => 'float', 'value' => $v];
    return ['type' => 'text', 'value' => (string)$v];
}

/** Run one statement against Turso via the Hrana-over-HTTP pipeline endpoint. */
function db_turso(string $sql, array $args): array {
    $base = preg_replace('#^libsql://#', 'https://', rtrim((string)cfg('turso_url'), '/'));
    $body = json_encode([
        'requests' => [
            ['type' => 'execute', 'stmt' => ['sql' => $sql, 'args' => array_map('db_arg', array_values($args))]],
            ['type' => 'close'],
        ],
    ], JSON_THROW_ON_ERROR);

    $ch = curl_init($base . '/v2/pipeline');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . cfg('turso_token'),
            'Content-Type: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) throw new RuntimeException('Turso request failed: ' . curl_error($ch));
    curl_close($ch);
    if ($code !== 200) throw new RuntimeException("Turso HTTP $code: $raw");

    $res = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['results'][0] ?? null;
    if (!$res) throw new RuntimeException('Empty Turso response');
    if ($res['type'] === 'error') throw new RuntimeException($res['error']['message'] ?? 'Turso error');

    $r    = $res['response']['result'];
    $cols = array_column($r['cols'], 'name');
    $rows = [];
    foreach ($r['rows'] as $row) {
        $o = [];
        foreach ($row as $i => $cell) {
            $o[$cols[$i]] = match ($cell['type']) {
                'null'    => null,
                'integer' => (int)$cell['value'],
                'float'   => (float)$cell['value'],
                'blob'    => base64_decode($cell['base64'] ?? ''),
                default   => $cell['value'],
            };
        }
        $rows[] = $o;
    }
    return [
        'rows'     => $rows,
        'affected' => (int)($r['affected_row_count'] ?? 0),
        'last_id'  => isset($r['last_insert_rowid']) ? (int)$r['last_insert_rowid'] : null,
    ];
}

/** CREATE statements for the given driver (all idempotent). */
function db_schema(string $driver): array {
    if ($driver === 'mysql') {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(32) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                is_admin TINYINT NOT NULL DEFAULT 0,
                created_at BIGINT NOT NULL) $t",
            "CREATE TABLE IF NOT EXISTS pastes (
                id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(255),
                language VARCHAR(32) NOT NULL DEFAULT 'plaintext',
                content MEDIUMTEXT NOT NULL,
                visibility VARCHAR(16) NOT NULL DEFAULT 'unlisted',
                created_at BIGINT NOT NULL,
                expires_at BIGINT,
                INDEX idx_pastes_user (user_id, created_at)) $t",
            "CREATE TABLE IF NOT EXISTS invites (
                code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
                created_by INT NOT NULL,
                created_at BIGINT NOT NULL,
                expires_at BIGINT,
                used_by INT,
                used_at BIGINT) $t",
        ];
    }
    // Turso and SQLite share a dialect.
    return [
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL)",
        "CREATE TABLE IF NOT EXISTS pastes (
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            title TEXT,
            language TEXT NOT NULL DEFAULT 'plaintext',
            content TEXT NOT NULL,
            visibility TEXT NOT NULL DEFAULT 'unlisted',
            created_at INTEGER NOT NULL,
            expires_at INTEGER)",
        "CREATE INDEX IF NOT EXISTS idx_pastes_user ON pastes(user_id, created_at DESC)",
        "CREATE TABLE IF NOT EXISTS invites (
            code TEXT PRIMARY KEY,
            created_by INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER,
            used_by INTEGER,
            used_at INTEGER)",
    ];
}

function db_create_schema(): void {
    foreach (db_schema((string)cfg('db_driver')) as $sql) db($sql);
}
