<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$items = cart_items();
if (!$items) {
    redirect('cart.php');
}

$viewer   = current_user();
$isRes    = Pricing::isReseller($viewer);
$subtotal = cart_subtotal();
$resale   = cart_resale_value();
$points   = get_pickup_points();
$methods  = fulfilment_methods();
$payments = payment_methods();

$discount   = 0.0;
$couponCode = $_SESSION['coupon'] ?? null;
if ($couponCode) {
    $check = validate_coupon($couponCode, $subtotal, $isRes ? 'reseller' : 'retail');
    if ($check['valid']) { $discount = (float) $check['discount']; } else { unset($_SESSION['coupon'], $couponCode); $couponCode = null; }
}

$errors = [];
$form   = [
    'name'       => $viewer['name']  ?? '',
    'email'      => $viewer['email'] ?? '',
    'phone'      => $viewer['phone'] ?? '',
    'fulfilment' => isset($methods['pudo']) ? 'pudo' : 'delivery',
    'pickup'     => $points[0]['id'] ?? null,
    'locker'     => '',
    'payment'    => isset($payments['eft']) ? 'eft' : (string) array_key_first($payments),
    'address'    => '',
    'notes'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['name','email','phone','address','notes'] as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $form['fulfilment'] = (string) ($_POST['fulfilment'] ?? 'delivery');
    $form['pickup']     = !empty($_POST['pickup_point_id']) ? (int) $_POST['pickup_point_id'] : null;
    $form['locker']     = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['pudo_locker'] ?? ''));
    $form['payment']    = (string) ($_POST['payment'] ?? '');

    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please review and submit again.';
    }
    if (mb_strlen($form['name']) < 2)                     { $errors[] = 'Enter the name the order is for.'; }
    if (!safe_email($form['email']))                      { $errors[] = 'Enter a valid email address — this is where your order confirmation goes.'; }
    if (mb_strlen(preg_replace('/\D/', '', $form['phone']) ?? '') < 9) { $errors[] = 'Enter a contact number we can reach you on.'; }
    if (!isset($methods[$form['fulfilment']]))            { $errors[] = 'Choose how you would like to receive your order.'; }
    if (!isset($payments[$form['payment']]))              { $errors[] = 'Choose how you would like to pay.'; }
    $locker = null;
    if ($form['fulfilment'] === 'pudo') {
        try {
            $locker = $form['locker'] !== '' ? Pudo::locker($form['locker']) : null;
        } catch (Throwable) {
            $locker = null;
        }
        if (!$locker) { $errors[] = 'Choose the Pudo locker your order should go to — tap one on the map.'; }
    }
    if ($form['fulfilment'] === 'delivery' && mb_strlen($form['address']) < 12) {
        $errors[] = 'Enter a full delivery address including the suburb and postal code.';
    }
    if (in_array($form['fulfilment'], ['collection', 'runner'], true) && !$form['pickup']) {
        $errors[] = 'Choose a collection point.';
    }
    if (empty($_POST['terms'])) { $errors[] = 'Please accept the terms to place the order.'; }

    if (!$errors) {
        $shipping = shipping_cost($form['fulfilment'], $subtotal, $form['pickup'], $form['locker']);
        $total    = max(0.0, $subtotal - $discount + $shipping);

        $pickupLabel = '';
        if ($locker) {
            $pickupLabel = 'Pudo locker ' . $locker['code'] . ': ' . $locker['name'] . ' — ' . $locker['address'];
        } elseif ($form['fulfilment'] !== 'delivery' && $form['pickup']) {
            foreach ($points as $p) {
                if ((int) $p['id'] === (int) $form['pickup']) {
                    $pickupLabel = $p['name'] . ' — ' . $p['address'];
                }
            }
        }

        $orderRow = [
            'user_id'          => $viewer['id'] ?? null,
            'order_type'       => $isRes ? 'reseller' : 'retail',
            'customer_name'    => sanitize($form['name']),
            'customer_email'   => $form['email'],
            'customer_phone'   => sanitize($form['phone']),
            'subtotal'         => $subtotal,
            'discount_amount'  => $discount,
            'shipping_amount'  => $shipping,
            'total_amount'     => $total,
            'est_resale_value' => $isRes ? $resale : 0,
            'coupon_code'      => $couponCode,
            'status'           => 'pending',
            'payment_method'   => $form['payment'] === 'cod' ? 'cash' : 'eft',
            'payment_status'   => 'unpaid',
            'fulfilment'       => $form['fulfilment'],
            'pickup_point_id'  => in_array($form['fulfilment'], ['collection', 'runner'], true) ? $form['pickup'] : null,
            'pudo_locker_code' => $locker['code'] ?? null,
            'pudo_locker_name' => $locker ? mb_substr($locker['name'], 0, 160) : null,
            'shipping_address' => $form['fulfilment'] === 'delivery' ? sanitize($form['address']) : $pickupLabel,
            'notes'            => $form['notes'] !== '' ? sanitize($form['notes']) : null,
        ];

        $lines = array_map(fn($i) => [
            'product_id'   => (int) $i['product']['id'],
            'variant_id'   => $i['variant']['id'] ?? null,
            'name'         => $i['product']['name'],
            'variant_text' => $i['variant_text'],
            'sku'          => $i['variant']['sku'] ?? $i['product']['sku'],
            'qty'          => $i['qty'],
            'unit_price'   => $i['unit_price'],
            'unit_rrp'     => $i['unit_rrp'],
            'image'        => $i['image'],
        ], $items);

        try {
            $created = (new OrderRepository())->create($orderRow, $lines);

            if ($couponCode) {
                db()->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE code = :c')->execute([':c' => $couponCode]);
            }

            notify_order_placed($orderRow + ['order_number' => $created['order_number']], $lines);
            cart_clear();
            redirect('order-confirmation.php?order=' . urlencode($created['order_number']));
        } catch (Throwable $e) {
            error_log('Checkout failed: ' . $e->getMessage());
            $errors[] = 'We could not place that order. Nothing has been charged — please try again, or contact us if it keeps happening.';
        }
    }
}

