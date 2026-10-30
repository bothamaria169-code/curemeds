<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

// "Shop by need": every category as a photo tile. Moved off the home page so
// products come first there.
$categories = get_categories();

$page_title       = 'Shop by need — CureMeds';
$meta_description = 'Find health products by what you are looking after: immune support, vitamins, blood sugar, heart, gut, sleep, joints and more.';
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Shop by need</span></nav>
    <h1>What are you looking after?</h1>
    <p>Start with what matters to you. Every product shows its price, pack size and how to take it.</p>
  </div>
</header>

<section class="section section--tight" style="padding-bottom:3rem">
  <div class="container">
    <div class="row g-3">
      <?php foreach ($categories as $c): ?>
      <div class="col-lg-3 col-md-4 col-sm-6" data-reveal>
        <a class="cat-tile cat-tile--photo" href="<?= SITE_URL ?>/shop.php?category=<?= e($c['slug']) ?>">
          <?php if (!empty($c['image'])): ?>
            <img class="cat-tile__img" src="<?= e(product_image($c['image'])) ?>" alt="" loading="lazy">
          <?php endif; ?>
          <i class="fa-solid <?= e($c['icon'] ?: 'fa-tag') ?>"></i>
          <b><?= e($c['name']) ?></b>
          <span><?= e($c['blurb'] ?? '') ?></span>
          <span class="count"><?= (int) $c['product_count'] ?> products <i class="fa-solid fa-arrow-right ms-1"></i></span>
        </a>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="text-center mt-4">
      <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--lg">See all products <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
