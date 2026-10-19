<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'coupons';
$page_title  = 'Coupons — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $cid    = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $data = [
            'code'           => strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_POST['code']) ?? ''),
            'description'    => sanitize((string) ($_POST['description'] ?? '')) ?: null,
            'discount_type'  => ($_POST['discount_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage',
            'discount_value' => (float) ($_POST['discount_value'] ?? 0),
            'min_order'      => (float) ($_POST['min_order'] ?? 0),
            'applies_to'     => in_array($_POST['applies_to'] ?? 'all', ['all', 'retail', 'reseller'], true) ? (string) $_POST['applies_to'] : 'all',
            'max_uses'       => ($_POST['max_uses'] ?? '') !== '' ? (int) $_POST['max_uses'] : null,
            'expiry_date'    => ($_POST['expiry_date'] ?? '') !== '' ? (string) $_POST['expiry_date'] : null,
            'status'         => (string) ($_POST['status'] ?? 'active'),
        ];
        if ($data['code'] === '') {
            $flash = ['warn', 'A coupon needs a code.'];
        } else {
            try {
                if ($cid) { db_update('coupons', $cid, $data); $flash = ['ok', 'Coupon updated.']; }
                else      { db_insert('coupons', $data);       $flash = ['ok', 'Coupon created.']; }
            } catch (PDOException $e) {
                $flash = ['warn', $e->getCode() === '23000' ? 'That code already exists.' : 'Could not save that coupon.'];
            }
        }
    } elseif ($action === 'delete' && $cid) {
        db()->prepare('DELETE FROM coupons WHERE id = :id')->execute([':id' => $cid]);
        $flash = ['ok', 'Coupon deleted.'];
    } elseif ($action === 'toggle' && $cid) {
        db()->prepare("UPDATE coupons SET status = IF(status='active','inactive','active') WHERE id = :id")->execute([':id' => $cid]);
        $flash = ['ok', 'Coupon status flipped.'];
    }
}

$rows = db()->query('SELECT * FROM coupons ORDER BY status, created_at DESC')->fetchAll();
$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM coupons WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
?>

<div class="dash-head">
  <div><h1>Coupons</h1><p>Promo codes applied in the basket. Reseller-only codes never work on a retail order and vice versa.</p></div>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= $flash[0] ?>"><i class="fa-solid fa-circle-info"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="table-wrap">
      <table class="table-fc">
        <thead><tr><th>Code</th><th>Discount</th><th>Applies to</th><th class="text-end">Min order</th><th class="text-center">Used</th><th>Expires</th><th>Status</th><th style="width:1%"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c):
          $expired = $c['expiry_date'] && strtotime($c['expiry_date']) < strtotime('today');
          $usedUp  = $c['max_uses'] !== null && (int) $c['used_count'] >= (int) $c['max_uses']; ?>
          <tr>
            <td>
              <b style="font-family:var(--font-mono);letter-spacing:.03em"><?= e($c['code']) ?></b>
              <?php if ($c['description']): ?><span class="d-block text-muted-3" style="font-size:.75rem"><?= e($c['description']) ?></span><?php endif; ?>
            </td>
            <td><b><?= $c['discount_type'] === 'percentage' ? (float) $c['discount_value'] . '%' : format_price_short((float) $c['discount_value']) ?></b></td>
            <td><span class="badge-fc <?= $c['applies_to'] === 'reseller' ? 'badge-fc--amber' : 'badge-fc--muted' ?>"><?= e($c['applies_to']) ?></span></td>
            <td class="text-end"><?= (float) $c['min_order'] > 0 ? format_price_short((float) $c['min_order']) : '—' ?></td>
            <td class="text-center"><?= (int) $c['used_count'] ?><?= $c['max_uses'] !== null ? ' / ' . (int) $c['max_uses'] : '' ?></td>
            <td style="font-size:.83rem"><?= $c['expiry_date'] ? date('j M Y', strtotime($c['expiry_date'])) : 'Never' ?></td>
            <td>
              <?php if ($expired): ?><span class="pill pill--danger">Expired</span>
              <?php elseif ($usedUp): ?><span class="pill pill--danger">Used up</span>
              <?php else: ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="pill pill--<?= $c['status'] === 'active' ? 'ok' : 'muted' ?>" style="border:0;cursor:pointer" type="submit"><?= e(ucfirst($c['status'])) ?></button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <a class="btn-fc btn-fc--sm btn-fc--light" href="?edit=<?= (int) $c['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                <form method="post" onsubmit="return confirm('Delete the code <?= e($c['code']) ?>?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn-fc btn-fc--sm btn-fc--light" style="color:var(--coral)" type="submit"><i class="fa-solid fa-trash-can"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-lg-4">
    <form method="post" class="card-fc card-fc--pad">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <h2 style="font-size:1.05rem"><?= $edit ? 'Edit coupon' : 'New coupon' ?></h2>

      <div class="field">
        <label for="code">Code</label>
        <input class="input-fc" type="text" id="code" name="code" value="<?= e((string) ($edit['code'] ?? '')) ?>" required
               style="text-transform:uppercase;font-family:var(--font-mono)" placeholder="WELCOME10">
      </div>
      <div class="field">
        <label for="description">Description</label>
        <input class="input-fc" type="text" id="description" name="description" value="<?= e((string) ($edit['description'] ?? '')) ?>">
      </div>
      <div class="row g-2">
        <div class="col-6 field">
          <label for="discount_type">Type</label>
          <select class="select-fc" id="discount_type" name="discount_type">
            <option value="percentage" <?= ($edit['discount_type'] ?? 'percentage') === 'percentage' ? 'selected' : '' ?>>Percentage</option>
            <option value="fixed"      <?= ($edit['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
          </select>
        </div>
        <div class="col-6 field">
          <label for="discount_value">Value</label>
          <input class="input-fc" type="number" step="0.01" min="0" id="discount_value" name="discount_value" value="<?= e((string) ($edit['discount_value'] ?? '')) ?>" required>
        </div>
      </div>
      <div class="row g-2">
        <div class="col-6 field">
          <label for="min_order">Min order</label>
          <input class="input-fc" type="number" step="0.01" min="0" id="min_order" name="min_order" value="<?= e((string) ($edit['min_order'] ?? '0')) ?>">
        </div>
        <div class="col-6 field">
          <label for="max_uses">Max uses</label>
          <input class="input-fc" type="number" min="1" id="max_uses" name="max_uses" value="<?= e((string) ($edit['max_uses'] ?? '')) ?>" placeholder="Unlimited">
        </div>
      </div>
      <div class="row g-2">
        <div class="col-6 field">
          <label for="applies_to">Applies to</label>
          <select class="select-fc" id="applies_to" name="applies_to">
            <?php foreach (['all' => 'All orders', 'retail' => 'Retail only', 'reseller' => 'Resellers only'] as $k => $label): ?>
              <option value="<?= $k ?>" <?= ($edit['applies_to'] ?? 'all') === $k ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 field">
          <label for="expiry_date">Expires</label>
          <input class="input-fc" type="date" id="expiry_date" name="expiry_date" value="<?= e((string) ($edit['expiry_date'] ?? '')) ?>">
        </div>
      </div>
      <div class="field">
        <label for="status">Status</label>
        <select class="select-fc" id="status" name="status">
          <option value="active"   <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>

      <button class="btn-fc btn-fc--block" type="submit"><?= $edit ? 'Save changes' : 'Create coupon' ?></button>
      <?php if ($edit): ?><a href="coupons.php" class="btn-fc btn-fc--light btn-fc--block mt-2">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
