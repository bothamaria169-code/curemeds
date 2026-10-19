<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'newsletter';
$page_title  = 'Sign-up form — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

NewsletterService::ensureSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $mode = in_array($_POST['mode'] ?? '', ['locked', 'popup', 'off'], true) ? (string) $_POST['mode'] : 'locked';
    set_setting('newsletter_gate_mode', $mode);
    set_setting('newsletter_gate_heading', mb_substr(trim((string) ($_POST['heading'] ?? '')), 0, 120));
    set_setting('newsletter_gate_text', mb_substr(trim((string) ($_POST['text'] ?? '')), 0, 300));
    set_setting('newsletter_popup_delay', (string) max(0, min(120, (int) ($_POST['delay'] ?? 8))));
    set_setting('newsletter_welcome_coupon', trim((string) ($_POST['coupon'] ?? '')) ?: 'none');
    set_setting('newsletter_welcome_email', !empty($_POST['welcome_email']) ? '1' : '0');

    $_SESSION['nl_flash'] = ['ok', match ($mode) {
        'locked' => 'Saved. Visitors must now join the list before they can browse the shop.',
        'popup'  => 'Saved. Visitors will be offered the sign-up after a few seconds, and can close it.',
        'off'    => 'Saved. The sign-up gate and popup are switched off. The footer form still works.',
    }];
    redirect('dashboards/admin/newsletter-settings.php');
}

$flash = $_SESSION['nl_flash'] ?? null;
unset($_SESSION['nl_flash']);

$mode    = NewsletterService::gateMode();
$coupon  = (string) get_setting('newsletter_welcome_coupon', 'WELCOME10');
$coupons = db()->query("SELECT code, discount_type, discount_value, min_order, applies_to FROM coupons WHERE status = 'active' ORDER BY code")->fetchAll();
$active  = NewsletterService::welcomeCoupon();
$offer   = $active ? NewsletterService::describeCoupon($active) : null;

$bySource = db()->query(
    "SELECT source, COUNT(*) n FROM newsletter_subscribers
     WHERE status = 'active' AND subscribed_at >= NOW() - INTERVAL 30 DAY GROUP BY source"
)->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<div class="dash-head">
  <div>
    <h1>Sign-up form</h1>
    <p>How the site asks visitors to join the list.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn-fc btn-fc--light" href="<?= SITE_URL ?>/index.php?preview_gate=locked" target="_blank" rel="noopener"><i class="fa-solid fa-lock"></i> Preview locked</a>
    <a class="btn-fc btn-fc--light" href="<?= SITE_URL ?>/index.php?preview_gate=popup" target="_blank" rel="noopener"><i class="fa-regular fa-window-restore"></i> Preview popup</a>
  </div>
</div>

<?php $nl_tab = 'settings'; include __DIR__ . '/_newsletter-nav.php'; ?>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= e($flash[0]) ?>"><i class="fa-solid fa-circle-info"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>

