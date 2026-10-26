<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

// Testimonials: message screenshots, longer stories with a photo or video, and short quotes (Admin → Testimonials).
$shots   = db()->query("SELECT * FROM testimonials WHERE status = 'published' AND screenshot IS NOT NULL AND screenshot <> '' ORDER BY sort_order, id DESC")->fetchAll();
$stories = db()->query("SELECT * FROM testimonials WHERE status = 'published' AND (story IS NOT NULL OR video_url IS NOT NULL) ORDER BY sort_order, id DESC")->fetchAll();
$quotes  = db()->query("SELECT * FROM testimonials WHERE status = 'published' AND quote <> '' AND story IS NULL AND video_url IS NULL ORDER BY sort_order, id DESC LIMIT 24")->fetchAll();

/** YouTube links become an embedded player; other video links open on their site. */
$youtube = function (string $url): ?string {
    return preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/))([\w-]{11})~', $url, $m) ? 'https://www.youtube-nocookie.com/embed/' . $m[1] : null;
};

$page_title       = 'Testimonials — what CureMeds customers say | ' . SITE_NAME;
$meta_description = 'Real CureMeds customers in their own words: their messages, their stories and how they keep their health routine on track.';
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Testimonials</span></nav>
    <h1>What our customers say</h1>
    <p>Real customers, in their own words. Shared with their permission.</p>
  </div>
</header>

<div class="section section--tight">
  <div class="container">
    <?php if (!$shots && !$stories && !$quotes): ?>
      <div class="empty card-fc"><div class="empty__icon"><i class="fa-solid fa-comment-dots"></i></div><h2>Testimonials coming soon</h2><p>Bought from us? We'd love to hear how it went — WhatsApp us.</p></div>
    <?php endif; ?>

    <?php if ($shots): ?>
    <div class="cm-shots mb-4">
      <?php foreach ($shots as $t): ?>
      <a class="cm-shot" href="<?= e(product_image($t['screenshot'])) ?>" data-lightbox data-caption="<?= e(trim($t['name'] . ' · ' . ($t['location'] ?? ''), ' ·')) ?>">
        <img src="<?= e(product_image($t['screenshot'])) ?>" alt="Message from <?= e($t['name']) ?>" loading="lazy">
        <span><?= e($t['name']) ?><?= $t['location'] ? ' · ' . e($t['location']) : '' ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($stories as $s): $embed = $s['video_url'] ? $youtube((string) $s['video_url']) : null; ?>
    <article class="story">
      <?php if ($embed): ?>
        <div class="story__media"><iframe src="<?= e($embed) ?>" title="<?= e($s['name']) ?>'s story" loading="lazy" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>
      <?php elseif ($s['avatar']): ?>
        <div class="story__media story__media--photo"><img src="<?= e(product_image($s['avatar'])) ?>" alt="<?= e($s['name']) ?>" loading="lazy"></div>
      <?php endif; ?>
      <div class="story__body">
        <?php if ($s['quote'] !== ''): ?><h2>&ldquo;<?= e($s['quote']) ?>&rdquo;</h2><?php endif; ?>
        <?php if ($s['story']): ?><div class="prose"><?= nl2br(e((string) $s['story'])) ?></div><?php endif; ?>
        <?php if ($s['video_url'] && !$embed): ?><a class="text-leaf fw-bold" href="<?= e($s['video_url']) ?>" target="_blank" rel="noopener">Watch the video <i class="fa-solid fa-play"></i></a><?php endif; ?>
        <p class="story__who"><b><?= e($s['name']) ?></b> <?= e(trim(($s['role'] ?? '') . ' · ' . ($s['location'] ?? ''), ' ·')) ?> <?= stars_html((float) $s['rating']) ?></p>
      </div>
    </article>
    <?php endforeach; ?>

    <?php if ($quotes): ?>
    <div class="row g-3">
      <?php foreach ($quotes as $t): ?>
      <div class="col-lg-3 col-md-6"><div class="quote-card"><?= stars_html((float) $t['rating']) ?><p class="mt-2">&ldquo;<?= e($t['quote']) ?>&rdquo;</p>
        <div class="quote-card__who"><span class="quote-card__av"><?= e(mb_substr($t['name'], 0, 1)) ?></span><span><b><?= e($t['name']) ?></b><span><?= e(trim(($t['role'] ?? '') . ($t['location'] ? ' · ' . $t['location'] : ''), ' ·')) ?></span></span></div></div></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card-fc card-fc--pad mt-5 text-center">
      <h2 style="font-size:1.3rem">Would you share yours?</h2>
      <p class="text-muted-2">Your story could help someone who has just been diagnosed. We only publish your first name and town, and only with your permission.</p>
      <a class="btn-fc btn-fc--whatsapp" href="<?= e(wa_link("Hi CureMeds, I'd like to share my story.")) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Share my story</a>
    </div>
  </div>
</div>

<?php if ($shots) { include __DIR__ . '/includes/lightbox.php'; } ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
