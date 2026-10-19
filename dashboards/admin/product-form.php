<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'products';
require_once __DIR__ . '/../../includes/dash-head.php';

$repo    = new ProductRepository();
$id      = (int) ($_GET['id'] ?? 0);
$product = $id ? $repo->getById($id) : null;
$isNew   = !$product;

$errors = [];
$flash  = null;

/** Handles one uploaded image, returning a web path or null. */
$storeImage = function (string $field): ?string {
    if (empty($_FILES[$field]['name'])) { return null; }
    $check = validate_upload($_FILES[$field], ALLOWED_IMAGE_TYPES);
    if (!$check['ok']) { return null; }
    $dir = UPLOAD_PATH . 'products/';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $name = secure_filename($_FILES[$field]['name']);
    return move_uploaded_file($_FILES[$field]['tmp_name'], $dir . $name) ? 'uploads/products/' . $name : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please save again.';
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    if (mb_strlen($name) < 2)                  { $errors[] = 'Give the product a name.'; }
    if ((float) ($_POST['retail_price'] ?? 0) <= 0) { $errors[] = 'Set a retail price above zero.'; }

    if (!$errors) {
        $data = [
            'name'             => strip_tags($name),
            'category_id'      => (int) ($_POST['category_id'] ?? 0) ?: null,
            'brand_id'         => (int) ($_POST['brand_id'] ?? 0) ?: null,
            // Stored as typed (tags stripped); every page escapes on output.
            'short_desc'       => trim(strip_tags((string) ($_POST['short_desc'] ?? ''))),
            'description'      => trim(strip_tags((string) ($_POST['description'] ?? ''), '<p><br><b><strong><i><em><ul><ol><li><h3><h4>')),
            'usage_notes'      => trim(strip_tags((string) ($_POST['usage_notes'] ?? ''))),
            'benefits'         => trim(strip_tags((string) ($_POST['benefits'] ?? ''))),
            'type'             => ($_POST['type'] ?? 'simple') === 'variable' ? 'variable' : 'simple',
            'retail_price'     => (float) $_POST['retail_price'],
            'sale_price'       => ($_POST['sale_price'] ?? '') !== '' ? (float) $_POST['sale_price'] : null,
            'reseller_price'   => ($_POST['reseller_price'] ?? '') !== '' ? (float) $_POST['reseller_price'] : null,
            'rrp'              => ($_POST['rrp'] ?? '') !== '' ? (float) $_POST['rrp'] : null,
            'pack_size'        => sanitize((string) ($_POST['pack_size'] ?? '')) ?: null,
            'sku'              => sanitize((string) ($_POST['sku'] ?? '')) ?: null,
            'stock_qty'        => (int) ($_POST['stock_qty'] ?? 0),
            'stock_status'     => (string) ($_POST['stock_status'] ?? 'in_stock'),
            'min_reseller_qty' => max(1, (int) ($_POST['min_reseller_qty'] ?? 6)),
            'is_featured'      => !empty($_POST['is_featured']) ? 1 : 0,
            'is_bestseller'    => !empty($_POST['is_bestseller']) ? 1 : 0,
            'is_new'           => !empty($_POST['is_new']) ? 1 : 0,
            'is_sale'          => !empty($_POST['is_sale']) ? 1 : 0,
            'status'           => (string) ($_POST['status'] ?? 'draft'),
            'tags'             => sanitize((string) ($_POST['tags'] ?? '')) ?: null,
            'meta_title'       => sanitize((string) ($_POST['meta_title'] ?? '')) ?: null,
            'meta_desc'        => sanitize((string) ($_POST['meta_desc'] ?? '')) ?: null,
        ];

        if ($uploaded = $storeImage('featured_image')) {
            $data['featured_image'] = $uploaded;
        } elseif (!empty($_POST['featured_image_url'])) {
            $data['featured_image'] = trim((string) $_POST['featured_image_url']);
        }

        if ($isNew) {
            $data['slug'] = $repo->uniqueSlug($name);
            $id = $repo->create($data);
            $flash = 'Product created.';
        } else {
            if ($product['name'] !== $name) {
                $data['slug'] = $repo->uniqueSlug($name, $id);
            }
            $repo->update($id, $data);
            $flash = 'Changes saved.';
        }
        $product = $repo->getById($id);
        $isNew   = false;
    }
}

