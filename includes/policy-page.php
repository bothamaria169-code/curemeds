<?php
/**
 * CureMeds policy centre — shared layout for the returns, privacy and terms pages.
 *
 * The page sets $policy and includes this file:
 *
 *   $policy = [
 *     'key'      => 'returns' | 'privacy' | 'terms',
 *     'title'    => 'Returns policy',
 *     'lede'     => 'One-line promise under the heading.',
 *     'updated'  => '18 September 2026',          // a fixed date, never date()
 *     'summary'  => ['Plain-language key point', …],
 *     'sections' => [['id' => 'slug', 'icon' => 'fa-…', 'title' => '…', 'html' => '…'], …],
 *   ];
 *
 * Laid out the way the big South African retailers do it: tabs between the
 * three policies, a grid of quick links, then numbered sections — so someone
 * looking for one answer can get to it in a click.
 */

$policyTabs = [
    'returns'  => ['refund-policy.php',       'fa-rotate-left',   'Returns policy'],
    'privacy'  => ['privacy.php',             'fa-user-shield',   'Privacy policy'],
    'terms'    => ['terms.php',               'fa-file-contract', 'Terms & conditions'],
    'reseller' => ['reseller-programme.php',  'fa-handshake',     'Reseller programme'],
];

$company = [
    'name'    => (string) get_setting('company_name', (string) get_setting('bank_account_holder', 'CureMeds Trading (Pty) Ltd')),
    'reg'     => (string) get_setting('company_reg_no', ''),
    'vat'     => (string) get_setting('company_vat_no', ''),
    'address' => (string) get_setting('contact_address', ''),
    'email'   => (string) get_setting('contact_email', SITE_EMAIL),
    'phone'   => (string) get_setting('contact_phone', ''),
    'wa'      => preg_replace('/\D/', '', (string) get_setting('contact_whatsapp', '')),
];

$page_title       = $policy['title'] . ' — ' . SITE_NAME;
$meta_description = $meta_description ?? $policy['lede'];
$body_class       = 'policy-page';
include __DIR__ . '/header.php';
?>

<header class="policy-hero" id="top">
  <div class="container">
    <div class="policy-hero__top no-print">
      <nav class="crumbs crumbs--light" style="margin:0"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Policy centre</span></nav>
      <a class="policy-hero__back" href="<?= SITE_URL ?>/shop.php"><i class="fa-solid fa-arrow-left"></i> Back to shopping</a>
    </div>

    <nav class="policy-tabs no-print" aria-label="Policies">
      <?php foreach ($policyTabs as $key => [$href, $icon, $label]): ?>
        <a href="<?= SITE_URL ?>/<?= $href ?>" class="<?= $policy['key'] === $key ? 'is-on' : '' ?>"
           <?= $policy['key'] === $key ? 'aria-current="page"' : '' ?>>
          <i class="fa-solid <?= $icon ?>"></i> <?= $label ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <span class="eyebrow">CureMeds policy centre</span>
    <h1><?= e($policy['title']) ?></h1>
    <p class="policy-hero__lede"><?= e($policy['lede']) ?></p>
    <p class="policy-hero__meta">
      <span><i class="fa-regular fa-calendar"></i> Last updated <?= e($policy['updated']) ?></span>
      <button type="button" class="no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print or save as PDF</button>
    </p>
  </div>
</header>

<div class="section section--tight">
  <div class="container">

    <?php if (!empty($policy['summary'])): ?>
      <aside class="policy-summary" aria-labelledby="policySummary">
        <h2 id="policySummary"><i class="fa-solid fa-bolt"></i> The short version</h2>
        <ul>
          <?php foreach ($policy['summary'] as $point): ?>
            <li><i class="fa-solid fa-check"></i><span><?= $point ?></span></li>
          <?php endforeach; ?>
        </ul>
      </aside>
    <?php endif; ?>

    <nav class="policy-quick no-print" aria-label="Jump to a topic">
      <?php foreach ($policy['sections'] as $i => $s): ?>
        <a href="#<?= e($s['id']) ?>" class="policy-quick__card">
          <span class="policy-quick__icon"><i class="fa-solid <?= e($s['icon']) ?>"></i></span>
          <span><?= e($s['title']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="policy-layout">
      <aside class="policy-toc no-print" aria-label="On this page">
        <b>On this page</b>
        <ol>
          <?php foreach ($policy['sections'] as $s): ?>
            <li><a href="#<?= e($s['id']) ?>"><?= e($s['title']) ?></a></li>
          <?php endforeach; ?>
        </ol>
      </aside>

      <article class="policy-body prose">
        <?php foreach ($policy['sections'] as $i => $s): ?>
          <section id="<?= e($s['id']) ?>" class="policy-section">
            <h2><span class="policy-section__n"><?= $i + 1 ?></span><?= e($s['title']) ?></h2>
            <?= $s['html'] ?>
            <a class="policy-section__top no-print" href="#top"><i class="fa-solid fa-arrow-up"></i> Back to top</a>
          </section>
        <?php endforeach; ?>
      </article>
    </div>

    <section class="policy-help no-print" aria-labelledby="policyHelp">
      <div>
        <h2 id="policyHelp">Still have a question?</h2>
        <p>Real people answer, usually within one working day.</p>
      </div>
      <div class="policy-help__ways">
        <a class="btn-fc" href="<?= SITE_URL ?>/contact.php"><i class="fa-solid fa-envelope"></i> Send us a message</a>
        <?php if ($company['wa']): ?>
          <a class="btn-fc btn-fc--light" href="https://wa.me/<?= e($company['wa']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
        <?php endif; ?>
        <a class="btn-fc btn-fc--light" href="<?= SITE_URL ?>/faq.php"><i class="fa-solid fa-circle-question"></i> FAQs</a>
      </div>
    </section>

    <script>
    /* Highlights the section being read in the side contents list. */
    (function () {
      const links = document.querySelectorAll('.policy-toc a');
      if (!links.length || !('IntersectionObserver' in window)) return;
      const byId = {};
      links.forEach((a) => { byId[a.getAttribute('href').slice(1)] = a; });
      const io = new IntersectionObserver((entries) => {
        entries.forEach((en) => {
          if (!en.isIntersecting) return;
          links.forEach((a) => { a.classList.remove('is-current'); a.removeAttribute('aria-current'); });
          const a = byId[en.target.id];
          if (a) { a.classList.add('is-current'); a.setAttribute('aria-current', 'location'); }
        });
      }, { rootMargin: '-20% 0px -70% 0px' });
      document.querySelectorAll('.policy-section').forEach((s) => io.observe(s));
    })();
    </script>

    <footer class="policy-company" aria-label="Company information">
      <b><?= e($company['name']) ?></b> trading as CureMeds
      <?php if ($company['reg']): ?> · Registration number <?= e($company['reg']) ?><?php endif; ?>
      <?php if ($company['vat']): ?> · VAT number <?= e($company['vat']) ?><?php endif; ?>
      <?php if ($company['address']): ?><br><?= e($company['address']) ?><?php endif; ?>
      <br><?= e($company['email']) ?><?= $company['phone'] ? ' · ' . e($company['phone']) : '' ?> · <?= e(SITE_DOMAIN) ?>
    </footer>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
