<?php
// One-time web installer: picks a database, creates the schema and the first admin, then writes
// ../config.local.php. Locks itself once a database is configured (file or env vars).
require __DIR__ . '/../lib.php';
if (is_installed()) { http_response_code(404); exit('Already installed.'); }

$root      = dirname(__DIR__);
$localFile = $root . '/config.local.php';
$needs     = ['turso' => 'curl', 'sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql'];
$c         = config();
$in        = fn(string $k, $default = '') => trim((string)($_POST[$k] ?? $default));
$driver    = $in('db_driver', array_key_first(array_filter($needs, 'extension_loaded')) ?? 'sqlite');
$err       = '';
$manual    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new   = ['db_driver' => $driver, 'site_name' => $in('site_name', 'Pasta') ?: 'Pasta'];
    $admin = $in('admin_user');
    $pw    = (string)($_POST['admin_pass'] ?? '');

    if (!isset(DB_DRIVERS[$driver]))               $err = 'Pick a database.';
    elseif (!extension_loaded($needs[$driver]))    $err = "PHP extension '{$needs[$driver]}' is required for " . DB_DRIVERS[$driver] . '.';
    elseif ($driver === 'turso') {
        $new += ['turso_url' => $in('turso_url'), 'turso_token' => $in('turso_token')];
        if (!preg_match('#^(libsql|https)://[^/\s]+#', $new['turso_url'])) $err = 'Turso URL should look like libsql://db-org.turso.io';
        elseif ($new['turso_token'] === '')                                $err = 'Turso auth token is required.';
    } elseif ($driver === 'sqlite') {
        $path = $in('sqlite_path');
        if ($path !== '' && $path[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) $path = $root . '/' . $path;
        $new['sqlite_path'] = $path;
        if ($path === '') $err = 'SQLite file path is required.';
        elseif (str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', __DIR__) . '/')) $err = 'Keep the SQLite file outside public/ or it can be downloaded.';
    } else {
        $new += ['mysql_host' => $in('mysql_host'), 'mysql_port' => (int)$in('mysql_port', '3306'),
                 'mysql_db' => $in('mysql_db'), 'mysql_user' => $in('mysql_user'), 'mysql_pass' => (string)($_POST['mysql_pass'] ?? '')];
        if ($new['mysql_host'] === '' || $new['mysql_db'] === '' || $new['mysql_user'] === '') $err = 'MySQL host, database and user are required.';
        elseif ($new['mysql_port'] < 1 || $new['mysql_port'] > 65535)                           $err = 'Invalid MySQL port.';
    }
    if (!$err && $admin !== '') {
        if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $admin)) $err = 'Admin username must be 3-32 chars: letters, digits, _ or -.';
        elseif (strlen($pw) < 10)                            $err = 'Admin password must be at least 10 characters.';
        elseif ($pw !== (string)($_POST['admin_pass2'] ?? '')) $err = 'Admin passwords do not match.';
    }

    if (!$err) {
        try {
            config(array_merge($c, $new));
            db_create_schema();
            if ($admin === '' && !db('SELECT 1 FROM users LIMIT 1')['rows']) {
                $err = 'The database has no users yet, so an admin account is required.';
            } elseif ($admin !== '') {
                if (db('SELECT 1 FROM users WHERE username = ?', [$admin])['rows']) {
                    $err = 'That username already exists in this database. Leave the admin fields empty to keep existing accounts.';
                } else {
                    db('INSERT INTO users(username,password_hash,is_admin,created_at) VALUES(?,?,1,?)',
                       [$admin, password_hash($pw, PASSWORD_DEFAULT), time()]);
                }
            }
        } catch (Throwable $e) {
            $err = 'Database error: ' . $e->getMessage();
        }
        config($c);
    }

    if (!$err) {
        $php = "<?php\n// Written by public/install.php on " . gmdate('Y-m-d H:i') . " UTC. Delete this file to re-run the installer.\nreturn "
             . var_export($new, true) . ";\n";
        if (@file_put_contents($localFile, $php, LOCK_EX) !== false) {
            @chmod($localFile, 0640);
            flash('Installation complete. Log in with your admin account.');
            redirect('login.php');
        }
        $manual = $php;
    }
}

