<?php
/**
 * Site header: announcement bar, brand, live search, mega menu, account menu,
 * cart, mobile drawer and the mobile tab bar.
 * Expects $current_user, $cart_count, $wishlist_count, $is_reseller from header.php.
 */
$script  = $_SERVER['SCRIPT_NAME'] ?? '';
$current = basename($script, '.php');
$inDir   = fn(string $dir) => str_contains($script, "/$dir/");
$active  = fn(bool $on) => $on ? 'is-active' : '';

$nav_categories = get_categories();
$nav_brands     = get_brands();
$announcement   = get_setting('announcement', '');
?>

<?php if ($announcement !== ''): ?>
<div class="announce">
  <div class="container">
    <span><i class="fa-solid fa-bolt me-2" style="color:var(--amber)"></i><?= e($announcement) ?></span>
    <?php if (!$is_reseller): ?>
      <a href="<?= SITE_URL ?>/shop.php" class="d-none d-md-inline">Shop now <i class="fa-solid fa-arrow-right ms-1"></i></a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<header class="site-header">
  <div class="container header-row">

    <a href="<?= SITE_URL ?>/index.php" class="brand" aria-label="<?= e(SITE_NAME) ?> home">
      <img src="<?= SITE_URL ?>/assets/img/logo/curemeds-mark.svg" alt="" class="brand__mark">
      <span class="brand__word">Cure<em>Meds</em></span>
    </a>

    <form class="header-search" action="<?= SITE_URL ?>/shop.php" method="get" role="search" autocomplete="off">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" id="siteSearch" placeholder="Search vitamins, immune support, devices…"
             value="<?= e($_GET['q'] ?? '') ?>" aria-label="Search products">
      <div class="suggest" id="searchSuggest" role="listbox" aria-label="Search suggestions"></div>
    </form>

    <nav class="desktop-nav" aria-label="Primary">
      <div class="has-mega">
        <a href="<?= SITE_URL ?>/shop.php" class="<?= $active($current === 'shop') ?>">
          Shop <i class="fa-solid fa-chevron-down ms-1" style="font-size:.6rem"></i>
        </a>
        <div class="mega">
          <div class="container mega__grid">
            <div>
              <div class="eyebrow">Shop by category</div>
              <div class="mega__cats">
                <?php foreach ($nav_categories as $c): ?>
                <a class="mega__cat" href="<?= SITE_URL ?>/shop.php?category=<?= e($c['slug']) ?>">
                  <i class="fa-solid <?= e($c['icon'] ?: 'fa-tag') ?>"></i>
                  <span><b><?= e($c['name']) ?></b><span><?= (int) $c['product_count'] ?> products</span></span>
                </a>
                <?php endforeach; ?>
              </div>

              <div class="eyebrow mt-4">Shop by brand</div>
              <div class="d-flex gap-2 flex-wrap">
                <?php foreach ($nav_brands as $b): ?>
                  <a class="chip" href="<?= SITE_URL ?>/shop.php?brand=<?= e($b['slug']) ?>">
                    <span style="width:8px;height:8px;border-radius:50%;background:<?= e($b['accent']) ?>"></span>
                    <?= e($b['name']) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="mega__promo">
              <?php if ($is_reseller): ?>
                <span class="badge-fc badge-fc--amber mb-2"><?= e(RESELLER_TIERS[$current_user['reseller_tier']]['label']) ?> reseller</span>
                <h4>Your wholesale prices are live</h4>
                <p>Every price on the site is your cost, with the margin shown next to it.</p>
                <a href="<?= SITE_URL ?>/price-list.php" class="btn-fc btn-fc--amber btn-fc--sm mt-2">
                  <i class="fa-solid fa-file-lines"></i> My price list
                </a>
              <?php else: ?>
                <span class="badge-fc badge-fc--amber mb-2"><i class="fa-brands fa-whatsapp"></i> Order on WhatsApp</span>
                <h4>Not sure what you need?</h4>
                <p>Tell us what you are managing and what you already take. We reply the same day.</p>
                <a href="<?= e(wa_link("Hi CureMeds, I'd like some help choosing a product.")) ?>" target="_blank" rel="noopener" class="btn-fc btn-fc--whatsapp btn-fc--sm mt-2">
                  <i class="fa-brands fa-whatsapp"></i> Ask us
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <a href="<?= SITE_URL ?>/price-list.php" class="<?= $active($current === 'price-list') ?>">Price list</a>
      <a href="<?= SITE_URL ?>/dashboards/customer/index.php" class="<?= $active($inDir('customer')) ?>">Patient portal</a>
      <a href="<?= SITE_URL ?>/modules/blog/index.php" class="<?= $active($inDir('blog')) ?>">Learn</a>
      <a href="<?= SITE_URL ?>/about.php"      class="<?= $active($current === 'about') ?>">About</a>
      <a href="<?= SITE_URL ?>/testimonials.php" class="<?= $active($current === 'testimonials') ?>">Reviews</a>
      <a href="<?= SITE_URL ?>/contact.php"    class="<?= $active($current === 'contact') ?>">Contact</a>
    </nav>

    <div class="header-actions">
      <?php if ($current_user): ?>
        <div class="dd" id="accountDd">
          <button class="icon-btn" type="button" data-dd-toggle aria-label="Account menu" aria-expanded="false">
            <i class="fa-solid fa-user"></i>
          </button>
          <div class="dd__menu">
            <div class="dd__head">
              <b><?= e($current_user['name']) ?></b>
              <span><?= e($current_user['email'] ?: Health::displayPhone($current_user['phone'] ?? '')) ?></span>
              <?php if ($is_reseller): ?>
                <span class="pill pill--warn mt-2 d-inline-flex">
                  <i class="fa-solid fa-star"></i><?= e(RESELLER_TIERS[$current_user['reseller_tier']]['label']) ?> · <?= e((string) $current_user['reseller_code']) ?>
                </span>
              <?php elseif (($current_user['reseller_status'] ?? '') === 'pending'): ?>
                <span class="pill pill--muted mt-2 d-inline-flex"><i class="fa-solid fa-hourglass-half"></i>Reseller application under review</span>
              <?php endif; ?>
            </div>

            <?php if ($current_user['role'] === 'admin'): ?>
              <a href="<?= SITE_URL ?>/dashboards/admin/index.php"><i class="fa-solid fa-gauge-high"></i>Admin dashboard</a>
            <?php endif; ?>
            <?php if ($is_reseller): ?>
              <a href="<?= SITE_URL ?>/dashboards/reseller/index.php"><i class="fa-solid fa-chart-line"></i>Reseller dashboard</a>
            <?php endif; ?>
            <a href="<?= SITE_URL ?>/dashboards/customer/index.php"><i class="fa-solid fa-heart-pulse"></i>My health tracker</a>
            <a href="<?= SITE_URL ?>/dashboards/customer/medications.php"><i class="fa-solid fa-pills"></i>My medication</a>
            <a href="<?= SITE_URL ?>/dashboards/customer/side-effects.php"><i class="fa-solid fa-notes-medical"></i>Side effects</a>
            <a href="<?= SITE_URL ?>/dashboards/customer/wishlist.php"><i class="fa-regular fa-heart"></i>Saved items</a>
            <div class="dd__sep"></div>
            <a href="<?= SITE_URL ?>/modules/auth/logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i>Sign out</a>
          </div>
        </div>
      <?php else: ?>
        <a href="<?= SITE_URL ?>/modules/auth/login.php" class="icon-btn" aria-label="Sign in"><i class="fa-regular fa-user"></i></a>
      <?php endif; ?>

      <a href="<?= SITE_URL ?>/dashboards/customer/wishlist.php" class="icon-btn d-none d-md-inline-flex" aria-label="Saved items">
        <i class="fa-regular fa-heart"></i>
        <?php if ($wishlist_count > 0): ?><span class="icon-btn__badge"><?= $wishlist_count ?></span><?php endif; ?>
      </a>

      <a href="<?= SITE_URL ?>/cart.php" class="icon-btn" aria-label="Enquiry list" title="Enquiry list">
        <i class="fa-solid fa-clipboard-list"></i>
        <span class="icon-btn__badge<?= $cart_count ? '' : ' d-none' ?>" data-cart-badge><?= $cart_count ?></span>
      </a>

      <button class="icon-btn d-lg-none" type="button" id="menuToggle" aria-label="Open menu" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
      </button>
    </div>
  </div>