$shipping = shipping_cost($form['fulfilment'], $subtotal, $form['pickup'], $form['locker']);
$total    = max(0.0, $subtotal - $discount + $shipping);

$page_title       = 'Checkout — ' . SITE_NAME;
$meta_robots      = 'noindex';
if (isset($methods['pudo'])) {
    $extra_css = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css'];
    $extra_js  = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js', asset('assets/js/pudo-map.js')];
}
$meta_description = 'Complete your CureMeds order.';
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs">
      <a href="<?= SITE_URL ?>/cart.php">Cart</a><i class="fa-solid fa-chevron-right"></i><span>Checkout</span>
    </nav>
    <h1>Checkout</h1>
    <p>Place the order, then pay by EFT into our Standard Bank account and upload your proof of payment. Your stock is held for 48 hours.</p>
  </div>
</header>

<div class="section section--tight">
  <div class="container checkout-layout">

    <form method="post" novalidate>
      <?= csrf_field() ?>

      <?php foreach ($errors as $err): ?>
        <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div>
      <?php endforeach; ?>

      <?php if (!$viewer): ?>
        <div class="alert-fc alert-fc--info">
          <i class="fa-solid fa-circle-info"></i>
          <span>
            You can check out as a guest, or <a href="<?= SITE_URL ?>/modules/auth/login.php?mode=email&redirect=<?= urlencode('checkout.php') ?>" class="fw-bold">sign in<?= google_login_enabled() ? ' with Google' : '' ?></a>
            to keep all your orders and proof of payment in your account.
          </span>
        </div>
      <?php endif; ?>

      <!-- ── 1. Contact ─────────────────────────────────────────── -->
      <section class="checkout-step card-fc card-fc--pad">
        <div class="checkout-step__head"><span class="checkout-step__n">1</span><h3>Who is this order for?</h3></div>
        <div class="row g-3">
          <div class="col-md-12 field">
            <label for="name">Full name</label>
            <input class="input-fc" type="text" id="name" name="name" value="<?= e($form['name']) ?>" required autocomplete="name">
          </div>
          <div class="col-md-6 field">
            <label for="email">Email address</label>
            <input class="input-fc" type="email" id="email" name="email" value="<?= e($form['email']) ?>" required autocomplete="email">
            <div class="field__hint">Your order number and bank details go here.</div>
          </div>
          <div class="col-md-6 field">
            <label for="phone">Mobile number</label>
            <input class="input-fc" type="tel" id="phone" name="phone" value="<?= e($form['phone']) ?>" required autocomplete="tel" placeholder="+27 82 000 0000">
            <div class="field__hint">Used by the courier. Pudo SMSes your locker PIN here.</div>
          </div>
        </div>
      </section>

      <!-- ── 2. Fulfilment ──────────────────────────────────────── -->
      <section class="checkout-step card-fc card-fc--pad">
        <div class="checkout-step__head"><span class="checkout-step__n">2</span><h3>How would you like it?</h3></div>

        <div class="row g-2 mb-3">
          <?php foreach ($methods as $key => $m):
            $cost = shipping_cost($key, $subtotal, $points[0]['id'] ?? null, $key === 'pudo' ? ($form['locker'] ?: null) : null); ?>
          <div class="col-md-6">
            <label class="opt-card h-100">
              <input type="radio" name="fulfilment" value="<?= $key ?>" <?= $form['fulfilment'] === $key ? 'checked' : '' ?>>
              <span>
                <span class="opt-card__title"><i class="fa-solid <?= e($m['icon']) ?> text-leaf"></i><?= e($m['label']) ?></span>
                <span class="opt-card__blurb"><?= e($m['blurb']) ?></span>
                <span class="badge-fc badge-fc--leaf mt-2"><?= $cost > 0 ? ($key === 'pudo' && !$form['locker'] && (float) get_setting('pudo_fixed_fee', '0') <= 0 ? 'From ' : '') . format_price_short($cost) : 'Free' ?></span>
              </span>
            </label>
          </div>
          <?php endforeach; ?>
        </div>

        <div id="deliveryFields">
          <div class="field">
            <label for="shippingAddress">Delivery address</label>
            <textarea class="textarea-fc" id="shippingAddress" name="address" rows="3"
                      placeholder="Street address, complex or unit number, suburb, city, postal code"><?= e($form['address']) ?></textarea>
            <div class="field__hint">A business address gets delivered faster than a residential one.</div>
          </div>
        </div>

        <?php if (isset($methods['pudo'])): ?>
        <div id="pudoFields" class="pudo" <?= $form['fulfilment'] === 'pudo' ? '' : 'hidden' ?>>
          <input type="hidden" name="pudo_locker" id="pudoLocker" value="<?= e($form['locker']) ?>">
          <div class="pudo__bar">
            <input class="input-fc" type="search" placeholder="Search a suburb, town or mall…" data-pudo-search aria-label="Search Pudo lockers">
            <button type="button" class="btn-fc btn-fc--light btn-fc--sm" data-pudo-near><i class="fa-solid fa-location-crosshairs"></i> Near me</button>
          </div>
          <div class="pudo__map" data-pudo-map></div>
          <div class="pudo__status" data-pudo-status>Loading lockers…</div>
          <div class="pudo__list" data-pudo-list></div>
          <div class="pudo__chosen" data-pudo-chosen hidden></div>
          <div class="field__hint mt-2">You get an SMS and email with a PIN when your parcel is in the locker. Many lockers are open 24/7.</div>
        </div>
        <?php endif; ?>

        <div id="pickupFields" hidden>
          <div class="field">
            <label for="pickupPoint">Collection point</label>
            <select class="select-fc" id="pickupPoint" name="pickup_point_id">
              <?php foreach ($points as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $form['pickup'] === (int) $p['id'] ? 'selected' : '' ?>>
                  <?= e($p['name']) ?> — <?= e($p['address']) ?><?= $p['fee'] > 0 ? ' (' . format_price_short((float) $p['fee']) . ')' : ' (free)' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="field__hint">
              We message you when the order is packed. Bring your order number and ID.
            </div>
          </div>
        </div>

        <div class="field mb-0">
          <label for="notes">Order notes <span class="text-muted-3 fw-normal">(optional)</span></label>
          <textarea class="textarea-fc" id="notes" name="notes" rows="2" style="min-height:70px"
                    placeholder="Gate code, best delivery times, anything we should know"><?= e($form['notes']) ?></textarea>
        </div>
      </section>

      <!-- ── 3. Payment ─────────────────────────────────────────── -->
      <section class="checkout-step card-fc card-fc--pad">
        <div class="checkout-step__head"><span class="checkout-step__n">3</span><h3>Payment</h3></div>

        <?php foreach ($payments as $key => $m): ?>
        <label class="opt-card mb-3">
          <input type="radio" name="payment" value="<?= e($key) ?>" <?= $form['payment'] === $key ? 'checked' : '' ?>>
          <span>
            <span class="opt-card__title"><i class="fa-solid <?= e($m['icon']) ?> text-leaf"></i><?= $key === 'eft' ? 'Bank EFT with proof of payment' : e($m['label']) ?></span>
            <span class="opt-card__blurb"><?= $key === 'eft' ? 'Place the order, transfer the total, then upload your proof of payment on the order page. Confirmed within one working day.' : e($m['note']) ?></span>
          </span>
        </label>
        <?php endforeach; ?>

        <?php if (isset($payments['eft'])): ?>
        <div class="bank-box mb-3">
          <b class="d-block mb-2"><i class="fa-solid fa-circle-info me-1 text-leaf"></i>These are the details you will pay into</b>
          <dl>
            <dt>Bank</dt><dd><?= e((string) get_setting('bank_name', '')) ?></dd>
            <?php if (get_setting('bank_account_holder')): ?><dt>Account holder</dt><dd><?= e((string) get_setting('bank_account_holder', '')) ?></dd><?php endif; ?>
            <dt>Account number</dt><dd><?= e((string) get_setting('bank_account_number', '')) ?></dd>
            <?php if (get_setting('bank_branch_code')): ?><dt>Branch code</dt><dd><?= e((string) get_setting('bank_branch_code', '')) ?></dd><?php endif; ?>
            <dt>Reference</dt><dd>Your order number (shown on the next page)</dd>
          </dl>
        </div>
        <?php endif; ?>

      </section>

      <label class="opt-card mb-3">
        <input type="checkbox" name="terms" value="1" required>
        <span class="opt-card__blurb">
          I accept the <a href="<?= SITE_URL ?>/terms.php" target="_blank" class="text-leaf fw-bold">terms of sale</a>
          and the <a href="<?= SITE_URL ?>/refund-policy.php" target="_blank" class="text-leaf fw-bold">returns policy</a>,
          and I understand that supplements sold here are complementary medicines that have not been evaluated by SAHPRA.
        </span>
      </label>

      <button class="btn-fc btn-fc--lg btn-fc--block" type="submit">
        <i class="fa-solid fa-lock"></i> Place order · <span id="orderTotalBtn"><?= format_price($total) ?></span>
      </button>
      <p class="text-center text-muted-3 mt-2 mb-0" style="font-size:.78rem">
        No money leaves your account on this page. You pay after the order is placed.
      </p>
    </form>

    <!-- ── Summary ─────────────────────────────────────────────── -->
    <aside class="summary">
      <div class="card-fc card-fc--pad">
        <h2 style="font-size:1.1rem">Your order</h2>

        <div style="max-height:300px;overflow-y:auto;margin:0 -.4rem;padding:0 .4rem">
          <?php foreach ($items as $i): ?>
          <div class="d-flex gap-2 align-items-center py-2" style="border-bottom:1px solid var(--line)">
            <div style="position:relative;flex-shrink:0">
              <img src="<?= e(product_image($i['image'])) ?>" alt="" width="48" height="48"
                   style="border-radius:var(--r-xs);object-fit:cover;background:var(--paper-2)">
              <span class="icon-btn__badge" style="top:-6px;right:-6px"><?= (int) $i['qty'] ?></span>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:.83rem;font-weight:700;line-height:1.3"><?= e($i['product']['name']) ?></div>
              <?php if ($i['variant_text']): ?><div class="text-muted-3" style="font-size:.73rem"><?= e($i['variant_text']) ?></div><?php endif; ?>
            </div>
            <span class="money" style="font-size:.86rem"><?= format_price_short($i['line_total']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="mt-3">
          <div class="summary-row"><span>Subtotal</span><b><?= format_price($subtotal) ?></b></div>
          <?php if ($discount > 0): ?>
            <div class="summary-row" style="color:var(--leaf)"><span>Discount (<?= e((string) $couponCode) ?>)</span><b style="color:var(--leaf)">−<?= format_price($discount) ?></b></div>
          <?php endif; ?>
          <div class="summary-row"><span>Delivery</span><b id="shipAmount"><?= $shipping > 0 ? format_price($shipping) : 'Free' ?></b></div>
          <div class="summary-total"><span>Total</span><b class="money" id="orderTotal"><?= format_price($total) ?></b></div>
        </div>

        <?php if ($isRes && $resale > $subtotal): ?>
          <div class="mt-3 pt-3" style="border-top:1px dashed var(--line-strong)">
            <div class="summary-row"><span>Resale value at RRP</span><b><?= format_price($resale) ?></b></div>
            <div class="summary-row"><span class="fw-bold text-amber">Your profit if it sells through</span><b style="color:var(--leaf)">+<?= format_price($resale - $subtotal) ?></b></div>
          </div>
        <?php endif; ?>

        <a href="<?= SITE_URL ?>/cart.php" class="btn-fc btn-fc--light btn-fc--block btn-fc--sm mt-3">
          <i class="fa-solid fa-pen"></i> Edit cart
        </a>
      </div>
    </aside>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
