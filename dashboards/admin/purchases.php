<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'purchases';
$page_title  = 'Live ticker — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $id     = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $product = (new ProductRepository())->getById((int) ($_POST['product_id'] ?? 0));
        $name    = clean_text((string) ($_POST['customer'] ?? ''));
        $when    = strtotime((string) ($_POST['happened_at'] ?? '')) ?: time();
        if ($name === '' || !$product) {
            $flash = ['danger', 'Add the customer name and choose the product.'];
        } else {
            db_insert('purchase_feed', [
                'customer'     => mb_substr(ticker_name($name), 0, 80),
                'town'         => mb_substr(clean_text((string) ($_POST['town'] ?? '')), 0, 80) ?: null,
                'product_id'   => (int) $product['id'],
                'product_name' => $product['name'],
                'kind'         => ($_POST['kind'] ?? '') === 'received' ? 'received' : 'bought',
                'happened_at'  => date('Y-m-d H:i:s', min($when, time())),
            ]);
            $flash = ['ok', 'Added — it shows in the ticker on the site now.'];
        }
    } elseif ($action === 'toggle' && $id) {
        db()->prepare('UPDATE purchase_feed SET is_live = 1 - is_live WHERE id = :id')->execute([':id' => $id]);
        $flash = ['ok', 'Updated.'];
    } elseif ($action === 'delete' && $id) {
        db()->prepare('DELETE FROM purchase_feed WHERE id = :id')->execute([':id' => $id]);
        $flash = ['ok', 'Deleted.'];
    } elseif ($action === 'settings') {
        set_setting('ticker_enabled', !empty($_POST['ticker_enabled']) ? '1' : '0');
        $flash = ['ok', 'Ticker ' . (!empty($_POST['ticker_enabled']) ? 'switched on.' : 'switched off.')];
    }
}

$rows     = db()->query('SELECT * FROM purchase_feed ORDER BY happened_at DESC LIMIT 200')->fetchAll();
$products = db()->query("SELECT id, name FROM products WHERE status = 'published' ORDER BY name")->fetchAll();
?>

<div class="dash-head">
  <div><h1>Live ticker</h1><p>Real purchases and deliveries, shown one at a time in the corner of the shop ("Thandi M. from Soweto just bought …"). Entries from the last 30 days show. Marking an enquiry <b>Sold</b> adds one here for you.</p></div>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= $flash[0] ?>"><i class="fa-solid fa-circle-<?= $flash[0] === 'ok' ? 'check' : 'exclamation' ?>"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card-fc">
      <table class="table-fc">
        <thead><tr><th>Customer</th><th>What happened</th><th>When</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="4" class="text-muted-2">Nothing yet. Add your first real sale on the right.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr style="<?= $r['is_live'] ? '' : 'opacity:.5' ?>">
            <td><b><?= e($r['customer']) ?></b><?= $r['town'] ? '<br><span class="text-muted-3" style="font-size:.82rem">' . e($r['town']) . '</span>' : '' ?></td>
            <td><?= $r['kind'] === 'received' ? 'Received' : 'Bought' ?> <?= e($r['product_name']) ?></td>
            <td style="white-space:nowrap"><?= e(time_ago((string) $r['happened_at'])) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn-fc btn-fc--sm btn-fc--light" name="action" value="toggle"><?= $r['is_live'] ? 'Hide' : 'Show' ?></button>
                <button class="btn-fc btn-fc--sm btn-fc--light" style="color:var(--coral)" name="action" value="delete" aria-label="Delete" onclick="return confirm('Delete this entry?')"><i class="fa-solid fa-trash-can"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-lg-4">
    <form method="post" class="card-fc card-fc--pad mb-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <h2 style="font-size:1.05rem">Add a purchase</h2>
      <div class="field"><label for="customer">Customer name</label>
        <input class="input-fc" id="customer" name="customer" required placeholder="Thandi Mokoena">
        <div class="field__hint">Only the first name and surname initial are shown (Thandi M.).</div></div>
      <div class="field"><label for="town">Town <span class="text-muted-3 fw-normal">(optional)</span></label>
        <input class="input-fc" id="town" name="town" placeholder="Soweto"></div>
      <div class="field"><label for="product_id">Product</label>
        <select class="select-fc" id="product_id" name="product_id" required><option value="">Choose…</option>
          <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="row g-2">
        <div class="col-6 field"><label for="kind">Type</label>
          <select class="select-fc" id="kind" name="kind"><option value="bought">Bought</option><option value="received">Received their order</option></select></div>
        <div class="col-6 field"><label for="happened_at">When</label>
          <input class="input-fc" type="datetime-local" id="happened_at" name="happened_at" value="<?= date('Y-m-d\TH:i') ?>"></div>
      </div>
      <button class="btn-fc btn-fc--block"><i class="fa-solid fa-plus"></i> Add to ticker</button>
    </form>
    <form method="post" class="card-fc card-fc--pad">
      <?= csrf_field() ?><input type="hidden" name="action" value="settings">
      <label style="font-size:.92rem"><input type="checkbox" name="ticker_enabled" value="1" <?= get_setting('ticker_enabled', '1') === '1' ? 'checked' : '' ?>> Show the ticker on the site</label>
      <button class="btn-fc btn-fc--sm btn-fc--light mt-2">Save</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
