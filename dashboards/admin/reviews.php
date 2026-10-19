<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'reviews';
$page_title  = 'Reviews — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $rid = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'approve' && $rid) {
        db_update('reviews', $rid, ['is_approved' => 1]);
        $flash = 'Review published.';
    } elseif (($_POST['action'] ?? '') === 'hide' && $rid) {
        db_update('reviews', $rid, ['is_approved' => 0]);
        $flash = 'Review hidden from the product page.';
    } elseif (($_POST['action'] ?? '') === 'delete' && $rid) {
        db()->prepare('DELETE FROM reviews WHERE id = :id')->execute([':id' => $rid]);
        $flash = 'Review deleted.';
    }
}

$onlyPending = isset($_GET['pending']);
$sql = "SELECT r.*, u.name AS user_name, u.email, p.name AS product_name, p.slug AS product_slug
        FROM reviews r
        JOIN users u ON u.id = r.user_id
        JOIN products p ON p.id = r.product_id"
     . ($onlyPending ? ' WHERE r.is_approved = 0' : '')
     . ' ORDER BY r.is_approved, r.created_at DESC LIMIT 200';
$rows    = db()->query($sql)->fetchAll();
$pending = (int) db()->query('SELECT COUNT(*) FROM reviews WHERE is_approved = 0')->fetchColumn();
?>

<div class="dash-head">
  <div>
    <h1>Reviews</h1>
    <p>New reviews stay hidden until you approve them. Nothing appears on a product page unapproved.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="chip <?= $onlyPending ? '' : 'is-on' ?>" href="reviews.php">All</a>
    <a class="chip <?= $onlyPending ? 'is-on' : '' ?>" href="?pending">Awaiting approval<?= $pending ? ' · ' . $pending : '' ?></a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--ok"><i class="fa-solid fa-circle-check"></i><span><?= e($flash) ?></span></div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="empty card-fc">
    <div class="empty__icon"><i class="fa-solid fa-star"></i></div>
    <h3><?= $onlyPending ? 'Nothing waiting' : 'No reviews yet' ?></h3>
  </div>
<?php else: ?>
  <?php foreach ($rows as $r): ?>
  <article class="card-fc card-fc--pad mb-2" style="<?= $r['is_approved'] ? '' : 'border-left:3px solid var(--amber)' ?>">
    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
      <div>
        <?= stars_html((float) $r['rating']) ?>
        <?php if (!$r['is_approved']): ?><span class="badge-fc badge-fc--amber ms-2">Awaiting approval</span><?php endif; ?>
        <?php if ($r['title']): ?><b class="d-block mt-1" style="font-family:var(--font-display)"><?= e($r['title']) ?></b><?php endif; ?>
        <div class="text-muted-3" style="font-size:.8rem">
          on <a href="<?= SITE_URL ?>/modules/shop/product.php?slug=<?= e($r['product_slug']) ?>" target="_blank" class="fw-bold"><?= e($r['product_name']) ?></a>
          by <?= e($r['user_name']) ?> · <?= time_ago($r['created_at']) ?>
        </div>
      </div>
      <div class="d-flex gap-1">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="hidden" name="action" value="<?= $r['is_approved'] ? 'hide' : 'approve' ?>">
          <button class="btn-fc btn-fc--sm <?= $r['is_approved'] ? 'btn-fc--light' : '' ?>" type="submit">
            <i class="fa-solid fa-<?= $r['is_approved'] ? 'eye-slash' : 'check' ?>"></i>
            <?= $r['is_approved'] ? 'Hide' : 'Approve' ?>
          </button>
        </form>
        <form method="post" onsubmit="return confirm('Delete this review permanently?')">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn-fc btn-fc--sm btn-fc--light" style="color:var(--coral)" type="submit"><i class="fa-solid fa-trash-can"></i></button>
        </form>
      </div>
    </div>
    <p class="mt-2 mb-0" style="font-size:.9rem;color:var(--text-2)"><?= e((string) $r['comment']) ?></p>
  </article>
  <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
