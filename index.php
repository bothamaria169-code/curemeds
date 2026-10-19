<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$repo        = new ProductRepository();
$viewer      = current_user();
$wish        = $viewer ? wishlist_ids((int) $viewer['id']) : [];

$featured    = $repo->getFeatured(8);
$bestsellers = $repo->getBestsellers(8);
$categories  = get_categories();
$brands      = get_brands();

$testimonials = db()->query("SELECT * FROM testimonials WHERE status='published' ORDER BY sort_order, id LIMIT 6")->fetchAll();
$posts        = get_blogs([], 1, 3)['items'];
$productCount = (int) db()->query("SELECT COUNT(*) FROM products WHERE status='published'")->fetchColumn();
$waDisplay    = Health::displayPhone(whatsapp_number());

$page_title       = 'CureMeds — health products and supplements, ordered on WhatsApp';
$meta_description = 'Immune support, vitamins, heart, gut and joint health products with clear prices. Order on WhatsApp and get medication reminders in your free patient portal. Courier delivery across South Africa.';
$og_image         = SITE_URL . '/assets/img/stock/shieldwell.jpg';
$json_ld = [
    '@type' => 'WebSite',
    'name'  => SITE_NAME,
    'url'   => SITE_URL,
    'potentialAction' => [
        '@type'       => 'SearchAction',
        'target'      => SITE_URL . '/shop.php?q={search_term_string}',
        'query-input' => 'required name=search_term_string',
    ],
];
include __DIR__ . '/includes/header.php';
?>

<!-- ── Promotions carousel (Admin → Banners) ────────────────────────── -->
<?php if (get_banners('home')): ?>
<div style="padding-top:1.25rem">
  <?php $carousel_page = 'home'; include __DIR__ . '/includes/banner-carousel.php'; ?>
</div>
<?php endif; ?>

<!-- ── Hero ─────────────────────────────────────────────────────────── -->
<section class="hero"<?= get_banners('home') ? ' style="margin-top:1.5rem"' : '' ?>>
  <div class="container hero__grid">
    <div>
      <span class="badge-fc badge-fc--amber mb-3">
        <i class="fa-solid fa-shield-heart"></i> Health products · <?= $productCount ?> in stock · nationwide delivery
      </span>

      <h1>Health support that<br>keeps <em>checking in</em>.</h1>

      <p class="hero__lede">
        Immune support, vitamins and everyday health essentials at clear prices. Order on WhatsApp,
        then let your free patient portal remind you of every dose and record how you feel.
      </p>

      <div class="hero__cta">
        <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--lg">
          <i class="fa-solid fa-capsules"></i> Browse products
        </a>
        <a href="<?= e(wa_link("Hi CureMeds, I'd like some help choosing a product.")) ?>" target="_blank" rel="noopener" class="btn-fc btn-fc--whatsapp btn-fc--lg">
          <i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp
        </a>
      </div>

      <div class="hero__stats">
        <div class="hero__stat"><b><?= $productCount ?></b><span>PRODUCTS</span></div>
        <div class="hero__stat"><b>2–4 days</b><span>COURIER, NATIONWIDE</span></div>
        <div class="hero__stat"><b>Free</b><span>DOSE REMINDERS</span></div>
      </div>
    </div>

    <?php $heroProduct = $repo->getBySlug('shieldwell-hiv-immune-support') ?: ($featured[0] ?? null); ?>
    <div class="hero__art">
      <figure class="hero__shot">
        <img src="<?= SITE_URL ?>/assets/img/stock/hero-consult.jpg" alt="A health professional explaining results to a patient">
      </figure>

      <?php if ($heroProduct): ?>
      <a class="hero__buy-card" href="<?= SITE_URL ?>/modules/shop/product.php?slug=<?= e($heroProduct['slug']) ?>"
         aria-label="View <?= e($heroProduct['name']) ?>">
        <div class="hero__buy-head">
          <img src="<?= e(product_image($heroProduct['featured_image'])) ?>" alt="">
          <span>
            <b><?= e($heroProduct['name']) ?></b>
            <span><?= e((string) $heroProduct['pack_size']) ?> · <?= e((string) $heroProduct['brand_name']) ?></span>
          </span>
        </div>
        <div class="hero__buy-rows">
          <div class="is-price">
            <span>In stock now</span>
            <b class="money"><?= format_price_short(Pricing::retail($heroProduct)) ?></b>
          </div>
        </div>
        <p class="hero__buy-foot">
          <i class="fa-brands fa-whatsapp"></i> Enquire on WhatsApp — we reply the same day.
        </p>
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ── Trust strip ──────────────────────────────────────────────────── -->
<section class="trust">
  <div class="container trust__grid">
    <div class="trust__item"><i class="fa-brands fa-whatsapp"></i><span><b>Order on WhatsApp</b><span><?= e($waDisplay) ?>, same-day replies</span></span></div>
    <div class="trust__item"><i class="fa-solid fa-bell"></i><span><b>Dose reminders</b><span>Emailed at every dose time</span></span></div>
    <div class="trust__item"><i class="fa-solid fa-truck-fast"></i><span><b>Courier delivery</b><span>2–4 working days, nationwide</span></span></div>
    <div class="trust__item"><i class="fa-solid fa-user-shield"></i><span><b>Private &amp; POPIA-compliant</b><span>Your health records stay yours</span></span></div>
  </div>
