<?php
// CLI only:  php setup.php [admin_username]
// Creates tables, and optionally the first admin account (needed since signup is invite-only).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require __DIR__ . '/db.php';

$schema = [
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
foreach ($schema as $sql) db($sql);
echo "Schema ready.\n";

if (!empty($argv[1])) {
    $name = $argv[1];
    if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $name)) exit("Invalid username\n");
    echo "Password for $name (min 10 chars): ";
    @system('stty -echo 2>/dev/null');
    $pw = trim((string)fgets(STDIN));
    @system('stty echo 2>/dev/null');
    echo "\n";
    if (strlen($pw) < 10) exit("Password too short\n");
    db('INSERT INTO users(username,password_hash,is_admin,created_at) VALUES(?,?,1,?)',
       [$name, password_hash($pw, PASSWORD_DEFAULT), time()]);
    echo "Admin '$name' created.\n";
}
