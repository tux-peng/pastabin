<?php
// CLI alternative to the web installer (public/install.php):  php setup.php [admin_username]
// Uses the existing config (config.local.php and/or env vars). Creates tables, and optionally
// the first admin account (needed since signup is invite-only).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require __DIR__ . '/db.php';

if (!is_installed()) exit("No database configured. Use the web installer or set DB_DRIVER and friends (see README).\n");
db_create_schema();
echo "Schema ready (" . DB_DRIVERS[cfg('db_driver')] . ").\n";

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
