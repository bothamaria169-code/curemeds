<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'banners';
$page_title  = 'Banners — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$flash = null;

/** Stores an uploaded banner file and returns its web path, or null. */
function store_banner_upload(string $field): ?string
{
    if (empty($_FILES[$field]['name'])) {
        return null;
    }
    $check = validate_upload($_FILES[$field], ALLOWED_IMAGE_TYPES);
    if (!$check['ok']) {
        return null;
    }
    $dir = __DIR__ . '/../../assets/img/banners/';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $name = secure_filename($_FILES[$field]['name']);
    return move_uploaded_file($_FILES[$field]['tmp_name'], $dir . $name)
        ? 'assets/img/banners/' . $name
        : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $bid    = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $image = store_banner_upload('image_file') ?: trim((string) ($_POST['image_url'] ?? ''));

        if ($title === '') {
            $flash = ['warn', 'Give the banner a title so you can recognise it in this list.'];
        } elseif ($image === '') {
            $flash = ['warn', 'Upload an image, or paste a path to one already on the server.'];
        } else {
            $data = [
                'title'         => sanitize($title),
                'image'         => $image,
                'alt_text'      => sanitize((string) ($_POST['alt_text'] ?? '')) ?: null,
                'link_url'      => sanitize((string) ($_POST['link_url'] ?? '')) ?: null,
                'aspect'        => ($_POST['aspect'] ?? 'wide') === 'tall' ? 'tall' : 'wide',
                'show_home'     => !empty($_POST['show_home']) ? 1 : 0,
                'show_shop'     => !empty($_POST['show_shop']) ? 1 : 0,
                'show_reseller' => !empty($_POST['show_reseller']) ? 1 : 0,
                'sort_order'    => (int) ($_POST['sort_order'] ?? 0),
                'status'        => (string) ($_POST['status'] ?? 'active'),
            ];
            if ($bid) {
                db_update('banners', $bid, $data);
                $flash = ['ok', 'Banner updated.'];
            } else {
                db_insert('banners', $data);
                $flash = ['ok', 'Banner added.'];
            }
        }
    } elseif ($action === 'delete' && $bid) {
        db()->prepare('DELETE FROM banners WHERE id = :id')->execute([':id' => $bid]);
        $flash = ['ok', 'Banner deleted.'];
    } elseif ($action === 'toggle' && $bid) {
        db()->prepare("UPDATE banners SET status = IF(status='active','inactive','active') WHERE id = :id")->execute([':id' => $bid]);
        $flash = ['ok', 'Banner status flipped.'];
    } elseif ($action === 'move' && $bid) {
        $dir = ($_POST['dir'] ?? 'up') === 'down' ? 1 : -1;
        db()->prepare('UPDATE banners SET sort_order = GREATEST(0, sort_order + :d) WHERE id = :id')
            ->execute([':d' => $dir, ':id' => $bid]);
        $flash = ['ok', 'Order updated.'];
    }
}

$rows = db()->query('SELECT * FROM banners ORDER BY aspect, sort_order, id')->fetchAll();

$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM banners WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
?>

<div class="dash-head">
  <div>
    <h1>Banners</h1>
    <p>The rotating promotions on the homepage, shop and reseller pages. Drag order with the arrows; a banner can sit on more than one page.</p>
  </div>
  <a href="<?= SITE_URL ?>/index.php" target="_blank" class="btn-fc btn-fc--ghost btn-fc--sm">
    <i class="fa-solid fa-arrow-up-right-from-square"></i> View homepage
  </a>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= $flash[0] ?>"><i class="fa-solid fa-circle-info"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>

