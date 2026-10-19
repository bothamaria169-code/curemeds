<?php
/**
 * Reusable product card.
 * Expects: $product. Optional: $in_wishlist (bool), $card_user (array|null).
 *
 * The card renders a different bottom strip depending on who is looking:
 * a shopper sees the price, an approved reseller sees cost + rand profit.
 * That single difference is the whole point of the site, so it lives here
 * rather than being duplicated at every call site.
 */
$card_user   = $card_user ?? current_user();
$is_res      = Pricing::isReseller($card_user);
$price       = Pricing::effective($product, $card_user);
$listPrice   = (float) $product['retail_price'];
$salePct     = Pricing::salePercent($product);
$img         = product_image($product['featured_image']);
$in_wishlist = $in_wishlist ?? false;
$url         = SITE_URL . '/modules/shop/product.php?slug=' . urlencode($product['slug']);
$soldOut     = $product['stock_status'] === 'out_of_stock';
$margin      = $is_res ? Pricing::margin($product, $card_user) : null;
?>
<article class="p-card" data-product-id="<?= (int) $product['id'] ?>">
  <div class="p-card__media">
    <a href="<?= e($url) ?>" tabindex="-1" aria-hidden="true">
      <img class="p-card__img" src="<?= e($img) ?>" alt="<?= e($product['name']) ?>" loading="lazy" decoding="async"
           onerror="this.onerror=null;this.src='<?= SITE_URL ?>/assets/img/placeholder.svg'">
    </a>

    <div class="p-card__badges">
      <?php if ($salePct > 0): ?><span class="badge-fc badge-fc--coral">−<?= $salePct ?>%</span><?php endif; ?>
      <?php if (!empty($product['is_new'])): ?><span class="badge-fc badge-fc--leaf">New</span><?php endif; ?>
      <?php if (!empty($product['is_bestseller']) && !$salePct): ?><span class="badge-fc badge-fc--amber">Best seller</span><?php endif; ?>
      <?php if ($soldOut): ?><span class="badge-fc badge-fc--ink">Sold out</span>
      <?php elseif ($product['stock_status'] === 'low_stock'): ?><span class="badge-fc badge-fc--muted">Low stock</span><?php endif; ?>
    </div>

    <div class="p-card__tools">
      <button class="p-card__tool wish-btn <?= $in_wishlist ? 'is-on' : '' ?>" type="button"
              data-product-id="<?= (int) $product['id'] ?>" aria-pressed="<?= $in_wishlist ? 'true' : 'false' ?>"
              title="<?= $in_wishlist ? 'Remove from saved' : 'Save for later' ?>">
        <i class="<?= $in_wishlist ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i>
      </button>
      <button class="p-card__tool compare-btn" type="button"
              data-product-id="<?= (int) $product['id'] ?>"
              data-name="<?= e($product['name']) ?>" data-img="<?= e($img) ?>"
              title="Add to comparison">
        <i class="fa-solid fa-code-compare"></i>
      </button>
      <a class="p-card__tool" href="<?= e($url) ?>" title="View product"><i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>

  <div class="p-card__body">
    <?php if (!empty($product['brand_name'])): ?>
      <div class="p-card__brand"><?= e($product['brand_name']) ?></div>
    <?php endif; ?>

    <h3 class="p-card__name"><a href="<?= e($url) ?>"><?= e($product['name']) ?></a></h3>

    <?php if (!empty($product['pack_size'])): ?>
      <div class="p-card__meta"><?= e($product['pack_size']) ?></div>
    <?php endif; ?>

    <div class="p-card__price">
      <span class="money now"><?= format_price_short($price) ?></span>
      <?php if ($is_res): ?>
        <span class="was" style="text-decoration:none;color:var(--text-3);font-size:.76rem;">your cost</span>
      <?php elseif ($salePct > 0): ?>
        <span class="money was"><?= format_price_short($listPrice) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($margin && $margin['profit'] > 0): ?>
      <div class="p-card__margin">
        <span class="lbl"><i class="fa-solid fa-arrow-trend-up me-1"></i>Sell at <?= format_price_short($margin['rrp']) ?></span>
        <span class="money val">+<?= format_price_short($margin['profit']) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <div class="p-card__foot">
    <?php if ($soldOut): ?>
      <button class="btn-fc btn-fc--ghost btn-fc--block btn-fc--sm notify-btn" type="button" data-product-id="<?= (int) $product['id'] ?>">
        <i class="fa-regular fa-bell"></i> Notify me
      </button>
    <?php elseif ($product['type'] === 'variable'): ?>
      <a href="<?= e($url) ?>" class="btn-fc btn-fc--ghost btn-fc--block btn-fc--sm">
        <i class="fa-solid fa-sliders"></i> Choose option
      </a>
    <?php else: ?>
      <div class="p-card__foot--split">
        <a class="btn-fc btn-fc--whatsapp btn-fc--sm" href="<?= SITE_URL ?>/enquire.php?product=<?= (int) $product['id'] ?>"
           target="_blank" rel="noopener nofollow">
          <i class="fa-brands fa-whatsapp"></i> Enquire
        </a>
        <button class="icon-square add-to-cart" type="button" data-product-id="<?= (int) $product['id'] ?>" data-qty="1"
                title="Add to enquiry list" aria-label="Add <?= e($product['name']) ?> to enquiry list">
          <i class="fa-solid fa-plus"></i>
        </button>
      </div>
    <?php endif; ?>
  </div>
</article>