</section>

<!-- ── Categories ───────────────────────────────────────────────────── -->
<section class="section">
  <div class="container">
    <div class="section__head d-flex flex-wrap justify-content-between align-items-end gap-3">
      <div>
        <span class="eyebrow">Shop by need</span>
        <h2>What are you looking after?</h2>
        <p>Start with what matters to you. Every product shows its price, pack size and how to take it.</p>
      </div>
      <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--ghost">All products <i class="fa-solid fa-arrow-right"></i></a>
    </div>

    <div class="row g-3">
      <?php foreach ($categories as $c): ?>
      <div class="col-lg-3 col-md-4 col-sm-6" data-reveal>
        <a class="cat-tile cat-tile--photo" href="<?= SITE_URL ?>/shop.php?category=<?= e($c['slug']) ?>">
          <?php if (!empty($c['image'])): ?>
            <img class="cat-tile__img" src="<?= e(product_image($c['image'])) ?>" alt="" loading="lazy">
          <?php endif; ?>
          <i class="fa-solid <?= e($c['icon'] ?: 'fa-tag') ?>"></i>
          <b><?= e($c['name']) ?></b>
          <span><?= e($c['blurb'] ?? '') ?></span>
          <span class="count"><?= (int) $c['product_count'] ?> products <i class="fa-solid fa-arrow-right ms-1"></i></span>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── Featured ─────────────────────────────────────────────────────── -->
<section class="section section--tight" style="background:var(--card);border-block:1px solid var(--line)">
  <div class="container">
    <div class="section__head d-flex flex-wrap justify-content-between align-items-end gap-3">
      <div>
        <span class="eyebrow">Hand-picked</span>
        <h2>Our most asked-about products</h2>
        <p>Tap <b>Enquire</b> on any product and your WhatsApp message is written for you.</p>
      </div>
      <a href="<?= SITE_URL ?>/shop.php?sort=featured" class="btn-fc btn-fc--ghost">See all <i class="fa-solid fa-arrow-right"></i></a>
    </div>

    <div class="grid-products">
      <?php foreach ($featured as $product):
        $in_wishlist = in_array((int) $product['id'], $wish, true);
        include __DIR__ . '/includes/product-card.php';
      endforeach; ?>
    </div>
  </div>
</section>

