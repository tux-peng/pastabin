<?php
require __DIR__ . '/../lib.php';
$u = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'create') {
        $days = (int)($_POST['days'] ?? 0);
        $now  = time();
        $code = bin2hex(random_bytes(10));
        db('INSERT INTO invites(code,created_by,created_at,expires_at) VALUES(?,?,?,?)',
           [$code, $u['id'], $now, $days > 0 ? $now + $days * 86400 : null]);
        flash("New invite: $code");
    } elseif (($_POST['action'] ?? '') === 'revoke') {
        db('DELETE FROM invites WHERE code = ? AND used_by IS NULL', [(string)($_POST['code'] ?? '')]);
        flash('Invite revoked.');
    }
    redirect('invites.php');
}

$rows = db('SELECT i.*, c.username AS creator, d.username AS used_name
            FROM invites i
            LEFT JOIN users c ON c.id = i.created_by
            LEFT JOIN users d ON d.id = i.used_by
            ORDER BY i.created_at DESC LIMIT 100')['rows'];

layout_start('Invites');
?>
<form method="post" class="card row" style="align-items:end">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div><label>Expires after</label><select name="days">
    <option value="0">Never</option><option value="1">1 day</option><option value="7" selected>7 days</option><option value="30">30 days</option></select></div>
  <div style="flex:0"><button>Generate invite</button></div>
</form>

<div class="card"><strong>Invites</strong>
<table>
  <tr><th>Code</th><th>Status</th><th>Created</th><th></th></tr>
  <?php foreach ($rows as $i):
      $expired = $i['expires_at'] !== null && $i['expires_at'] <= time();
      $status = $i['used_by'] ? 'used by ' . h($i['used_name'] ?? '?') : ($expired ? 'expired' : 'unused'); ?>
  <tr>
    <td><?php if (!$i['used_by'] && !$expired): ?><a href="register.php?invite=<?= h($i['code']) ?>"><code><?= h($i['code']) ?></code></a>
        <?php else: ?><code class="mut"><?= h($i['code']) ?></code><?php endif ?></td>
    <td><?= $status ?></td>
    <td class="mut"><?= h(fmt_time($i['created_at'])) ?></td>
    <td><?php if (!$i['used_by']): ?>
      <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="revoke">
        <input type="hidden" name="code" value="<?= h($i['code']) ?>"><button class="danger">Revoke</button></form>
    <?php endif ?></td>
  </tr>
  <?php endforeach ?>
</table></div>
<?php layout_end();
