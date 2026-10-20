<?php
require __DIR__ . '/../lib.php';
if (user()) redirect('index.php');
$err = '';
$code = trim((string)($_POST['invite'] ?? $_GET['invite'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim((string)($_POST['username'] ?? ''));
    $pw   = (string)($_POST['password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $name))      $err = 'Username must be 3-32 chars: letters, digits, _ or -.';
    elseif (strlen($pw) < 10)                                $err = 'Password must be at least 10 characters.';
    elseif ($pw !== (string)($_POST['password2'] ?? ''))     $err = 'Passwords do not match.';
    elseif (db('SELECT 1 FROM users WHERE username = ?', [$name])['rows']) $err = 'Username is taken.';
    else {
        $now = time();
        // Atomically claim the invite (single use). -1 is a temporary sentinel.
        $claim = db('UPDATE invites SET used_by = -1, used_at = ?
                     WHERE code = ? AND used_by IS NULL AND (expires_at IS NULL OR expires_at > ?)', [$now, $code, $now]);
        if ($claim['affected'] !== 1) {
            $err = 'Invalid, used, or expired invite code.';
        } else {
            try {
                $ins = db('INSERT INTO users(username,password_hash,is_admin,created_at) VALUES(?,?,0,?)',
                          [$name, password_hash($pw, PASSWORD_DEFAULT), $now]);
                db('UPDATE invites SET used_by = ? WHERE code = ?', [$ins['last_id'], $code]);
                login_user($ins['last_id']);
                redirect('index.php');
            } catch (RuntimeException $e) {
                db('UPDATE invites SET used_by = NULL, used_at = NULL WHERE code = ? AND used_by = -1', [$code]);
                $err = 'Could not create account (username taken?).';
            }
        }
    }
}
layout_start('Sign up');
?>
<form method="post" class="card" style="max-width:380px;margin:40px auto">
  <?= csrf_field() ?><strong>Sign up (invite only)</strong>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif ?>
  <label>Invite code</label><input type="text" name="invite" value="<?= h($code) ?>" required autocomplete="off">
  <label>Username</label><input type="text" name="username" value="<?= h($_POST['username'] ?? '') ?>" autocomplete="username" required>
  <label>Password</label><input type="password" name="password" autocomplete="new-password" required>
  <label>Repeat password</label><input type="password" name="password2" autocomplete="new-password" required>
  <p><button>Create account</button></p>
</form>
<?php layout_end();
