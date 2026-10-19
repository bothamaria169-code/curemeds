<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

/**
 * Comparison is held in the visitor's own browser (localStorage), so the ids
 * arrive as a query parameter written by assets/js/compare-page.js rather than
 * from a session. Nothing about a comparison is worth storing server-side.
 */
$ids = array_slice(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))), 0, 4);

$viewer = current_user();
$isRes  = Pricing::isReseller($viewer);
$items  = $ids ? (new ProductRepository())->getAll(['ids' => $ids], 1, 4)['items'] : [];

$page_title       = 'Compare products — ' . SITE_NAME;
$meta_description = 'Put up to four CureMeds products side by side on price, pack size, stock and margin.';
$extra_js         = [SITE_URL . '/assets/js/compare-page.js'];
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Compare</span></nav>
    <h1>Side by side</h1>
    <p>Up to four products at a time<?= $isRes ? ' — including what each one costs you and what you keep on it.' : '.' ?></p>
  </div>
</header>

<div class="section section--tight">
  <div class="container">

    <div id="compareEmpty" class="empty card-fc" <?= $items ? 'hidden' : '' ?>>
      <div class="empty__icon"><i class="fa-solid fa-code-compare"></i></div>
      <h3>Nothing to compare yet</h3>
      <p class="mb-3">Hover any product card and tap the compare icon. Up to four at a time.</p>
      <a href="<?= SITE_URL ?>/shop.php" class="btn-fc">Browse the catalogue</a>
    </div>

    <?php if ($items): ?>
    <div class="table-wrap">
      <table class="table-fc" style="min-width:640px">
        <thead>
          <tr>
            <th style="width:150px">&nbsp;</th>
            <?php foreach ($items as $p): ?>
              <th style="min-width:190px;vertical-align:top;padding-top:1rem">
                <a href="<?= SITE_URL ?>/modules/shop/product.php?slug=<?= e($p['slug']) ?>">
                  <img src="<?= e(product_image($p['featured_image'])) ?>" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:var(--r);background:var(--paper-2);margin-bottom:.5rem">
                  <span style="display:block;font-family:var(--font-display);font-size:.95rem;color:var(--ink);text-transform:none;letter-spacing:0"><?= e($p['name']) ?></span>
                </a>
                <button class="btn-fc btn-fc--sm btn-fc--light mt-2" type="button" data-remove="<?= (int) $p['id'] ?>">
                  <i class="fa-solid fa-xmark"></i> Remove
                </button>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><b>Brand</b></td>
            <?php foreach ($items as $p): ?><td><?= e((string) $p['brand_name']) ?></td><?php endforeach; ?>
          </tr>
          <tr>
            <td><b>Category</b></td>
            <?php foreach ($items as $p): ?><td><?= e((string) $p['category_name']) ?></td><?php endforeach; ?>
          </tr>
          <tr>
            <td><b>Pack size</b></td>
            <?php foreach ($items as $p): ?><td><?= e((string) $p['pack_size']) ?: '—' ?></td><?php endforeach; ?>
          </tr>
          <tr>
            <td><b>Retail price</b></td>
            <?php foreach ($items as $p): ?><td class="money"><?= format_price_short((float) $p['retail_price']) ?></td><?php endforeach; ?>
          </tr>

          <?php if ($isRes): ?>
          <tr style="background:var(--amber-wash)">
            <td><b>Your cost</b></td>
            <?php foreach ($items as $p): ?>
              <td class="money" style="color:var(--leaf-dark)"><?= format_price_short(Pricing::margin($p, $viewer)['cost']) ?></td>
            <?php endforeach; ?>
          </tr>
          <tr style="background:var(--amber-wash)">
            <td><b>Profit per unit</b></td>
            <?php foreach ($items as $p):
              $m = Pricing::margin($p, $viewer); ?>
              <td><b class="money" style="color:var(--leaf)">+<?= format_price_short($m['profit']) ?></b>
                  <span class="d-block text-muted-3" style="font-size:.74rem"><?= $m['margin_pct'] ?>% margin</span></td>
            <?php endforeach; ?>
          </tr>
          <tr>
            <td><b>Case minimum</b></td>
            <?php foreach ($items as $p): ?><td><?= (int) $p['min_reseller_qty'] ?> units</td><?php endforeach; ?>
          </tr>
          <?php endif; ?>

          <tr>
            <td><b>Stock</b></td>
            <?php foreach ($items as $p): ?>
              <td>
                <?php if ($p['stock_status'] === 'out_of_stock'): ?><span class="pill pill--danger">Out of stock</span>
                <?php elseif ($p['stock_status'] === 'low_stock'): ?><span class="pill pill--warn">Low — <?= (int) $p['stock_qty'] ?></span>
                <?php else: ?><span class="pill pill--ok">In stock</span><?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
          <tr>
            <td><b>Rating</b></td>
            <?php foreach ($items as $p):
              $s = (new ProductRepository())->reviewStats((int) $p['id']); ?>
              <td><?= $s['count'] ? stars_html($s['average']) . '<span class="d-block text-muted-3" style="font-size:.74rem">' . $s['count'] . ' review' . ($s['count'] === 1 ? '' : 's') . '</span>' : '<span class="text-muted-3">No reviews</span>' ?></td>
            <?php endforeach; ?>
          </tr>
          <tr>
            <td><b>Best for</b></td>
            <?php foreach ($items as $p):
              $b = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $p['benefits']) ?: []))); ?>
              <td style="font-size:.83rem;color:var(--text-2)">
                <?php foreach (array_slice($b, 0, 3) as $line): ?>
                  <div><i class="fa-solid fa-check me-1 text-leaf" style="font-size:.7rem"></i><?= e($line) ?></div>
                <?php endforeach; ?>
              </td>
            <?php endforeach; ?>
          </tr>
          <tr>
            <td>&nbsp;</td>
            <?php foreach ($items as $p): ?>
              <td>
                <?php if ($p['stock_status'] === 'out_of_stock'): ?>
                  <button class="btn-fc btn-fc--ghost btn-fc--sm btn-fc--block notify-btn" type="button" data-product-id="<?= (int) $p['id'] ?>">Notify me</button>
                <?php elseif ($p['type'] === 'variable'): ?>
                  <a class="btn-fc btn-fc--ghost btn-fc--sm btn-fc--block" href="<?= SITE_URL ?>/modules/shop/product.php?slug=<?= e($p['slug']) ?>">Choose option</a>
                <?php else: ?>
                  <button class="btn-fc btn-fc--sm btn-fc--block add-to-cart" type="button" data-product-id="<?= (int) $p['id'] ?>" data-qty="1">
                    <i class="fa-solid fa-basket-shopping"></i> Add
                  </button>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="d-flex gap-2 mt-3">
      <a href="<?= SITE_URL ?>/shop.php" class="btn-fc btn-fc--ghost btn-fc--sm">Add another product</a>
      <button class="btn-fc btn-fc--light btn-fc--sm" type="button" id="clearAll">Clear comparison</button>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
