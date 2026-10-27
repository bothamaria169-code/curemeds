<?php
declare(strict_types=1);
$dash_role   = 'customer';
$dash_active = 'orders';
$page_title  = 'My orders — CureMeds';
require_once __DIR__ . '/../../includes/dash-head.php';

// Guest orders placed with this email before they had an account join it here.
if (!empty($user['email'])) {
    db()->prepare('UPDATE orders SET user_id = :u WHERE user_id IS NULL AND customer_email = :e')
        ->execute([':u' => (int) $user['id'], ':e' => (string) $user['email']]);
}
$stmt = db()->prepare('SELECT * FROM orders WHERE user_id = :u ORDER BY created_at DESC LIMIT 100');
$stmt->execute([':u' => (int) $user['id']]);
$orders = $stmt->fetchAll();
?>

<div class="dash-head">
  <div>
    <h1>My orders</h1>
    <p>Pay, upload your proof of payment and follow each order from here.</p>
  </div>
  <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--sm"><i class="fa-solid fa-basket-shopping"></i> Shop</a>
</div>

<?php if (!$orders): ?>
  <div class="empty card-fc">
    <div class="empty__icon"><i class="fa-solid fa-receipt"></i></div>
    <h3>No orders yet</h3>
    <p class="mb-3">Orders you place while signed in — or with this email address — show up here.</p>
    <a href="<?= SITE_URL ?>/shop.php" class="btn-fc">Browse the shop</a>
  </div>
<?php else: ?>
  <div class="card-fc" style="overflow:auto">
    <table class="table-fc">
      <thead><tr><th>Order</th><th>Placed</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o):
        $meta = ORDER_STATUSES[$o['status']] ?? ['label' => ucfirst($o['status']), 'tone' => 'muted', 'icon' => 'fa-circle'];
        $needsPop = $o['payment_status'] === 'unpaid' && $o['payment_method'] === 'eft' && !$o['pop_path'] && $o['status'] !== 'cancelled'; ?>
        <tr>
          <td><b><?= e($o['order_number']) ?></b></td>
          <td style="white-space:nowrap"><?= date('j M Y', strtotime($o['created_at'])) ?></td>
          <td class="money"><?= format_price((float) $o['total_amount']) ?></td>
          <td>
            <?php if ($o['payment_status'] === 'paid'): ?><span class="pill pill--ok">Paid</span>
            <?php elseif ($needsPop): ?><span class="pill pill--warn">Upload proof of payment</span>
            <?php elseif ($o['pop_path']): ?><span class="pill pill--info">Proof received</span>
            <?php else: ?><span class="pill pill--muted"><?= $o['payment_method'] === 'cash' ? 'Cash on delivery' : ucfirst($o['payment_status']) ?></span><?php endif; ?>
          </td>
          <td><span class="pill pill--<?= e($meta['tone']) ?>"><i class="fa-solid <?= e($meta['icon']) ?>"></i><?= e($meta['label']) ?></span></td>
          <td><a class="btn-fc <?= $needsPop ? '' : 'btn-fc--ghost ' ?>btn-fc--sm" href="<?= SITE_URL ?>/order-confirmation.php?order=<?= urlencode($o['order_number']) ?>"><?= $needsPop ? 'Pay &amp; upload' : 'View' ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
