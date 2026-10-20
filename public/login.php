<?php
require __DIR__ . '/../lib.php';
if (user()) redirect('index.php');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim((string)($_POST['username'] ?? ''));
    $row  = db('SELECT id, password_hash FROM users WHERE username = ?', [$name])['rows'][0] ?? null;
    // Always run a verify to keep timing similar for unknown users.
    $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    if (password_verify((string)($_POST['password'] ?? ''), $hash) && $row) {
        login_user($row['id']);
        redirect('index.php');
    }
    $err = 'Invalid username or password.';
    usleep(300000);
}
layout_start('Log in');
?>
<form method="post" class="card" style="max-width:380px;margin:40px auto">
  <?= csrf_field() ?><strong>Log in</strong>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif ?>
  <label>Username</label><input type="text" name="username" autocomplete="username" required>
  <label>Password</label><input type="password" name="password" autocomplete="current-password" required>
  <p><button>Log in</button></p>
  <p class="mut">Have an invite? <a href="register.php">Sign up</a></p>
</form>
<?php layout_end();
