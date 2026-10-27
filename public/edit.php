<?php
require __DIR__ . '/../lib.php';
$u = require_login();

// Only the owner can edit (admins can still delete from view.php).
$id = (string)($_GET['id'] ?? '');
$p  = preg_match('/^[A-Za-z0-9]{6,32}$/', $id)
    ? db('SELECT * FROM pastes WHERE id = ? AND user_id = ? AND (expires_at IS NULL OR expires_at > ?)', [$id, $u['id'], time()])['rows'][0] ?? null
    : null;
if (!$p) { http_response_code(404); exit('Not found'); }
$keep = $p['expires_at'];
$err  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$f, $err] = paste_input($u['id'], $keep);
    if (!$err) {
        db('UPDATE pastes SET title=?, content=?, language=?, visibility=?, folder_id=?, expires_at=?, updated_at=? WHERE id=? AND user_id=?',
           [$f['title'], $f['content'], $f['language'], $f['visibility'], $f['folder_id'], $f['expires_at'], time(), $id, $u['id']]);
        flash('Paste updated.');
        redirect('view.php?id=' . $id);
    }
    $p = array_merge($p, $f);
}

layout_start('Edit ' . ($p['title'] ?: $id));
?>
<form method="post" class="card">
  <?= csrf_field() ?><strong>Edit paste</strong>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif ?>
  <?php paste_form($p, user_folders($u['id']), $keep); ?>
  <p><button>Save changes</button> <a href="view.php?id=<?= h($id) ?>" style="margin-left:10px">Cancel</a></p>
</form>
<?php layout_end();
