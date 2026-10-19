<?php
declare(strict_types=1);
$dash_role   = 'customer';
$dash_active = 'medications';
$page_title  = 'My medication — CureMeds';
require_once __DIR__ . '/../../includes/dash-head.php';

$uid = (int) $user['id'];
$errors = [];
$editing = isset($_GET['edit']) ? Health::medication((int) $_GET['edit'], $uid) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? 'save');
    $id     = (int) ($_POST['id'] ?? 0) ?: null;

    if ($action === 'status' && $id && Health::medication($id, $uid)) {
        $status = in_array($_POST['status'] ?? '', ['active', 'paused', 'completed'], true) ? $_POST['status'] : 'active';
        db_update('patient_medications', $id, ['status' => $status]);
        flash('success', ['active' => 'Reminders are back on.', 'paused' => 'Paused — no reminders until you resume it.', 'completed' => 'Marked as finished.'][$status]);
        back();
    }

    if ($action === 'save') {
        [$savedId, $errors] = Health::saveMedication($uid, $_POST, $id);
        if ($savedId) {
            flash('success', $id ? 'Medication updated.' : 'Added. We\'ll remind you at ' . e(implode(' and ', Health::parseTimes((string) $_POST['dose_times']))) . '.');
            redirect('dashboards/customer/medications.php');
        }
        $editing = $_POST + ['id' => $id];
    }
}

$meds     = Health::medications($uid);
$products = db()->query("SELECT id, name, pack_size FROM products WHERE status = 'published' ORDER BY name")->fetchAll();
$form     = $editing ?: ['dose_times' => '08:00', 'units_per_dose' => 1, 'reminders' => 1, 'start_date' => date('Y-m-d'), 'status' => 'active'];
$times    = Health::parseTimes((string) ($form['dose_times'] ?? '')) ?: ['08:00'];
?>

<div class="dash-head">
  <div><h1>My medication</h1><p>What you take, how much and when. We remind you at each dose time.</p></div>
  <?php if (!$editing): ?><a href="#add" class="btn-fc btn-fc--sm"><i class="fa-solid fa-plus"></i> Add medication</a><?php endif; ?>
</div>

<?= flash_html() ?>
<?php foreach ($errors as $err): ?>
  <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div>
<?php endforeach; ?>

