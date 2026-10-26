<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

// Customer photos (Admin → Customer gallery), shown exactly as uploaded.
$photos = db()->query("SELECT * FROM customer_gallery WHERE status = 'published' ORDER BY sort_order, id DESC")->fetchAll();

$page_title       = 'Gallery — from our customers | ' . SITE_NAME;
$meta_description = 'Photos from CureMeds customers: orders delivered across South Africa.';
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Gallery</span></nav>
    <h1>From our customers</h1>
    <p>Orders delivered across South Africa, shared by the people who received them.</p>
  </div>
</header>

<div class="section section--tight">
  <div class="container">
    <?php if (!$photos): ?>
      <div class="empty card-fc"><div class="empty__icon"><i class="fa-solid fa-camera-retro"></i></div><h2>Photos coming soon</h2><p>Received your order? Send us a photo on WhatsApp.</p></div>
    <?php else: ?>
    <div class="cm-gallery">
      <?php foreach ($photos as $g): ?>
      <a class="cm-gallery__item" href="<?= e(product_image($g['image'])) ?>" data-lightbox data-caption="<?= e((string) $g['caption']) ?>">
        <img src="<?= e(product_image($g['image'])) ?>" alt="<?= e($g['caption'] ?: 'Photo from a CureMeds customer') ?>" loading="lazy">
        <?php if ($g['caption']): ?><span><?= e($g['caption']) ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($photos) { include __DIR__ . '/includes/lightbox.php'; } ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
