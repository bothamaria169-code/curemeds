<?php
/**
 * The newsletter sign-up gate / popup. Included by header.php straight after
 * <body> when NewsletterService::gateFor() says this visitor should see it.
 *
 * Expects $nl_gate = 'locked' | 'popup'.
 *
 * locked — covers the page until an email is given. The form is a real POST
 *          to modules/newsletter/join.php, so it still works if JavaScript
 *          fails; newsletter-gate.js upgrades it to an in-place submit.
 * popup  — rendered hidden; the script shows it after a delay, and a dismissal
 *          keeps it away for a week.
 */

$nl_locked  = $nl_gate === 'locked';
$nl_coupon  = NewsletterService::welcomeCoupon();
$nl_offer   = $nl_coupon ? NewsletterService::describeCoupon($nl_coupon) : null;
// The badge on the image is a short stamp ("10% OFF"), not a repeat of the heading.
$nl_stamp   = null;
if ($nl_coupon) {
    $v = (float) $nl_coupon['discount_value'];
    $nl_stamp = $nl_coupon['discount_type'] === 'percentage'
        ? rtrim(rtrim(number_format($v, 2), '0'), '.') . '%'
        : format_price_short($v);
}
$nl_heading = (string) get_setting('newsletter_gate_heading', $nl_offer ? 'Get ' . $nl_offer : 'Join the CureMeds list');
$nl_text    = (string) get_setting(
    'newsletter_gate_text',
    'New arrivals, restocks and member-only deals, straight to your inbox. Pop your email in to start shopping.'
);
$nl_error   = mb_substr(trim((string) ($_GET['nl_error'] ?? '')), 0, 160);
$nl_delay   = max(0, min(120, (int) get_setting('newsletter_popup_delay', '8')));
?>
<div class="nl-gate nl-gate--<?= $nl_locked ? 'locked' : 'popup' ?>" id="nlGate"
     role="dialog" aria-modal="true" aria-labelledby="nlGateTitle" aria-describedby="nlGateText"
     data-mode="<?= $nl_locked ? 'locked' : 'popup' ?>" data-delay="<?= $nl_delay ?>"
     <?= $nl_locked || $nl_error !== '' ? '' : 'hidden' ?>>
  <div class="nl-gate__panel">

    <?php if (!$nl_locked): ?>
      <button type="button" class="nl-gate__close" data-nl-close aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>
    <?php endif; ?>

    <div class="nl-gate__art" aria-hidden="true">
      <img src="<?= SITE_URL ?>/assets/img/stock/healthy-food.jpg" alt="" loading="lazy">
      <?php if ($nl_stamp): ?>
        <span class="nl-gate__badge"><b><?= e($nl_stamp) ?></b> off<small>for new members</small></span>
      <?php endif; ?>
    </div>

    <div class="nl-gate__body" data-nl-step="form">
      <img class="nl-gate__logo" src="<?= SITE_URL ?>/assets/img/logo/curemeds-logo.svg" alt="CureMeds" width="150" height="42">
      <span class="eyebrow"><?= $nl_locked ? 'Members-only shop' : 'Members get more' ?></span>
      <h2 id="nlGateTitle"><?= e($nl_heading) ?></h2>
      <p id="nlGateText" class="nl-gate__text"><?= e($nl_text) ?></p>

      <form class="nl-gate__form" method="post" action="<?= SITE_URL ?>/modules/newsletter/join.php" data-nl-form novalidate>
        <input type="hidden" name="source" value="<?= $nl_locked ? 'gate' : 'popup' ?>">
        <input type="hidden" name="return_to" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '/')) ?>">
        <label class="visually-hidden" for="nlEmail">Email address</label>
        <input class="input-fc" type="email" id="nlEmail" name="email" required
               autocomplete="email" inputmode="email" placeholder="you@email.co.za">
        <button class="btn-fc" type="submit">
          <?= $nl_locked ? 'Unlock the shop' : ($nl_offer ? 'Get my code' : 'Sign me up') ?>
          <i class="fa-solid fa-arrow-right"></i>
        </button>
      </form>
      <p class="nl-gate__error" data-nl-error role="alert"><?= e($nl_error) ?></p>

      <p class="nl-gate__fine">
        By joining you agree to receive CureMeds emails about products and offers.
        Unsubscribe any time with one click.
        <a href="<?= SITE_URL ?>/privacy.php#marketing" target="_blank" rel="noopener">How we use your email</a>
      </p>

      <?php if ($nl_locked): ?>
        <p class="nl-gate__alt">
          Already have an account? <a href="<?= SITE_URL ?>/modules/auth/login.php">Sign in</a>
        </p>
      <?php else: ?>
        <button type="button" class="nl-gate__skip" data-nl-close>No thanks</button>
      <?php endif; ?>
    </div>

    <div class="nl-gate__body nl-gate__done" data-nl-step="done" hidden>
      <span class="nl-gate__tick"><i class="fa-solid fa-check"></i></span>
      <h2>You're in</h2>
      <p class="nl-gate__text" data-nl-done-text>Welcome to CureMeds. Keep an eye on your inbox.</p>

      <div class="nl-gate__code" data-nl-code-box hidden>
        <span class="nl-gate__code-label" data-nl-offer></span>
        <button type="button" class="nl-gate__code-value" data-nl-copy title="Copy code">
          <span data-nl-code></span> <i class="fa-regular fa-copy"></i>
        </button>
        <span class="nl-gate__code-hint" data-nl-copy-hint>Tap to copy · use it at checkout</span>
      </div>

      <button type="button" class="btn-fc btn-fc--block" data-nl-finish>
        Start shopping <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
</div>
