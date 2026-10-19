<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'products';
$page_title  = 'Products — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$repo  = new ProductRepository();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $id     = (int) ($_POST['product_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete' && $id) {
        $repo->delete($id);
        $flash = ['warn', 'Product deleted, along with its options and gallery.'];
    } elseif ($action === 'toggle' && $id) {
        $p = $repo->getById($id);
        $new = $p['status'] === 'published' ? 'draft' : 'published';
        $repo->update($id, ['status' => $new]);
        $flash = ['ok', $p['name'] . ' is now ' . $new . '.'];
    } elseif ($action === 'bulk_margin') {
        // Re-derives every reseller price from retail at one percentage.
        // The margin panels, price list and calculator all read this column,
        // so this is the single lever for repricing the whole catalogue.
        $off = max(0, min(90, (int) ($_POST['off'] ?? 45))) / 100;
        db()->prepare("UPDATE products SET reseller_price = ROUND(retail_price * (1 - :off), 0)")->execute([':off' => $off]);
        $flash = ['ok', 'Wholesale prices reset to ' . (int) ($off * 100) . '% off retail across the catalogue.'];
    }
}

$filters = [
    'search'   => trim((string) ($_GET['q'] ?? '')),
    'category' => (string) ($_GET['category'] ?? ''),
    'brand'    => (string) ($_GET['brand'] ?? ''),
    'status'   => (string) ($_GET['status'] ?? 'all'),
    'sort'     => (string) ($_GET['sort'] ?? 'name'),
];
$page   = max(1, (int) ($_GET['page'] ?? 1));
$result = $repo->getAll($filters, $page, 30);
?>

<div class="dash-head">
  <div>
    <h1>Products</h1>
    <p><?= (int) $result['total'] ?> product<?= $result['total'] === 1 ? '' : 's' ?>. Retail is what shoppers pay; wholesale is what approved resellers pay.</p>
  </div>
  <a href="product-form.php" class="btn-fc btn-fc--sm"><i class="fa-solid fa-plus"></i> New product</a>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= $flash[0] ?>"><i class="fa-solid fa-circle-info"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>

<div class="card-fc card-fc--pad mb-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-lg-4 field mb-0">
      <label for="q">Search</label>
      <input class="input-fc" type="search" id="q" name="q" value="<?= e($filters['search']) ?>" placeholder="Name, tag or SKU">
    </div>
    <div class="col-lg-3 field mb-0">
      <label for="category">Category</label>
      <select class="select-fc" id="category" name="category">
        <option value="">All categories</option>
        <?php foreach (get_categories() as $c): ?>
          <option value="<?= e($c['slug']) ?>" <?= $filters['category'] === $c['slug'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-lg-3 field mb-0">
      <label for="brand">Brand</label>
      <select class="select-fc" id="brand" name="brand">
        <option value="">All brands</option>
        <?php foreach (get_brands() as $b): ?>
          <option value="<?= e($b['slug']) ?>" <?= $filters['brand'] === $b['slug'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-lg-2">
      <button class="btn-fc btn-fc--block" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
    </div>
  </form>
</div>

<div class="table-wrap">
  <table class="table-fc">
    <thead>
      <tr>
        <th style="width:32%">Product</th><th>Brand</th>
        <th class="text-end">Retail</th><th class="text-end">Wholesale</th><th class="text-end">RRP</th><th class="text-end">Margin</th>
        <th class="text-center">Stock</th><th>Status</th><th style="width:1%"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($result['items'] as $p):
      $m = Pricing::margin($p, null); ?>
      <tr>
        <td>
          <div class="d-flex gap-2 align-items-center">
            <img src="<?= e(product_image($p['featured_image'])) ?>" alt="" width="40" height="40"
                 style="border-radius:6px;object-fit:cover;background:var(--paper-2);flex-shrink:0">
            <span style="min-width:0">
              <a href="product-form.php?id=<?= (int) $p['id'] ?>" style="font-weight:700;font-size:.87rem"><?= e($p['name']) ?></a>
              <span class="d-block text-muted-3" style="font-size:.73rem">
                <?= e((string) $p['category_name']) ?><?= $p['pack_size'] ? ' · ' . e($p['pack_size']) : '' ?>
              </span>
            </span>
          </div>
        </td>
        <td style="font-size:.83rem"><?= e((string) $p['brand_name']) ?></td>
        <td class="text-end money"><?= format_price_short((float) $p['retail_price']) ?></td>
        <td class="text-end money" style="color:var(--leaf-dark)"><?= format_price_short($m['cost']) ?></td>
        <td class="text-end money"><?= format_price_short($m['rrp']) ?></td>
        <td class="text-end">
          <span class="pill <?= $m['margin_pct'] >= 40 ? 'pill--ok' : ($m['margin_pct'] >= 25 ? 'pill--warn' : 'pill--danger') ?>">
            <?= $m['margin_pct'] ?>%
          </span>
        </td>
        <td class="text-center">
          <?php if ($p['stock_status'] === 'out_of_stock'): ?><span class="pill pill--danger">Out</span>
          <?php elseif ($p['stock_status'] === 'low_stock'): ?><span class="pill pill--warn"><?= (int) $p['stock_qty'] ?></span>
          <?php else: ?><span class="text-muted-2"><?= (int) $p['stock_qty'] ?></span><?php endif; ?>
        </td>
        <td>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="action" value="toggle">
            <button class="pill <?= $p['status'] === 'published' ? 'pill--ok' : 'pill--muted' ?>" style="border:0;cursor:pointer" type="submit"
                    title="Click to <?= $p['status'] === 'published' ? 'unpublish' : 'publish' ?>">
              <?= e(ucfirst($p['status'])) ?>
            </button>
          </form>
        </td>
        <td>
          <div class="d-flex gap-1">
            <a class="btn-fc btn-fc--sm btn-fc--light" href="<?= SITE_URL ?>/modules/shop/product.php?slug=<?= e($p['slug']) ?>" target="_blank" title="View"><i class="fa-solid fa-eye"></i></a>
            <a class="btn-fc btn-fc--sm btn-fc--light" href="product-form.php?id=<?= (int) $p['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
            <form method="post" onsubmit="return confirm('Delete “<?= e($p['name']) ?>”? Its options and gallery go with it. This cannot be undone.')">
              <?= csrf_field() ?>
              <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn-fc btn-fc--sm btn-fc--light" style="color:var(--coral)" type="submit" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= render_pager($result['page'], $result['pages']) ?>

<div class="card-fc card-fc--pad mt-3">
  <h2 style="font-size:1.05rem">Reprice the whole catalogue</h2>
  <p class="text-muted-2" style="font-size:.86rem">
    Sets every product's wholesale price to a single percentage off its retail price.
    Individual products can still be overridden afterwards on their own form.
  </p>
  <form method="post" class="d-flex gap-2 align-items-end flex-wrap"
        onsubmit="return confirm('This overwrites the wholesale price on EVERY product. Continue?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_margin">
    <div class="field mb-0" style="max-width:160px">
      <label for="off">Discount off retail</label>
      <div class="d-flex align-items-center gap-2">
        <input class="input-fc" type="number" id="off" name="off" value="45" min="5" max="90" step="1">
        <span class="text-muted-2">%</span>
      </div>
    </div>
    <button class="btn-fc btn-fc--ghost" type="submit"><i class="fa-solid fa-arrows-rotate"></i> Apply to all products</button>
  </form>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
