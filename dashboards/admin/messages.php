<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'messages';
$page_title  = 'Messages — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $mid = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'read' && $mid) {
        db_update('contact_messages', $mid, ['is_read' => 1]);
    } elseif (($_POST['action'] ?? '') === 'unread' && $mid) {
        db_update('contact_messages', $mid, ['is_read' => 0]);
    } elseif (($_POST['action'] ?? '') === 'delete' && $mid) {
        db()->prepare('DELETE FROM contact_messages WHERE id = :id')->execute([':id' => $mid]);
        $flash = 'Message deleted.';
    }
}

$onlyUnread = !empty($_GET['unread']);
$sql = 'SELECT * FROM contact_messages' . ($onlyUnread ? ' WHERE is_read = 0' : '') . ' ORDER BY is_read, created_at DESC LIMIT 200';
$rows = db()->query($sql)->fetchAll();
$unread = (int) db()->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0')->fetchColumn();
?>

<div class="dash-head">
  <div>
    <h1>Messages</h1>
    <p>Every contact-form enquiry is saved here before email is even attempted, so an SMTP outage never loses one.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="chip <?= $onlyUnread ? '' : 'is-on' ?>" href="messages.php">All</a>
    <a class="chip <?= $onlyUnread ? 'is-on' : '' ?>" href="?unread=1">Unread<?= $unread ? ' · ' . $unread : '' ?></a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--ok"><i class="fa-solid fa-circle-check"></i><span><?= e($flash) ?></span></div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="empty card-fc">
    <div class="empty__icon"><i class="fa-solid fa-inbox"></i></div>
    <h3><?= $onlyUnread ? 'Nothing unread' : 'No messages yet' ?></h3>
    <p>Enquiries from the contact page land here.</p>
  </div>
<?php else: ?>
  <?php foreach ($rows as $m): ?>
  <article class="card-fc card-fc--pad mb-2" style="<?= $m['is_read'] ? '' : 'border-left:3px solid var(--leaf)' ?>">
    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
      <div>
        <b style="font-family:var(--font-display)"><?= e($m['subject'] ?: 'No subject') ?></b>
        <?php if (!$m['is_read']): ?><span class="badge-fc badge-fc--leaf ms-2">New</span><?php endif; ?>
        <div class="text-muted-3" style="font-size:.8rem">
          <?= e($m['name']) ?> ·
          <a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a>
          <?php if ($m['phone']): ?> · <?= e($m['phone']) ?><?php endif; ?>
          · <?= date('j M Y, H:i', strtotime($m['created_at'])) ?>
        </div>
      </div>
      <div class="d-flex gap-1">
        <a class="btn-fc btn-fc--sm btn-fc--light"
           href="mailto:<?= e($m['email']) ?>?subject=<?= urlencode('Re: ' . ($m['subject'] ?: 'Your CureMeds enquiry')) ?>">
          <i class="fa-solid fa-reply"></i> Reply
        </a>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
          <input type="hidden" name="action" value="<?= $m['is_read'] ? 'unread' : 'read' ?>">
          <button class="btn-fc btn-fc--sm btn-fc--light" type="submit" title="Mark as <?= $m['is_read'] ? 'unread' : 'read' ?>">
            <i class="fa-<?= $m['is_read'] ? 'regular' : 'solid' ?> fa-envelope<?= $m['is_read'] ? '' : '-open' ?>"></i>
          </button>
        </form>
        <form method="post" onsubmit="return confirm('Delete this message permanently?')">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn-fc btn-fc--sm btn-fc--light" style="color:var(--coral)" type="submit"><i class="fa-solid fa-trash-can"></i></button>
        </form>
      </div>
    </div>
    <p class="mt-2 mb-0" style="font-size:.9rem;color:var(--text-2);white-space:pre-line"><?= e($m['message']) ?></p>
  </article>
  <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
