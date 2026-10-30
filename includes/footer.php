<?php
$extra_js = $extra_js ?? [];
$wa       = get_setting('contact_whatsapp', '');
?>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">

      <div>
        <div class="brand mb-3">
          <img src="<?= SITE_URL ?>/assets/img/logo/curemeds-mark.png" alt="" class="brand__mark">
          <span class="brand__word" style="color:#fff">Cure<em style="color:var(--leaf-light)">Meds</em></span>
        </div>
        <p style="color:var(--on-dark-2);font-size:.88rem;max-width:34ch;">
          Health products and supplements with clear prices, ordered on WhatsApp and
          delivered nationwide — with free dose reminders in your patient portal.
        </p>
        <div class="social-row">
          <?php foreach ([['facebook_url','fa-facebook-f','Facebook'],['instagram_url','fa-instagram','Instagram'],['tiktok_url','fa-tiktok','TikTok']] as [$k, $icon, $label]):
            $url = get_setting($k, ''); ?>
            <a href="<?= $url ? e($url) : '#' ?>" <?= $url ? 'target="_blank" rel="noopener"' : 'aria-disabled="true"' ?> aria-label="<?= $label ?>">
              <i class="fa-brands <?= $icon ?>"></i>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <h6>Shop</h6>
        <div class="footer-links">
          <a href="<?= SITE_URL ?>/shop.php">All products</a>
          <a href="<?= SITE_URL ?>/categories.php">Shop by need</a>
          <?php foreach (array_slice(get_categories(), 0, 5) as $c): ?>
            <a href="<?= SITE_URL ?>/shop.php?category=<?= e($c['slug']) ?>"><?= e($c['name']) ?></a>
          <?php endforeach; ?>
          <a href="<?= SITE_URL ?>/price-list.php">Full price list</a>
        </div>
      </div>

      <div>
        <h6>CureMeds</h6>
        <div class="footer-links">
          <a href="<?= SITE_URL ?>/about.php">About us</a>
          <a href="<?= SITE_URL ?>/testimonials.php">Testimonials</a>
          <a href="<?= SITE_URL ?>/gallery.php">Gallery</a>
          <a href="<?= SITE_URL ?>/modules/blog/index.php">Guides &amp; advice</a>
          <a href="<?= SITE_URL ?>/dashboards/customer/index.php">Patient portal</a>
          <a href="<?= SITE_URL ?>/faq.php">FAQ</a>
          <a href="<?= SITE_URL ?>/contact.php">Contact</a>
          <a href="<?= SITE_URL ?>/refund-policy.php">Returns &amp; refunds</a>
        </div>
      </div>

      <div>
        <h6>Health tips &amp; specials</h6>
        <p style="color:var(--on-dark-2);font-size:.85rem;">
          New products, health guides and the occasional special. One email, no noise.
        </p>
        <form class="newsletter-inline" data-newsletter>
          <input type="email" name="email" required placeholder="you@email.co.za" aria-label="Email address">
          <button type="submit" class="btn-fc" style="padding:.72rem 1.1rem;" aria-label="Subscribe"><i class="fa-solid fa-arrow-right"></i></button>
        </form>
        <div data-newsletter-msg style="font-size:.78rem;margin-top:.5rem;color:var(--on-dark-2);"></div>

        <div style="margin-top:1.4rem;font-size:.85rem;color:var(--on-dark-2);line-height:1.8;">
          <div><a href="<?= e(wa_link()) ?>" target="_blank" rel="noopener" style="color:inherit"><i class="fa-brands fa-whatsapp me-2" style="color:var(--wa)"></i><?= e(Health::displayPhone(whatsapp_number())) ?></a></div>
          <div><i class="fa-solid fa-envelope me-2" style="color:var(--leaf-light)"></i><?= e((string) get_setting('contact_email', SITE_EMAIL)) ?></div>
          <div><i class="fa-solid fa-location-dot me-2" style="color:var(--leaf-light)"></i><?= e((string) get_setting('contact_address', '')) ?></div>
        </div>
      </div>
    </div>

    <div style="border-top:1px solid var(--line-dark);padding:1.2rem 0;font-size:.78rem;color:var(--on-dark-2);line-height:1.6;">
      <b style="color:var(--on-dark)">Health products notice.</b>
      Supplements sold here are complementary medicines. Unless stated otherwise they have not been evaluated by the South
      African Health Products Regulatory Authority (SAHPRA) for quality, safety or intended use, and are not intended to
      diagnose, treat, cure or prevent any disease. They never replace prescribed medication, including ARVs. Speak to a
      healthcare professional before starting if you are pregnant, breastfeeding, under 18 or on chronic medication.
      In an emergency call <b style="color:var(--on-dark)">10177</b>.
    </div>

    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> CureMeds. All rights reserved.</span>
      <div class="d-flex gap-3 flex-wrap">
        <a href="<?= SITE_URL ?>/privacy.php">Privacy</a>
        <a href="<?= SITE_URL ?>/terms.php">Terms</a>
        <a href="<?= SITE_URL ?>/refund-policy.php">Returns</a>
        <a href="<?= SITE_URL ?>/sitemap.php">Sitemap</a>
      </div>
    </div>
  </div>
</footer>

<?php if ($wa !== ''): ?>
<a class="wa-float" href="<?= e(wa_link("Hi CureMeds, I have a question.")) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
  <i class="fa-brands fa-whatsapp"></i>
</a>
<?php endif; ?>

<?php include __DIR__ . '/chat-widget.php'; ?>
<?php include __DIR__ . '/purchase-ticker.php'; ?>

<!-- Compare tray -->
<div class="compare-bar" id="compareBar">
  <div class="compare-bar__thumbs" id="compareThumbs"></div>
  <span style="font-size:.84rem;font-weight:600;" id="compareCount"></span>
  <a href="<?= SITE_URL ?>/compare.php" class="btn-fc btn-fc--amber btn-fc--sm">Compare</a>
  <button class="icon-btn" type="button" id="compareClear" style="color:#fff" aria-label="Clear comparison"><i class="fa-solid fa-xmark"></i></button>
</div>

<div class="toast-wrap" id="toastWrap" aria-live="polite" aria-atomic="true"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const SITE_URL = <?= json_encode(SITE_URL) ?>;
  const FC_USER  = <?= json_encode($current_user ? ['id' => (int) $current_user['id'], 'reseller' => $is_reseller] : null) ?>;
</script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<script src="<?= asset('assets/js/cart.js') ?>"></script>
<script src="<?= asset('assets/js/carousel.js') ?>"></script>
<script src="<?= asset('assets/js/chat.js') ?>"></script>
<?php if (!empty($nl_gate)): ?>
<script src="<?= asset('assets/js/newsletter-gate.js') ?>"></script>
<?php endif; ?>
<?php foreach ($extra_js as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
