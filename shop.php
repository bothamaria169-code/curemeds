<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$repo   = new ProductRepository();
$viewer = current_user();
$isRes  = Pricing::isReseller($viewer);
$wish   = $viewer ? wishlist_ids((int) $viewer['id']) : [];

$filters = [
    'category' => trim((string) ($_GET['category'] ?? '')),
    'brand'    => trim((string) ($_GET['brand'] ?? '')),
    'search'   => trim((string) ($_GET['q'] ?? '')),
    'sort'     => (string) ($_GET['sort'] ?? 'featured'),
    'min'      => $_GET['min'] ?? '',
    'max'      => $_GET['max'] ?? '',
    'sale'     => !empty($_GET['sale']),
    'in_stock' => !empty($_GET['in_stock']),
];
$page   = max(1, (int) ($_GET['page'] ?? 1));
$result = $repo->getAll($filters, $page, PRODUCTS_PER_PAGE);

$categories = get_categories();
$brands     = get_brands();

$activeCategory = $filters['category'] ? array_values(array_filter($categories, fn($c) => $c['slug'] === $filters['category']))[0] ?? null : null;
$activeBrand    = $filters['brand']    ? array_values(array_filter($brands,     fn($b) => $b['slug'] === $filters['brand']))[0]    ?? null : null;

/** Rebuilds the current query string with one parameter changed or dropped. */
$withParam = function (string $key, $value) {
    $q = $_GET;
    unset($q['page']);
    if ($value === null || $value === '') { unset($q[$key]); } else { $q[$key] = $value; }
    return '?' . http_build_query($q);
};

$hasFilters = $filters['category'] || $filters['brand'] || $filters['search'] || $filters['sale'] || $filters['in_stock'] || $filters['min'] !== '' || $filters['max'] !== '';

$heading = $activeCategory['name'] ?? $activeBrand['name'] ?? ($filters['search'] !== '' ? 'Results for “' . $filters['search'] . '”' : 'The full catalogue');
$blurb   = $activeCategory['blurb'] ?? $activeBrand['blurb'] ?? 'Four South African brands, one basket, one delivery.';

$page_title       = $heading . ' — ' . SITE_NAME;
$meta_description = excerpt($blurb, 155);

include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs" aria-label="Breadcrumb">
      <a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i>
      <a href="<?= SITE_URL ?>/shop.php">Shop</a>
      <?php if ($activeCategory): ?><i class="fa-solid fa-chevron-right"></i><span><?= e($activeCategory['name']) ?></span><?php endif; ?>
      <?php if ($activeBrand): ?><i class="fa-solid fa-chevron-right"></i><span><?= e($activeBrand['name']) ?></span><?php endif; ?>
    </nav>
    <h1><?= e($heading) ?></h1>
    <p><?= e($blurb) ?></p>

    <?php if ($isRes): ?>
      <div class="mt-3">
        <span class="badge-fc badge-fc--amber">
          <i class="fa-solid fa-tag"></i>
          <?= e(RESELLER_TIERS[$viewer['reseller_tier']]['label']) ?> pricing — every price below is your cost
        </span>
      </div>
    <?php endif; ?>
  </div>
</header>

<div style="padding-top:1.25rem">
  <?php $carousel_page = 'shop'; include __DIR__ . '/includes/banner-carousel.php'; ?>
</div>

