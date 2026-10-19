<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

/**
 * Serves XML to crawlers (sitemap.php?xml=1, and via the .htaccess rewrite from
 * /sitemap.xml) and a readable HTML index to people.
 */
$products   = db()->query("SELECT slug, updated_at FROM products WHERE status='published' ORDER BY updated_at DESC")->fetchAll();
$posts      = db()->query("SELECT slug, updated_at FROM blogs WHERE status='published' ORDER BY updated_at DESC")->fetchAll();
$categories = get_categories();
$brands     = get_brands();

$statics = [
    ['', '1.0', 'daily'],
    ['/shop.php', '0.9', 'daily'],
    ['/price-list.php', '0.9', 'daily'],
    ['/about.php', '0.6', 'monthly'],
    ['/faq.php', '0.6', 'monthly'],
    ['/contact.php', '0.6', 'monthly'],
    ['/modules/blog/index.php', '0.7', 'weekly'],
    ['/terms.php', '0.3', 'yearly'],
    ['/privacy.php', '0.3', 'yearly'],
    ['/refund-policy.php', '0.4', 'yearly'],
];

if (isset($_GET['xml'])) {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    $url = function (string $loc, string $priority, string $freq, ?string $lastmod = null) {
        echo "  <url>\n    <loc>" . htmlspecialchars(SITE_URL . $loc, ENT_XML1) . "</loc>\n";
        if ($lastmod) { echo '    <lastmod>' . date('Y-m-d', strtotime($lastmod)) . "</lastmod>\n"; }
        echo "    <changefreq>$freq</changefreq>\n    <priority>$priority</priority>\n  </url>\n";
    };

    foreach ($statics as [$loc, $p, $f])  { $url($loc ?: '/', $p, $f); }
    foreach ($categories as $c)           { $url('/shop.php?category=' . urlencode($c['slug']), '0.7', 'weekly'); }
    foreach ($brands as $b)               { $url('/shop.php?brand=' . urlencode($b['slug']), '0.7', 'weekly'); }
    foreach ($products as $p)             { $url('/modules/shop/product.php?slug=' . urlencode($p['slug']), '0.8', 'weekly', $p['updated_at']); }
    foreach ($posts as $b)                { $url('/modules/blog/post.php?slug=' . urlencode($b['slug']), '0.6', 'monthly', $b['updated_at']); }

    echo '</urlset>';
    exit;
}

$page_title       = 'Sitemap — ' . SITE_NAME;
$meta_description = 'Every page on CureMeds in one list.';
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Sitemap</span></nav>
    <h1>Sitemap</h1>
    <p>Everything on the site, in one list. The <a href="<?= SITE_URL ?>/sitemap.php?xml=1" style="color:var(--amber)">XML version</a> is what search engines read.</p>
  </div>
</header>

<div class="section section--tight">
  <div class="container">
    <div class="row g-4">

      <div class="col-lg-3 col-md-6">
        <h2 style="font-size:1.05rem">Main pages</h2>
        <div class="filter-list">
          <a href="<?= SITE_URL ?>/index.php">Home</a>
          <a href="<?= SITE_URL ?>/shop.php">Shop</a>
          <a href="<?= SITE_URL ?>/price-list.php">Price list</a>
          <a href="<?= SITE_URL ?>/modules/auth/login.php">Patient portal</a>
          <a href="<?= SITE_URL ?>/modules/blog/index.php">Learn</a>
          <a href="<?= SITE_URL ?>/about.php">About</a>
          <a href="<?= SITE_URL ?>/faq.php">FAQ</a>
          <a href="<?= SITE_URL ?>/contact.php">Contact</a>
          <a href="<?= SITE_URL ?>/compare.php">Compare</a>
        </div>
        <h2 style="font-size:1.05rem" class="mt-4">Legal</h2>
        <div class="filter-list">
          <a href="<?= SITE_URL ?>/terms.php">Terms</a>
          <a href="<?= SITE_URL ?>/privacy.php">Privacy</a>
          <a href="<?= SITE_URL ?>/refund-policy.php">Returns &amp; refunds</a>
          <a href="<?= SITE_URL ?>/reseller-programme.php">Reseller programme</a>
        </div>
      </div>

      <div class="col-lg-3 col-md-6">
        <h2 style="font-size:1.05rem">Categories</h2>
        <div class="filter-list">
          <?php foreach ($categories as $c): ?>
            <a href="<?= SITE_URL ?>/shop.php?category=<?= e($c['slug']) ?>">
              <?= e($c['name']) ?> <span class="n"><?= (int) $c['product_count'] ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <h2 style="font-size:1.05rem" class="mt-4">Brands</h2>
        <div class="filter-list">
          <?php foreach ($brands as $b): ?>
            <a href="<?= SITE_URL ?>/shop.php?brand=<?= e($b['slug']) ?>">
              <?= e($b['name']) ?> <span class="n"><?= (int) $b['product_count'] ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-lg-4 col-md-6">
        <h2 style="font-size:1.05rem">Products <span class="text-muted-3" style="font-size:.8rem;font-weight:400">(<?= count($products) ?>)</span></h2>
        <div class="filter-list" style="max-height:520px;overflow-y:auto">
          <?php foreach ($products as $p): ?>
            <a href="<?= SITE_URL ?>/modules/shop/product.php?slug=<?= e($p['slug']) ?>"><?= e(ucwords(str_replace('-', ' ', $p['slug']))) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-lg-2 col-md-6">
        <h2 style="font-size:1.05rem">Articles</h2>
        <div class="filter-list">
          <?php foreach ($posts as $b): ?>
            <a href="<?= SITE_URL ?>/modules/blog/post.php?slug=<?= e($b['slug']) ?>"><?= e(ucfirst(str_replace('-', ' ', $b['slug']))) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