$val = fn(string $k) => h($_POST[$k] ?? $c[$k] ?? '');
layout_start('Install');
?>
<style>.drv{display:none}form:has(#d-turso:checked) .drv-turso,form:has(#d-sqlite:checked) .drv-sqlite,form:has(#d-mysql:checked) .drv-mysql{display:block}
.pick label{display:inline-flex;gap:6px;align-items:center;margin-right:18px;color:inherit;font-size:15px}</style>

<?php if ($manual !== null): ?>
<div class="card">
  <strong>Almost done</strong>
  <p>The database is ready, but <code><?= h($localFile) ?></code> is not writable by the web server.
     Save the following as that file, then <a href="login.php">log in</a>.</p>
  <textarea readonly style="min-height:200px"><?= h($manual) ?></textarea>
</div>
<?php else: ?>
<form method="post" class="card" style="max-width:560px;margin:24px auto">
  <?= csrf_field() ?><strong>Install <?= h(cfg('site_name')) ?></strong>
  <p class="mut">Choose where pastes are stored and create the first admin account. This page disappears once installation finishes.</p>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif ?>

  <label>Site name</label><input type="text" name="site_name" value="<?= $val('site_name') ?>" maxlength="60">

  <label>Database</label>
  <div class="pick">
  <?php foreach (DB_DRIVERS as $k => $name): $ok = extension_loaded($needs[$k]); ?>
    <label><input type="radio" name="db_driver" id="d-<?= $k ?>" value="<?= $k ?>" <?= $driver === $k ? 'checked' : '' ?> <?= $ok ? '' : 'disabled' ?>>
      <?= h($name) ?><?php if (!$ok): ?> <span class="mut">(needs <?= h($needs[$k]) ?>)</span><?php endif ?></label>
  <?php endforeach ?>
  </div>

  <div class="drv drv-turso">
    <label>Database URL</label><input type="text" name="turso_url" value="<?= $val('turso_url') ?>" placeholder="libsql://pasta-myorg.turso.io">
    <label>Auth token</label><input type="password" name="turso_token" autocomplete="off">
    <p class="mut">From <code>turso db show pasta --url</code> and <code>turso db tokens create pasta</code>.</p>
  </div>
  <div class="drv drv-sqlite">
    <label>Database file</label><input type="text" name="sqlite_path" value="<?= $val('sqlite_path') ?>">
    <p class="mut">Created if missing. The web server needs write access to the file and its directory. Relative paths are relative to <code><?= h($root) ?></code>.</p>
  </div>
  <div class="drv drv-mysql">
    <div class="row">
      <div style="flex:3"><label>Host</label><input type="text" name="mysql_host" value="<?= $val('mysql_host') ?>"></div>
      <div><label>Port</label><input type="text" name="mysql_port" value="<?= $val('mysql_port') ?>" inputmode="numeric"></div>
    </div>
    <label>Database name</label><input type="text" name="mysql_db" value="<?= $val('mysql_db') ?>">
    <div class="row">
      <div><label>User</label><input type="text" name="mysql_user" value="<?= $val('mysql_user') ?>" autocomplete="off"></div>
      <div><label>Password</label><input type="password" name="mysql_pass" autocomplete="off"></div>
    </div>
    <p class="mut">The database must already exist; tables are created automatically.</p>
  </div>

  <label>Admin username</label><input type="text" name="admin_user" value="<?= h($_POST['admin_user'] ?? '') ?>" autocomplete="username">
  <div class="row">
    <div><label>Admin password</label><input type="password" name="admin_pass" autocomplete="new-password"></div>
    <div><label>Repeat password</label><input type="password" name="admin_pass2" autocomplete="new-password"></div>
  </div>
  <p class="mut">Leave the admin fields empty only when connecting to a database that already has accounts.</p>
  <p><button>Install</button></p>
</form>
<?php endif;
layout_end();
