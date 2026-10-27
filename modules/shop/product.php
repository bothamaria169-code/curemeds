<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';

$repo    = new ProductRepository();
$product = $repo->getBySlug(trim((string) ($_GET['slug'] ?? '')));

if (!$product || ($product['status'] !== 'published' && !is_admin())) {
    http_response_code(404);
    $page_title = 'Product not found — ' . SITE_NAME;
    include __DIR__ . '/../../includes/header.php';
    echo '<div class="section"><div class="container empty card-fc">'
       . '<div class="empty__icon"><i class="fa-solid fa-box-open"></i></div>'
       . '<h1>We could not find that product</h1>'
       . '<p class="mb-4">It may have been renamed or discontinued.</p>'
       . '<a href="' . SITE_URL . '/shop.php" class="btn-fc">Browse the catalogue</a>'
       . '</div></div>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$repo->bumpView((int) $product['id']);
record_recently_viewed((int) $product['id']);

$viewer   = current_user();
$isRes    = Pricing::isReseller($viewer);
$variants = $repo->getVariants((int) $product['id']);
$gallery  = $repo->getGallery((int) $product['id']);
$related  = $repo->getRelated($product, 4);
$stats    = $repo->reviewStats((int) $product['id']);
$reviews  = $repo->approvedReviews((int) $product['id']);
$wish     = $viewer ? wishlist_ids((int) $viewer['id']) : [];

$price     = Pricing::effective($product, $viewer);
$listPrice = (float) $product['retail_price'];
$salePct   = Pricing::salePercent($product);
$margin    = Pricing::margin($product, $viewer);
$soldOut   = $product['stock_status'] === 'out_of_stock';
$benefits  = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $product['benefits']) ?: [])));

$images = array_merge(
    [['image' => $product['featured_image'], 'caption' => $product['name']]],
    array_map(fn($v) => ['image' => $v['image'], 'caption' => $v['option_value']], array_filter($variants, fn($v) => !empty($v['image']))),
    $gallery
);
// De-duplicate: a variant image is often also the featured image.
$seen = [];
$images = array_values(array_filter($images, function ($i) use (&$seen) {
    if (!$i['image'] || in_array($i['image'], $seen, true)) { return false; }
    $seen[] = $i['image'];
    return true;
}));

$page_title       = $product['meta_title'] ?: ($product['name'] . ' — ' . $product['brand_name'] . ' | ' . SITE_NAME);
$meta_description = $product['meta_desc'] ?: excerpt($product['short_desc'], 155);
$og_image         = product_image($product['header_image'] ?? null ?: $product['featured_image']);
$json_ld = [
    '@type'       => 'Product',
    'name'        => $product['name'],
    'image'       => array_map(fn($i) => product_image($i['image']), array_slice($images, 0, 4)),
    'description' => strip_tags((string) $product['short_desc']),
    'sku'         => $product['sku'],
    'brand'       => ['@type' => 'Brand', 'name' => $product['brand_name']],
    'offers'      => [
        '@type'         => 'Offer',
        'url'           => SITE_URL . '/modules/shop/product.php?slug=' . $product['slug'],
        'priceCurrency' => CURRENCY_CODE,
        'price'         => number_format($listPrice, 2, '.', ''),
        'availability'  => $soldOut ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
    ],
] + ($stats['count'] > 0 ? ['aggregateRating' => [
        '@type'       => 'AggregateRating',
        'ratingValue' => $stats['average'],
        'reviewCount' => $stats['count'],
    ]] : []);