<!-- ── Patient portal ───────────────────────────────────────────────── -->
<section class="section">
  <div class="container">
    <div class="reseller-band" data-reveal>
      <div class="row g-4 align-items-center" style="position:relative;z-index:1">
        <div class="col-lg-6">
          <span class="badge-fc badge-fc--amber mb-3"><i class="fa-solid fa-heart-pulse"></i> Free with every order</span>
          <h2>Your health tracker, on your phone.</h2>
          <p class="mb-4" style="max-width:52ch">
            After you buy, we set up your medication schedule. Sign in with your cellphone number and
            a 4-digit PIN — no passwords to remember — to tick off doses, track how you feel and tell
            us about any side effects. Our team reads every report.
          </p>

          <div class="d-flex gap-2 flex-wrap mb-4">
            <a href="<?= SITE_URL ?>/modules/auth/login.php" class="btn-fc btn-fc--amber btn-fc--lg">
              <i class="fa-solid fa-mobile-screen"></i> Patient sign-in
            </a>
            <a href="<?= SITE_URL ?>/modules/auth/register.php" class="btn-fc btn-fc--on-dark btn-fc--lg">Create my tracker</a>
          </div>

          <p style="font-size:.82rem;color:var(--on-dark-2);margin:0">
            <i class="fa-solid fa-lock me-1"></i> Only you and authorised CureMeds staff can see your health information.
          </p>
        </div>

        <div class="col-lg-6">
          <div class="tier-grid">
            <?php foreach ([
              ['fa-bell',            'Email', 'dose reminders',   'one tap to log it'],
              ['fa-chart-line',      '7-day', 'adherence score',  'and your streak'],
              ['fa-notes-medical',   'Report', 'side effects',    'reviewed by our team'],
              ['fa-heart-pulse',     'Track', 'BP, weight, sugar', 'with simple charts'],
            ] as [$icon, $big, $label, $sub]): ?>
            <div class="tier">
              <i class="fa-solid <?= $icon ?>" style="color:var(--leaf-light);font-size:1.05rem;margin-bottom:.35rem"></i>
              <div class="off" style="font-size:1.4rem"><?= $big ?></div>
              <b style="font-size:.82rem"><?= $label ?></b>
              <div style="margin-top:.35rem;font-size:.7rem;color:var(--on-dark-2)"><?= $sub ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── Best sellers ─────────────────────────────────────────────────── -->
<?php if ($bestsellers): ?>
<section class="section section--tight">
  <div class="container">
    <div class="section__head d-flex flex-wrap justify-content-between align-items-end gap-3">
      <div>
        <span class="eyebrow eyebrow--amber">Reordered most</span>
        <h2>Best sellers</h2>
        <p>The products our patients come back for month after month.</p>
      </div>
      <a href="<?= SITE_URL ?>/shop.php?sort=popular" class="btn-fc btn-fc--ghost">Full ranking <i class="fa-solid fa-arrow-right"></i></a>
    </div>

    <div class="grid-products">
      <?php foreach ($bestsellers as $product):
        $in_wishlist = in_array((int) $product['id'], $wish, true);
        include __DIR__ . '/includes/product-card.php';
      endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── How ordering works ───────────────────────────────────────────── -->
<section class="section" style="background:var(--card);border-block:1px solid var(--line)">
  <div class="container">
    <div class="row g-4 align-items-center">
      <div class="col-lg-5" data-reveal>
        <figure class="photo-frame">
          <img src="<?= SITE_URL ?>/assets/img/stock/pharmacist.jpg" alt="A CureMeds team member ready to help" loading="lazy">
        </figure>
      </div>
      <div class="col-lg-7">
        <span class="eyebrow">How ordering works</span>
        <h2>Three steps, one WhatsApp chat</h2>
        <p class="mb-4" style="max-width:56ch">No checkout forms and no card details on a website. A real person confirms every order with you.</p>

        <?php foreach ([
          ['fa-hand-pointer', 'Choose your products', 'Tap Enquire on a product, or add a few to your list and send them together. Prices are shown up front.'],
          ['fa-brands fa-whatsapp', 'We confirm on WhatsApp', 'Your message arrives at ' . $waDisplay . ' with the products filled in. We confirm stock, the total and delivery, and send payment details.'],
          ['fa-bell', 'Delivery + your reminders', 'Your parcel ships in 2–4 working days, and we set up your dose reminders in the patient portal.'],
        ] as $i => [$icon, $title, $text]): ?>
        <div class="step-row" data-reveal>
          <span class="step-row__n"><?= $i + 1 ?></span>
          <div>
            <h3><i class="<?= str_starts_with($icon, 'fa-brands') ? $icon : 'fa-solid ' . $icon ?> me-2" style="color:var(--leaf)"></i><?= e($title) ?></h3>
            <p><?= e($text) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ── Brands ───────────────────────────────────────────────────────── -->
