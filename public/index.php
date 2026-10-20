<?php
require __DIR__ . '/../lib.php';
$u = require_login();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $content = str_replace("\r\n", "\n", (string)($_POST['content'] ?? ''));
    $title   = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 100);
    $lang    = in_array($_POST['language'] ?? '', LANGS, true) ? $_POST['language'] : 'plaintext';
    $vis     = ($_POST['visibility'] ?? '') === 'private' ? 'private' : 'unlisted';
    $ttl     = ['0' => null, '3600' => 3600, '86400' => 86400, '604800' => 604800, '2592000' => 2592000][$_POST['expires'] ?? '0'] ?? null;

    if (trim($content) === '') $err = 'Paste is empty.';
    elseif (strlen($content) > cfg('max_paste_bytes')) $err = 'Paste is too large.';
    else {
        $now = time();
        for ($try = 0; $try < 3; $try++) {
            $id = random_id();
            try {
                db('INSERT INTO pastes(id,user_id,title,language,content,visibility,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?)',
                   [$id, $u['id'], $title ?: null, $lang, $content, $vis, $now, $ttl ? $now + $ttl : null]);
                redirect('view.php?id=' . $id);
            } catch (RuntimeException $e) { if ($try === 2) $err = 'Could not save paste.'; }
        }
    }
}

$mine = db('SELECT id,title,language,visibility,created_at,expires_at FROM pastes
            WHERE user_id=? AND (expires_at IS NULL OR expires_at>?) ORDER BY created_at DESC LIMIT 20',
           [$u['id'], time()])['rows'];

layout_start('New paste');
?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif ?>
  <label>Title (optional)</label><input type="text" name="title" maxlength="100">
  <label>Content</label><textarea name="content" required spellcheck="false"><?= h($_POST['content'] ?? '') ?></textarea>
  <div class="row">
    <div><label>Language</label><select name="language"><?php foreach (LANGS as $l) echo '<option>' . h($l) . '</option>'; ?></select></div>
    <div><label>Expires</label><select name="expires">
      <option value="0">Never</option><option value="3600">1 hour</option><option value="86400">1 day</option>
      <option value="604800">1 week</option><option value="2592000">30 days</option></select></div>
    <div><label>Visibility</label><select name="visibility">
      <option value="unlisted">Unlisted (anyone with link)</option><option value="private">Private (only you)</option></select></div>
  </div>
  <p><button>Create paste</button></p>
</form>

<?php if ($mine): ?>
<div class="card"><strong>Your recent pastes</strong>
  <table><?php foreach ($mine as $p): ?>
    <tr><td><a href="view.php?id=<?= h($p['id']) ?>"><?= h($p['title'] ?: $p['id']) ?></a></td>
        <td class="mut"><?= h($p['language']) ?></td><td class="mut"><?= h($p['visibility']) ?></td>
        <td class="mut"><?= h(fmt_time($p['created_at'])) ?></td></tr>
  <?php endforeach ?></table>
</div>
<?php endif;
layout_end();