$extra_js = [SITE_URL . '/assets/js/product.js'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="section section--tight">
  <div class="container">
    <nav class="crumbs crumbs--light" aria-label="Breadcrumb">
      <a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i>
      <a href="<?= SITE_URL ?>/shop.php">Shop</a><i class="fa-solid fa-chevron-right"></i>
      <a href="<?= SITE_URL ?>/shop.php?category=<?= e((string) $product['category_slug']) ?>"><?= e((string) $product['category_name']) ?></a>
      <i class="fa-solid fa-chevron-right"></i><span class="text-muted-3"><?= e($product['name']) ?></span>
    </nav>
  </div>
</div>

<div class="container">
  <div class="pdp">

    <!-- ── Gallery ────────────────────────────────────────────────── -->
    <div class="pdp-gallery">
      <figure class="pdp-main">
        <img id="pdpMainImage" src="<?= e(product_image($images[0]['image'] ?? null)) ?>" alt="<?= e($product['name']) ?>"
             onerror="this.onerror=null;this.src='<?= SITE_URL ?>/assets/img/placeholder.svg'">
        <?php if ($salePct > 0): ?>
          <span class="badge-fc badge-fc--coral" style="position:absolute;top:16px;left:16px">−<?= $salePct ?>% today</span>
        <?php endif; ?>
      </figure>

      <?php if (count($images) > 1): ?>
      <div class="pdp-thumbs">
        <?php foreach ($images as $i => $img): ?>
          <button type="button" class="pdp-thumb <?= $i === 0 ? 'is-on' : '' ?>" data-full="<?= e(product_image($img['image'])) ?>"
                  aria-label="View image <?= $i + 1 ?>">
            <img src="<?= e(product_image($img['image'])) ?>" alt="" loading="lazy">
          </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Buy box ────────────────────────────────────────────────── -->
    <div>
      <?php if ($product['brand_name']): ?>
        <a class="pdp-brand" href="<?= SITE_URL ?>/shop.php?brand=<?= e((string) $product['brand_slug']) ?>">
          <span style="width:9px;height:9px;border-radius:50%;background:<?= e((string) $product['brand_accent']) ?>"></span>
          <?= e($product['brand_name']) ?>
        </a>
      <?php endif; ?>

      <h1 class="pdp-title"><?= e($product['name']) ?></h1>

      <div class="d-flex align-items-center gap-3 flex-wrap mb-2">
        <?php if ($stats['count'] > 0): ?>
          <span class="d-flex align-items-center gap-2">
            <?= stars_html($stats['average']) ?>
            <a href="#reviews" style="font-size:.82rem;color:var(--text-2)"><?= $stats['count'] ?> review<?= $stats['count'] === 1 ? '' : 's' ?></a>
          </span>
        <?php endif; ?>
        <?php if ($product['sku']): ?><span class="text-muted-3" style="font-size:.78rem">SKU <?= e($product['sku']) ?></span><?php endif; ?>
        <?php if ((int) $product['sold_count'] > 20): ?>
          <span class="badge-fc badge-fc--muted"><i class="fa-solid fa-fire"></i><?= (int) $product['sold_count'] ?> sold</span>
        <?php endif; ?>
      </div>

      <p class="lede"><?= e((string) $product['short_desc']) ?></p>

      <div class="pdp-price-row">
        <span class="money pdp-price" id="pdpPrice"><?= format_price($price) ?></span>
        <?php if ($isRes): ?>
          <span class="badge-fc badge-fc--amber"><?= e(RESELLER_TIERS[$viewer['reseller_tier']]['label']) ?> price</span>
          <span class="text-muted-3" style="font-size:.85rem">retail <?= format_price_short($listPrice) ?></span>
        <?php elseif ($salePct > 0): ?>
          <span class="money pdp-price-was"><?= format_price($listPrice) ?></span>
          <span class="badge-fc badge-fc--coral">Save <?= format_price_short($listPrice - $price) ?></span>
        <?php endif; ?>
        <?php if ($product['pack_size']): ?>
          <span class="text-muted-3" style="font-size:.88rem"><?= e($product['pack_size']) ?></span>
        <?php endif; ?>
      </div>

      <!-- ── The margin panel ─────────────────────────────────────── -->
      <?php if ($isRes): ?>
        <section class="margin-panel" aria-label="Your margin on this product">
          <div class="margin-panel__head">
            <i class="fa-solid fa-arrow-trend-up"></i> Your margin on this line
          </div>
          <div class="margin-grid">
            <div><b class="money"><?= format_price_short($margin['cost']) ?></b><span>You pay</span></div>
            <div><b class="money"><?= format_price_short($margin['rrp']) ?></b><span>Recommended sell</span></div>
            <div><b class="money hero-figure">+<?= format_price_short($margin['profit']) ?></b><span>Profit / unit</span></div>
          </div>
          <p class="margin-panel__foot">
            <?= $margin['margin_pct'] ?>% margin · <?= $margin['markup_pct'] ?>% markup on cost.
            A case of <?= (int) $product['min_reseller_qty'] ?> costs you
            <b><?= format_price_short($margin['cost'] * (int) $product['min_reseller_qty']) ?></b>,
            takes <b><?= format_price_short($margin['rrp'] * (int) $product['min_reseller_qty']) ?></b> at the
            recommended price, and leaves you
            <b><?= format_price_short($margin['profit'] * (int) $product['min_reseller_qty']) ?></b> once it sells through.
          </p>
        </section>
      <?php else:
        $freeAt    = (float) get_setting('free_shipping_threshold', '1500');
        $thisPrice = Pricing::effective($product, $viewer);
        $toFree    = max(0.0, $freeAt - $thisPrice);
      ?>
        <section class="margin-panel" aria-label="Buying this product">
          <div class="margin-panel__head">
            <i class="fa-solid fa-truck-fast"></i> Getting it to you
          </div>
          <div class="margin-grid">
            <div><b>Same day</b><span>WhatsApp reply</span></div>
            <div><b>2–4 days</b><span>Courier</span></div>
            <div><b>Free</b><span>Dose reminders</span></div>
          </div>
          <p class="margin-panel__foot">
            <?php if ($toFree > 0): ?>
              Free courier delivery on orders over <b><?= format_price_short($freeAt) ?></b>.
            <?php else: ?>
              <b>This product qualifies for free courier delivery.</b>
            <?php endif; ?>
            We confirm every order with you on WhatsApp before you pay.
          </p>
        </section>
      <?php endif; ?>

      <!-- ── Options ──────────────────────────────────────────────── -->
      <?php if ($variants): ?>
      <div class="mt-3" id="variantRow">
        <label class="form-label-fc"><?= e($variants[0]['option_label']) ?></label>
        <div class="variant-row">
          <?php foreach ($variants as $i => $v): ?>
            <button type="button" class="variant-btn <?= $i === 0 ? 'is-on' : '' ?>"
                    data-variant-id="<?= (int) $v['id'] ?>"
                    data-add="<?= number_format((float) $v['additional_price'], 2, '.', '') ?>"
                    data-image="<?= e(product_image($v['image'] ?: $product['featured_image'])) ?>"
                    data-stock="<?= (int) $v['stock_qty'] ?>"
                    <?= (int) $v['stock_qty'] < 1 ? 'disabled' : '' ?>>
              <?php if ($v['swatch_hex']): ?><span class="variant-swatch" style="background:<?= e($v['swatch_hex']) ?>"></span><?php endif; ?>
              <?= e($v['option_value']) ?>
            </button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="selectedVariant" value="<?= (int) $variants[0]['id'] ?>">
        <div class="field__hint mt-2" id="variantStock"></div>
      </div>
      <?php endif; ?>

      <!-- ── Buy ──────────────────────────────────────────────────── -->
      <div class="d-flex gap-2 align-items-center mt-4 flex-wrap">
        <?php if (!$soldOut): ?>
          <div class="qty">
            <button type="button" data-step="down" aria-label="Decrease quantity"><i class="fa-solid fa-minus"></i></button>
            <input type="number" id="pdpQty" value="1" min="1" max="99" aria-label="Quantity">
            <button type="button" data-step="up" aria-label="Increase quantity"><i class="fa-solid fa-plus"></i></button>
          </div>
          <button class="btn-fc btn-fc--lg add-to-cart" type="button" style="flex:1;min-width:180px"
                  data-product-id="<?= (int) $product['id'] ?>"
                  data-qty-from="#pdpQty"
                  data-requires-variant="<?= $product['type'] === 'variable' ? '1' : '0' ?>">
            <i class="fa-solid fa-cart-plus"></i> Add to cart
          </button>
          <button class="btn-fc btn-fc--ghost btn-fc--lg add-to-cart" type="button"
                  data-product-id="<?= (int) $product['id'] ?>"
                  data-qty-from="#pdpQty" data-buy-now="1"
                  data-requires-variant="<?= $product['type'] === 'variable' ? '1' : '0' ?>">
            <i class="fa-solid fa-bolt"></i> Buy now
          </button>
          <a class="btn-fc btn-fc--whatsapp btn-fc--block" target="_blank" rel="noopener nofollow"
             href="<?= SITE_URL ?>/enquire.php?product=<?= (int) $product['id'] ?>" id="pdpEnquire"
             data-base="<?= SITE_URL ?>/enquire.php?product=<?= (int) $product['id'] ?>">
            <i class="fa-brands fa-whatsapp"></i> Questions? Ask on WhatsApp
          </a>
          <script>
            // Carry the chosen quantity into the WhatsApp message.
            document.getElementById('pdpEnquire').addEventListener('click', function () {
              this.href = this.dataset.base + '&qty=' + (parseInt(document.getElementById('pdpQty').value, 10) || 1);
            });
          </script>
        <?php else: ?>
          <button class="btn-fc btn-fc--ghost btn-fc--lg btn-fc--block notify-btn" type="button" data-product-id="<?= (int) $product['id'] ?>">
            <i class="fa-regular fa-bell"></i> Email me when it is back
          </button>
        <?php endif; ?>

        <button class="icon-btn wish-btn <?= in_array((int) $product['id'], $wish, true) ? 'is-on' : '' ?>"
                type="button" data-product-id="<?= (int) $product['id'] ?>"
                style="border:1px solid var(--line-strong);width:52px;height:52px"
                aria-label="Save for later">
          <i class="<?= in_array((int) $product['id'], $wish, true) ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i>
        </button>
      </div>

      <?php if ($isRes): ?>
        <p class="field__hint mt-2">
          <i class="fa-solid fa-circle-info me-1"></i>
          Reseller minimum on this line is <?= (int) $product['min_reseller_qty'] ?> units so we can pack a clean case.
        </p>
      <?php endif; ?>

      <?php $payCardClass = 'mt-3'; include __DIR__ . '/../../includes/payment-card.php'; ?>

      <!-- ── Benefits ─────────────────────────────────────────────── -->
      <?php if ($benefits): ?>
        <ul class="pdp-benefits">
          <?php foreach ($benefits as $b): ?>
            <li><i class="fa-solid fa-check"></i><span><?= e($b) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <div class="pdp-meta">
        <span><i class="fa-solid fa-truck-fast me-2 text-leaf"></i><b>2–4 days</b> courier</span>
        <span><i class="fa-solid fa-bell me-2 text-leaf"></i><b>Free</b> dose reminders</span>
        <span><i class="fa-solid fa-rotate-left me-2 text-leaf"></i><b>7-day</b> returns</span>
        <span>
          <i class="fa-solid fa-boxes-stacked me-2 text-leaf"></i>
          <?php if ($soldOut): ?><b>Out of stock</b>
          <?php elseif ($product['stock_status'] === 'low_stock'): ?><b>Low stock</b> — <?= (int) $product['stock_qty'] ?> left
          <?php else: ?><b>In stock</b><?php endif; ?>
        </span>
      </div>
    </div>
  </div>
</div>

<!-- ── Tabs ─────────────────────────────────────────────────────── -->
<div class="section">
  <div class="container">
    <div class="tabs" role="tablist">
      <button class="is-on" data-tab="desc" type="button">Description</button>
      <?php if ($product['usage_notes']): ?><button data-tab="use" type="button">How to use</button><?php endif; ?>
      <button data-tab="ship" type="button">Delivery &amp; returns</button>
      <button data-tab="reviews" type="button">Reviews<?= $stats['count'] ? ' (' . $stats['count'] . ')' : '' ?></button>
    </div>

    <div class="tab-panel is-on" data-panel="desc">
      <div class="prose">
        <?= $product['description'] ? rich_text((string) $product['description']) : '<p>' . e((string) $product['short_desc']) . '</p>' ?>
        <?php if (!in_array($product['category_slug'] ?? '', ['devices-care'], true)): ?>
          <div class="alert-fc alert-fc--warn mt-4">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>
              <b>Health product notice.</b> Unless stated otherwise, this product has not been evaluated by SAHPRA for
              quality, safety or intended use, and is not intended to diagnose, treat, cure or prevent any disease. It does
              not replace prescribed medication — keep taking your treatment exactly as your clinic prescribed. Speak to a
              healthcare professional first if you are pregnant, breastfeeding, under 18 or on chronic medication.
            </span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($product['usage_notes']): ?>
    <div class="tab-panel" data-panel="use">
      <div class="prose"><p><?= nl2br(e((string) $product['usage_notes'])) ?></p></div>
    </div>
    <?php endif; ?>

    <div class="tab-panel" data-panel="ship">
      <div class="prose">
        <h3>Getting your order</h3>
        <ul>
          <?php foreach (FULFILMENT_METHODS as $key => $m): ?>
            <li><b><?= e($m['label']) ?></b> — <?= e($m['blurb']) ?></li>
          <?php endforeach; ?>
        </ul>
        <p>Courier delivery is <?= format_price_short((float) get_setting('shipping_flat_rate', '120')) ?>,
           free on orders over <?= format_price_short((float) get_setting('free_shipping_threshold', '1500')) ?>.</p>
        <h3>Returns</h3>
        <p>Unopened, unused stock in its original packaging can be returned within 7 days of delivery for a refund or
           exchange. Opened consumables cannot be returned for hygiene reasons. Anything damaged in transit is replaced
           free — send us a photo within 48 hours.</p>
        <p><a href="<?= SITE_URL ?>/refund-policy.php" class="text-leaf fw-bold">Read the full returns policy</a></p>
      </div>
    </div>

    <div class="tab-panel" data-panel="reviews" id="reviews">
      <?php if ($stats['count'] > 0): ?>
        <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
          <div class="text-center card-fc card-fc--pad" style="min-width:130px">
            <div class="money" style="font-size:2.4rem;line-height:1"><?= number_format($stats['average'], 1) ?></div>
            <?= stars_html($stats['average']) ?>
            <div class="text-muted-3" style="font-size:.78rem;margin-top:.3rem"><?= $stats['count'] ?> review<?= $stats['count'] === 1 ? '' : 's' ?></div>
          </div>
        </div>

        <?php foreach ($reviews as $r): ?>
          <article class="card-fc card-fc--pad mb-2">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
              <div>
                <?= stars_html((float) $r['rating']) ?>
                <?php if ($r['title']): ?><b class="d-block mt-1" style="font-family:var(--font-display)"><?= e($r['title']) ?></b><?php endif; ?>
              </div>
              <span class="text-muted-3" style="font-size:.76rem"><?= time_ago($r['created_at']) ?></span>
            </div>
            <p class="mt-2 mb-1" style="font-size:.92rem;color:var(--text-2)"><?= e($r['comment']) ?></p>
            <span class="text-muted-3" style="font-size:.78rem">
              <?= e($r['user_name']) ?>
              <?php if ($r['reseller_status'] === 'approved'): ?><span class="badge-fc badge-fc--amber ms-2">Reseller</span><?php endif; ?>
            </span>
          </article>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty">
          <div class="empty__icon"><i class="fa-regular fa-comment"></i></div>
          <p>No reviews yet. If you have used it, yours would be the first.</p>
        </div>
      <?php endif; ?>

      <?php if ($viewer): ?>
        <form class="card-fc card-fc--pad mt-3" id="reviewForm" style="max-width:600px">
          <h3 style="font-size:1.05rem">Write a review</h3>
          <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
          <div class="field">
            <label for="rating">Your rating</label>
            <select class="select-fc" name="rating" id="rating">
              <?php for ($i = 5; $i >= 1; $i--): ?>
                <option value="<?= $i ?>"><?= str_repeat('★', $i) . str_repeat('☆', 5 - $i) ?> — <?= [1=>'Poor',2=>'Fair',3=>'Good',4=>'Very good',5=>'Excellent'][$i] ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="field">
            <label for="rtitle">Headline <span class="text-muted-3 fw-normal">(optional)</span></label>
            <input class="input-fc" type="text" name="title" id="rtitle" maxlength="140" placeholder="Sum it up in a few words">
          </div>
          <div class="field">
            <label for="rcomment">Your review</label>
            <textarea class="textarea-fc" name="comment" id="rcomment" required minlength="10" placeholder="What did you use it for, and did it do what you expected?"></textarea>
          </div>
          <button class="btn-fc" type="submit">Submit review</button>
          <div id="reviewMsg" style="font-size:.84rem;margin-top:.6rem"></div>
        </form>
      <?php else: ?>
        <div class="alert-fc alert-fc--info mt-3">
          <i class="fa-solid fa-circle-info"></i>
          <span><a href="<?= SITE_URL ?>/modules/auth/login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="fw-bold">Sign in</a> to leave a review.</span>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Related ──────────────────────────────────────────────────── -->
<?php if ($related): ?>
<section class="section section--tight" style="background:var(--card);border-block:1px solid var(--line)">
  <div class="container">
    <div class="section__head"><span class="eyebrow">Goes with it</span><h2>You might also want</h2></div>
    <div class="grid-products">
      <?php foreach ($related as $product):
        $in_wishlist = in_array((int) $product['id'], $wish, true);
        include __DIR__ . '/../../includes/product-card.php';
      endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php $recent = recently_viewed((int) ($product['id'] ?? 0), 5); if (count($recent) > 1): ?>
<section class="section section--tight">
  <div class="container">
    <div class="section__head"><span class="eyebrow">Where you have been</span><h2>Recently viewed</h2></div>
    <div class="grid-products">
      <?php foreach ($recent as $product):
        $in_wishlist = in_array((int) $product['id'], $wish, true);
        include __DIR__ . '/../../includes/product-card.php';
      endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
