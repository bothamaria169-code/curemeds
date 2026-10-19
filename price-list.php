<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

/**
 * The price list, grouped by brand the way the supplier sheets are — and built
 * to be printed and carried. A reseller working a stall needs paper, not a
 * responsive grid, so the print stylesheet strips the site furniture and the
 * page falls back to a clean black-on-white table.
 */
$viewer = current_user();
$isRes  = Pricing::isReseller($viewer);
$repo   = new ProductRepository();

$brandFilter = trim((string) ($_GET['brand'] ?? ''));
$brands      = get_brands();
$all         = $repo->getAll(['status' => 'published', 'brand' => $brandFilter ?: null, 'sort' => 'name'], 1, 300)['items'];

// Group by brand, then by category, mirroring how the printed sheets read.
$grouped = [];
foreach ($all as $p) {
    $grouped[$p['brand_name'] ?: 'Other'][$p['category_name'] ?: 'General'][] = $p;
}

$totalCost = 0.0;
$totalRrp  = 0.0;
foreach ($all as $p) {
    $m = Pricing::margin($p, $viewer);
    $totalCost += $m['cost'];
    $totalRrp  += $m['rrp'];
}

$page_title       = 'Price list — ' . SITE_NAME;
$meta_description = 'The full CureMeds price list across all four brands. Retail prices for everyone; wholesale cost, recommended selling price and margin for approved resellers.';
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs no-print"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Price list</span></nav>
    <div class="d-flex justify-content-between align-items-end gap-3 flex-wrap">
      <div>
        <h1>Price list</h1>
        <p>
          <?php if ($isRes): ?>
            Your <?= e(strtolower(RESELLER_TIERS[$viewer['reseller_tier']]['label'])) ?> wholesale cost, the recommended
            selling price and your margin on all <?= count($all) ?> lines. Printed <?= date('j F Y') ?>.
          <?php else: ?>
            Every price on one page — all <?= count($all) ?> products, updated live from the catalogue.
            Print it, save it as a PDF, or tap any product to go straight to it.
          <?php endif; ?>
        </p>
      </div>
      <div class="d-flex gap-2 no-print">
        <button class="btn-fc btn-fc--amber" type="button" onclick="window.print()">
          <i class="fa-solid fa-print"></i> Print / save as PDF
        </button>
      </div>
    </div>

    <div class="print-only" style="margin-top:1rem;font-size:11pt">
      <b>CureMeds</b> · <?= e(SITE_TAGLINE) ?> · <?= e((string) get_setting('contact_phone', '')) ?> ·
      <?= e(SITE_DOMAIN) ?><?= $isRes ? ' · Reseller ' . e((string) $viewer['reseller_code']) . ' (' . e(RESELLER_TIERS[$viewer['reseller_tier']]['label']) . ')' : '' ?>
    </div>
  </div>
</header>

