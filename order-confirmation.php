<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$repo   = new OrderRepository();
$number = trim((string) ($_GET['order'] ?? ''));
$order  = $number !== '' ? $repo->getByNumber($number) : null;

if (!$order) {
    http_response_code(404);
    $page_title = 'Order not found — ' . SITE_NAME;
    include __DIR__ . '/includes/header.php';
    echo '<div class="section"><div class="container empty card-fc">'
       . '<div class="empty__icon"><i class="fa-solid fa-receipt"></i></div>'
       . '<h1>We could not find that order</h1>'
       . '<p class="mb-4">Check the order number in your confirmation email.</p>'
       . '<a href="' . SITE_URL . '/contact.php" class="btn-fc">Contact support</a></div></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$viewer = current_user();
// A guest order placed with this account's email joins the account once they sign in.
if ($viewer && empty($order['user_id']) && strcasecmp((string) $viewer['email'], (string) $order['customer_email']) === 0) {
    db_update('orders', (int) $order['id'], ['user_id' => (int) $viewer['id']]);
}
$items  = $repo->getItems((int) $order['id']);
$events = $repo->getEvents((int) $order['id']);

// Proof-of-payment upload. The order number is the only credential needed —
// it is unguessable, and requiring an account here would lock guests out of
// paying for their own order.
$uploadMsg = null;
$uploadErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pop'])) {
    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $uploadErr = 'Your session expired. Please try the upload again.';
    } else {
        $check = validate_upload($_FILES['pop'], ALLOWED_POP_TYPES, 8 * 1024 * 1024);
        if (!$check['ok']) {
            $uploadErr = $check['error'] . ' Accepted: JPG, PNG or PDF up to 8MB.';
        } else {
            $dir = UPLOAD_PATH . 'proof-of-payments/';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            // Bank screenshots: never served directly, only through serve-pop.php.
            if (!is_file($dir . '.htaccess')) { @file_put_contents($dir . '.htaccess', "Require all denied\n"); }
            $filename = $order['order_number'] . '-' . secure_filename($_FILES['pop']['name']);
            if (move_uploaded_file($_FILES['pop']['tmp_name'], $dir . $filename)) {
                $repo->attachPop((int) $order['id'], 'uploads/proof-of-payments/' . $filename);
                db_insert('order_events', ['order_id' => (int) $order['id'], 'status' => $order['status'], 'note' => 'Proof of payment uploaded.']);
                $order  = $repo->getByNumber($number);
                $events = $repo->getEvents((int) $order['id']);
                $uploadMsg = 'Thank you — your proof of payment is with us. We confirm within one working day.';
                Mailer::send(ADMIN_EMAIL, 'POP uploaded for ' . $order['order_number'],
                    email_wrap('<p>A proof of payment was uploaded for order <b>' . e($order['order_number']) . '</b> ('
                             . format_price((float) $order['total_amount']) . ').</p><p><a href="' . SITE_URL . '/dashboards/admin/order-detail.php?id=' . (int) $order['id'] . '">Open the order</a></p>'));
            } else {
                $uploadErr = 'The upload did not complete. Please try again.';
            }
        }
    }
}


// ── Pudo tracking ────────────────────────────────────────────────────
$tracking = null;
if ($order['fulfilment'] === 'pudo' && !empty($order['courier_waybill']) && Pudo::configured()) {
    try {
        $tracking = Pudo::track((string) $order['courier_waybill']);
    } catch (Throwable $e) {
        error_log('Pudo tracking failed: ' . $e->getMessage());
    }
}

$flow    = ['pending', 'confirmed', 'packing', in_array($order['fulfilment'], ['delivery', 'pudo'], true) ? 'shipped' : 'ready', 'delivered'];
$current = array_search($order['status'], $flow, true);
$isDone  = fn(int $i) => $order['status'] === 'cancelled' ? false : ($current !== false && $i < $current);
$isNow   = fn(int $i) => $current !== false && $i === $current;

$page_title       = 'Order ' . $order['order_number'] . ' — ' . SITE_NAME;
$meta_description = 'Your CureMeds order status and payment details.';
$meta_robots      = 'noindex';
$body_class       = 'order-page';
include __DIR__ . '/includes/header.php';
?>

