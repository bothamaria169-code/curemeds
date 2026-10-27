<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$viewer   = current_user();
$items    = cart_items();
$subtotal = cart_subtotal();

$freeAt   = (float) get_setting('free_shipping_threshold', '1000');
$flatRate = (float) get_setting('shipping_flat_rate', '100');
$toFree   = max(0.0, $freeAt - $subtotal);
$estShip  = $subtotal >= $freeAt ? 0.0 : $flatRate;
$waDisplay = Health::displayPhone(whatsapp_number());
$pudoOn    = Pudo::enabled();
$pudoFrom  = (float) get_setting('pudo_fixed_fee', '0') > 0 ? (float) get_setting('pudo_fixed_fee') : (float) get_setting('pudo_fallback_fee', '65');

$page_title       = 'Your enquiry list — ' . SITE_NAME;
$meta_description = 'Send the products on your list to CureMeds on WhatsApp in one message.';
$meta_robots      = 'noindex';
if ($pudoOn) {
    $extra_css = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css'];
    $extra_js  = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js', asset('assets/js/pudo-map.js')];
}
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Enquiry list</span></nav>
    <h1>Your enquiry list</h1>
    <p>Collect the products you want, then send them to us on WhatsApp in one message. Nothing is charged on the website.</p>
  </div>
</header>