<div class="section section--tight">
  <div class="container shop-layout">

    <!-- ── Filters ────────────────────────────────────────────────── -->
    <aside class="filters" id="shopFilters" aria-label="Filters">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 style="font-size:1rem;margin:0">Filter</h2>
        <button class="icon-btn filters__close" type="button" id="filterClose" aria-label="Close filters"><i class="fa-solid fa-xmark"></i></button>
      </div>

      <?php if ($hasFilters): ?>
        <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--ghost btn-fc--sm btn-fc--block mb-2">
          <i class="fa-solid fa-rotate-left"></i> Clear all filters
        </a>
      <?php endif; ?>

      <div class="filter-block">
        <h6>Category</h6>
        <div class="filter-list">
          <a href="<?= e($withParam('category', null)) ?>" class="<?= $filters['category'] === '' ? 'is-on' : '' ?>">
            All categories <span class="n"><?= array_sum(array_column($categories, 'product_count')) ?></span>
          </a>
          <?php foreach ($categories as $c): if (!$c['product_count']) continue; ?>
            <a href="<?= e($withParam('category', $c['slug'])) ?>" class="<?= $filters['category'] === $c['slug'] ? 'is-on' : '' ?>">
              <?= e($c['name']) ?> <span class="n"><?= (int) $c['product_count'] ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="filter-block">
        <h6>Brand</h6>
        <div class="filter-list">
          <a href="<?= e($withParam('brand', null)) ?>" class="<?= $filters['brand'] === '' ? 'is-on' : '' ?>">All brands</a>
          <?php foreach ($brands as $b): ?>
            <a href="<?= e($withParam('brand', $b['slug'])) ?>" class="<?= $filters['brand'] === $b['slug'] ? 'is-on' : '' ?>">
              <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= e($b['accent']) ?>;margin-right:.45rem"></span><?= e($b['name']) ?></span>
              <span class="n"><?= (int) $b['product_count'] ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="filter-block">
        <h6>Price</h6>
        <form method="get" class="d-flex gap-2 align-items-center">
          <?php foreach ($_GET as $k => $v): if (in_array($k, ['min','max','page'], true)) continue; ?>
            <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
          <?php endforeach; ?>
          <input class="input-fc" type="number" name="min" min="0" step="10" placeholder="Min" value="<?= e((string) $filters['min']) ?>" style="padding:.5rem .6rem" aria-label="Minimum price">
          <span class="text-muted-3">–</span>
          <input class="input-fc" type="number" name="max" min="0" step="10" placeholder="Max" value="<?= e((string) $filters['max']) ?>" style="padding:.5rem .6rem" aria-label="Maximum price">
          <button class="btn-fc btn-fc--sm" type="submit" aria-label="Apply price filter"><i class="fa-solid fa-check"></i></button>
        </form>
      </div>

      <div class="filter-block">
        <h6>Show only</h6>
        <div class="filter-list">
          <a href="<?= e($withParam('in_stock', $filters['in_stock'] ? null : '1')) ?>" class="<?= $filters['in_stock'] ? 'is-on' : '' ?>">
            <span><i class="fa-solid <?= $filters['in_stock'] ? 'fa-square-check' : 'fa-square' ?> me-2"></i>In stock</span>
          </a>
          <a href="<?= e($withParam('sale', $filters['sale'] ? null : '1')) ?>" class="<?= $filters['sale'] ? 'is-on' : '' ?>">
            <span><i class="fa-solid <?= $filters['sale'] ? 'fa-square-check' : 'fa-square' ?> me-2"></i>On promotion</span>
          </a>
        </div>
      </div>

      <?php if (!$isRes): ?>
      <div class="filter-block">
        <div class="card-fc card-fc--pad" style="background:var(--leaf-wash);border-color:#BFE3D5">
          <b style="font-family:var(--font-display);display:block;margin-bottom:.3rem">Free delivery over <?= format_price_short((float) get_setting('free_shipping_threshold', '1500')) ?></b>
          <p style="font-size:.82rem;color:var(--text-2);margin-bottom:.8rem">
            Courier to your door in 2–4 working days, anywhere in South Africa. Every order is confirmed
            with you on WhatsApp first.
          </p>
          <a href="<?= e(wa_link("Hi CureMeds, I have a question about a product.")) ?>" target="_blank" rel="noopener" class="btn-fc btn-fc--whatsapp btn-fc--sm btn-fc--block"><i class="fa-brands fa-whatsapp"></i> Ask on WhatsApp</a>
        </div>
      </div>
      <?php endif; ?>

      <?php foreach (get_banners('shop', 'tall') as $tile): ?>
        <div class="filter-block">
          <?php $tileHref = banner_link($tile['link_url']); ?>
          <a class="promo-tile" <?= $tileHref ? 'href="' . e($tileHref) . '"' : '' ?>>
            <img src="<?= e(product_image($tile['image'])) ?>" alt="<?= e($tile['alt_text'] ?: $tile['title']) ?>" loading="lazy" decoding="async">
          </a>
        </div>
      <?php endforeach; ?>
    </aside>

    <!-- ── Results ────────────────────────────────────────────────── -->
    <div>
      <div class="shop-toolbar">
        <button class="btn-fc btn-fc--ghost btn-fc--sm d-lg-none" type="button" id="filterToggle">
          <i class="fa-solid fa-sliders"></i> Filters<?= $hasFilters ? ' <span class="badge-fc badge-fc--leaf ms-1">on</span>' : '' ?>
        </button>

        <div class="count">
          <b><?= (int) $result['total'] ?></b> product<?= $result['total'] === 1 ? '' : 's' ?>
          <?php if ($result['pages'] > 1): ?>
            <span class="text-muted-3">· page <?= $result['page'] ?> of <?= $result['pages'] ?></span>
          <?php endif; ?>
        </div>

        <form method="get" class="d-flex align-items-center gap-2">
          <?php foreach ($_GET as $k => $v): if (in_array($k, ['sort','page'], true)) continue; ?>
            <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
          <?php endforeach; ?>
          <label for="sort" class="text-muted-3" style="font-size:.8rem;white-space:nowrap">Sort by</label>
          <select class="select-fc" name="sort" id="sort" onchange="this.form.submit()" style="padding:.45rem 2.2rem .45rem .7rem;font-size:.84rem;width:auto">
            <?php foreach ([
              'featured'   => 'Recommended',
              'popular'    => 'Best selling',
              'newest'     => 'Newest first',
              'price_asc'  => 'Price: low to high',
              'price_desc' => 'Price: high to low',
              'name'       => 'Name A–Z',
            ] as $v => $label): ?>
              <option value="<?= $v ?>" <?= $filters['sort'] === $v ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <?php if ($hasFilters): ?>
      <div class="d-flex gap-2 flex-wrap mb-3">
        <?php
        $pills = [];
        if ($activeCategory)          { $pills[] = ['category', $activeCategory['name']]; }
        if ($activeBrand)             { $pills[] = ['brand', $activeBrand['name']]; }
        if ($filters['search'] !== '') { $pills[] = ['q', '“' . $filters['search'] . '”']; }
        if ($filters['sale'])          { $pills[] = ['sale', 'On promotion']; }
        if ($filters['in_stock'])      { $pills[] = ['in_stock', 'In stock']; }
        if ($filters['min'] !== '')    { $pills[] = ['min', 'From ' . format_price_short((float) $filters['min'])]; }
        if ($filters['max'] !== '')    { $pills[] = ['max', 'Up to ' . format_price_short((float) $filters['max'])]; }
        foreach ($pills as [$key, $label]): ?>
          <a class="chip is-on" href="<?= e($withParam($key, null)) ?>"><?= e($label) ?> <i class="fa-solid fa-xmark ms-1"></i></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!$result['items']): ?>
        <div class="empty card-fc">
          <div class="empty__icon"><i class="fa-solid fa-magnifying-glass"></i></div>
          <h3>Nothing matched those filters</h3>
          <p class="mb-4">Try widening the price range, or clear the filters and browse everything.</p>
          <a href="<?= SITE_URL ?>/shop.php" class="btn-fc">Show all products</a>
        </div>
      <?php else: ?>
        <div class="grid-products">
          <?php foreach ($result['items'] as $product):
            $in_wishlist = in_array((int) $product['id'], $wish, true);
            include __DIR__ . '/includes/product-card.php';
          endforeach; ?>
        </div>
        <?= render_pager($result['page'], $result['pages']) ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