<div class="alert-fc alert-fc--info">
  <i class="fa-solid fa-lightbulb"></i>
  <span>
    <b>Wide</b> banners (about 3:1, e.g. 2100&times;700) become slides in the page carousel.
    <b>Tall</b> banners (portrait, e.g. 1150&times;1360) render as a single promo tile — the shop sidebar
    and the reseller page use these. Keep uploads under 8MB.
  </span>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <?php if (!$rows): ?>
      <div class="empty card-fc">
        <div class="empty__icon"><i class="fa-solid fa-images"></i></div>
        <h3>No banners yet</h3>
        <p>Add one with the form on the right and it appears on the pages you tick.</p>
      </div>
    <?php else: ?>
      <div class="row g-3">
      <?php foreach ($rows as $b): ?>
        <div class="col-md-6">
          <div class="card-fc h-100" style="overflow:hidden;display:flex;flex-direction:column">
            <img src="<?= e(product_image($b['image'])) ?>" alt=""
                 style="width:100%;<?= $b['aspect'] === 'wide' ? 'aspect-ratio:3/1' : 'aspect-ratio:1/1' ?>;object-fit:cover;background:var(--paper-2)">
            <div style="padding:.9rem 1rem;display:flex;flex-direction:column;flex:1">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <b style="font-family:var(--font-display);font-size:.95rem"><?= e($b['title']) ?></b>
                <span class="badge-fc <?= $b['aspect'] === 'wide' ? 'badge-fc--leaf' : 'badge-fc--amber' ?>"><?= e($b['aspect']) ?></span>
              </div>

              <div class="d-flex gap-1 flex-wrap mt-2">
                <?php foreach (['show_home' => 'Home', 'show_shop' => 'Shop', 'show_reseller' => 'Reseller'] as $col => $label): ?>
                  <span class="badge-fc <?= $b[$col] ? 'badge-fc--muted' : '' ?>"
                        style="<?= $b[$col] ? '' : 'opacity:.35' ?>"><?= $label ?></span>
                <?php endforeach; ?>
              </div>

              <?php if ($b['link_url']): ?>
                <div class="text-muted-3 mt-2" style="font-size:.74rem;word-break:break-all">
                  <i class="fa-solid fa-link"></i> <?= e($b['link_url']) ?>
                </div>
              <?php endif; ?>

              <div class="d-flex gap-1 mt-3 flex-wrap align-items-center" style="margin-top:auto!important">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="pill pill--<?= $b['status'] === 'active' ? 'ok' : 'muted' ?>" style="border:0;cursor:pointer" type="submit">
                    <?= e(ucfirst($b['status'])) ?>
                  </button>
                </form>

                <form method="post" class="d-flex gap-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="btn-fc btn-fc--sm btn-fc--light" name="dir" value="up" title="Move earlier"><i class="fa-solid fa-arrow-up"></i></button>
                  <button class="btn-fc btn-fc--sm btn-fc--light" name="dir" value="down" title="Move later"><i class="fa-solid fa-arrow-down"></i></button>
                </form>

                <a class="btn-fc btn-fc--sm btn-fc--light" href="?edit=<?= (int) $b['id'] ?>"><i class="fa-solid fa-pen"></i></a>

                <form method="post" onsubmit="return confirm('Delete “<?= e($b['title']) ?>”?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="btn-fc btn-fc--sm btn-fc--light" style="color:var(--coral)" type="submit"><i class="fa-solid fa-trash-can"></i></button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <form method="post" enctype="multipart/form-data" class="card-fc card-fc--pad">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

      <h2 style="font-size:1.05rem"><?= $edit ? 'Edit banner' : 'Add a banner' ?></h2>

      <?php if ($edit): ?>
        <img src="<?= e(product_image($edit['image'])) ?>" alt=""
             style="width:100%;border-radius:var(--r);margin-bottom:.8rem;background:var(--paper-2)">
      <?php endif; ?>

      <div class="field">
        <label for="title">Title <span class="text-muted-3 fw-normal">(internal)</span></label>
        <input class="input-fc" type="text" id="title" name="title" value="<?= e((string) ($edit['title'] ?? '')) ?>" required
               placeholder="Flash Sale — Lady Bloom Juice">
      </div>

      <div class="field">
        <label for="image_file">Image</label>
        <input class="input-fc" type="file" id="image_file" name="image_file" accept="image/jpeg,image/png,image/webp">
        <div class="field__hint">JPG, PNG or WebP, up to 8MB.</div>
      </div>

      <div class="field">
        <label for="image_url">…or paste a path</label>
        <input class="input-fc" type="text" id="image_url" name="image_url" value="<?= e((string) ($edit['image'] ?? '')) ?>"
               placeholder="assets/img/banners/name.jpg">
      </div>

      <div class="field">
        <label for="alt_text">Alt text</label>
        <textarea class="textarea-fc" id="alt_text" name="alt_text" rows="2" style="min-height:66px"
                  placeholder="What the banner says, for screen readers and search"><?= e((string) ($edit['alt_text'] ?? '')) ?></textarea>
      </div>

      <div class="field">
        <label for="link_url">Links to</label>
        <input class="input-fc" type="text" id="link_url" name="link_url" value="<?= e((string) ($edit['link_url'] ?? '')) ?>"
               placeholder="/shop.php?brand=blossom">
        <div class="field__hint">A path on this site, or a full https:// URL. Leave empty for no link.</div>
      </div>

      <div class="row g-2">
        <div class="col-6 field">
          <label for="aspect">Type</label>
          <select class="select-fc" id="aspect" name="aspect">
            <option value="wide" <?= ($edit['aspect'] ?? 'wide') === 'wide' ? 'selected' : '' ?>>Wide (carousel)</option>
            <option value="tall" <?= ($edit['aspect'] ?? '') === 'tall' ? 'selected' : '' ?>>Tall (promo tile)</option>
          </select>
        </div>
        <div class="col-6 field">
          <label for="sort_order">Order</label>
          <input class="input-fc" type="number" id="sort_order" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
        </div>
      </div>

      <div class="field">
        <label>Show on</label>
        <div class="d-flex flex-column gap-2">
          <?php foreach (['show_home' => 'Homepage', 'show_shop' => 'Shop page', 'show_reseller' => 'Reseller page'] as $col => $label): ?>
            <label style="display:flex;gap:.55rem;align-items:center;font-size:.87rem;cursor:pointer">
              <input type="checkbox" name="<?= $col ?>" value="1"
                     <?= !empty($edit[$col]) || (!$edit && $col === 'show_home') ? 'checked' : '' ?>
                     style="accent-color:var(--leaf)">
              <?= $label ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="field">
        <label for="status">Status</label>
        <select class="select-fc" id="status" name="status">
          <option value="active"   <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>

      <button class="btn-fc btn-fc--block" type="submit"><?= $edit ? 'Save changes' : 'Add banner' ?></button>
      <?php if ($edit): ?><a href="banners.php" class="btn-fc btn-fc--light btn-fc--block mt-2">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