// ── Variant add/remove ───────────────────────────────────────────────────
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['variant_action']) && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    if ($_POST['variant_action'] === 'add' && !empty($_POST['option_value'])) {
        $repo->addVariant([
            'product_id'       => $id,
            'option_label'     => sanitize((string) ($_POST['option_label'] ?? 'Option')),
            'option_value'     => sanitize((string) $_POST['option_value']),
            'swatch_hex'       => sanitize((string) ($_POST['swatch_hex'] ?? '')) ?: null,
            'additional_price' => (float) ($_POST['additional_price'] ?? 0),
            'stock_qty'        => (int) ($_POST['variant_stock'] ?? 0),
            'sku'              => sanitize((string) ($_POST['variant_sku'] ?? '')) ?: null,
        ]);
        $flash = 'Option added.';
    } elseif ($_POST['variant_action'] === 'delete') {
        $repo->deleteVariant((int) $_POST['variant_id']);
        $flash = 'Option removed.';
    }
}

// ── Gallery add/remove ───────────────────────────────────────────────────
// Separate from the main save handler above: the featured image is one file
// on the product row, but a gallery is N rows in product_gallery, so it
// needs its own multi-file upload path and its own delete-by-row action.
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['gallery_action']) && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    if ($_POST['gallery_action'] === 'add' && !empty($_FILES['gallery_images']['name'][0])) {
        $dir = UPLOAD_PATH . 'products/';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

        $names     = $_FILES['gallery_images']['name'];
        $added     = 0;
        $skipped   = 0;
        $nextOrder = (int) db()->query("SELECT COALESCE(MAX(sort_order),0) FROM product_gallery WHERE product_id = " . (int) $id)->fetchColumn();

        foreach ($names as $i => $origName) {
            if ($origName === '') { continue; }
            $file = [
                'name'     => $origName,
                'type'     => $_FILES['gallery_images']['type'][$i] ?? '',
                'tmp_name' => $_FILES['gallery_images']['tmp_name'][$i] ?? '',
                'error'    => $_FILES['gallery_images']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $_FILES['gallery_images']['size'][$i] ?? 0,
            ];
            $check = validate_upload($file, ALLOWED_IMAGE_TYPES);
            if (!$check['ok']) { $skipped++; continue; }

            $filename = secure_filename($origName);
            if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                $repo->addGalleryImage($id, 'uploads/products/' . $filename, null, ++$nextOrder);
                $added++;
            } else {
                $skipped++;
            }
        }

        $flash = $added > 0
            ? "$added image(s) added to the gallery." . ($skipped ? " $skipped skipped (bad file type or too large)." : '')
            : null;
        if ($added === 0) {
            $errors[] = 'Nothing was added — check the file type and size (JPG, PNG, WebP or SVG, up to 8MB each).';
        }
    } elseif ($_POST['gallery_action'] === 'delete' && !empty($_POST['gallery_id'])) {
        $repo->deleteGalleryImage((int) $_POST['gallery_id']);
        $flash = 'Image removed from the gallery.';
    }
}

$variants = $id ? $repo->getVariants($id) : [];
$gallery  = $id ? $repo->getGallery($id) : [];
$v = fn(string $k, $default = '') => e((string) ($product[$k] ?? $default));

$page_title = ($isNew ? 'New product' : 'Edit ' . $product['name']) . ' — CureMeds admin';
$preview    = $product ? Pricing::margin($product, null) : null;
?>

