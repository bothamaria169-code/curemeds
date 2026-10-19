<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'brands';
$page_title  = 'Brands — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $bid    = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $accent = (string) ($_POST['accent'] ?? '');
        $data = [
            'name'       => sanitize((string) $_POST['name']),
            'tagline'    => sanitize((string) ($_POST['tagline'] ?? '')) ?: null,
            'blurb'      => sanitize((string) ($_POST['blurb'] ?? '')) ?: null,
            'accent'     => preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#1557D0',
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'status'     => (string) ($_POST['status'] ?? 'active'),
        ];
        if ($bid) {
            db_update('brands', $bid, $data);
            $flash = 'Brand updated.';
        } else {
            $data['slug'] = slugify($data['name']);
            db_insert('brands', $data);
            $flash = 'Brand added.';
        }
    } elseif ($action === 'delete' && $bid) {
        // Products survive a brand deletion — they just lose the association.
        db()->prepare('UPDATE products SET brand_id = NULL WHERE brand_id = :id')->execute([':id' => $bid]);
        db()->prepare('DELETE FROM brands WHERE id = :id')->execute([':id' => $bid]);
        $flash = 'Brand deleted. Its products stay in the catalogue without a brand.';
    }
}

$rows = db()->query(
    "SELECT b.*, (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id) n
     FROM brands b ORDER BY b.sort_order, b.name"
)->fetchAll();

$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM brands WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
?>

<div class="dash-head">
  <div><h1>Brands</h1><p>The suppliers whose price lists this catalogue carries.</p></div>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--ok"><i class="fa-solid fa-circle-check"></i><span><?= e($flash) ?></span></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="row g-3">
    <?php foreach ($rows as $b): ?>
      <div class="col-md-6">
        <div class="card-fc card-fc--pad h-100 d-flex flex-column">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span style="width:14px;height:14px;border-radius:50%;background:<?= e($b['accent']) ?>"></span>
            <b style="font-family:var(--font-display);font-size:1.05rem"><?= e($b['name']) ?></b>
            <span class="pill pill--<?= $b['status'] === 'active' ? 'ok' : 'muted' ?> ms-auto"><?= (int) $b['n'] ?> products</span>
          </div>
          <div class="eyebrow" style="color:<?= e($b['accent']) ?>;margin-bottom:.4rem"><?= e((string) $b['tagline']) ?></div>
          <p style="font-size:.85rem;color:var(--text-2)"><?= e(excerpt($b['blurb'], 130)) ?></p>
          <div class="d-flex gap-1 mt-auto">
            <a class="btn-fc btn-fc--sm btn-fc--light" href="?edit=<?= (int) $b['id'] ?>"><i class="fa-solid fa-pen"></i> Edit</a>
            <a class="btn-fc btn-fc--sm btn-fc--light" href="<?= SITE_URL ?>/shop.php?brand=<?= e($b['slug']) ?>" target="_blank" aria-label="View on the shop"><i class="fa-solid fa-eye"></i></a>
            <form method="post" onsubmit="return confirm('Delete <?= e($b['name']) ?>? Its <?= (int) $b['n'] ?> product(s) stay but lose their brand.')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button class="btn-fc btn-fc--sm btn-fc--light" style="color:var(--coral)" type="submit" aria-label="Delete"><i class="fa-solid fa-trash-can"></i></button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>

  <div class="col-lg-4">
    <form method="post" class="card-fc card-fc--pad">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

      <h2 style="font-size:1.05rem"><?= $edit ? 'Edit brand' : 'Add a brand' ?></h2>

      <div class="field">
        <label for="name">Name</label>
        <input class="input-fc" type="text" id="name" name="name" value="<?= e((string) ($edit['name'] ?? '')) ?>" required>
      </div>
      <div class="field">
        <label for="tagline">Tagline</label>
        <input class="input-fc" type="text" id="tagline" name="tagline" value="<?= e((string) ($edit['tagline'] ?? '')) ?>">
      </div>
      <div class="field">
        <label for="blurb">About this brand</label>
        <textarea class="textarea-fc" id="blurb" name="blurb" rows="4" style="min-height:100px"><?= e((string) ($edit['blurb'] ?? '')) ?></textarea>
      </div>
      <div class="row g-2">
        <div class="col-4 field">
          <label for="accent">Accent</label>
          <input class="input-fc" type="color" id="accent" name="accent" value="<?= e((string) ($edit['accent'] ?? '#1557D0')) ?>" style="padding:.3rem;height:44px">
        </div>
        <div class="col-4 field">
          <label for="sort_order">Order</label>
          <input class="input-fc" type="number" id="sort_order" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
        </div>
        <div class="col-4 field">
          <label for="status">Status</label>
          <select class="select-fc" id="status" name="status">
            <option value="active"   <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
      </div>

      <button class="btn-fc btn-fc--block" type="submit"><?= $edit ? 'Save changes' : 'Add brand' ?></button>
      <?php if ($edit): ?><a href="brands.php" class="btn-fc btn-fc--light btn-fc--block mt-2">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
