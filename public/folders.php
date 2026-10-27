<?php
require __DIR__ . '/../lib.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $fid    = (int)($_POST['id'] ?? 0);
    $name   = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', (string)($_POST['name'] ?? '')) ?? ''), 0, 60);
    $taken  = fn() => (bool)db('SELECT 1 FROM folders WHERE user_id = ? AND name = ? AND id <> ?', [$u['id'], $name, $fid])['rows'];

    if ($action === 'create' || $action === 'rename') {
        if ($name === '')  flash('Folder name is required.');
        elseif ($taken())  flash("You already have a folder named \"$name\".");
        elseif ($action === 'create') {
            db('INSERT INTO folders(user_id,name,created_at) VALUES(?,?,?)', [$u['id'], $name, time()]);
            flash("Folder \"$name\" created.");
        } else {
            db('UPDATE folders SET name = ? WHERE id = ? AND user_id = ?', [$name, $fid, $u['id']]);
            flash('Folder renamed.');
        }
    } elseif ($action === 'delete') {
        // Pastes are kept; they just become unfiled.
        db('UPDATE pastes SET folder_id = NULL WHERE folder_id = ? AND user_id = ?', [$fid, $u['id']]);
        db('DELETE FROM folders WHERE id = ? AND user_id = ?', [$fid, $u['id']]);
        flash('Folder deleted. Its pastes are now unfiled.');
    }
    redirect('folders.php');
}

$folders = user_folders($u['id']);
layout_start('Folders');
?>
<form method="post" class="card row" style="align-items:end">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div><label>New folder</label><input type="text" name="name" maxlength="60" required></div>
  <div style="flex:0"><button>Create folder</button></div>
</form>

<div class="card"><strong>Your folders</strong>
<?php if (!$folders): ?><p class="mut">No folders yet. Create one above, then pick it when creating or editing a paste.</p><?php else: ?>
<table>
  <tr><th>Name</th><th>Pastes</th><th></th></tr>
  <?php foreach ($folders as $f): ?>
  <tr>
    <td><form method="post" style="margin:0;display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="rename">
      <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <input type="text" name="name" value="<?= h($f['name']) ?>" maxlength="60" required><button>Rename</button></form></td>
    <td><a href="index.php?folder=<?= (int)$f['id'] ?>"><?= (int)$f['n'] ?></a></td>
    <td><form method="post" style="margin:0" onsubmit="return confirm('Delete this folder? Its pastes are kept.')"><?= csrf_field() ?>
      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <button class="danger">Delete</button></form></td>
  </tr>
  <?php endforeach ?>
</table>
<?php endif ?>
</div>
<?php layout_end();