<div class="section section--tight">
  <div class="container">

    <?php if (!$items): ?>
      <div class="empty card-fc">
        <div class="empty__icon"><i class="fa-solid fa-clipboard-list"></i></div>
        <h2>Your list is empty</h2>
        <p class="mb-4">Tap the <i class="fa-solid fa-plus"></i> on any product to add it here, or enquire about one product straight away.</p>
        <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--lg">Browse products</a>
      </div>

    <?php else: ?>
      <div class="cart-layout">

        <!-- ── Lines ─────────────────────────────────────────────── -->
        <div class="card-fc" style="padding:0 1.4rem">
          <?php foreach ($items as $item):
            $p = $item['product']; ?>
          <div class="cart-line">
            <a class="cart-line__img" href="<?= SITE_URL ?>/modules/shop/product.php?slug=<?= e($p['slug']) ?>">
              <img src="<?= e(product_image($item['image'])) ?>" alt="<?= e($p['name']) ?>"
                   onerror="this.onerror=null;this.src='<?= SITE_URL ?>/assets/img/placeholder.svg'">
            </a>

            <div>
              <a href="<?= SITE_URL ?>/modules/shop/product.php?slug=<?= e($p['slug']) ?>">
                <div class="cart-line__name"><?= e($p['name']) ?></div>
              </a>
              <div class="cart-line__meta">
                <?= e((string) $p['brand_name']) ?>
                <?php if ($item['variant_text']): ?> · <?= e($item['variant_text']) ?><?php endif; ?>
                <?php if ($p['pack_size']): ?> · <?= e($p['pack_size']) ?><?php endif; ?>
              </div>

              <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="qty">
                  <button type="button" data-step="down" aria-label="Decrease"><i class="fa-solid fa-minus"></i></button>
                  <input type="number" value="<?= (int) $item['qty'] ?>" min="1" max="999"
                         data-cart-qty="<?= e($item['key']) ?>" aria-label="Quantity for <?= e($p['name']) ?>">
                  <button type="button" data-step="up" aria-label="Increase"><i class="fa-solid fa-plus"></i></button>
                </div>
                <span class="text-muted-3" style="font-size:.82rem"><?= format_price($item['unit_price']) ?> each</span>
                <button class="btn-fc btn-fc--sm" type="button" data-cart-remove="<?= e($item['key']) ?>"
                        style="background:none;color:var(--text-3);padding:.3rem .5rem">
                  <i class="fa-solid fa-trash-can"></i> Remove
                </button>
              </div>
            </div>

            <div class="cart-line__right">
              <span class="money" style="font-size:1.1rem"><?= format_price($item['line_total']) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- ── Send ──────────────────────────────────────────────── -->
        <div class="summary">
          <form class="card-fc card-fc--pad" method="post" action="<?= SITE_URL ?>/enquire.php" target="_blank" id="enquiryForm">
            <?= csrf_field() ?>
            <h2 style="font-size:1.15rem">Send on WhatsApp</h2>

            <div class="summary-row"><span><?= cart_count() ?> item<?= cart_count() === 1 ? '' : 's' ?></span><b><?= format_price($subtotal) ?></b></div>
            <div class="summary-row">
              <span>Delivery <span class="text-muted-3">(estimate)</span></span>
              <b id="shipLabel" data-courier="<?= e($estShip > 0 ? format_price($estShip) : 'Free') ?>"><?= $estShip > 0 ? format_price($estShip) : 'Free' ?></b>
            </div>
            <div class="summary-total">
              <span>Estimated total</span>
              <b class="money" id="totalLabel" data-courier="<?= e(format_price($subtotal + $estShip)) ?>"><?= format_price($subtotal + $estShip) ?></b>
            </div>
            <?php if ($toFree > 0): ?>
              <p class="field__hint"><i class="fa-solid fa-truck-fast me-1"></i>Free delivery on orders over <?= format_price_short($freeAt) ?>.</p>
            <?php endif; ?>

            <?php if ($pudoOn): ?>
            <div class="field mt-3">
              <label>Delivery</label>
              <label class="d-block" style="font-weight:500;font-size:.92rem"><input type="radio" name="delivery_method" value="delivery" checked> Courier to your door</label>
              <label class="d-block" style="font-weight:500;font-size:.92rem"><input type="radio" name="delivery_method" value="pudo"> Pudo locker near you <span class="text-muted-3">(from <?= format_price_short($pudoFrom) ?>)</span></label>
            </div>
            <div id="pudoFields" class="pudo" hidden>
              <input type="hidden" name="pudo_locker" id="pudoLocker" value="">
              <div class="pudo__bar">
                <input class="input-fc" type="search" placeholder="Search a suburb, town or mall…" data-pudo-search aria-label="Search Pudo lockers">
                <button type="button" class="btn-fc btn-fc--light btn-fc--sm" data-pudo-near><i class="fa-solid fa-location-crosshairs"></i> Near me</button>
              </div>
              <div class="pudo__map" data-pudo-map></div>
              <div class="pudo__status" data-pudo-status>Loading lockers…</div>
              <div class="pudo__list" data-pudo-list></div>
              <div class="pudo__chosen" data-pudo-chosen hidden></div>
              <div class="field__hint">Pudo SMSes a PIN to your cellphone when the parcel is in the locker, so please add your number below.</div>
            </div>
            <?php endif; ?>

            <div class="field mt-3">
              <label for="enqName">Your name</label>
              <input class="input-fc" id="enqName" name="name" value="<?= e((string) ($viewer['name'] ?? '')) ?>" autocomplete="name" required>
            </div>
            <div class="field">
              <label for="enqPhone">Cellphone <span class="text-muted-3">(optional)</span></label>
              <input class="input-fc" id="enqPhone" name="phone" type="tel" inputmode="tel" placeholder="082 123 4567"
                     value="<?= e($viewer ? Health::displayPhone($viewer['phone'] ?? '') : '') ?>" autocomplete="tel">
              <div class="field__hint">So we can set up your dose reminders once you've bought.</div>
            </div>
            <div class="field">
              <label for="enqEmail">Email <span class="text-muted-3">(optional)</span></label>
              <input class="input-fc" id="enqEmail" name="email" type="email" placeholder="you@example.com"
                     value="<?= e((string) ($viewer['email'] ?? '')) ?>" autocomplete="email">
              <div class="field__hint">We email you the order and our payment details.</div>
            </div>
            <?php if (count(payment_methods()) > 1): ?>
            <div class="field">
              <label>How would you like to pay?</label>
              <?php foreach (payment_methods() as $k => $m): ?>
                <label class="d-block" style="font-weight:500;font-size:.92rem"><input type="radio" name="payment_method" value="<?= e($k) ?>" <?= $k === 'eft' ? 'checked' : '' ?>> <?= e($m['label']) ?></label>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <button class="btn-fc btn-fc--whatsapp btn-fc--block btn-fc--lg" type="submit">
              <i class="fa-brands fa-whatsapp"></i> Send to <?= e($waDisplay) ?>
            </button>
            <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--light btn-fc--block mt-2">Keep browsing</a>
          </form>

          <div class="card-fc card-fc--pad mt-3" style="background:var(--leaf-wash);border-color:transparent">
            <b style="font-family:var(--font-display);display:block;margin-bottom:.5rem">What happens next</b>
            <ul style="font-size:.85rem;color:var(--text-2);margin:0;padding-left:1.1rem;line-height:1.8">
              <li>WhatsApp opens with your list typed in — just press send.</li>
              <li>We confirm stock, the final total and delivery.</li>
              <li>You pay by EFT or cash on delivery; we courier it in 2–4 working days.</li>
              <li>We set up your reminders in the patient portal.</li>
            </ul>
          </div>
          <?php $payCardClass = 'mt-3'; include __DIR__ . '/includes/payment-card.php'; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
  // The list is emptied once it is sent; refresh this tab so it doesn't look unsent.
  document.getElementById('enquiryForm')?.addEventListener('submit', () => setTimeout(() => location.reload(), 1200));
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
