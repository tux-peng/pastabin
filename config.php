<?php
// Set these as environment variables (php-fpm pool env, Apache SetEnv, or a .env loader of your choice).
return [
    'turso_url'       => getenv('TURSO_URL') ?: '',    // e.g. libsql://mydb-myorg.turso.io
    'turso_token'     => getenv('TURSO_TOKEN') ?: '',  // turso db tokens create mydb
    'site_name'       => 'Pasta',
    'max_paste_bytes' => 512 * 1024,
];
