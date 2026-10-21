<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('pasta_sid');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if (!is_installed() && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
    header('Location: install.php'); exit;
}

const LANGS = ['plaintext','php','javascript','typescript','python','bash','sql','json','yaml','html','css','c','cpp','go','rust','java','markdown','diff'];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function fmt_time(int $t): string { return gmdate('Y-m-d H:i', $t) . ' UTC'; }

function user(): ?array {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid']) && is_installed()) {
            $u = db('SELECT id, username, is_admin FROM users WHERE id = ?', [(int)$_SESSION['uid']])['rows'][0] ?? null;
            if (!$u) { session_destroy(); }
        }
    }
    return $u;
}
function require_login(): array { $u = user(); if (!$u) redirect('login.php'); return $u; }
function require_admin(): array { $u = require_login(); if (!$u['is_admin']) { http_response_code(403); exit('Forbidden'); } return $u; }

function login_user(int $id): void { session_regenerate_id(true); $_SESSION['uid'] = $id; }

function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Bad CSRF token'); }
}

function flash(?string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m;
}

function random_id(int $len = 10): string {
    $a = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return $s;
}

function layout_start(string $title, bool $hljs = false): void {
    $u = user(); $site = cfg('site_name'); $f = flash();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . ' · ' . h($site) . '</title><style>
:root{color-scheme:light dark;--bg:#fafafa;--fg:#1a1a1a;--mut:#6b7280;--card:#fff;--bd:#e5e7eb;--ac:#2563eb}
@media(prefers-color-scheme:dark){:root{--bg:#0f1115;--fg:#e5e7eb;--mut:#9ca3af;--card:#171a21;--bd:#2a2f3a;--ac:#60a5fa}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,sans-serif}
header,main{max-width:860px;margin:0 auto;padding:14px 16px}header{display:flex;gap:16px;align-items:center;border-bottom:1px solid var(--bd)}
header .sp{flex:1}a{color:var(--ac);text-decoration:none}a:hover{text-decoration:underline}
.card{background:var(--card);border:1px solid var(--bd);border-radius:10px;padding:16px;margin-bottom:16px}
input,select,textarea,button{font:inherit;color:inherit;background:var(--card);border:1px solid var(--bd);border-radius:6px;padding:8px 10px}
textarea{width:100%;min-height:340px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px}
input[type=text],input[type=password]{width:100%}label{display:block;margin:10px 0 4px;color:var(--mut);font-size:13px}
button{background:var(--ac);color:#fff;border-color:var(--ac);cursor:pointer}button.danger{background:#dc2626;border-color:#dc2626}
.row{display:flex;gap:12px;flex-wrap:wrap}.row>*{flex:1;min-width:140px}.mut{color:var(--mut);font-size:13px}
.flash{background:#fef3c7;color:#78350f;border-radius:6px;padding:8px 12px;margin-bottom:12px}.err{color:#dc2626;margin-bottom:8px}
pre{margin:0;overflow:auto;border-radius:8px}pre code{padding:14px!important;font-size:13px}
table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:6px 8px;border-bottom:1px solid var(--bd);font-size:14px}
</style>';
    if ($hljs) echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">';
    echo '</head><body><header><strong><a href="index.php">' . h($site) . '</a></strong><span class="sp"></span>';
    if ($u) {
        if ($u['is_admin']) echo '<a href="invites.php">Invites</a>';
        echo '<span class="mut">' . h($u['username']) . '</span><a href="logout.php">Log out</a>';
    } else {
        echo '<a href="login.php">Log in</a><a href="register.php">Sign up</a>';
    }
    echo '</header><main>';
    if ($f) echo '<div class="flash">' . h($f) . '</div>';
}
function layout_end(bool $hljs = false): void {
    echo '</main>';
    if ($hljs) echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script><script>hljs.highlightAll()</script>';
    echo '</body></html>';
}
