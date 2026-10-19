<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'orders';
$page_title  = 'Orders — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$repo  = new OrderRepository();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $order   = $repo->getById($orderId);

    if ($order) {
        if (!empty($_POST['status']) && isset(ORDER_STATUSES[(string) $_POST['status']])) {
            $repo->setStatus($orderId, (string) $_POST['status']);
            $order['status'] = (string) $_POST['status'];
            notify_order_status($order);
            $flash = [$order['order_number'] . ' moved to “' . ORDER_STATUSES[$order['status']]['label'] . '” and the customer emailed.'];
        }
        if (!empty($_POST['payment_status'])) {
            $repo->setPaymentStatus($orderId, (string) $_POST['payment_status']);
            if ((string) $_POST['payment_status'] === 'paid' && $order['status'] === 'pending') {
                $repo->setStatus($orderId, 'confirmed', 'Payment matched and confirmed.');
            }
            $flash = [$order['order_number'] . ' marked ' . e((string) $_POST['payment_status']) . '.'];
        }
    }
}

$filters = [
    'status'         => (string) ($_GET['status'] ?? ''),
    'type'           => (string) ($_GET['type'] ?? ''),
    'payment_status' => (string) ($_GET['payment'] ?? ''),
    'search'         => trim((string) ($_GET['q'] ?? '')),
];
$page   = max(1, (int) ($_GET['page'] ?? 1));
$result = $repo->getAll($filters, $page, 25);

$byStatus = [];
foreach (db()->query("SELECT status, COUNT(*) n FROM orders GROUP BY status") as $r) {
    $byStatus[$r['status']] = (int) $r['n'];
}
?>

<div class="dash-head">
  <div>
    <h1>Orders</h1>
    <p><?= (int) $result['total'] ?> order<?= $result['total'] === 1 ? '' : 's' ?> matching the current filters.</p>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--ok"><i class="fa-solid fa-circle-check"></i><span><?= $flash[0] ?></span></div>
<?php endif; ?>

<div class="d-flex gap-2 flex-wrap mb-3">
  <a class="chip <?= $filters['status'] === '' ? 'is-on' : '' ?>" href="?">All</a>
  <?php foreach (ORDER_STATUSES as $key => $meta): ?>
    <a class="chip <?= $filters['status'] === $key ? 'is-on' : '' ?>" href="?status=<?= $key ?>">
      <?= e($meta['label']) ?><?= isset($byStatus[$key]) ? ' · ' . $byStatus[$key] : '' ?>
    </a>
  <?php endforeach; ?>
  <a class="chip <?= $filters['payment_status'] === 'unpaid' ? 'is-on' : '' ?>" href="?payment=unpaid">Unpaid</a>
  <a class="chip <?= $filters['type'] === 'reseller' ? 'is-on' : '' ?>" href="?type=reseller">Reseller orders</a>
</div>

<div class="card-fc card-fc--pad mb-3">
  <form method="get" class="d-flex gap-2">
    <input class="input-fc" type="search" name="q" value="<?= e($filters['search']) ?>" placeholder="Order number, customer name or email">
    <button class="btn-fc" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
  </form>
</div>

<?php if (!$result['items']): ?>
  <div class="empty card-fc">
    <div class="empty__icon"><i class="fa-solid fa-receipt"></i></div>
    <h3>No orders match</h3>
    <p>Try clearing the filters.</p>
  </div>
<?php else: ?>
<div class="table-wrap">
  <table class="table-fc">
    <thead>
      <tr><th>Order</th><th>Customer</th><th>Fulfilment</th><th>Payment</th><th>Status</th><th class="text-end">Total</th><th style="width:1%"></th></tr>
    </thead>
    <tbody>
    <?php foreach ($result['items'] as $o):
      $meta = ORDER_STATUSES[$o['status']];
      $tone = ['ok' => 'ok', 'warn' => 'warn', 'danger' => 'danger'][$meta['tone']] ?? 'info'; ?>
      <tr>
        <td>
          <a href="order-detail.php?id=<?= (int) $o['id'] ?>" style="font-family:var(--font-mono);font-size:.8rem;font-weight:700"><?= e($o['order_number']) ?></a>
          <span class="d-block text-muted-3" style="font-size:.73rem"><?= date('j M Y, H:i', strtotime($o['created_at'])) ?></span>
          <?php if ($o['order_type'] === 'reseller'): ?><span class="badge-fc badge-fc--amber mt-1">Reseller</span><?php endif; ?>
        </td>
        <td>
          <b style="font-size:.87rem"><?= e((string) $o['customer_name']) ?></b>
          <span class="d-block text-muted-3" style="font-size:.73rem"><?= e((string) $o['customer_email']) ?></span>
          <span class="d-block text-muted-3" style="font-size:.73rem"><?= (int) $o['line_count'] ?> line<?= (int) $o['line_count'] === 1 ? '' : 's' ?></span>
        </td>
        <td>
          <span style="font-size:.83rem"><i class="fa-solid <?= e(FULFILMENT_METHODS[$o['fulfilment']]['icon']) ?> me-1 text-muted-3"></i><?= e(FULFILMENT_METHODS[$o['fulfilment']]['label']) ?></span>
        </td>
        <td>
          <span class="pill pill--<?= $o['payment_status'] === 'paid' ? 'ok' : ($o['payment_status'] === 'unpaid' ? 'warn' : 'danger') ?>">
            <?= e(ucfirst($o['payment_status'])) ?>
          </span>
          <?php if ($o['pop_path']): ?>
            <a href="<?= SITE_URL ?>/serve-pop.php?order=<?= urlencode($o['order_number']) ?>" target="_blank" class="d-block text-leaf" style="font-size:.72rem;font-weight:700">
              <i class="fa-solid fa-paperclip"></i> View POP
            </a>
          <?php endif; ?>
        </td>
        <td><span class="pill pill--<?= $tone ?>"><i class="fa-solid <?= $meta['icon'] ?>"></i><?= e($meta['label']) ?></span></td>
        <td class="text-end money"><?= format_price_short((float) $o['total_amount']) ?></td>
        <td>
          <div class="d-flex gap-1">
            <?php if ($o['payment_status'] === 'unpaid'): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
              <input type="hidden" name="payment_status" value="paid">
              <button class="btn-fc btn-fc--sm" onclick="return confirm('Confirm payment received for <?= e($o['order_number']) ?>?')">
                <i class="fa-solid fa-check"></i> Paid
              </button>
            </form>
            <?php endif; ?>
            <a href="order-detail.php?id=<?= (int) $o['id'] ?>" class="btn-fc btn-fc--sm btn-fc--light">Open</a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= render_pager($result['page'], $result['pages']) ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
