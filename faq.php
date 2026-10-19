<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$rows = db()->query("SELECT * FROM faqs WHERE status='published' ORDER BY topic, sort_order, id")->fetchAll();

$byTopic = [];
foreach ($rows as $r) { $byTopic[$r['topic']][] = $r; }

$page_title       = 'Frequently asked questions — ' . SITE_NAME;
$meta_description = 'Delivery, payment, returns and product questions — everything people actually ask before ordering, answered plainly.';
$json_ld = [
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(fn($r) => [
        '@type'          => 'Question',
        'name'           => $r['question'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $r['answer']],
    ], $rows),
];
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>FAQ</span></nav>
    <h1>Questions people actually ask</h1>
    <p>Delivery, payment, returns and the products themselves. If yours is not here, the assistant in the corner or the contact form will get you an answer.</p>
  </div>
</header>

<div class="section section--tight">
  <div class="container">
    <div class="row g-4">

      <div class="col-lg-3">
        <nav class="filters" style="position:sticky;top:calc(var(--header-h) + 16px)" aria-label="FAQ topics">
          <h2 style="font-size:1rem">Topics</h2>
          <div class="filter-list">
            <?php foreach ($byTopic as $topic => $items): ?>
              <a href="#topic-<?= e(slugify($topic)) ?>">
                <?= e($topic) ?> <span class="n"><?= count($items) ?></span>
              </a>
            <?php endforeach; ?>
          </div>

          <div class="card-fc card-fc--pad mt-3" style="background:var(--leaf-wash);border-color:#BFE3D5">
            <b style="font-family:var(--font-display);display:block;margin-bottom:.3rem">Still stuck?</b>
            <p style="font-size:.84rem;color:var(--text-2);margin-bottom:.8rem">We answer within one working day.</p>
            <a href="<?= SITE_URL ?>/contact.php" class="btn-fc btn-fc--sm btn-fc--block">Message us</a>
          </div>
        </nav>
      </div>

      <div class="col-lg-9">
        <?php foreach ($byTopic as $topic => $items): ?>
          <section id="topic-<?= e(slugify($topic)) ?>" class="mb-4" style="scroll-margin-top:calc(var(--header-h) + 20px)">
            <div class="section__head" style="margin-bottom:.6rem">
              <span class="eyebrow"><?= e($topic) ?></span>
            </div>
            <div class="card-fc" style="padding:0 1.3rem">
              <?php foreach ($items as $f): ?>
                <div class="faq-item">
                  <button class="faq-q" type="button" aria-expanded="false">
                    <?= e($f['question']) ?><i class="fa-solid fa-plus"></i>
                  </button>
                  <div class="faq-a"><p><?= nl2br(e($f['answer'])) ?></p></div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>

        <div class="card-fc card-fc--pad" style="background:var(--amber-wash);border-color:#F0DFB6">
          <div class="d-flex gap-3 align-items-start">
            <span style="width:44px;height:44px;display:grid;place-items:center;background:#fff;color:var(--amber-dark);border-radius:var(--r);flex-shrink:0">
              <i class="fa-solid fa-triangle-exclamation"></i>
            </span>
            <div>
              <b style="font-family:var(--font-display);display:block;margin-bottom:.3rem">One thing worth repeating</b>
              <p style="font-size:.9rem;color:var(--text-2);margin:0">
                The supplements sold here are Category D complementary medicines. They have not been evaluated by the
                South African Health Products Regulatory Authority for quality, safety or intended use, and they are
                intended to complement a diet — not to diagnose, treat or cure any condition. If you are pregnant,
                breastfeeding, under 18 or on chronic medication, speak to a pharmacist or doctor before starting.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
