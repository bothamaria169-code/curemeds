<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';

$page    = max(1, (int) ($_GET['page'] ?? 1));
$category= trim((string) ($_GET['category'] ?? ''));
$search  = trim((string) ($_GET['q'] ?? ''));
$result  = get_blogs(['category' => $category ?: null, 'search' => $search ?: null], $page, 9);

$categories = db()->query("SELECT category, COUNT(*) n FROM blogs WHERE status='published' GROUP BY category ORDER BY category")->fetchAll();

$page_title       = 'Learn — ' . SITE_NAME;
$meta_description = 'Pricing your stock, reading a supplement label honestly, getting the most out of what you buy, and how delivery actually works.';
include __DIR__ . '/../../includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Learn</span></nav>
    <h1>Learn</h1>
    <p>How to price stock so it moves, how to read a supplement label without falling for the hype, and how to get the most out of what you buy.</p>
  </div>
</header>

<div class="section section--tight">
  <div class="container">

    <div class="d-flex gap-2 flex-wrap mb-4 align-items-center justify-content-between">
      <div class="d-flex gap-2 flex-wrap">
        <a class="chip <?= $category === '' ? 'is-on' : '' ?>" href="<?= SITE_URL ?>/modules/blog/index.php">All</a>
        <?php foreach ($categories as $c): ?>
          <a class="chip <?= $category === $c['category'] ? 'is-on' : '' ?>" href="?category=<?= urlencode($c['category']) ?>">
            <?= e($c['category']) ?> · <?= (int) $c['n'] ?>
          </a>
        <?php endforeach; ?>
      </div>
      <form method="get" class="d-flex gap-2" style="max-width:320px">
        <input class="input-fc" type="search" name="q" value="<?= e($search) ?>" placeholder="Search articles" style="padding:.5rem .8rem">
        <button class="btn-fc btn-fc--sm" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
      </form>
    </div>

    <?php if (!$result['items']): ?>
      <div class="empty card-fc">
        <div class="empty__icon"><i class="fa-solid fa-newspaper"></i></div>
        <h3>Nothing here yet</h3>
        <p class="mb-3">No articles match that.</p>
        <a href="<?= SITE_URL ?>/modules/blog/index.php" class="btn-fc">See all articles</a>
      </div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($result['items'] as $i => $post):
          $wide = $i === 0 && $page === 1 && $post['is_featured']; ?>
        <div class="col-lg-<?= $wide ? '12' : '4' ?> col-md-6" data-reveal>
          <a class="post-card" href="<?= SITE_URL ?>/modules/blog/post.php?slug=<?= e($post['slug']) ?>"
             <?= $wide ? 'style="flex-direction:row"' : '' ?>>
            <div class="post-card__media" <?= $wide ? 'style="flex:0 0 45%;aspect-ratio:auto"' : '' ?>>
              <img src="<?= e(product_image($post['image'])) ?>" alt="<?= e($post['title']) ?>" loading="lazy">
            </div>
            <div class="post-card__body">
              <div class="d-flex gap-2 mb-2">
                <span class="badge-fc badge-fc--muted"><?= e($post['category']) ?></span>
                <?php if ($post['is_featured']): ?><span class="badge-fc badge-fc--amber">Featured</span><?php endif; ?>
              </div>
              <h3 <?= $wide ? 'style="font-size:1.5rem"' : '' ?>><?= e($post['title']) ?></h3>
              <p><?= e(excerpt($post['excerpt'], $wide ? 220 : 110)) ?></p>
              <div class="post-card__meta">
                <span><i class="fa-regular fa-clock me-1"></i><?= (int) $post['read_mins'] ?> min read</span>
                <span><?= date('j M Y', strtotime($post['created_at'])) ?></span>
                <?php if ($post['author_name']): ?><span><?= e($post['author_name']) ?></span><?php endif; ?>
              </div>
            </div>
          </a>
        </div>
        <?php endforeach; ?>
      </div>
      <?= render_pager($result['page'], $result['pages']) ?>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