<div class="section section--tight">
  <div class="container">

    <?php if (!$isRes): ?>
      <div class="alert-fc alert-fc--info no-print">
        <i class="fa-solid fa-circle-info"></i>
        <span>
          <b>Prices are live.</b> What you see here is what you pay at checkout — nothing changes on the last step.
          Free courier delivery on orders over <?= format_price_short((float) get_setting('free_shipping_threshold', '1500')) ?>.
          <a href="<?= SITE_URL ?>/shop.php" class="fw-bold">Start shopping</a>.
        </span>
      </div>
    <?php else: ?>
      <div class="alert-fc alert-fc--ok no-print">
        <i class="fa-solid fa-circle-check"></i>
        <span>
          Buying one of everything costs you <b><?= format_price($totalCost) ?></b> and is worth
          <b><?= format_price($totalRrp) ?></b> at the recommended prices —
          <b style="color:var(--leaf)">+<?= format_price($totalRrp - $totalCost) ?></b> across the range.
        </span>
      </div>
    <?php endif; ?>

    <div class="d-flex gap-2 flex-wrap mb-4 no-print">
      <a class="chip <?= $brandFilter === '' ? 'is-on' : '' ?>" href="<?= SITE_URL ?>/price-list.php">All brands</a>
      <?php foreach ($brands as $b): ?>
        <a class="chip <?= $brandFilter === $b['slug'] ? 'is-on' : '' ?>" href="?brand=<?= e($b['slug']) ?>">
          <span style="width:8px;height:8px;border-radius:50%;background:<?= e($b['accent']) ?>"></span><?= e($b['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php foreach ($grouped as $brandName => $categories):
      $brandRow = array_values(array_filter($brands, fn($b) => $b['name'] === $brandName))[0] ?? null; ?>

      <section class="mb-4" style="break-inside:auto">
        <div class="pricelist-brand-head">
          <span style="width:12px;height:12px;border-radius:50%;background:<?= e($brandRow['accent'] ?? '#1557D0') ?>"></span>
          <span>
            <b><?= e((string) $brandName) ?></b>
            <?php if ($brandRow): ?><span> · <?= e((string) $brandRow['tagline']) ?></span><?php endif; ?>
          </span>
        </div>

        <div class="table-wrap" style="border-radius:0 0 var(--r-lg) var(--r-lg);border-top:0">
          <table class="table-fc">
            <thead>
              <tr>
                <th style="width:38%">Product</th>
                <th>Size</th>
                <th class="text-end">Retail</th>
                <?php if ($isRes): ?>
                  <th class="text-end">Your cost</th>
                  <th class="text-end">Sell at</th>
                  <th class="text-end">Profit</th>
                  <th class="text-end">Margin</th>
                  <th class="text-center no-print">Min</th>
                <?php else: ?>
                  <th class="text-center">Stock</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($categories as $categoryName => $products): ?>
              <tr>
                <td colspan="<?= $isRes ? 8 : 4 ?>" style="background:var(--paper);padding:.5rem 1rem">
                  <span class="eyebrow" style="margin:0"><?= e((string) $categoryName) ?></span>
                </td>
              </tr>
              <?php foreach ($products as $p):
                $m = Pricing::margin($p, $viewer); ?>
              <tr>
                <td>
                  <a href="<?= SITE_URL ?>/modules/shop/product.php?slug=<?= e($p['slug']) ?>" style="font-weight:700">
                    <?= e($p['name']) ?>
                  </a>
                  <?php if ($p['sku']): ?>
                    <span class="text-muted-3 d-block" style="font-size:.72rem;font-family:var(--font-mono)"><?= e($p['sku']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-muted-2"><?= e((string) $p['pack_size']) ?></td>
                <td class="text-end money"><?= format_price_short((float) $p['retail_price']) ?></td>

                <?php if ($isRes): ?>
                  <td class="text-end money" style="color:var(--leaf-dark)"><?= format_price_short($m['cost']) ?></td>
                  <td class="text-end money"><?= format_price_short($m['rrp']) ?></td>
                  <td class="text-end money" style="color:var(--leaf);font-weight:800">+<?= format_price_short($m['profit']) ?></td>
                  <td class="text-end"><?= $m['margin_pct'] ?>%</td>
                  <td class="text-center no-print"><?= (int) $p['min_reseller_qty'] ?></td>
                <?php else: ?>
                  <td class="text-center">
                    <?php if ($p['stock_status'] === 'out_of_stock'): ?>
                      <span class="pill pill--danger">Out</span>
                    <?php elseif ($p['stock_status'] === 'low_stock'): ?>
                      <span class="pill pill--warn">Low</span>
                    <?php else: ?>
                      <span class="pill pill--ok">In</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>

    <div class="card-fc card-fc--pad" style="font-size:.82rem;color:var(--text-2)">
      <b>Notes.</b>
      Prices include VAT where applicable and are valid while stock lasts. Recommended selling prices are exactly
      that — a recommendation. You set your own prices.
      Supplements listed here are Category D complementary medicines; they have not been evaluated by SAHPRA for
      quality, safety or intended use, and must not be sold with claims that they treat or cure any condition.
      <span class="no-print">Generated <?= date('j F Y \a\t H:i') ?> from the live catalogue.</span>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