<?php if (empty($user['email'])): ?>
  <div class="alert-fc alert-fc--info"><i class="fa-solid fa-bell"></i>
    <span>Reminders are emailed. <a href="account.php" class="fw-bold">Add your email address</a> to start getting them.</span></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <?php if (!$meds): ?>
      <div class="card-fc empty">
        <div class="empty__icon"><i class="fa-solid fa-pills"></i></div>
        <h3>Nothing added yet</h3>
        <p>Use the form to add your first medication or supplement.</p>
      </div>
    <?php endif; ?>

    <div class="d-grid gap-3">
    <?php foreach ($meds as $m):
      $left = Health::daysLeft($m);
      $tone = ['active' => 'ok', 'paused' => 'warn', 'completed' => 'muted'][$m['status']]; ?>
      <div class="card-fc card-fc--pad">
        <div class="d-flex gap-3 align-items-start">
          <img src="<?= e(product_image($m['featured_image'] ?? null)) ?>" alt="" style="width:64px;height:64px;border-radius:var(--r);object-fit:cover;flex-shrink:0">
          <div style="flex:1;min-width:0">
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <b style="font-family:var(--font-display);font-size:1.05rem"><?= e($m['name']) ?></b>
              <span class="pill pill--<?= $tone ?>"><?= ucfirst($m['status']) ?></span>
              <?php if (!$m['reminders']): ?><span class="pill pill--muted"><i class="fa-solid fa-bell-slash"></i> No reminders</span><?php endif; ?>
            </div>
            <div class="text-muted-2" style="font-size:.86rem;margin-top:.2rem">
              <?= e(trim(($m['dosage'] ?? '') . ($m['instructions'] ? ' · ' . $m['instructions'] : ''), ' ·')) ?>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2">
              <?php foreach (Health::parseTimes((string) $m['dose_times']) as $t): ?>
                <span class="badge-fc badge-fc--leaf"><i class="fa-regular fa-clock"></i> <?= e($t) ?></span>
              <?php endforeach; ?>
              <?php if ($left !== null): ?>
                <span class="badge-fc <?= $left <= Health::REFILL_DAYS ? 'badge-fc--amber' : 'badge-fc--muted' ?>"><?= (int) $m['units_left'] ?> left · ~<?= $left ?> days</span>
              <?php endif; ?>
            </div>
            <div class="text-muted-3 mt-2" style="font-size:.76rem">
              Since <?= date('j M Y', strtotime($m['start_date'])) ?><?= $m['end_date'] ? ' until ' . date('j M Y', strtotime($m['end_date'])) : '' ?>
            </div>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3 pt-3" style="border-top:1px solid var(--line)">
          <a href="?edit=<?= (int) $m['id'] ?>#add" class="btn-fc btn-fc--ghost btn-fc--sm"><i class="fa-solid fa-pen"></i> Edit</a>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <?php if ($m['status'] === 'active'): ?>
              <button class="btn-fc btn-fc--ghost btn-fc--sm" name="status" value="paused"><i class="fa-solid fa-pause"></i> Pause</button>
              <button class="btn-fc btn-fc--ghost btn-fc--sm" name="status" value="completed"><i class="fa-solid fa-flag-checkered"></i> Finished</button>
            <?php else: ?>
              <button class="btn-fc btn-fc--sm" name="status" value="active"><i class="fa-solid fa-play"></i> Resume</button>
            <?php endif; ?>
          </form>
          <?php if (!empty($m['product_slug'])): ?>
            <a href="<?= e(wa_link('Hi CureMeds, I would like to reorder ' . $m['name'] . '.')) ?>" target="_blank" rel="noopener" class="btn-fc btn-fc--whatsapp btn-fc--sm ms-auto"><i class="fa-brands fa-whatsapp"></i> Reorder</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>

  <div class="col-lg-5">
    <form class="card-fc card-fc--pad" method="post" id="add" style="position:sticky;top:1rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php if (!empty($form['id'])): ?><input type="hidden" name="id" value="<?= (int) $form['id'] ?>"><?php endif; ?>
      <h2 style="font-size:1.1rem"><?= !empty($form['id']) ? 'Edit medication' : 'Add medication' ?></h2>

      <div class="field">
        <label for="product_id">Bought from CureMeds?</label>
        <select class="input-fc" id="product_id" name="product_id">
          <option value="">No — I'll type the name</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= (int) ($form['product_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name'] . ($p['pack_size'] ? ' (' . $p['pack_size'] . ')' : '')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="name">Name</label>
        <input class="input-fc" id="name" name="name" value="<?= e((string) ($form['name'] ?? '')) ?>" placeholder="e.g. ARVs, Vitamin C, blood pressure tablet">
        <div class="field__hint">Filled in for you if you pick a product above.</div>
      </div>
      <div class="row g-2">
        <div class="col-6 field">
          <label for="dosage">How much</label>
          <input class="input-fc" id="dosage" name="dosage" value="<?= e((string) ($form['dosage'] ?? '')) ?>" placeholder="1 tablet">
        </div>
        <div class="col-6 field">
          <label for="instructions">Notes</label>
          <input class="input-fc" id="instructions" name="instructions" value="<?= e((string) ($form['instructions'] ?? '')) ?>" placeholder="With food">
        </div>
      </div>

      <div class="field">
        <label>Dose times</label>
        <div id="timeRows" class="d-grid gap-2">
          <?php foreach ($times as $t): ?>
            <div class="d-flex gap-2"><input class="input-fc" type="time" value="<?= e($t) ?>" data-time required>
              <button type="button" class="btn-fc btn-fc--ghost btn-fc--sm" data-remove-time aria-label="Remove this time"><i class="fa-solid fa-xmark"></i></button></div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn-fc btn-fc--light btn-fc--sm mt-2" id="addTime"><i class="fa-solid fa-plus"></i> Another time</button>
        <input type="hidden" name="dose_times" id="doseTimes" value="<?= e(implode(',', $times)) ?>">
      </div>

      <div class="row g-2">
        <div class="col-6 field">
          <label for="start_date">Start</label>
          <input class="input-fc" type="date" id="start_date" name="start_date" value="<?= e((string) ($form['start_date'] ?? date('Y-m-d'))) ?>">
        </div>
        <div class="col-6 field">
          <label for="end_date">End <span class="text-muted-3 fw-normal">(optional)</span></label>
          <input class="input-fc" type="date" id="end_date" name="end_date" value="<?= e((string) ($form['end_date'] ?? '')) ?>">
        </div>
        <div class="col-6 field">
          <label for="units_left">How many left?</label>
          <input class="input-fc" type="number" min="0" id="units_left" name="units_left" value="<?= e((string) ($form['units_left'] ?? '')) ?>" placeholder="e.g. 60">
          <div class="field__hint">We'll warn you before you run out.</div>
        </div>
        <div class="col-6 field">
          <label for="units_per_dose">Per dose</label>
          <input class="input-fc" type="number" min="0.25" step="0.25" id="units_per_dose" name="units_per_dose" value="<?= e((string) ($form['units_per_dose'] ?? 1)) ?>">
        </div>
      </div>

      <label class="opt-card mb-3">
        <input type="checkbox" name="reminders" value="1" <?= !empty($form['reminders']) ? 'checked' : '' ?>>
        <span class="opt-card__blurb">Email me a reminder at each dose time</span>
      </label>
      <input type="hidden" name="status" value="<?= e((string) ($form['status'] ?? 'active')) ?>">

      <button class="btn-fc btn-fc--block" type="submit"><?= !empty($form['id']) ? 'Save changes' : 'Add medication' ?></button>
      <?php if (!empty($form['id'])): ?><a href="medications.php" class="btn-fc btn-fc--light btn-fc--block mt-2">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<script>
(() => {
  const rows = document.getElementById('timeRows');
  const out  = document.getElementById('doseTimes');
  const sync = () => { out.value = [...rows.querySelectorAll('[data-time]')].map((i) => i.value).filter(Boolean).join(','); };
  document.getElementById('addTime').addEventListener('click', () => {
    if (rows.children.length >= 6) return;
    const row = rows.firstElementChild.cloneNode(true);
    row.querySelector('[data-time]').value = '20:00';
    rows.appendChild(row); sync();
  });
  rows.addEventListener('click', (e) => {
    const b = e.target.closest('[data-remove-time]');
    if (b && rows.children.length > 1) { b.parentElement.remove(); sync(); }
  });
  rows.addEventListener('input', sync);
})();
</script>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
