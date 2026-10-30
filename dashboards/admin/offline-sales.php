<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'offline-sales';
$page_title  = 'Offline sales — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    switch ((string) ($_POST['action'] ?? '')) {
        case 'sale':
            $lines = [];
            foreach ((array) ($_POST['product_id'] ?? []) as $i => $pid) {
                $lines[] = ['product_id' => (int) $pid, 'qty' => (int) ($_POST['qty'][$i] ?? 0), 'price' => (string) ($_POST['price'][$i] ?? '')];
            }
            [$id, $errors] = OfflineSales::record($_POST, $lines, (int) $user['id']);
            if ($id) {
                flash('success', 'Sale S' . $id . ' recorded and stock updated.');
                redirect('dashboards/admin/offline-sales.php?' . http_build_query(array_filter(['from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null])));
            }
            $old = $_POST;
            break;

        case 'delete':
            OfflineSales::delete((int) ($_POST['id'] ?? 0))
                ? flash('success', 'Sale deleted and its stock put back.')
                : flash('danger', 'That sale could not be deleted.');
            back();

        case 'stock':
            $pid = (int) ($_POST['id'] ?? 0);
            $qty = max(0, (int) ($_POST['stock_qty'] ?? 0));
            $status = $qty === 0 ? 'out_of_stock' : ($qty <= 5 ? 'low_stock' : 'in_stock');
            db_update('products', $pid, ['stock_qty' => $qty, 'stock_status' => $status]);
            flash('success', 'Stock updated.');
            back('products');
    }
}

// The period shown (default: this month).
$presets = [
    'today' => [date('Y-m-d'), date('Y-m-d'), 'Today'],
    '7d'    => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d'), 'Last 7 days'],
    'month' => [date('Y-m-01'), date('Y-m-d'), 'This month'],
    '90d'   => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d'), 'Last 90 days'],
];
$range = (string) ($_GET['range'] ?? (isset($_GET['from']) ? '' : 'month'));
[$from, $to] = isset($presets[$range]) ? $presets[$range] : [
    preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01'),
    preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d'),
];
$q        = trim((string) ($_GET['q'] ?? ''));
$tab      = ($_GET['tab'] ?? '') === 'products' ? 'products' : 'sales';
$sales    = OfflineSales::list($from, $to, $q);
$totals   = OfflineSales::totals($from, $to);
$today    = OfflineSales::totals(date('Y-m-d'), date('Y-m-d'));
$products = OfflineSales::byProduct($from, $to);
$catalogue = db()->query("SELECT id, name, pack_size, retail_price, sale_price, is_sale, stock_qty FROM products WHERE status = 'published' ORDER BY name")->fetchAll();
$prefill  = (int) ($_GET['sell'] ?? 0);
$qs       = fn(array $extra) => '?' . http_build_query(array_filter(['range' => $range ?: null, 'from' => $range ? null : $from, 'to' => $range ? null : $to, 'q' => $q ?: null] + $extra));
?>

<div class="dash-head">
  <div><h1>Offline sales</h1><p>Sales made outside the website — over the counter, at events, or agreed on WhatsApp and paid in cash or by card. Recording one counts the stock down.</p></div>
  <a href="#new" class="btn-fc btn-fc--sm"><i class="fa-solid fa-plus"></i> Record a sale</a>
</div>

<?= flash_html() ?>
<?php foreach ($errors as $err): ?>
  <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div>
<?php endforeach; ?>

<div class="stat-grid">
  <div class="stat"><span class="stat__icon stat__icon--leaf"><i class="fa-solid fa-cash-register"></i></span>
    <span><b><?= format_price_short($today['total']) ?></b><span>Sold offline today</span><div class="delta"><?= $today['count'] ?> sale<?= $today['count'] === 1 ? '' : 's' ?></div></span></div>
  <div class="stat"><span class="stat__icon stat__icon--ink"><i class="fa-solid fa-calendar"></i></span>
    <span><b><?= format_price_short($totals['total']) ?></b><span><?= e($presets[$range][2] ?? (date('j M', strtotime($from)) . ' – ' . date('j M', strtotime($to)))) ?></span>
      <div class="delta"><?= $totals['count'] ?> sales · <?= $totals['units'] ?> units</div></span></div>
  <?php foreach (['cash' => 'fa-money-bill-wave', 'card' => 'fa-credit-card'] as $pm => $icon): ?>
  <div class="stat"><span class="stat__icon stat__icon--amber"><i class="fa-solid <?= $icon ?>"></i></span>
    <span><b><?= format_price_short($totals['by_payment'][$pm] ?? 0) ?></b><span><?= OfflineSales::PAYMENT[$pm] ?> in period</span></span></div>
  <?php endforeach; ?>
</div>

<!-- Period and search -->
<form class="card-fc card-fc--pad mb-3 d-flex flex-wrap gap-2 align-items-end" method="get">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <?php foreach ($presets as $k => $p): ?>
    <a href="?tab=<?= $tab ?>&range=<?= $k ?>" class="chip <?= $range === $k ? 'is-on' : '' ?>"><?= $p[2] ?></a>
  <?php endforeach; ?>
  <div class="field mb-0"><label style="font-size:.78rem">From</label><input class="input-fc" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="field mb-0"><label style="font-size:.78rem">To</label><input class="input-fc" type="date" name="to" value="<?= e($to) ?>"></div>
  <?php if ($tab === 'sales'): ?>
    <div class="field mb-0" style="flex:1;min-width:200px"><label style="font-size:.78rem">Search</label><input class="input-fc" name="q" value="<?= e($q) ?>" placeholder="Customer, number or product"></div>
  <?php endif; ?>
  <button class="btn-fc btn-fc--ghost btn-fc--sm">Show</button>
</form>

<div class="d-flex gap-2 mb-3">
  <a href="<?= e($qs(['tab' => null])) ?>" class="chip <?= $tab === 'sales' ? 'is-on' : '' ?>"><i class="fa-solid fa-receipt me-1"></i> Sales</a>
  <a href="<?= e($qs(['tab' => 'products'])) ?>#products" class="chip <?= $tab === 'products' ? 'is-on' : '' ?>"><i class="fa-solid fa-boxes-stacked me-1"></i> All products</a>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <?php if ($tab === 'sales'): ?>
    <div class="card-fc">
      <?php if (!$sales): ?>
        <div class="empty"><div class="empty__icon"><i class="fa-solid fa-cash-register"></i></div><h3>No offline sales in this period</h3><p>Record one with the form.</p></div>
      <?php else: ?>
      <div style="overflow:auto"><table class="table-fc">
        <thead><tr><th>Sale</th><th>Customer</th><th>Products</th><th>Paid</th><th class="text-end">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sales as $s): ?>
          <tr>
            <td style="white-space:nowrap"><b>S<?= (int) $s['id'] ?></b><div class="text-muted-3" style="font-size:.75rem"><?= date('j M Y', strtotime($s['sold_on'])) ?></div></td>
            <td><?= $s['user_id'] ? '<a href="patient.php?id=' . (int) $s['user_id'] . '" class="fw-bold">' . e($s['customer_name'] ?: 'Patient') . '</a>' : e($s['customer_name'] ?: '—') ?>
              <div class="text-muted-3" style="font-size:.75rem"><?= e(Health::displayPhone($s['customer_phone'])) ?></div></td>
            <td style="font-size:.84rem;max-width:300px"><?= e(implode(', ', array_map(fn($i) => $i['qty'] . '× ' . $i['name'], $s['items']))) ?>
              <?php if ($s['notes']): ?><div class="text-muted-3" style="font-size:.75rem"><?= e($s['notes']) ?></div><?php endif; ?></td>
            <td style="font-size:.84rem"><?= e(OfflineSales::PAYMENT[$s['payment_method']] ?? $s['payment_method']) ?>
              <div class="text-muted-3" style="font-size:.75rem"><?= e(OfflineSales::CHANNELS[$s['channel']] ?? $s['channel']) ?></div></td>
            <td class="text-end money"><b><?= format_price_short((float) $s['total']) ?></b>
              <?php if ((float) $s['discount'] > 0): ?><div class="text-muted-3" style="font-size:.72rem">−<?= format_price_short((float) $s['discount']) ?> off</div><?php endif; ?></td>
            <td><form method="post" onsubmit="return confirm('Delete sale S<?= (int) $s['id'] ?>? Its stock goes back.')"><?= csrf_field() ?>
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn-fc btn-fc--sm" style="background:none;color:var(--text-3)" aria-label="Delete sale"><i class="fa-solid fa-trash-can"></i></button></form></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="card-fc" id="products">
      <div style="overflow:auto"><table class="table-fc">
        <thead><tr><th>Product</th><th>Price</th><th class="text-end">Sold offline</th><th class="text-end">Revenue</th><th>Stock</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
          <tr>
            <td><div class="d-flex gap-2 align-items-center">
              <img src="<?= e(product_image($p['featured_image'])) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:8px">
              <span><a href="product-form.php?id=<?= (int) $p['id'] ?>" class="fw-bold" style="font-size:.88rem"><?= e($p['name']) ?></a>
                <span class="d-block text-muted-3" style="font-size:.74rem"><?= e((string) $p['pack_size']) ?><?= $p['status'] !== 'published' ? ' · ' . e($p['status']) : '' ?><?= $p['last_sold'] ? ' · last sold ' . date('j M', strtotime($p['last_sold'])) : '' ?></span></span></div></td>
            <td class="money" style="font-size:.88rem"><?= format_price_short(Pricing::retail($p)) ?></td>
            <td class="text-end"><b><?= (int) $p['units'] ?></b></td>
            <td class="text-end money"><?= format_price_short((float) $p['revenue']) ?></td>
            <td><form method="post" class="d-flex gap-1 align-items-center"><?= csrf_field() ?>
              <input type="hidden" name="action" value="stock"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <input class="input-fc" type="number" min="0" name="stock_qty" value="<?= (int) $p['stock_qty'] ?>" style="width:80px;padding:.35rem .5rem" aria-label="Stock for <?= e($p['name']) ?>">
              <button class="btn-fc btn-fc--ghost btn-fc--sm" title="Save stock" aria-label="Save stock"><i class="fa-solid fa-check"></i></button></form>
              <span class="pill pill--<?= $p['stock_status'] === 'out_of_stock' ? 'danger' : ($p['stock_status'] === 'low_stock' ? 'warn' : 'ok') ?> mt-1" style="font-size:.7rem"><?= e(str_replace('_', ' ', $p['stock_status'])) ?></span></td>
            <td><a href="<?= e($qs(['tab' => 'products', 'sell' => (int) $p['id']])) ?>#new" class="btn-fc btn-fc--sm"><i class="fa-solid fa-plus"></i> Sell</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Record a sale -->
  <div class="col-xl-5">
    <form method="post" class="card-fc card-fc--pad" id="new" style="position:sticky;top:1rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sale">
      <h2 style="font-size:1.1rem">Record a sale</h2>

      <div id="saleLines" class="d-grid gap-2">
        <?php
        $lineRows = $old ? array_map(null, (array) ($old['product_id'] ?? []), (array) ($old['qty'] ?? []), (array) ($old['price'] ?? []))
                         : [[$prefill ?: '', 1, '']];
        foreach ($lineRows as [$pid, $qty, $price]): ?>
        <div class="sale-line d-flex gap-2 align-items-end">
          <div class="field mb-0" style="flex:1;min-width:0"><label style="font-size:.78rem">Product</label>
            <select class="input-fc" name="product_id[]" data-product>
              <option value="">Choose…</option>
              <?php foreach ($catalogue as $c): ?>
                <option value="<?= (int) $c['id'] ?>" data-price="<?= number_format(Pricing::retail($c), 2, '.', '') ?>" data-stock="<?= (int) $c['stock_qty'] ?>" <?= (int) $pid === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name'] . ($c['pack_size'] ? ' (' . $c['pack_size'] . ')' : '')) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field mb-0" style="width:70px"><label style="font-size:.78rem">Qty</label>
            <input class="input-fc" type="number" min="1" name="qty[]" value="<?= (int) ($qty ?: 1) ?>" data-qty></div>
          <div class="field mb-0" style="width:100px"><label style="font-size:.78rem">Each (R)</label>
            <input class="input-fc" type="number" min="0" step="0.01" name="price[]" value="<?= e((string) $price) ?>" data-price-input></div>
          <button type="button" class="btn-fc btn-fc--ghost btn-fc--sm mb-1" data-remove-line aria-label="Remove line"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn-fc btn-fc--light btn-fc--sm mt-2" id="addLine"><i class="fa-solid fa-plus"></i> Another product</button>

      <div class="row g-2 mt-2">
        <div class="col-6 field"><label>Customer name</label><input class="input-fc" name="customer_name" value="<?= e((string) ($old['customer_name'] ?? '')) ?>" placeholder="Optional"></div>
        <div class="col-6 field"><label>Cellphone</label><input class="input-fc" type="tel" name="customer_phone" value="<?= e((string) ($old['customer_phone'] ?? '')) ?>" placeholder="Optional"></div>
        <div class="col-6 field"><label>Paid by</label>
          <select class="input-fc" name="payment_method"><?php foreach (OfflineSales::PAYMENT as $k => $l): ?><option value="<?= $k ?>" <?= ($old['payment_method'] ?? 'cash') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-6 field"><label>Where</label>
          <select class="input-fc" name="channel"><?php foreach (OfflineSales::CHANNELS as $k => $l): ?><option value="<?= $k ?>" <?= ($old['channel'] ?? 'counter') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-6 field"><label>Date</label><input class="input-fc" type="date" name="sold_on" max="<?= date('Y-m-d') ?>" value="<?= e((string) ($old['sold_on'] ?? date('Y-m-d'))) ?>"></div>
        <div class="col-6 field"><label>Discount (R)</label><input class="input-fc" type="number" min="0" step="0.01" name="discount" value="<?= e((string) ($old['discount'] ?? '')) ?>" placeholder="0" data-discount></div>
        <div class="col-12 field"><label>Notes</label><input class="input-fc" name="notes" value="<?= e((string) ($old['notes'] ?? '')) ?>" placeholder="Receipt number, who served, …"></div>
      </div>
      <label class="d-flex gap-2 align-items-start mb-3" style="font-size:.86rem">
        <input type="checkbox" name="add_medication" value="1" <?= !$old || !empty($old['add_medication']) ? 'checked' : '' ?> style="margin-top:.2rem">
        <span>If the cellphone belongs to a patient, add these products to their medication and start reminders (refills top up the count).</span>
      </label>

      <div class="d-flex justify-content-between align-items-baseline mb-3" style="border-top:1px solid var(--line);padding-top:.8rem">
        <span class="text-muted-2">Total</span><b class="money" style="font-size:1.5rem" id="saleTotal">R0</b>
      </div>
      <button class="btn-fc btn-fc--block btn-fc--lg" type="submit"><i class="fa-solid fa-check"></i> Record sale</button>
    </form>
  </div>
</div>

<script>
(() => {
  const lines = document.getElementById('saleLines');
  const totalEl = document.getElementById('saleTotal');
  const discount = document.querySelector('[data-discount]');
  const rand = (n) => 'R' + n.toLocaleString('en-ZA', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
  const recalc = () => {
    let sum = 0;
    lines.querySelectorAll('.sale-line').forEach((row) => {
      const opt = row.querySelector('[data-product]').selectedOptions[0];
      const priceIn = row.querySelector('[data-price-input]');
      const price = priceIn.value !== '' ? parseFloat(priceIn.value) : parseFloat(opt?.dataset.price || 0);
      priceIn.placeholder = opt?.dataset.price || '';
      sum += (parseInt(row.querySelector('[data-qty]').value, 10) || 0) * (price || 0);
    });
    totalEl.textContent = rand(Math.max(0, sum - (parseFloat(discount.value) || 0)));
  };
  document.getElementById('addLine').addEventListener('click', () => {
    const row = lines.lastElementChild.cloneNode(true);
    row.querySelector('[data-product]').value = '';
    row.querySelector('[data-qty]').value = 1;
    row.querySelector('[data-price-input]').value = '';
    lines.appendChild(row);
    recalc();
  });
  lines.addEventListener('click', (e) => {
    const b = e.target.closest('[data-remove-line]');
    if (b && lines.children.length > 1) { b.closest('.sale-line').remove(); recalc(); }
  });
  lines.addEventListener('input', recalc);
  lines.addEventListener('change', recalc);
  discount.addEventListener('input', recalc);
  recalc();
})();
</script>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
