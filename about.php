<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$brands   = get_brands();
$products = (int) db()->query("SELECT COUNT(*) FROM products WHERE status='published'")->fetchColumn();

$page_title       = 'About CureMeds — health products with care that follows up';
$meta_description = 'CureMeds sells health products and supplements across South Africa, ordered on WhatsApp, with a free patient portal for dose reminders and side-effect reporting.';
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>About</span></nav>
    <h1>Health products, and someone<br>who checks in afterwards.</h1>
    <p>
      CureMeds sells health products and supplements across South Africa. You order through a real
      conversation on WhatsApp, and we stay with you after delivery with reminders and a place to tell us how you feel.
    </p>
  </div>
</header>

<section class="section">
  <div class="container">
    <div class="row g-5 align-items-center">
      <div class="col-lg-6">
        <span class="eyebrow">Why we built it</span>
        <h2>Buying is the easy part. Taking it every day is hard.</h2>
        <div class="prose">
          <p>
            Most shops stop caring once the parcel is delivered. But a supplement only helps if you take it, on
            time, every day — and if something doesn't agree with you, somebody should hear about it.
          </p>
          <p>
            So every CureMeds order comes with a free patient portal. We set up your schedule, email you at each
            dose time, and give you one place to record side effects, blood pressure, weight or blood sugar. Our
            team reads every side-effect report and replies.
          </p>
          <p>
            And because health questions deserve a person, not a checkout form, you order on WhatsApp.
            We'll tell you honestly when a question is one for your clinic instead.
          </p>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="row g-3">
          <div class="col-6"><img src="<?= SITE_URL ?>/assets/img/stock/nurse-bp.jpg" alt="A nurse checking a patient's blood pressure" style="border-radius:var(--r-lg);width:100%;aspect-ratio:3/4;object-fit:cover"></div>
          <div class="col-6 d-flex flex-column gap-3">
            <img src="<?= SITE_URL ?>/assets/img/stock/pill-box.jpg" alt="A weekly pill organiser" style="border-radius:var(--r-lg);width:100%;aspect-ratio:1;object-fit:cover">
            <img src="<?= SITE_URL ?>/assets/img/stock/pharmacy.jpg" alt="Medication on a pharmacy counter" style="border-radius:var(--r-lg);width:100%;aspect-ratio:1;object-fit:cover">
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section section--tight" style="background:var(--card);border-block:1px solid var(--line)">
  <div class="container">
    <div class="row g-3 text-center">
      <?php foreach ([
        [(string) $products, 'health products'],
        ['Same day', 'WhatsApp replies'],
        ['2–4', 'day courier, nationwide'],
        ['Free', 'dose reminders for every patient'],
      ] as [$n, $label]): ?>
      <div class="col-lg-3 col-6">
        <div class="card-fc card-fc--pad h-100">
          <div class="money" style="font-size:2.2rem;color:var(--leaf);line-height:1.1"><?= e($n) ?></div>
          <div class="text-muted-2" style="font-size:.84rem;font-weight:600"><?= e($label) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section__head text-center">
      <span class="eyebrow">What we stock</span>
      <h2>Our ranges</h2>
      <p class="mx-auto">Every product here is stock we hold and check ourselves.</p>
    </div>
    <div class="row g-3 justify-content-center">
      <?php foreach ($brands as $b): ?>
      <div class="col-lg-4" data-reveal>
        <a class="card-fc card-fc--pad card-fc--hover d-block h-100" href="<?= SITE_URL ?>/shop.php?brand=<?= e($b['slug']) ?>">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span style="width:14px;height:14px;border-radius:50%;background:<?= e($b['accent']) ?>"></span>
            <b style="font-family:var(--font-display);font-size:1.15rem"><?= e($b['name']) ?></b>
            <span class="badge-fc badge-fc--muted ms-auto"><?= (int) $b['product_count'] ?> products</span>
          </div>
          <div class="eyebrow" style="color:<?= e($b['accent']) ?>"><?= e((string) $b['tagline']) ?></div>
          <p style="font-size:.9rem;color:var(--text-2);margin:0"><?= e((string) $b['blurb']) ?></p>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" style="background:var(--card);border-block:1px solid var(--line)">
  <div class="container">
    <div class="section__head text-center">
      <span class="eyebrow">How we behave</span>
      <h2>Four things we will not do</h2>
    </div>
    <div class="row g-3">
      <?php foreach ([
        ['fa-ban', 'Claim a supplement cures anything', 'Supplements support your health; they do not treat or cure disease. We will never suggest one replaces your prescribed medication — including ARVs, blood pressure or diabetes treatment.'],
        ['fa-user-doctor', 'Play doctor', 'If a question is about your diagnosis or your prescription, we will send you to your clinic. A side effect that sounds serious gets a "go to a clinic now", not a product suggestion.'],
        ['fa-tags', 'Surprise you on price', 'The price on the product page is the price we quote. Delivery is confirmed on WhatsApp before you pay anything.'],
        ['fa-user-shield', 'Share your health information', 'Your medication, readings and reports are seen only by you and the staff who look after you. Never sold, never used for advertising.'],
      ] as [$icon, $title, $body]): ?>
      <div class="col-lg-6" data-reveal>
        <div class="card-fc card-fc--pad h-100 d-flex gap-3">
          <span style="width:44px;height:44px;display:grid;place-items:center;background:var(--coral-wash);color:var(--coral);border-radius:var(--r);flex-shrink:0">
            <i class="fa-solid <?= $icon ?>"></i>
          </span>
          <span>
            <b style="font-family:var(--font-display);font-size:1rem;display:block;margin-bottom:.25rem"><?= e($title) ?></b>
            <span style="font-size:.88rem;color:var(--text-2)"><?= e($body) ?></span>
          </span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section--tight" style="padding-bottom:3rem">
  <div class="container">
    <div class="card-fc" style="padding:clamp(2rem,4vw,3rem);text-align:center;background:linear-gradient(135deg,var(--leaf-wash),var(--card))">
      <h2>Have a look at what we stock</h2>
      <p class="lede mx-auto mb-4" style="max-width:54ch">
        Every product with its price, pack size and how to take it — or message us and we'll help you choose.
      </p>
      <div class="d-flex gap-2 justify-content-center flex-wrap">
        <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--lg">Browse products</a>
        <a href="<?= e(wa_link("Hi CureMeds, I'd like some help choosing a product.")) ?>" target="_blank" rel="noopener" class="btn-fc btn-fc--whatsapp btn-fc--lg"><i class="fa-brands fa-whatsapp"></i> WhatsApp us</a>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
