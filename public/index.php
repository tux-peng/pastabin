<?php
require __DIR__ . '/../lib.php';
$u = require_login();
$err = '';
$folders = user_folders($u['id']);

// Folder filter: '' = all, 'none' = unfiled, otherwise a folder id.
$filter = (string)($_GET['folder'] ?? '');
$names  = array_column($folders, 'name', 'id');
if ($filter !== 'none' && !isset($names[(int)$filter])) $filter = '';
$p = ['folder_id' => $filter !== '' && $filter !== 'none' ? (int)$filter : null];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$p, $err] = paste_input($u['id']);
    if (!$err) {
        $now = time();
        for ($try = 0; $try < 3; $try++) {
            $id = random_id();
            try {
                db('INSERT INTO pastes(id,user_id,folder_id,title,language,content,visibility,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?)',
                   [$id, $u['id'], $p['folder_id'], $p['title'], $p['language'], $p['content'], $p['visibility'], $now, $p['expires_at']]);
                redirect('view.php?id=' . $id);
            } catch (RuntimeException $e) { if ($try === 2) $err = 'Could not save paste.'; }
        }
    }
}

$where = 'user_id=? AND (expires_at IS NULL OR expires_at>?)';
$args  = [$u['id'], time()];
if ($filter === 'none') $where .= ' AND folder_id IS NULL';
elseif ($filter !== '') { $where .= ' AND folder_id=?'; $args[] = (int)$filter; }
$mine = db("SELECT id,title,language,visibility,folder_id,created_at,updated_at FROM pastes
            WHERE $where ORDER BY created_at DESC LIMIT 100", $args)['rows'];

$tab = fn(string $value, string $label) => $filter === $value
    ? '<strong>' . h($label) . '</strong>'
    : '<a href="index.php' . ($value === '' ? '' : '?folder=' . h($value)) . '">' . h($label) . '</a>';

layout_start('New paste');
?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif ?>
  <?php paste_form($p, $folders); ?>
  <p><button>Create paste</button></p>
</form>

<div class="card">
  <div class="row" style="align-items:baseline">
    <div><strong>Your pastes</strong><?php if ($filter !== ''): ?> <span class="mut">in <?= h($filter === 'none' ? 'no folder' : $names[(int)$filter]) ?></span><?php endif ?></div>
    <div style="flex:0;white-space:nowrap"><a href="folders.php">Manage folders</a></div>
  </div>
  <p class="mut" style="display:flex;gap:14px;flex-wrap:wrap">
    <?= $tab('', 'All') ?><?= $tab('none', 'Unfiled') ?>
    <?php foreach ($folders as $f) echo $tab((string)$f['id'], $f['name']) . ' <span class="mut">(' . (int)$f['n'] . ')</span>'; ?>
  </p>
  <?php if (!$mine): ?><p class="mut">No pastes here yet.</p><?php else: ?>
  <table><?php foreach ($mine as $m): ?>
    <tr><td><a href="view.php?id=<?= h($m['id']) ?>"><?= h($m['title'] ?: $m['id']) ?></a></td>
        <?php if ($filter === ''): ?><td class="mut"><?= h($names[(int)$m['folder_id']] ?? '') ?></td><?php endif ?>
        <td class="mut"><?= h($m['language']) ?></td><td class="mut"><?= h($m['visibility']) ?></td>
        <td class="mut"><?= h(fmt_time($m['updated_at'] ?? $m['created_at'])) ?></td>
        <td><a href="edit.php?id=<?= h($m['id']) ?>">edit</a></td></tr>
  <?php endforeach ?></table>
  <?php endif ?>
</div>
<?php
layout_end();
