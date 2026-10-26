<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'gallery';
$page_title  = 'Customer gallery — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $gid    = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'upload') {
        // Several photos at once; each one is saved exactly as uploaded.
        $files = $_FILES['photos'] ?? null;
        $added = 0;
        $order = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM customer_gallery')->fetchColumn();
        foreach (array_keys((array) ($files['name'] ?? [])) as $i) {
            if (($files['name'][$i] ?? '') === '') { continue; }
            $one = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            if ($path = save_public_image($one, 'gallery')) {
                db_insert('customer_gallery', [
                    'image'      => $path,
                    'caption'    => clean_text((string) ($_POST['caption'] ?? '')) ?: null,
                    'sort_order' => ++$order,
                    'status'     => ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published',
                ]);
                $added++;
            }
        }
        $flash = $added
            ? ['ok', $added . ' photo' . ($added === 1 ? '' : 's') . ' added to the gallery.']
            : ['danger', 'No photos were added. Use JPG, PNG or WebP files under 8 MB.'];
    } elseif ($action === 'save' && $gid) {
        db_update('customer_gallery', $gid, [
            'caption'    => clean_text((string) ($_POST['caption'] ?? '')) ?: null,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'status'     => ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published',
        ]);
        $flash = ['ok', 'Photo updated.'];
    } elseif ($action === 'delete' && $gid) {
        $stmt = db()->prepare('SELECT image FROM customer_gallery WHERE id = :id');
        $stmt->execute([':id' => $gid]);
        $img = (string) $stmt->fetchColumn();
        db()->prepare('DELETE FROM customer_gallery WHERE id = :id')->execute([':id' => $gid]);
        if (str_starts_with($img, 'uploads/gallery/')) { @unlink(UPLOAD_PATH . substr($img, 8)); }
        $flash = ['ok', 'Photo deleted.'];
    }
}

$rows = db()->query('SELECT * FROM customer_gallery ORDER BY sort_order, id DESC')->fetchAll();
?>

<div class="dash-head">
  <div><h1>Customer gallery</h1><p>Photos from customers show in the <b>From our customers</b> section on the home page, exactly as uploaded. The section stays hidden until at least one photo is live.</p></div>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= $flash[0] ?>"><i class="fa-solid fa-circle-<?= $flash[0] === 'ok' ? 'check' : 'exclamation' ?>"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <?php if (!$rows): ?><div class="card-fc card-fc--pad text-muted-2">No photos yet — add the first ones on the right.</div><?php endif; ?>
    <div class="row g-3">
    <?php foreach ($rows as $g): ?>
      <div class="col-md-6 col-xl-4">
        <form method="post" class="card-fc card-fc--pad h-100 d-flex flex-column">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
          <a href="<?= e(product_image($g['image'])) ?>" target="_blank" rel="noopener">
            <img src="<?= e(product_image($g['image'])) ?>" alt="" style="width:100%;height:180px;object-fit:cover;border-radius:10px;border:1px solid var(--line)">
          </a>
          <div class="field mt-2"><input class="input-fc" name="caption" value="<?= e((string) $g['caption']) ?>" placeholder="Caption (optional)" aria-label="Caption"></div>
          <div class="row g-2">
            <div class="col-5 field"><input class="input-fc" type="number" name="sort_order" value="<?= (int) $g['sort_order'] ?>" aria-label="Order"></div>
            <div class="col-7 field"><select class="select-fc" name="status" aria-label="Status">
              <option value="published" <?= $g['status'] === 'published' ? 'selected' : '' ?>>Live</option>
              <option value="draft" <?= $g['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
            </select></div>
          </div>
          <div class="d-flex gap-1 mt-auto">
            <button class="btn-fc btn-fc--sm btn-fc--light" name="action" value="save" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            <button class="btn-fc btn-fc--sm btn-fc--light ms-auto" style="color:var(--coral)" name="action" value="delete" type="submit" aria-label="Delete" onclick="return confirm('Delete this photo?')"><i class="fa-solid fa-trash-can"></i></button>
          </div>
        </form>
      </div>
    <?php endforeach; ?>
    </div>
  </div>

  <div class="col-lg-4">
    <form method="post" enctype="multipart/form-data" class="card-fc card-fc--pad">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <h2 style="font-size:1.05rem">Add photos</h2>
      <div class="field"><label for="photos">Photos <span class="text-muted-3 fw-normal">(pick several at once)</span></label>
        <input class="input-fc" type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
        <div class="field__hint">Only real customer photos, with their permission. Hide faces, names and phone numbers they did not agree to share.</div></div>
      <div class="field"><label for="caption">Caption <span class="text-muted-3 fw-normal">(optional, for all of them)</span></label>
        <input class="input-fc" id="caption" name="caption" placeholder="e.g. Delivered to Polokwane"></div>
      <div class="field"><label for="status">Status</label>
        <select class="select-fc" id="status" name="status"><option value="published">Live</option><option value="draft">Draft</option></select></div>
      <button class="btn-fc btn-fc--block" type="submit"><i class="fa-solid fa-upload"></i> Upload</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
