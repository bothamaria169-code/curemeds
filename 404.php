<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

http_response_code(404);

$popular = (new ProductRepository())->getBestsellers(4);
$viewer  = current_user();
$wish    = $viewer ? wishlist_ids((int) $viewer['id']) : [];

$page_title       = 'Page not found — ' . SITE_NAME;
$meta_description = 'That page does not exist. Here is the way back.';
include __DIR__ . '/includes/header.php';
?>

<div class="section">
  <div class="container" style="max-width:640px;text-align:center">
    <div class="money" style="font-size:clamp(4rem,14vw,7rem);color:var(--leaf);line-height:1">404</div>
    <h1>That page is not here</h1>
    <p class="lede mb-4">
      It may have been renamed, or the link that brought you here is out of date.
      The catalogue and the price list are both one click away.
    </p>

    <form action="<?= SITE_URL ?>/shop.php" method="get" class="d-flex gap-2 mb-4" role="search">
      <input class="input-fc" type="search" name="q" placeholder="Search the catalogue" aria-label="Search">
      <button class="btn-fc" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>

    <div class="d-flex gap-2 justify-content-center flex-wrap">
      <a href="<?= SITE_URL ?>/index.php" class="btn-fc">Home</a>
      <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--ghost">Shop</a>
      <a href="<?= SITE_URL ?>/price-list.php" class="btn-fc btn-fc--ghost">Price list</a>
      <a href="<?= SITE_URL ?>/contact.php" class="btn-fc btn-fc--ghost">Contact</a>
    </div>
  </div>
</div>

<?php if ($popular): ?>
<section class="section section--tight" style="background:var(--card);border-top:1px solid var(--line)">
  <div class="container">
    <div class="section__head text-center"><span class="eyebrow">While you are here</span><h2>What everyone else is buying</h2></div>
    <div class="grid-products">
      <?php foreach ($popular as $product):
        $in_wishlist = in_array((int) $product['id'], $wish, true);
        include __DIR__ . '/includes/product-card.php';
      endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
