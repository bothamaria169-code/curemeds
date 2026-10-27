<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'orders';
require_once __DIR__ . '/../../includes/dash-head.php';

$repo  = new OrderRepository();
$order = $repo->getById((int) ($_GET['id'] ?? 0));

if (!$order) {
    echo '<div class="empty card-fc"><div class="empty__icon"><i class="fa-solid fa-receipt"></i></div>'
       . '<h1>Order not found</h1><a href="orders.php" class="btn-fc mt-3">Back to orders</a></div>';
    require_once __DIR__ . '/../../includes/dash-foot.php';
    exit;
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? '')) && ($_POST['action'] ?? '') === 'book_pudo') {
    try {
        $res = Pudo::book($order);
        $repo->setStatus((int) $order['id'], 'shipped', 'Booked with The Courier Guy (Pudo). Waybill ' . ($res['custom_tracking_reference'] ?? $res['short_tracking_reference'] ?? ''));
        $order = $repo->getById((int) $order['id']);
        notify_order_status($order);
        $flash = 'Booked with Pudo — waybill ' . ($order['courier_waybill'] ?: '(pending)') . '. The customer was emailed.';
    } catch (Throwable $e) {
        $flash = 'Pudo: ' . $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    if (!empty($_POST['status']) && isset(ORDER_STATUSES[(string) $_POST['status']])) {
        $repo->setStatus((int) $order['id'], (string) $_POST['status'], trim((string) ($_POST['note'] ?? '')));
        $order['status'] = (string) $_POST['status'];
        notify_order_status($order);
        $flash = 'Status updated and the customer emailed.';
    }
    if (isset($_POST['payment_status']) && $_POST['payment_status'] !== '') {
        $repo->setPaymentStatus((int) $order['id'], (string) $_POST['payment_status']);
        $flash = 'Payment status updated.';
    }
    if (isset($_POST['admin_notes'])) {
        db_update('orders', (int) $order['id'], ['admin_notes' => sanitize((string) $_POST['admin_notes'])]);
        $flash = 'Internal note saved.';
    }
    $order = $repo->getById((int) $order['id']);
}

$items  = $repo->getItems((int) $order['id']);
$events = $repo->getEvents((int) $order['id']);

$page_title = 'Order ' . $order['order_number'] . ' — CureMeds admin';
?>

<div class="dash-head">
  <div>
    <nav class="crumbs crumbs--light"><a href="orders.php">Orders</a><i class="fa-solid fa-chevron-right"></i><span><?= e($order['order_number']) ?></span></nav>
    <h1 style="font-family:var(--font-mono);font-size:1.5rem"><?= e($order['order_number']) ?></h1>
    <p>
      Placed <?= date('j F Y \a\t H:i', strtotime($order['created_at'])) ?>
      <?php if ($order['order_type'] === 'reseller'): ?><span class="badge-fc badge-fc--amber ms-2">Reseller order</span><?php endif; ?>
    </p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= SITE_URL ?>/invoice.php?order=<?= urlencode($order['order_number']) ?>" target="_blank" class="btn-fc btn-fc--ghost btn-fc--sm">
      <i class="fa-solid fa-file-invoice"></i> Invoice
    </a>
    <a href="<?= SITE_URL ?>/order-confirmation.php?order=<?= urlencode($order['order_number']) ?>" target="_blank" class="btn-fc btn-fc--ghost btn-fc--sm">
      <i class="fa-solid fa-eye"></i> Customer view
    </a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--ok"><i class="fa-solid fa-circle-check"></i><span><?= e($flash) ?></span></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">

    <div class="card-fc mb-3">
      <div class="p-3 pb-0"><h2 style="font-size:1.05rem;margin:0">Lines</h2></div>
      <div class="table-wrap" style="border:0">
        <table class="table-fc">
          <thead><tr><th>Product</th><th>SKU</th><th class="text-center">Qty</th><th class="text-end">Unit</th><th class="text-end">Line</th></tr></thead>
          <tbody>
          <?php foreach ($items as $i): ?>
            <tr>
              <td>
                <div class="d-flex gap-2 align-items-center">
                  <img src="<?= e(product_image($i['image'])) ?>" alt="" width="38" height="38" style="border-radius:6px;object-fit:cover;background:var(--paper-2)">
                  <span>
                    <b style="font-size:.86rem"><?= e($i['name']) ?></b>
                    <?php if ($i['variant_text']): ?><span class="d-block text-muted-3" style="font-size:.74rem"><?= e($i['variant_text']) ?></span><?php endif; ?>
                  </span>
                </div>
              </td>
              <td style="font-family:var(--font-mono);font-size:.76rem"><?= e((string) $i['sku']) ?></td>
              <td class="text-center"><?= (int) $i['qty'] ?></td>
              <td class="text-end"><?= format_price((float) $i['unit_price']) ?></td>
              <td class="text-end money"><?= format_price((float) $i['unit_price'] * (int) $i['qty']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="p-3">
        <div class="summary-row"><span>Subtotal</span><b><?= format_price((float) $order['subtotal']) ?></b></div>
        <?php if ((float) $order['discount_amount'] > 0): ?>
          <div class="summary-row" style="color:var(--leaf)"><span>Discount<?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?></span><b style="color:var(--leaf)">−<?= format_price((float) $order['discount_amount']) ?></b></div>
        <?php endif; ?>
        <div class="summary-row"><span><?= e(FULFILMENT_METHODS[$order['fulfilment']]['label']) ?></span><b><?= format_price((float) $order['shipping_amount']) ?></b></div>
        <div class="summary-total"><span>Total</span><b class="money"><?= format_price((float) $order['total_amount']) ?></b></div>
      </div>
    </div>

    <div class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1.05rem">Move this order along</h2>
      <form method="post" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-md-4 field mb-0">
          <label for="status">Status</label>
          <select class="select-fc" id="status" name="status">
            <?php foreach (ORDER_STATUSES as $k => $m): ?>
              <option value="<?= $k ?>" <?= $order['status'] === $k ? 'selected' : '' ?>><?= e($m['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5 field mb-0">
          <label for="note">Note for the timeline <span class="text-muted-3 fw-normal">(optional)</span></label>
          <input class="input-fc" type="text" id="note" name="note" placeholder="e.g. Waybill JHB0012345">
        </div>
        <div class="col-md-3">
          <button class="btn-fc btn-fc--block" type="submit">Update &amp; email</button>
        </div>
      </form>

      <form method="post" class="d-flex gap-2 mt-3 pt-3 align-items-end" style="border-top:1px solid var(--line)">
        <?= csrf_field() ?>
        <div class="field mb-0" style="flex:1;max-width:220px">
          <label for="payment_status">Payment</label>
          <select class="select-fc" id="payment_status" name="payment_status">
            <?php foreach (['unpaid' => 'Unpaid', 'paid' => 'Paid', 'refunded' => 'Refunded', 'failed' => 'Failed'] as $k => $label): ?>
              <option value="<?= $k ?>" <?= $order['payment_status'] === $k ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn-fc btn-fc--ghost" type="submit">Save payment status</button>
      </form>
    </div>

    <div class="card-fc card-fc--pad">
      <h2 style="font-size:1.05rem">Internal notes</h2>
      <p class="text-muted-3" style="font-size:.82rem">Never shown to the customer.</p>
      <form method="post">
        <?= csrf_field() ?>
        <textarea class="textarea-fc" name="admin_notes" rows="3" style="min-height:90px"><?= e((string) $order['admin_notes']) ?></textarea>
        <button class="btn-fc btn-fc--sm mt-2" type="submit">Save note</button>
      </form>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1.05rem">Customer</h2>
      <b><?= e((string) $order['customer_name']) ?></b>
      <div style="font-size:.85rem;color:var(--text-2);line-height:1.8">
        <div><i class="fa-solid fa-envelope me-2 text-muted-3"></i><a href="mailto:<?= e((string) $order['customer_email']) ?>"><?= e((string) $order['customer_email']) ?></a></div>
        <div><i class="fa-solid fa-phone me-2 text-muted-3"></i><?= e((string) $order['customer_phone']) ?></div>
        <?php if ($order['reseller_code']): ?>
          <div><i class="fa-solid fa-handshake me-2 text-muted-3"></i><?= e($order['reseller_code']) ?></div>
        <?php endif; ?>
        <?php if (!$order['user_id']): ?>
          <div class="badge-fc badge-fc--muted mt-2">Guest checkout</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1.05rem"><?= e(FULFILMENT_METHODS[$order['fulfilment']]['label']) ?></h2>
      <p style="font-size:.86rem;color:var(--text-2);white-space:pre-line;margin:0"><?= e((string) $order['shipping_address']) ?></p>
      <?php if ($order['fulfilment'] === 'pudo'): ?>
        <?php if (!empty($order['courier_waybill'])): ?>
          <div class="mt-2" style="font-size:.88rem">Waybill <b><?= e((string) $order['courier_waybill']) ?></b><?= !empty($order['courier_pin']) ? ' · drop-off PIN <b>' . e((string) $order['courier_pin']) . '</b>' : '' ?></div>
          <p class="field__hint mb-0">Drop the parcel at your locker with the PIN, or wait for the courier collection. Print the waybill at customer.pudo.co.za.</p>
        <?php elseif (Pudo::configured()): ?>
          <form method="post" class="mt-3" onsubmit="return confirm('Book this parcel with Pudo now? Pudo charges your account for it.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="book_pudo">
            <button class="btn-fc btn-fc--block btn-fc--sm" type="submit"><i class="fa-solid fa-box-archive"></i> Book Pudo shipment</button>
            <div class="field__hint">Creates the waybill to locker <?= e((string) $order['pudo_locker_code']) ?>, marks the order "Shipped" and emails the customer.<?= $order['payment_status'] !== 'paid' ? ' <b style="color:var(--danger)">Not paid yet.</b>' : '' ?></div>
          </form>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($order['notes']): ?>
        <div class="alert-fc alert-fc--info mt-3 mb-0" style="font-size:.83rem">
          <i class="fa-solid fa-note-sticky"></i><span><b>Customer note:</b> <?= e($order['notes']) ?></span>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($order['pop_path']): ?>
    <div class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1.05rem">Proof of payment</h2>
      <a href="<?= SITE_URL ?>/serve-pop.php?order=<?= urlencode($order['order_number']) ?>" target="_blank" class="btn-fc btn-fc--ghost btn-fc--block btn-fc--sm">
        <i class="fa-solid fa-file-arrow-down"></i> Open the uploaded file
      </a>
    </div>
    <?php endif; ?>

    <?php if ($order['order_type'] === 'reseller' && (float) $order['est_resale_value'] > 0): ?>
    <div class="card-fc card-fc--pad mb-3" style="background:var(--amber-wash);border-color:#F0DFB6">
      <h2 style="font-size:1.05rem">Reseller economics</h2>
      <div class="summary-row"><span>They paid</span><b><?= format_price((float) $order['total_amount']) ?></b></div>
      <div class="summary-row"><span>Resale value at RRP</span><b><?= format_price((float) $order['est_resale_value']) ?></b></div>
      <div class="summary-row"><span class="fw-bold text-amber">Their margin</span><b style="color:var(--leaf)">+<?= format_price(max(0, (float) $order['est_resale_value'] - (float) $order['total_amount'])) ?></b></div>
    </div>
    <?php endif; ?>

    <div class="card-fc card-fc--pad">
      <h2 style="font-size:1.05rem">History</h2>
      <div class="timeline mt-3">
        <?php foreach (array_reverse($events) as $i => $ev): ?>
          <div class="tl-item <?= $i === 0 ? 'is-now' : 'is-done' ?>">
            <b><?= e(ORDER_STATUSES[$ev['status']]['label'] ?? $ev['status']) ?></b>
            <span><?= e((string) $ev['note']) ?></span>
            <span><?= date('j M Y, H:i', strtotime($ev['created_at'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
