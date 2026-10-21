<?php
// Settings come from config.local.php (written by public/install.php), overridden by any
// non-empty environment variables below (php-fpm pool env, Apache SetEnv, or a .env loader).
$c = array_merge([
    'db_driver'       => '',                  // turso | sqlite | mysql
    'turso_url'       => '',                  // e.g. libsql://mydb-myorg.turso.io
    'turso_token'     => '',                  // turso db tokens create mydb
    'sqlite_path'     => __DIR__ . '/data/pasta.sqlite',
    'mysql_host'      => '127.0.0.1',
    'mysql_port'      => 3306,
    'mysql_db'        => 'pasta',
    'mysql_user'      => '',
    'mysql_pass'      => '',
    'site_name'       => 'Pasta',
    'max_paste_bytes' => 512 * 1024,
], is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : []);

foreach ([
    'db_driver' => 'DB_DRIVER', 'turso_url' => 'TURSO_URL', 'turso_token' => 'TURSO_TOKEN',
    'sqlite_path' => 'SQLITE_PATH', 'mysql_host' => 'MYSQL_HOST', 'mysql_port' => 'MYSQL_PORT',
    'mysql_db' => 'MYSQL_DB', 'mysql_user' => 'MYSQL_USER', 'mysql_pass' => 'MYSQL_PASS',
] as $key => $var) {
    if (($v = getenv($var)) !== false && $v !== '') $c[$key] = $v;
}
// Backwards compatibility: TURSO_URL alone used to be the whole configuration.
if ($c['db_driver'] === '' && $c['turso_url'] !== '') $c['db_driver'] = 'turso';
$c['mysql_port'] = (int)$c['mysql_port'];
return $c;
