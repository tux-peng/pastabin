<?php
require __DIR__ . '/../lib.php';

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $id)) { http_response_code(404); exit('Not found'); }

$p = db('SELECT p.*, u.username, f.name AS folder_name FROM pastes p JOIN users u ON u.id = p.user_id
         LEFT JOIN folders f ON f.id = p.folder_id WHERE p.id = ?', [$id])['rows'][0] ?? null;
if ($p && $p['expires_at'] !== null && $p['expires_at'] <= time()) {
    db('DELETE FROM pastes WHERE id = ?', [$id]);
    $p = null;
}
$u = user();
if ($p && $p['visibility'] === 'private' && (!$u || ($u['id'] !== $p['user_id'] && !$u['is_admin']))) $p = null;
if (!$p) { http_response_code(404); exit('Not found'); }

$isOwner   = $u && $u['id'] === $p['user_id'];
$canDelete = $isOwner || ($u && $u['is_admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canDelete) {
    csrf_check();
    db('DELETE FROM pastes WHERE id = ?', [$id]);
    flash('Paste deleted.');
    redirect('index.php');
}

if (isset($_GET['raw'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    echo $p['content'];
    exit;
}

layout_start($p['title'] ?: $p['id'], true);
?>
<div class="card">
  <div class="row" style="align-items:center">
    <div><strong><?= h($p['title'] ?: 'Untitled') ?></strong>
      <div class="mut">by <?= h($p['username']) ?> · <?= h(fmt_time($p['created_at'])) ?> · <?= h($p['language']) ?> · <?= h($p['visibility']) ?>
        <?php if ($p['updated_at']): ?> · edited <?= h(fmt_time($p['updated_at'])) ?><?php endif ?>
        <?php if ($isOwner && $p['folder_name'] !== null): ?> · in <a href="index.php?folder=<?= (int)$p['folder_id'] ?>"><?= h($p['folder_name']) ?></a><?php endif ?>
        <?php if ($p['expires_at']): ?> · expires <?= h(fmt_time($p['expires_at'])) ?><?php endif ?></div></div>
    <div style="flex:0;white-space:nowrap"><?php if ($isOwner): ?><a href="edit.php?id=<?= h($id) ?>">edit</a> · <?php endif ?><a href="view.php?id=<?= h($id) ?>&raw=1">raw</a></div>
  </div>
</div>
<pre><code class="language-<?= h($p['language']) ?>"><?= h($p['content']) ?></code></pre>
<?php if ($canDelete): ?>
<form method="post" style="margin-top:16px" onsubmit="return confirm('Delete this paste?')">
  <?= csrf_field() ?><button class="danger">Delete</button>
</form>
<?php endif;
layout_end(true);