<div class="dash-head">
  <div>
    <nav class="crumbs crumbs--light"><a href="products.php">Products</a><i class="fa-solid fa-chevron-right"></i><span><?= $isNew ? 'New' : e($product['name']) ?></span></nav>
    <h1><?= $isNew ? 'New product' : 'Edit product' ?></h1>
    <?php if (!$isNew): ?><p>Slug <code><?= $v('slug') ?></code></p><?php endif; ?>
  </div>
  <?php if (!$isNew): ?>
    <a href="<?= SITE_URL ?>/modules/shop/product.php?slug=<?= $v('slug') ?>" target="_blank" class="btn-fc btn-fc--ghost btn-fc--sm">
      <i class="fa-solid fa-arrow-up-right-from-square"></i> View live
    </a>
  <?php endif; ?>
</div>

<?php if ($flash): ?><div class="alert-fc alert-fc--ok"><i class="fa-solid fa-circle-check"></i><span><?= e($flash) ?></span></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="row g-3">

    <div class="col-lg-8">
      <div class="card-fc card-fc--pad mb-3">
        <h2 style="font-size:1.05rem">The basics</h2>
        <div class="field">
          <label for="name">Product name</label>
          <input class="input-fc" type="text" id="name" name="name" value="<?= $v('name') ?>" required>
        </div>
        <div class="row g-3">
          <div class="col-md-6 field">
            <label for="brand_id">Brand</label>
            <select class="select-fc" id="brand_id" name="brand_id">
              <option value="">—</option>
              <?php foreach (get_brands(false) as $b): ?>
                <option value="<?= (int) $b['id'] ?>" <?= (int) ($product['brand_id'] ?? 0) === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 field">
            <label for="category_id">Category</label>
            <select class="select-fc" id="category_id" name="category_id">
              <option value="">—</option>
              <?php foreach (get_categories(false) as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) ($product['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label for="short_desc">Short description</label>
          <textarea class="textarea-fc" id="short_desc" name="short_desc" rows="2" style="min-height:70px"
                    placeholder="One or two lines — this is what shows on cards and in search"><?= $v('short_desc') ?></textarea>
        </div>
        <div class="field">
          <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <label for="description" class="mb-0">Full description</label>
            <button type="button" class="btn-fc btn-fc--ghost btn-fc--sm" id="aiWrite" <?= Groq::ready() ? '' : 'disabled title="Add a Groq API key in AI & social media first"' ?>>
              <i class="fa-solid fa-wand-magic-sparkles"></i> Write with AI
            </button>
          </div>
          <textarea class="textarea-fc mt-2" id="description" name="description" rows="6"><?= $v('description') ?></textarea>
          <div class="field__hint" id="aiHint">AI writes the short and full description from the name, pack size, benefits and how-to-use. Check it before saving — it must not make medical claims.</div>
        </div>
        <div class="row g-3">
          <div class="col-md-6 field mb-0">
            <label for="benefits">Key benefits</label>
            <textarea class="textarea-fc" id="benefits" name="benefits" rows="5"
                      placeholder="One per line — these render as the ticked list"><?= $v('benefits') ?></textarea>
          </div>
          <div class="col-md-6 field mb-0">
            <label for="usage_notes">How to use</label>
            <textarea class="textarea-fc" id="usage_notes" name="usage_notes" rows="5"><?= $v('usage_notes') ?></textarea>
          </div>
        </div>
      </div>

      <div class="card-fc card-fc--pad mb-3">
        <h2 style="font-size:1.05rem">Pricing</h2>
        <p class="text-muted-2" style="font-size:.85rem">
          Leave <b>wholesale</b> empty to price this line automatically at the buyer's tier discount off retail.
          Leave <b>RRP</b> empty and it falls back to the retail price.
        </p>
        <div class="row g-3">
          <div class="col-md-3 field">
            <label for="retail_price">Retail price</label>
            <input class="input-fc" type="number" step="0.01" min="0" id="retail_price" name="retail_price" value="<?= $v('retail_price') ?>" required>
          </div>
          <div class="col-md-3 field">
            <label for="sale_price">Sale price</label>
            <input class="input-fc" type="number" step="0.01" min="0" id="sale_price" name="sale_price" value="<?= $v('sale_price') ?>">
            <div class="field__hint">Needs "On sale" ticked.</div>
          </div>
          <div class="col-md-3 field">
            <label for="reseller_price">Wholesale price</label>
            <input class="input-fc" type="number" step="0.01" min="0" id="reseller_price" name="reseller_price" value="<?= $v('reseller_price') ?>">
          </div>
          <div class="col-md-3 field">
            <label for="rrp">Recommended sell (RRP)</label>
            <input class="input-fc" type="number" step="0.01" min="0" id="rrp" name="rrp" value="<?= $v('rrp') ?>">
          </div>
        </div>

        <?php if ($preview): ?>
        <div class="alert-fc alert-fc--warn mb-0" style="font-size:.86rem">
          <i class="fa-solid fa-calculator"></i>
          <span>
            <b>As it stands:</b> a reseller pays <b><?= format_price($preview['cost']) ?></b>,
            sells at <b><?= format_price($preview['rrp']) ?></b>,
            keeps <b><?= format_price($preview['profit']) ?></b> —
            a <?= $preview['margin_pct'] ?>% margin.
          </span>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!$isNew): ?>
      <div class="card-fc card-fc--pad">
        <h2 style="font-size:1.05rem">Options <span class="text-muted-3" style="font-size:.8rem;font-weight:400">(flavours, scents, sizes)</span></h2>
        <p class="text-muted-2" style="font-size:.85rem">
          Adding an option makes this a variable product — shoppers must choose one before it goes in the basket.
        </p>

        <?php if ($variants): ?>
        <div class="table-wrap mb-3">
          <table class="table-fc">
            <thead><tr><th>Label</th><th>Value</th><th class="text-end">+Price</th><th class="text-center">Stock</th><th>SKU</th><th style="width:1%"></th></tr></thead>
            <tbody>
            <?php foreach ($variants as $vr): ?>
              <tr>
                <td><?= e($vr['option_label']) ?></td>
                <td>
                  <?php if ($vr['swatch_hex']): ?><span class="variant-swatch d-inline-block me-1" style="background:<?= e($vr['swatch_hex']) ?>"></span><?php endif; ?>
                  <b><?= e($vr['option_value']) ?></b>
                </td>
                <td class="text-end"><?= (float) $vr['additional_price'] > 0 ? format_price((float) $vr['additional_price']) : '—' ?></td>
                <td class="text-center"><?= (int) $vr['stock_qty'] ?></td>
                <td style="font-family:var(--font-mono);font-size:.76rem"><?= e((string) $vr['sku']) ?></td>
                <td>
                  <form method="post" onsubmit="return confirm('Remove this option?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="variant_action" value="delete">
                    <input type="hidden" name="variant_id" value="<?= (int) $vr['id'] ?>">
                    <button class="btn-fc btn-fc--sm btn-fc--light" style="color:var(--coral)" type="submit"><i class="fa-solid fa-trash-can"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="variant_action" value="add">
          <div class="col-md-2 field mb-0">
            <label for="option_label">Label</label>
            <input class="input-fc" type="text" id="option_label" name="option_label" value="Flavour" placeholder="Flavour">
          </div>
          <div class="col-md-3 field mb-0">
            <label for="option_value">Value</label>
            <input class="input-fc" type="text" id="option_value" name="option_value" placeholder="Berry Blast" required>
          </div>
          <div class="col-md-2 field mb-0">
            <label for="swatch_hex">Swatch</label>
            <input class="input-fc" type="color" id="swatch_hex" name="swatch_hex" value="#1557D0" style="padding:.3rem;height:44px">
          </div>
          <div class="col-md-2 field mb-0">
            <label for="additional_price">+Price</label>
            <input class="input-fc" type="number" step="0.01" id="additional_price" name="additional_price" value="0">
          </div>
          <div class="col-md-1 field mb-0">
            <label for="variant_stock">Stock</label>
            <input class="input-fc" type="number" id="variant_stock" name="variant_stock" value="0">
          </div>
          <div class="col-md-2">
            <button class="btn-fc btn-fc--block btn-fc--sm" type="submit"><i class="fa-solid fa-plus"></i> Add</button>
          </div>
        </form>
      </div>

      <div class="card-fc card-fc--pad mt-3">
        <h2 style="font-size:1.05rem">Gallery <span class="text-muted-3" style="font-size:.8rem;font-weight:400">(extra photos shown on the product page)</span></h2>
        <p class="text-muted-2" style="font-size:.85rem">
          The Image panel on the right is the one card/thumbnail image. Anything added here shows in the photo strip
          on the product page itself — packaging shots, in-use photos, before/after, ingredient close-ups.
        </p>

        <?php if ($gallery): ?>
        <div class="row g-2 mb-3">
          <?php foreach ($gallery as $g): ?>
            <div class="col-6 col-md-3">
              <div style="position:relative;border-radius:var(--r-sm);overflow:hidden;border:1px solid var(--line)">
                <img src="<?= e(product_image($g['image'])) ?>" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;display:block;background:var(--paper-2)">
                <form method="post" onsubmit="return confirm('Remove this photo from the gallery?')"
                      style="position:absolute;top:6px;right:6px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="gallery_action" value="delete">
                  <input type="hidden" name="gallery_id" value="<?= (int) $g['id'] ?>">
                  <button class="btn-fc btn-fc--sm" style="background:rgba(14,23,38,.72);color:#fff;padding:.3rem .5rem" type="submit" title="Remove">
                    <i class="fa-solid fa-trash-can"></i>
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted-3" style="font-size:.83rem">No gallery photos yet — the product page will just show the one card image above.</p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end flex-wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="gallery_action" value="add">
          <div class="field mb-0" style="flex:1;min-width:240px">
            <label for="gallery_images">Add photos</label>
            <input class="input-fc" type="file" id="gallery_images" name="gallery_images[]" accept="image/jpeg,image/png,image/webp,image/svg+xml" multiple>
            <div class="field__hint">Select several at once — JPG, PNG, WebP or SVG, up to 8MB each.</div>
          </div>
          <button class="btn-fc btn-fc--sm" type="submit"><i class="fa-solid fa-upload"></i> Upload to gallery</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Sidebar ────────────────────────────────────────────────── -->
    <div class="col-lg-4">
      <div class="card-fc card-fc--pad mb-3">
        <h2 style="font-size:1.05rem">Publish</h2>
        <div class="field">
          <label for="status">Status</label>
          <select class="select-fc" id="status" name="status">
            <?php foreach (['published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived'] as $k => $label): ?>
              <option value="<?= $k ?>" <?= ($product['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="d-flex flex-column gap-2 mb-3">
          <?php foreach ([
            'is_featured'   => 'Featured on the homepage',
            'is_bestseller' => 'Show a “Best seller” badge',
            'is_new'        => 'Show a “New” badge',
            'is_sale'       => 'On sale (uses the sale price)',
          ] as $k => $label): ?>
            <label style="display:flex;gap:.55rem;align-items:center;font-size:.87rem;cursor:pointer">
              <input type="checkbox" name="<?= $k ?>" value="1" <?= !empty($product[$k]) ? 'checked' : '' ?> style="accent-color:var(--leaf)">
              <?= $label ?>
            </label>
          <?php endforeach; ?>
        </div>
        <button class="btn-fc btn-fc--block" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= $isNew ? 'Create product' : 'Save changes' ?></button>
      </div>

      <div class="card-fc card-fc--pad mb-3">
        <h2 style="font-size:1.05rem">Image</h2>
        <?php if (!empty($product['featured_image'])): ?>
          <img src="<?= e(product_image($product['featured_image'])) ?>" alt="" style="width:100%;border-radius:var(--r);margin-bottom:.8rem;background:var(--paper-2)">
        <?php endif; ?>
        <div class="field">
          <label for="featured_image">Upload a new image</label>
          <input class="input-fc" type="file" id="featured_image" name="featured_image" accept="image/jpeg,image/png,image/webp,image/svg+xml">
          <div class="field__hint">JPG, PNG, WebP or SVG, up to 8MB.</div>
        </div>
        <div class="field mb-0">
          <label for="featured_image_url">…or paste a path / URL</label>
          <input class="input-fc" type="text" id="featured_image_url" name="featured_image_url" value="<?= $v('featured_image') ?>"
                 placeholder="assets/img/products/name.jpg">
        </div>
      </div>

      <div class="card-fc card-fc--pad mb-3">
        <h2 style="font-size:1.05rem">Stock</h2>
        <div class="row g-2">
          <div class="col-6 field">
            <label for="stock_qty">On hand</label>
            <input class="input-fc" type="number" id="stock_qty" name="stock_qty" value="<?= $v('stock_qty', '0') ?>">
          </div>
          <div class="col-6 field">
            <label for="min_reseller_qty">Reseller min</label>
            <input class="input-fc" type="number" id="min_reseller_qty" name="min_reseller_qty" value="<?= $v('min_reseller_qty', '6') ?>" min="1">
          </div>
        </div>
        <div class="field">
          <label for="stock_status">Availability</label>
          <select class="select-fc" id="stock_status" name="stock_status">
            <?php foreach (['in_stock' => 'In stock', 'low_stock' => 'Low stock', 'out_of_stock' => 'Out of stock', 'on_backorder' => 'On backorder'] as $k => $label): ?>
              <option value="<?= $k ?>" <?= ($product['stock_status'] ?? 'in_stock') === $k ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row g-2 mb-0">
          <div class="col-6 field mb-0">
            <label for="pack_size">Pack size</label>
            <input class="input-fc" type="text" id="pack_size" name="pack_size" value="<?= $v('pack_size') ?>" placeholder="500ml">
          </div>
          <div class="col-6 field mb-0">
            <label for="sku">SKU</label>
            <input class="input-fc" type="text" id="sku" name="sku" value="<?= $v('sku') ?>">
          </div>
        </div>
      </div>

      <div class="card-fc card-fc--pad">
        <h2 style="font-size:1.05rem">Search &amp; SEO</h2>
        <div class="field">
          <label for="tags">Tags</label>
          <input class="input-fc" type="text" id="tags" name="tags" value="<?= $v('tags') ?>" placeholder="comma, separated, keywords">
          <div class="field__hint">Used by site search and the assistant.</div>
        </div>
        <div class="field">
          <label for="meta_title">Meta title</label>
          <input class="input-fc" type="text" id="meta_title" name="meta_title" value="<?= $v('meta_title') ?>" maxlength="200">
        </div>
        <div class="field mb-0">
          <label for="meta_desc">Meta description</label>
          <textarea class="textarea-fc" id="meta_desc" name="meta_desc" rows="3" maxlength="320" style="min-height:80px"><?= $v('meta_desc') ?></textarea>
        </div>
      </div>
    </div>
  </div>
</form>

<script>
document.getElementById('aiWrite')?.addEventListener('click', async function () {
  const val = (id) => (document.getElementById(id) || {}).value || '';
  const cat = document.getElementById('category_id');
  if (!val('name').trim()) { fcToast('Type the product name first.', 'info'); return; }
  const html = this.innerHTML;
  this.disabled = true;
  this.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Writing…';
  const res = await fcPost('/modules/api/ai-write.php', {
    name: val('name'), pack_size: val('pack_size'), benefits: val('benefits'), usage_notes: val('usage_notes'),
    short_desc: val('short_desc'), category_name: cat && cat.selectedIndex > 0 ? cat.options[cat.selectedIndex].text : '',
  });
  this.disabled = false;
  this.innerHTML = html;
  if (!res.success) { fcToast(res.message || 'The AI could not write that.', 'danger'); return; }
  if (!val('short_desc').trim() || confirm('Replace the short description too?')) document.getElementById('short_desc').value = res.short;
  document.getElementById('description').value = res.description;
  fcToast('Written — read it through, then save.');
});
</script>
<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