<form method="post" class="row g-3">
  <?= csrf_field() ?>

  <div class="col-xl-8">
    <section class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1.05rem">Mode</h2>
      <div class="nl-modes">
        <label class="nl-mode-card">
          <input type="radio" name="mode" value="locked" <?= $mode === 'locked' ? 'checked' : '' ?>>
          <span>
            <i class="fa-solid fa-lock"></i>
            <b>Locked</b>
            <small>The shop is hidden behind the sign-up until the visitor gives an email. Most sign-ups — and most visitors who leave.</small>
          </span>
        </label>
        <label class="nl-mode-card">
          <input type="radio" name="mode" value="popup" <?= $mode === 'popup' ? 'checked' : '' ?>>
          <span>
            <i class="fa-regular fa-window-restore"></i>
            <b>Popup</b>
            <small>The same offer appears after a few seconds and can be closed. Never shown on the cart or checkout.</small>
          </span>
        </label>
        <label class="nl-mode-card">
          <input type="radio" name="mode" value="off" <?= $mode === 'off' ? 'checked' : '' ?>>
          <span>
            <i class="fa-solid fa-power-off"></i>
            <b>Off</b>
            <small>No gate or popup. People can still join from the form in the footer.</small>
          </span>
        </label>
      </div>

      <div class="nl-locked-note" data-nl-locked-note <?= $mode === 'locked' ? '' : 'hidden' ?>>
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
          <b>Before you leave this on, know the trade-off.</b>
          <ul>
            <li><b>POPIA</b> says consent to marketing must be given <i>freely</i>. Requiring an email just to look at the shop is the kind of thing the Information Regulator could say was not free. Popup mode has no such problem.</li>
            <li><b>Sales</b> — many first-time visitors will leave rather than hand over an email to a shop they have not seen yet.</li>
            <li><b>Google</b> ranks pages lower when an overlay covers the content, so fewer people may find CureMeds through search.</li>
          </ul>
          <span>Signed-in customers never see the gate. The privacy policy, terms, returns, contact and sign-in pages always stay open.</span>
        </div>
      </div>
    </section>

    <section class="card-fc card-fc--pad">
      <h2 style="font-size:1.05rem">Wording and offer</h2>
      <div class="field">
        <label for="heading">Heading</label>
        <input class="input-fc" type="text" id="heading" name="heading" maxlength="120"
               value="<?= e((string) get_setting('newsletter_gate_heading', '')) ?>"
               placeholder="<?= e($offer ? 'Get ' . $offer : 'Join the CureMeds list') ?>">
        <div class="field__hint">Leave blank to use the placeholder, which follows the welcome coupon.</div>
      </div>
      <div class="field">
        <label for="text">Message</label>
        <textarea class="textarea-fc" id="text" name="text" rows="3" maxlength="300"
                  placeholder="New arrivals, restocks and member-only deals, straight to your inbox. Pop your email in to start shopping."><?= e((string) get_setting('newsletter_gate_text', '')) ?></textarea>
      </div>
      <div class="row g-2">
        <div class="col-sm-7 field">
          <label for="coupon">Welcome coupon</label>
          <select class="select-fc" id="coupon" name="coupon">
            <option value="none" <?= $coupon === 'none' ? 'selected' : '' ?>>No coupon</option>
            <?php foreach ($coupons as $c): ?>
              <option value="<?= e($c['code']) ?>" <?= $coupon === $c['code'] ? 'selected' : '' ?>>
                <?= e($c['code']) ?> — <?= e(NewsletterService::describeCoupon($c)) ?><?= $c['applies_to'] === 'reseller' ? ' (resellers only)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="field__hint">Shown after sign-up and sent in the welcome email. Manage codes under <a href="coupons.php">Coupons</a>.</div>
        </div>
        <div class="col-sm-5 field">
          <label for="delay">Popup delay</label>
          <div class="d-flex align-items-center gap-2">
            <input class="input-fc" type="number" id="delay" name="delay" min="0" max="120" style="max-width:110px"
                   value="<?= (int) get_setting('newsletter_popup_delay', '8') ?>">
            <span class="text-muted-2" style="font-size:.85rem">seconds</span>
          </div>
          <div class="field__hint">Popup mode only. Give people a moment to see the shop first.</div>
        </div>
      </div>
      <label class="nl-consent">
        <input type="checkbox" name="welcome_email" value="1" <?= get_setting('newsletter_welcome_email', '1') === '1' ? 'checked' : '' ?>>
        <span>Send a welcome email to new subscribers <small>With the coupon code and four best sellers.</small></span>
      </label>
      <div class="d-flex justify-content-end mt-3">
        <button class="btn-fc" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
      </div>
    </section>
  </div>

  <div class="col-xl-4">
    <div class="card-fc card-fc--pad">
      <h2 style="font-size:1.05rem">Last 30 days</h2>
      <?php
      $labels = ['gate' => 'Sign-up gate', 'popup' => 'Popup', 'footer' => 'Footer form', 'admin' => 'Added by admin'];
      $total30 = array_sum(array_map('intval', $bySource));
      ?>
      <div class="nl-big-number"><?= number_format($total30) ?></div>
      <p class="text-muted-2" style="font-size:.84rem">new subscribers</p>
      <?php foreach ($labels as $key => $label):
        $n = (int) ($bySource[$key] ?? 0);
        $pct = $total30 ? round($n / $total30 * 100) : 0; ?>
        <div class="nl-source-bar">
          <span><?= e($label) ?></span><b><?= number_format($n) ?></b>
          <div class="nl-progress" style="margin:.3rem 0 .7rem;height:6px"><span style="width:<?= $pct ?>%"></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</form>

<script>
document.querySelectorAll('input[name="mode"]').forEach((r) => r.addEventListener('change', () => {
  document.querySelector('[data-nl-locked-note]').hidden = r.value !== 'locked' || !r.checked;
}));
</script>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
