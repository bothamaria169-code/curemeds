<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';

$post = get_blog_by_slug(trim((string) ($_GET['slug'] ?? '')));

if (!$post || ($post['status'] !== 'published' && !is_admin())) {
    http_response_code(404);
    $page_title = 'Article not found — ' . SITE_NAME;
    include __DIR__ . '/../../includes/header.php';
    echo '<div class="section"><div class="container empty card-fc">'
       . '<div class="empty__icon"><i class="fa-solid fa-newspaper"></i></div>'
       . '<h1>We could not find that article</h1>'
       . '<a href="' . SITE_URL . '/modules/blog/index.php" class="btn-fc mt-3">All articles</a></div></div>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$more = get_blogs(['category' => $post['category']], 1, 4)['items'];
$more = array_values(array_filter($more, fn($p) => (int) $p['id'] !== (int) $post['id']));
if (count($more) < 3) {
    foreach (get_blogs([], 1, 5)['items'] as $p) {
        if (count($more) >= 3) break;
        if ((int) $p['id'] !== (int) $post['id'] && !in_array($p['id'], array_column($more, 'id'), false)) {
            $more[] = $p;
        }
    }
}
$more = array_slice($more, 0, 3);

$page_title       = $post['title'] . ' — ' . SITE_NAME;
$meta_description = excerpt($post['excerpt'] ?: $post['content'], 155);
$og_image         = product_image($post['image']);
$json_ld = [
    '@type'         => 'Article',
    'headline'      => $post['title'],
    'image'         => $og_image,
    'datePublished' => date('c', strtotime($post['created_at'])),
    'dateModified'  => date('c', strtotime($post['updated_at'])),
    'author'        => ['@type' => 'Organization', 'name' => SITE_NAME],
    'publisher'     => ['@type' => 'Organization', 'name' => SITE_NAME],
];
include __DIR__ . '/../../includes/header.php';
?>

<header class="page-head">
  <div class="container container--narrow">
    <nav class="crumbs">
      <a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i>
      <a href="<?= SITE_URL ?>/modules/blog/index.php">Learn</a><i class="fa-solid fa-chevron-right"></i>
      <span><?= e($post['category']) ?></span>
    </nav>
    <span class="badge-fc badge-fc--amber mb-2"><?= e($post['category']) ?></span>
    <h1><?= e($post['title']) ?></h1>
    <p class="d-flex gap-3 flex-wrap" style="font-size:.85rem">
      <span><i class="fa-regular fa-clock me-1"></i><?= (int) $post['read_mins'] ?> min read</span>
      <span><i class="fa-regular fa-calendar me-1"></i><?= date('j F Y', strtotime($post['created_at'])) ?></span>
      <?php if ($post['author_name']): ?><span><i class="fa-regular fa-user me-1"></i><?= e($post['author_name']) ?></span><?php endif; ?>
    </p>
  </div>
</header>

<article class="section section--tight">
  <div class="container container--narrow">
    <?php if ($post['image']): ?>
      <img src="<?= e(product_image($post['image'])) ?>" alt="<?= e($post['title']) ?>"
           style="width:100%;border-radius:var(--r-xl);margin-bottom:2rem;aspect-ratio:16/8;object-fit:cover;background:var(--paper-2)">
    <?php endif; ?>

    <?php if ($post['excerpt']): ?>
      <p class="lede" style="font-size:1.15rem;color:var(--text);border-left:3px solid var(--leaf);padding-left:1.1rem">
        <?= e($post['excerpt']) ?>
      </p>
    <?php endif; ?>

    <div class="prose" style="max-width:none;font-size:1rem">
      <?= $post['content'] ?>
    </div>

    <div class="card-fc card-fc--pad mt-4" style="background:var(--leaf-wash);border-color:#BFE3D5">
      <div class="d-flex gap-3 align-items-center flex-wrap">
        <div style="flex:1;min-width:220px">
          <b style="font-family:var(--font-display);font-size:1.05rem;display:block">Everything here is in the catalogue</b>
          <span style="font-size:.88rem;color:var(--text-2)">Live prices, live stock, and your margin once you are an approved reseller.</span>
        </div>
        <div class="d-flex gap-2">
          <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--sm">Shop</a>
          <a href="<?= SITE_URL ?>/price-list.php" class="btn-fc btn-fc--ghost btn-fc--sm">Price list</a>
        </div>
      </div>
    </div>
  </div>
</article>

<?php if ($more): ?>
<section class="section section--tight" style="background:var(--card);border-top:1px solid var(--line)">
  <div class="container">
    <div class="section__head"><span class="eyebrow">Keep reading</span><h2>More from Learn</h2></div>
    <div class="row g-3">
      <?php foreach ($more as $p): ?>
      <div class="col-lg-4 col-md-6">
        <a class="post-card" href="<?= SITE_URL ?>/modules/blog/post.php?slug=<?= e($p['slug']) ?>">
          <div class="post-card__media"><img src="<?= e(product_image($p['image'])) ?>" alt="" loading="lazy"></div>
          <div class="post-card__body">
            <span class="badge-fc badge-fc--muted mb-2" style="align-self:flex-start"><?= e($p['category']) ?></span>
            <h3><?= e($p['title']) ?></h3>
            <p><?= e(excerpt($p['excerpt'], 100)) ?></p>
            <div class="post-card__meta"><span><?= (int) $p['read_mins'] ?> min read</span></div>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