<div class="section section--tight">
  <div class="container" style="max-width:1000px">

    <div class="card-fc order-hero mb-3">
      <div class="order-hero__tick"><i class="fa-solid fa-check"></i></div>
      <h1 style="font-size:1.8rem">Order received</h1>
      <p class="text-muted-2 mb-2">Thanks <?= e((string) $order['customer_name']) ?> — here is everything you need.</p>
      <div class="order-no">
        <?= e($order['order_number']) ?>
        <button class="icon-btn" type="button" data-copy="<?= e($order['order_number']) ?>" style="width:32px;height:32px" aria-label="Copy order number">
          <i class="fa-regular fa-copy"></i>
        </button>
      </div>
      <p class="text-muted-3 mt-2 mb-0" style="font-size:.82rem">
        Placed <?= date('j F Y \a\t H:i', strtotime($order['created_at'])) ?> · use the order number as your EFT reference
      </p>
    </div>

    <?php if ($uploadMsg): ?>
      <div class="alert-fc alert-fc--ok"><i class="fa-solid fa-circle-check"></i><span><?= e($uploadMsg) ?></span></div>
    <?php endif; ?>
    <?php if ($uploadErr): ?>
      <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($uploadErr) ?></span></div>
    <?php endif; ?>

    <div class="row g-3">

      <!-- ── Left: payment + items ────────────────────────────────── -->
      <div class="col-lg-7">

        <?php if ($order['payment_status'] === 'unpaid' && $order['payment_method'] === 'cash'): ?>
        <div class="alert-fc alert-fc--info">
          <i class="fa-solid fa-hand-holding-dollar"></i>
          <span><b>Cash on delivery.</b> Pay <?= format_price((float) $order['total_amount']) ?> when your order arrives. <?= e((string) get_setting('cod_note', '')) ?></span>
        </div>
        <?php elseif ($order['payment_status'] === 'unpaid'): ?>
        <section class="card-fc card-fc--pad mb-3" style="border-color:var(--amber);border-width:1.5px">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge-fc badge-fc--amber"><i class="fa-solid fa-clock"></i>Step 1 of 2</span>
            <h2 style="font-size:1.15rem;margin:0">Pay by EFT</h2>
          </div>
          <p class="text-muted-2" style="font-size:.9rem">
            Transfer <b><?= format_price((float) $order['total_amount']) ?></b> using
            <b><?= e($order['order_number']) ?></b> as the reference. We hold your stock for 48 hours.
          </p>

          <div class="bank-box">
            <dl>
              <dt>Bank</dt><dd><?= e((string) get_setting('bank_name', '')) ?></dd>
              <?php if (get_setting('bank_account_holder')): ?><dt>Account holder</dt><dd><?= e((string) get_setting('bank_account_holder', '')) ?></dd><?php endif; ?>
              <dt>Account number</dt>
              <dd>
                <?= e((string) get_setting('bank_account_number', '')) ?>
                <button class="icon-btn" type="button" data-copy="<?= e((string) get_setting('bank_account_number', '')) ?>" style="width:26px;height:26px" aria-label="Copy account number"><i class="fa-regular fa-copy" style="font-size:.75rem"></i></button>
              </dd>
              <?php if (get_setting('bank_branch_code')): ?><dt>Branch code</dt><dd><?= e((string) get_setting('bank_branch_code', '')) ?></dd><?php endif; ?>
              <dt>Amount</dt><dd><?= format_price((float) $order['total_amount']) ?></dd>
              <dt>Reference</dt><dd><?= e($order['order_number']) ?></dd>
            </dl>
          </div>
        </section>

        <section class="card-fc card-fc--pad mb-3">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge-fc <?= $order['pop_path'] ? 'badge-fc--leaf' : 'badge-fc--amber' ?>">
              <i class="fa-solid <?= $order['pop_path'] ? 'fa-circle-check' : 'fa-upload' ?>"></i>Step 2 of 2
            </span>
            <h2 style="font-size:1.15rem;margin:0">Upload your proof of payment</h2>
          </div>

          <?php if ($order['pop_path']): ?>
            <div class="alert-fc alert-fc--ok mb-0">
              <i class="fa-solid fa-file-circle-check"></i>
              <span>Proof of payment received. We will confirm your order within one working day and email you.</span>
            </div>
          <?php else: ?>
            <p class="text-muted-2" style="font-size:.9rem">A screenshot of the transfer or a bank PDF is fine.</p>
            <form method="post" enctype="multipart/form-data">
              <?= csrf_field() ?>
              <div class="field">
                <input class="input-fc" type="file" name="pop" accept="image/jpeg,image/png,application/pdf" required aria-label="Proof of payment file">
                <div class="field__hint">JPG, PNG or PDF, up to 8MB.</div>
              </div>
              <button class="btn-fc" type="submit"><i class="fa-solid fa-upload"></i> Upload proof of payment</button>
            </form>
          <?php endif; ?>
        </section>
        <?php else: ?>
        <div class="alert-fc alert-fc--ok">
          <i class="fa-solid fa-circle-check"></i>
          <span><b>Payment confirmed.</b> Nothing more to do — we will keep you posted as your order moves.</span>
        </div>
        <?php endif; ?>

        <section class="card-fc card-fc--pad">
          <h2 style="font-size:1.15rem">What you ordered</h2>
          <?php foreach ($items as $i): ?>
          <div class="d-flex gap-3 align-items-center py-2" style="border-bottom:1px solid var(--line)">
            <img src="<?= e(product_image($i['image'])) ?>" alt="" width="54" height="54"
                 style="border-radius:var(--r-xs);object-fit:cover;background:var(--paper-2);flex-shrink:0">
            <div style="flex:1;min-width:0">
              <?php if ($i['product_slug']): ?>
                <a href="<?= SITE_URL ?>/modules/shop/product.php?slug=<?= e($i['product_slug']) ?>" style="font-weight:700;font-size:.9rem"><?= e($i['name']) ?></a>
              <?php else: ?>
                <span style="font-weight:700;font-size:.9rem"><?= e($i['name']) ?></span>
              <?php endif; ?>
              <div class="text-muted-3" style="font-size:.76rem">
                <?php if ($i['variant_text']): ?><?= e($i['variant_text']) ?> · <?php endif; ?>
                <?= (int) $i['qty'] ?> × <?= format_price((float) $i['unit_price']) ?>
              </div>
            </div>
            <span class="money" style="font-size:.92rem"><?= format_price((float) $i['unit_price'] * (int) $i['qty']) ?></span>
          </div>
          <?php endforeach; ?>

          <div class="mt-3">
            <div class="summary-row"><span>Subtotal</span><b><?= format_price((float) $order['subtotal']) ?></b></div>
            <?php if ((float) $order['discount_amount'] > 0): ?>
              <div class="summary-row" style="color:var(--leaf)"><span>Discount<?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?></span><b style="color:var(--leaf)">−<?= format_price((float) $order['discount_amount']) ?></b></div>
            <?php endif; ?>
            <div class="summary-row"><span><?= e(FULFILMENT_METHODS[$order['fulfilment']]['label']) ?></span><b><?= (float) $order['shipping_amount'] > 0 ? format_price((float) $order['shipping_amount']) : 'Free' ?></b></div>
            <div class="summary-total"><span>Total</span><b class="money"><?= format_price((float) $order['total_amount']) ?></b></div>
          </div>

          <?php if ($order['order_type'] === 'reseller' && (float) $order['est_resale_value'] > 0): ?>
            <div class="mt-3 p-3" style="background:var(--amber-wash);border-radius:var(--r)">
              <div class="summary-row" style="padding:.2rem 0"><span>Resale value at RRP</span><b><?= format_price((float) $order['est_resale_value']) ?></b></div>
              <div class="summary-row" style="padding:.2rem 0"><span class="fw-bold text-amber">Profit if it all sells</span><b style="color:var(--leaf)">+<?= format_price(max(0, (float) $order['est_resale_value'] - (float) $order['total_amount'])) ?></b></div>
            </div>
          <?php endif; ?>

        </section>
      </div>

      <!-- ── Right: status + fulfilment ───────────────────────────── -->
      <div class="col-lg-5">
        <section class="card-fc card-fc--pad mb-3">
          <h2 style="font-size:1.15rem">Order status</h2>

          <?php if ($order['status'] === 'cancelled'): ?>
            <div class="alert-fc alert-fc--danger mb-0"><i class="fa-solid fa-circle-xmark"></i><span>This order was cancelled. Contact us if that is unexpected.</span></div>
          <?php else: ?>
            <div class="timeline mt-3">
              <?php foreach ($flow as $i => $step):
                $meta = ORDER_STATUSES[$step]; ?>
                <div class="tl-item <?= $isDone($i) ? 'is-done' : ($isNow($i) ? 'is-now' : '') ?>">
                  <b><?= e($meta['label']) ?></b>
                  <span>
                    <?php
                    $stamp = null;
                    foreach ($events as $ev) { if ($ev['status'] === $step) { $stamp = $ev['created_at']; } }
                    echo $stamp ? date('j M, H:i', strtotime($stamp)) : ($isNow($i) ? 'In progress' : '—');
                    ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="card-fc card-fc--pad mb-3">
          <h2 style="font-size:1.15rem"><?= e(FULFILMENT_METHODS[$order['fulfilment']]['label']) ?></h2>
          <p class="text-muted-2" style="font-size:.88rem"><?= e(FULFILMENT_METHODS[$order['fulfilment']]['blurb']) ?></p>

          <?php if ($order['fulfilment'] === 'pudo'): ?>
            <div class="bank-box" style="border-style:solid">
              <b class="d-block mb-1" style="font-size:.8rem;color:var(--text-3)">PUDO LOCKER</b>
              <?= e((string) $order['shipping_address']) ?>
            </div>
            <?php if (!empty($order['courier_waybill'])): ?>
              <div class="mt-2" style="font-size:.86rem">
                Waybill <b><?= e((string) $order['courier_waybill']) ?></b>
                <?php if ($tracking && $tracking['status'] !== ''): ?> · <span class="badge-fc badge-fc--leaf"><?= e(ucwords(str_replace('-', ' ', $tracking['status']))) ?></span><?php endif; ?>
              </div>
              <?php if ($tracking && $tracking['events']): ?>
                <ul class="list-unstyled mt-2 mb-0" style="font-size:.8rem">
                  <?php foreach (array_slice($tracking['events'], 0, 6) as $ev): ?>
                    <li class="py-1" style="border-bottom:1px solid var(--line)">
                      <b><?= e(ucwords(str_replace('-', ' ', (string) ($ev['status'] ?? '')))) ?></b>
                      <span class="text-muted-3"> · <?= e(date('j M, H:i', strtotime((string) ($ev['date'] ?? 'now')))) ?><?= !empty($ev['location']) ? ' · ' . e((string) $ev['location']) : '' ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            <?php else: ?>
              <p class="text-muted-3 mt-2 mb-0" style="font-size:.8rem">Your waybill appears here once the parcel is booked with The Courier Guy.</p>
            <?php endif; ?>
          <?php elseif ($order['fulfilment'] === 'delivery'): ?>
            <div class="bank-box" style="border-style:solid">
              <b class="d-block mb-1" style="font-size:.8rem;color:var(--text-3)">DELIVERING TO</b>
              <?= nl2br(e((string) $order['shipping_address'])) ?>
            </div>
          <?php else: ?>
            <div class="bank-box" style="border-style:solid">
              <b class="d-block mb-1" style="font-size:.8rem;color:var(--text-3)">COLLECT FROM</b>
              <b><?= e((string) $order['pickup_name']) ?></b><br>
              <?= e((string) $order['pickup_address']) ?><br>
              <span class="text-muted-3" style="font-size:.82rem"><?= e((string) $order['pickup_hours']) ?></span>
            </div>
          <?php endif; ?>
        </section>

        <section class="card-fc card-fc--pad">
          <h2 style="font-size:1.05rem">Need a hand?</h2>
          <p class="text-muted-2" style="font-size:.86rem">Quote <b><?= e($order['order_number']) ?></b> and we will pick it up straight away.</p>
          <div class="d-flex flex-column gap-2">
            <a href="<?= SITE_URL ?>/contact.php" class="btn-fc btn-fc--ghost btn-fc--sm"><i class="fa-solid fa-envelope"></i> Message support</a>
            <?php if ($wa = get_setting('contact_whatsapp', '')): ?>
              <a href="https://wa.me/<?= e($wa) ?>?text=<?= urlencode('Hi CureMeds, about order ' . $order['order_number']) ?>"
                 target="_blank" rel="noopener" class="btn-fc btn-fc--ghost btn-fc--sm">
                <i class="fa-brands fa-whatsapp"></i> WhatsApp us
              </a>
            <?php endif; ?>
            <?php if ($viewer): ?>
              <a href="<?= SITE_URL ?>/dashboards/customer/orders.php" class="btn-fc btn-fc--light btn-fc--sm">All my orders</a>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
