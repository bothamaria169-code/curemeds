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

$page_title       = 'Your cart — ' . SITE_NAME;
$meta_description = 'Your CureMeds cart: check out and pay by EFT, or send the list on WhatsApp.';
$meta_robots      = 'noindex';
if ($pudoOn) {
    $extra_css = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css'];
    $extra_js  = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js', asset('assets/js/pudo-map.js')];
}
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Cart</span></nav>
    <h1>Your cart</h1>
    <p>Check out and pay by EFT into our Standard Bank account, then upload your proof of payment. Prefer to chat first? Send the list on WhatsApp instead.</p>
  </div>
</header>

<div class="section section--tight">
  <div class="container">

    <?php if (!$items): ?>
      <div class="empty card-fc">
        <div class="empty__icon"><i class="fa-solid fa-cart-shopping"></i></div>
        <h2>Your cart is empty</h2>
        <p class="mb-4">Tap <b>Add to cart</b> on any product to add it here.</p>
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
          <div class="card-fc card-fc--pad mb-3">
            <h2 style="font-size:1.15rem">Order summary</h2>
            <div class="summary-row"><span>Subtotal</span><b><?= format_price($subtotal) ?></b></div>
            <div class="summary-row"><span>Delivery</span><b class="text-muted-2" style="font-weight:500">Chosen at checkout</b></div>
            <?php if ($toFree > 0): ?>
              <p class="field__hint"><i class="fa-solid fa-truck-fast me-1"></i>Add <?= format_price_short($toFree) ?> more for free delivery.</p>
            <?php endif; ?>
            <a href="<?= SITE_URL ?>/checkout.php" class="btn-fc btn-fc--block btn-fc--lg mt-2"><i class="fa-solid fa-lock"></i> Checkout</a>
            <p class="text-center text-muted-3 mt-2 mb-0" style="font-size:.78rem">Pay by EFT and upload your proof of payment<?= isset(payment_methods()['cod']) ? ', or pay cash on delivery' : '' ?>.</p>
          </div>

          <details class="card-fc card-fc--pad">
          <summary style="cursor:pointer;font-family:var(--font-display);font-weight:700"><i class="fa-brands fa-whatsapp me-1" style="color:#25D366"></i> Or order on WhatsApp</summary>
          <form class="mt-3" method="post" action="<?= SITE_URL ?>/enquire.php" target="_blank" id="enquiryForm">
            <?= csrf_field() ?>

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
          </form>
          </details>
          <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--light btn-fc--block mt-3">Keep browsing</a>

          <div class="card-fc card-fc--pad mt-3" style="background:var(--leaf-wash);border-color:transparent">
            <b style="font-family:var(--font-display);display:block;margin-bottom:.5rem">What happens next</b>
            <ul style="font-size:.85rem;color:var(--text-2);margin:0;padding-left:1.1rem;line-height:1.8">
              <li>Check out — your order number and our bank details appear straight away.</li>
              <li>Pay by EFT with the order number as reference, then upload your proof of payment.</li>
              <li>We confirm within one working day and courier it in 2–4 working days.</li>
              <li>Track it under <b>My orders</b> — sign in with Google to keep everything in one place.</li>
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