<section class="section">
  <div class="container">
    <div class="section__head text-center">
      <span class="eyebrow">Our ranges</span>
      <h2>Brands we stand behind</h2>
      <p class="mx-auto">Every product is stock we hold and check ourselves. No mystery suppliers.</p>
    </div>

    <div class="row g-3 justify-content-center">
      <?php foreach ($brands as $b): ?>
      <div class="col-lg-4 col-md-6" data-reveal>
        <a class="card-fc card-fc--pad card-fc--hover d-block h-100" href="<?= SITE_URL ?>/shop.php?brand=<?= e($b['slug']) ?>">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span style="width:12px;height:12px;border-radius:50%;background:<?= e($b['accent']) ?>"></span>
            <b style="font-family:var(--font-display);font-size:1.1rem"><?= e($b['name']) ?></b>
          </div>
          <div class="eyebrow" style="color:<?= e($b['accent']) ?>;margin-bottom:.5rem"><?= e($b['tagline'] ?? '') ?></div>
          <p style="font-size:.86rem;color:var(--text-2)"><?= e($b['blurb'] ?? '') ?></p>
          <div style="font-size:.78rem;font-weight:700;color:var(--leaf)">
            <?= (int) $b['product_count'] ?> products <i class="fa-solid fa-arrow-right ms-1"></i>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── Testimonials ─────────────────────────────────────────────────── -->
<?php if ($testimonials): ?>
<section class="section" style="background:var(--card);border-block:1px solid var(--line)">
  <div class="container">
    <div class="section__head text-center">
      <span class="eyebrow">In their words</span>
      <h2>What patients say</h2>
    </div>
    <div class="row g-3">
      <?php foreach ($testimonials as $t): ?>
      <div class="col-lg-3 col-md-6" data-reveal>
        <div class="quote-card">
          <?= stars_html((float) $t['rating']) ?>
          <p class="mt-2">&ldquo;<?= e($t['quote']) ?>&rdquo;</p>
          <div class="quote-card__who">
            <span class="quote-card__av"><?= e(mb_substr($t['name'], 0, 1)) ?></span>
            <span>
              <b><?= e($t['name']) ?></b>
              <span><?= e(trim(($t['role'] ?? '') . ($t['location'] ? ' · ' . $t['location'] : ''), ' ·')) ?></span>
            </span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── Learn ────────────────────────────────────────────────────────── -->
<?php if ($posts): ?>
<section class="section">
  <div class="container">
    <div class="section__head d-flex flex-wrap justify-content-between align-items-end gap-3">
      <div>
        <span class="eyebrow">Learn</span>
        <h2>Worth reading before you start</h2>
        <p>Plain-language guides on supplements, routines and when to see a clinic.</p>
      </div>
      <a href="<?= SITE_URL ?>/modules/blog/index.php" class="btn-fc btn-fc--ghost">All articles <i class="fa-solid fa-arrow-right"></i></a>
    </div>

    <div class="row g-3">
      <?php foreach ($posts as $post): ?>
      <div class="col-lg-4 col-md-6" data-reveal>
        <a class="post-card" href="<?= SITE_URL ?>/modules/blog/post.php?slug=<?= e($post['slug']) ?>">
          <div class="post-card__media">
            <img src="<?= e(product_image($post['image'])) ?>" alt="<?= e($post['title']) ?>" loading="lazy">
          </div>
          <div class="post-card__body">
            <span class="badge-fc badge-fc--muted mb-2" style="align-self:flex-start"><?= e($post['category']) ?></span>
            <h3><?= e($post['title']) ?></h3>
            <p><?= e(excerpt($post['excerpt'], 110)) ?></p>
            <div class="post-card__meta">
              <span><i class="fa-regular fa-clock me-1"></i><?= (int) $post['read_mins'] ?> min read</span>
              <span><?= date('j M Y', strtotime($post['created_at'])) ?></span>
            </div>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── Closing CTA ──────────────────────────────────────────────────── -->
<section class="section section--tight" style="padding-bottom:3rem">
  <div class="container">
    <div class="card-fc" style="padding:clamp(2rem,4vw,3rem);text-align:center;background:linear-gradient(135deg,var(--leaf-wash),var(--card))">
      <span class="eyebrow">Not sure what you need?</span>
      <h2 class="mb-2">Ask us on WhatsApp.</h2>
      <p class="lede mx-auto mb-4" style="max-width:56ch">
        Tell us what you are managing and what you already take. We will point you to the right product —
        and tell you honestly when something is a question for your clinic instead.
      </p>
      <div class="d-flex gap-2 justify-content-center flex-wrap">
        <a href="<?= e(wa_link("Hi CureMeds, I'd like some advice on which product suits me.")) ?>" target="_blank" rel="noopener" class="btn-fc btn-fc--whatsapp btn-fc--lg">
          <i class="fa-brands fa-whatsapp"></i> <?= e($waDisplay) ?>
        </a>
        <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--ghost btn-fc--lg">Browse products</a>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
