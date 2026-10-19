<?php
declare(strict_types=1);
$dash_role   = 'customer';
$dash_active = 'wishlist';
$page_title  = 'Saved items — CureMeds';
require_once __DIR__ . '/../../includes/dash-head.php';

$ids   = wishlist_ids((int) $user['id']);
$items = $ids ? (new ProductRepository())->getAll(['ids' => $ids, 'status' => 'all'], 1, 60)['items'] : [];
?>

<div class="dash-head">
  <div>
    <h1>Saved items</h1>
    <p><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> saved. Nothing here is reserved — save it, then order it.</p>
  </div>
  <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--sm"><i class="fa-solid fa-basket-shopping"></i> Keep browsing</a>
</div>

<?php if (!$items): ?>
  <div class="empty card-fc">
    <div class="empty__icon"><i class="fa-regular fa-heart"></i></div>
    <h3>Nothing saved yet</h3>
    <p class="mb-3">Tap the heart on any product and it lands here.</p>
    <a href="<?= SITE_URL ?>/shop.php" class="btn-fc">Browse the catalogue</a>
  </div>
<?php else: ?>
  <div class="grid-products">
    <?php
    $card_user = $user;
    foreach ($items as $product):
      $in_wishlist = true;
      include __DIR__ . '/../../includes/product-card.php';
    endforeach; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