</header>

<!-- Mobile drawer -->
<div class="scrim" id="scrim"></div>
<aside class="drawer" id="drawer" aria-label="Menu">
  <div class="drawer__head">
    <span class="brand__word">Cure<em>Meds</em></span>
    <button class="icon-btn" type="button" id="drawerClose" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
  </div>

  <form class="px-3" action="<?= SITE_URL ?>/shop.php" method="get" role="search">
    <div class="position-relative">
      <input class="input-fc" type="search" name="q" placeholder="Search the catalogue…" value="<?= e($_GET['q'] ?? '') ?>" aria-label="Search">
    </div>
  </form>

  <nav aria-label="Mobile">
    <a href="<?= SITE_URL ?>/index.php"      class="<?= $active($current === 'index' && !$inDir('dashboards')) ?>">Home</a>
    <a href="<?= SITE_URL ?>/shop.php"       class="<?= $active($current === 'shop') ?>">Shop all</a>
    <a href="<?= SITE_URL ?>/price-list.php" class="<?= $active($current === 'price-list') ?>">Price list</a>
    <a href="<?= SITE_URL ?>/dashboards/customer/index.php" class="<?= $active($inDir('customer')) ?>">Patient portal</a>
    <a href="<?= SITE_URL ?>/cart.php"       class="<?= $active($current === 'cart') ?>">Enquiry list</a>
    <a href="<?= SITE_URL ?>/modules/blog/index.php" class="<?= $active($inDir('blog')) ?>">Learn</a>
    <a href="<?= SITE_URL ?>/about.php"      class="<?= $active($current === 'about') ?>">About</a>
    <a href="<?= SITE_URL ?>/testimonials.php" class="<?= $active($current === 'testimonials') ?>">Reviews</a>
    <a href="<?= SITE_URL ?>/faq.php"        class="<?= $active($current === 'faq') ?>">FAQ</a>
    <a href="<?= SITE_URL ?>/contact.php"    class="<?= $active($current === 'contact') ?>">Contact</a>
  </nav>

  <div class="px-3 pb-3">
    <div class="eyebrow">Categories</div>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($nav_categories as $c): ?>
        <a class="chip" href="<?= SITE_URL ?>/shop.php?category=<?= e($c['slug']) ?>"><?= e($c['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="px-3 pb-4 mt-auto">
    <?php if ($current_user): ?>
      <a href="<?= SITE_URL ?>/modules/auth/logout.php" class="btn-fc btn-fc--ghost btn-fc--block">Sign out</a>
    <?php else: ?>
      <a href="<?= SITE_URL ?>/modules/auth/login.php" class="btn-fc btn-fc--block mb-2">Patient sign-in</a>
      <a href="<?= e(wa_link()) ?>" target="_blank" rel="noopener" class="btn-fc btn-fc--whatsapp btn-fc--block"><i class="fa-brands fa-whatsapp"></i> WhatsApp us</a>
    <?php endif; ?>
  </div>
</aside>

<!-- Mobile tab bar -->
<nav class="tabbar" aria-label="Quick navigation">
  <a href="<?= SITE_URL ?>/index.php" class="<?= $active($current === 'index' && !$inDir('dashboards')) ?>"><i class="fa-solid fa-house"></i>Home</a>
  <a href="<?= SITE_URL ?>/shop.php"  class="<?= $active($current === 'shop') ?>"><i class="fa-solid fa-grid-2"></i><i class="fa-solid fa-shop d-none"></i>Shop</a>
  <a href="<?= e(wa_link()) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp" style="color:var(--wa)"></i>WhatsApp</a>
  <a href="<?= SITE_URL ?>/cart.php"  class="<?= $active($current === 'cart') ?>">
    <i class="fa-solid fa-clipboard-list"></i>List
    <span class="tab-badge<?= $cart_count ? '' : ' d-none' ?>" data-cart-badge><?= $cart_count ?></span>
  </a>
  <a href="<?= $current_user ? SITE_URL . '/dashboards/customer/index.php' : SITE_URL . '/modules/auth/login.php' ?>"
     class="<?= $active($inDir('dashboards')) ?>"><i class="fa-solid fa-heart-pulse"></i>Tracker</a>
</nav>
