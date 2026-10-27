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
// Upgrade databases created by older versions; checked once per session.
if (is_installed() && ($_SESSION['schema'] ?? 0) !== SCHEMA_VERSION) {
    db_upgrade();
    $_SESSION['schema'] = SCHEMA_VERSION;
}

const LANGS = ['plaintext','php','javascript','typescript','python','bash','sql','json','yaml','html','css','c','cpp','go','rust','java','markdown','diff'];
const TTLS  = [0 => 'Never', 3600 => '1 hour', 86400 => '1 day', 604800 => '1 week', 2592000 => '30 days'];

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

/** The user's folders with their count of live pastes, sorted by name. */
function user_folders(int $uid): array {
    return db('SELECT f.id, f.name, COUNT(p.id) AS n FROM folders f
               LEFT JOIN pastes p ON p.folder_id = f.id AND (p.expires_at IS NULL OR p.expires_at > ?)
               WHERE f.user_id = ? GROUP BY f.id, f.name ORDER BY f.name', [time(), $uid])['rows'];
}

/**
 * Validate the posted paste form (see paste_form()). $keepExpiry is the current expires_at when editing.
 * Returns [fields keyed by column name, error message or ''].
 */
function paste_input(int $uid, ?int $keepExpiry = null): array {
    $exp = (string)($_POST['expires'] ?? '0');
    $ttl = (int)$exp;
    $f = [
        'title'      => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 100) ?: null,
        'content'    => str_replace("\r\n", "\n", (string)($_POST['content'] ?? '')),
        'language'   => in_array($_POST['language'] ?? '', LANGS, true) ? $_POST['language'] : 'plaintext',
        'visibility' => ($_POST['visibility'] ?? '') === 'private' ? 'private' : 'unlisted',
        'folder_id'  => (int)($_POST['folder_id'] ?? 0) ?: null,
        'expires_at' => $exp === 'keep' ? $keepExpiry : ($ttl > 0 && isset(TTLS[$ttl]) ? time() + $ttl : null),
    ];
    if (trim($f['content']) === '')                     return [$f, 'Paste is empty.'];
    if (strlen($f['content']) > cfg('max_paste_bytes')) return [$f, 'Paste is too large.'];
    if ($f['folder_id'] && !db('SELECT 1 FROM folders WHERE id = ? AND user_id = ?', [$f['folder_id'], $uid])['rows']) {
        return [$f, 'That folder no longer exists.'];
    }
    return [$f, ''];
}

/** Fields of the create/edit paste form, prefilled from $p. $keepExpiry adds a "keep current expiry" choice. */
function paste_form(array $p, array $folders, ?int $keepExpiry = null): void {
    $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
    $exp = (string)($_POST['expires'] ?? ($keepExpiry ? 'keep' : '0'));
    ?>
  <label>Title (optional)</label><input type="text" name="title" maxlength="100" value="<?= h($p['title'] ?? '') ?>">
  <label>Content</label><textarea name="content" required spellcheck="false">
<?= h($p['content'] ?? '') ?></textarea>
  <div class="row">
    <div><label>Language</label><select name="language">
      <?php foreach (LANGS as $l) echo '<option' . $sel($l, $p['language'] ?? 'plaintext') . '>' . h($l) . '</option>'; ?></select></div>
    <div><label>Expires</label><select name="expires">
      <?php if ($keepExpiry): ?><option value="keep"<?= $sel('keep', $exp) ?>>Keep (<?= h(fmt_time($keepExpiry)) ?>)</option><?php endif ?>
      <?php foreach (TTLS as $s => $label) echo '<option value="' . $s . '"' . $sel($s, $exp) . '>' . h($keepExpiry && $s ? "$label from now" : $label) . '</option>'; ?></select></div>
    <div><label>Visibility</label><select name="visibility">
      <option value="unlisted">Unlisted (anyone with link)</option>
      <option value="private"<?= $sel('private', $p['visibility'] ?? '') ?>>Private (only you)</option></select></div>
    <?php if ($folders): ?>
    <div><label>Folder</label><select name="folder_id"><option value="">No folder</option>
      <?php foreach ($folders as $fo) echo '<option value="' . (int)$fo['id'] . '"' . $sel($fo['id'], $p['folder_id'] ?? '') . '>' . h($fo['name']) . '</option>'; ?></select></div>
    <?php endif ?>
  </div>
<?php
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
        echo '<a href="folders.php">Folders</a>';
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
