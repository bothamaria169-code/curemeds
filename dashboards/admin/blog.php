<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'blog';
$page_title  = 'Articles — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$flash = null;
$edit  = null;

if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM blogs WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $bid    = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if (mb_strlen($title) < 3) {
            $flash = ['warn', 'Give the article a title.'];
        } else {
            $body = (string) ($_POST['content'] ?? '');
            $data = [
                'title'       => sanitize($title),
                // Content is authored by admins and rendered raw, so only a
                // conservative tag set survives.
                'content'     => strip_tags($body, '<p><br><h2><h3><h4><ul><ol><li><strong><b><em><i><a><blockquote><img>'),
                'excerpt'     => sanitize((string) ($_POST['excerpt'] ?? '')) ?: null,
                'image'       => sanitize((string) ($_POST['image'] ?? '')) ?: null,
                'category'    => sanitize((string) ($_POST['category'] ?? 'General')),
                'read_mins'   => max(1, (int) ($_POST['read_mins'] ?? 4)),
                'is_featured' => !empty($_POST['is_featured']) ? 1 : 0,
                'status'      => (string) ($_POST['status'] ?? 'draft'),
                'author_id'   => (int) $user['id'],
            ];
            if ($bid) {
                db_update('blogs', $bid, $data);
                $flash = ['ok', 'Article saved.'];
            } else {
                $base = slugify($title);
                $slug = $base;
                $n = 1;
                while (true) {
                    $chk = db()->prepare('SELECT id FROM blogs WHERE slug = :s');
                    $chk->execute([':s' => $slug]);
                    if (!$chk->fetch()) { break; }
                    $slug = $base . '-' . (++$n);
                }
                $data['slug'] = $slug;
                $bid = db_insert('blogs', $data);
                $flash = ['ok', 'Article created.'];
            }
            $stmt = db()->prepare('SELECT * FROM blogs WHERE id = :id');
            $stmt->execute([':id' => $bid]);
            $edit = $stmt->fetch() ?: null;
        }
    } elseif ($action === 'delete' && $bid) {
        db()->prepare('DELETE FROM blogs WHERE id = :id')->execute([':id' => $bid]);
        $flash = ['ok', 'Article deleted.'];
        $edit  = null;
    }
}

$rows = db()->query(
    'SELECT b.*, u.name AS author_name FROM blogs b LEFT JOIN users u ON u.id = b.author_id ORDER BY b.created_at DESC'
)->fetchAll();
?>

<div class="dash-head">
  <div><h1>Articles</h1><p>The Learn hub. Reseller advice, product guides and honest label explainers.</p></div>
  <a href="blog.php" class="btn-fc btn-fc--sm"><i class="fa-solid fa-plus"></i> New article</a>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= $flash[0] ?>"><i class="fa-solid fa-circle-info"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" class="card-fc card-fc--pad">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

      <h2 style="font-size:1.05rem"><?= $edit ? 'Edit article' : 'New article' ?></h2>

      <div class="field">
        <label for="title">Title</label>
        <input class="input-fc" type="text" id="title" name="title" value="<?= e((string) ($edit['title'] ?? '')) ?>" required>
      </div>
      <div class="row g-2">
        <div class="col-md-5 field">
          <label for="category">Category</label>
          <input class="input-fc" type="text" id="category" name="category" value="<?= e((string) ($edit['category'] ?? 'General')) ?>"
                 list="cats" placeholder="Reseller">
          <datalist id="cats">
            <?php foreach (db()->query("SELECT DISTINCT category FROM blogs")->fetchAll(PDO::FETCH_COLUMN) as $c): ?>
              <option value="<?= e((string) $c) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="col-md-3 field">
          <label for="read_mins">Read time</label>
          <input class="input-fc" type="number" id="read_mins" name="read_mins" value="<?= (int) ($edit['read_mins'] ?? 4) ?>" min="1">
        </div>
        <div class="col-md-4 field">
          <label for="status">Status</label>
          <select class="select-fc" id="status" name="status">
            <option value="published" <?= ($edit['status'] ?? 'draft') === 'published' ? 'selected' : '' ?>>Published</option>
            <option value="draft"     <?= ($edit['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="image">Cover image path or URL</label>
        <input class="input-fc" type="text" id="image" name="image" value="<?= e((string) ($edit['image'] ?? '')) ?>"
               placeholder="assets/img/editorial/name.jpg">
      </div>
      <div class="field">
        <label for="excerpt">Excerpt</label>
        <textarea class="textarea-fc" id="excerpt" name="excerpt" rows="2" style="min-height:70px"><?= e((string) ($edit['excerpt'] ?? '')) ?></textarea>
      </div>
      <div class="field">
        <label for="content">Body <span class="text-muted-3 fw-normal">(HTML)</span></label>
        <textarea class="textarea-fc" id="content" name="content" rows="14" style="min-height:320px;font-family:var(--font-mono);font-size:.84rem"><?= e((string) ($edit['content'] ?? '')) ?></textarea>
        <div class="field__hint">Allowed: p, h2–h4, ul/ol/li, strong, em, a, blockquote, img.</div>
      </div>
      <label style="display:flex;gap:.55rem;align-items:center;font-size:.87rem;cursor:pointer;margin-bottom:1rem">
        <input type="checkbox" name="is_featured" value="1" <?= !empty($edit['is_featured']) ? 'checked' : '' ?> style="accent-color:var(--leaf)">
        Feature this article
      </label>

      <div class="d-flex gap-2">
        <button class="btn-fc" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= $edit ? 'Save' : 'Create' ?></button>
        <?php if ($edit): ?>
          <a class="btn-fc btn-fc--ghost" href="<?= SITE_URL ?>/modules/blog/post.php?slug=<?= e($edit['slug']) ?>" target="_blank">
            <i class="fa-solid fa-eye"></i> Preview
          </a>
          <a class="btn-fc btn-fc--light" href="blog.php">New article</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="col-lg-5">
    <div class="card-fc">
      <div class="p-3 pb-0"><h2 style="font-size:1.05rem;margin:0">All articles</h2></div>
      <div class="p-3">
        <?php foreach ($rows as $b): ?>
        <div class="d-flex gap-2 align-items-center py-2" style="border-bottom:1px solid var(--line)">
          <img src="<?= e(product_image($b['image'])) ?>" alt="" width="48" height="36"
               style="border-radius:6px;object-fit:cover;background:var(--paper-2);flex-shrink:0">
          <div style="flex:1;min-width:0">
            <a href="?edit=<?= (int) $b['id'] ?>" style="font-size:.86rem;font-weight:700;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($b['title']) ?></a>
            <span class="text-muted-3" style="font-size:.73rem">
              <?= e($b['category']) ?> · <?= date('j M Y', strtotime($b['created_at'])) ?>
              <?php if ($b['is_featured']): ?> · <i class="fa-solid fa-star" style="color:var(--amber)"></i><?php endif; ?>
            </span>
          </div>
          <span class="pill pill--<?= $b['status'] === 'published' ? 'ok' : 'muted' ?>"><?= e(ucfirst($b['status'])) ?></span>
          <form method="post" onsubmit="return confirm('Delete this article?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <button class="btn-fc btn-fc--sm btn-fc--light" style="color:var(--coral)" type="submit"><i class="fa-solid fa-trash-can"></i></button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
