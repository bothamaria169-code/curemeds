<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'patients';
$page_title  = 'Patient — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$pid  = (int) ($_GET['id'] ?? 0);
$load = function () use ($pid): ?array {
    $s = db()->prepare("SELECT * FROM users WHERE id = :id AND role <> 'admin'");
    $s->execute([':id' => $pid]);
    return $s->fetch() ?: null;
};
$patient = $load();
if (!$patient) {
    flash('danger', 'That patient was not found.');
    redirect('dashboards/admin/patients.php');
}

$errors = [];
$aiSummary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');
    $clean  = fn(string $k, int $len) => mb_substr(trim(strip_tags((string) ($_POST[$k] ?? ''))), 0, $len) ?: null;

    switch ($action) {
        case 'profile':
            $phone = Health::normalizePhone((string) ($_POST['phone'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            if (!$phone)                              { $errors[] = 'Enter a valid cellphone number.'; }
            if ($email !== '' && !safe_email($email)) { $errors[] = 'That email address doesn\'t look right.'; }
            if (!$errors) {
                $dup = db()->prepare('SELECT id FROM users WHERE id <> :id AND (phone = :p' . ($email !== '' ? ' OR email = :e' : '') . ') LIMIT 1');
                $dup->execute(array_filter([':id' => $pid, ':p' => $phone, ':e' => $email ?: null]));
                if ($dup->fetch()) { $errors[] = 'Another account already uses that number or email.'; }
            }
            if (!$errors) {
                db_update('users', $pid, [
                    'name' => $clean('name', 120) ?? $patient['name'], 'phone' => $phone, 'email' => $email ?: null,
                    'reminders_enabled' => !empty($_POST['reminders_enabled']) ? 1 : 0,
                    'conditions' => $clean('conditions', 1000), 'allergies' => $clean('allergies', 1000),
                    'doctor_name' => $clean('doctor_name', 120), 'emergency_name' => $clean('emergency_name', 120),
                    'emergency_phone' => Health::normalizePhone((string) ($_POST['emergency_phone'] ?? '')),
                    'patient_notes' => mb_substr(trim((string) ($_POST['patient_notes'] ?? '')), 0, 5000) ?: null,
                ]);
                flash('success', 'Patient details saved.');
                back('profile');
            }
            break;

        case 'reset_pin':
            $pin = Health::randomPin();
            db_update('users', $pid, ['pin_hash' => Health::hashPin($pin), 'pin_failed' => 0, 'pin_locked_until' => null]);
            $_SESSION['new_pin'] = ['id' => $pid, 'pin' => $pin];
            flash('success', 'New PIN created. Send it to the patient now — it won\'t be shown again.');
            back();

        case 'toggle_active':
            db_update('users', $pid, ['is_active' => $patient['is_active'] ? 0 : 1]);
            flash('success', $patient['is_active'] ? 'Account switched off — they can\'t sign in and get no reminders.' : 'Account switched back on.');
            back();

        case 'delete':
            if (($_POST['confirm'] ?? '') === 'DELETE') {
                db()->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $pid]);   // medication, doses, reports and readings cascade
                flash('success', e($patient['name']) . ' and all their health records were deleted.');
                redirect('dashboards/admin/patients.php');
            }
            $errors[] = 'Type DELETE to confirm.';
            break;

        case 'med_save':
            [$medId, $errors] = Health::saveMedication($pid, $_POST, (int) ($_POST['med_id'] ?? 0) ?: null, (int) $user['id']);
            if ($medId) {
                flash('success', 'Medication saved.');
                redirect('dashboards/admin/patient.php?id=' . $pid . '#medications');
            }
            break;

        case 'med_status':
            $mid = (int) ($_POST['med_id'] ?? 0);
            if (Health::medication($mid, $pid)) {
                if (($_POST['status'] ?? '') === 'delete') {
                    db()->prepare('DELETE FROM patient_medications WHERE id = :id')->execute([':id' => $mid]);
                    flash('success', 'Medication removed, with its dose history.');
                } else {
                    db_update('patient_medications', $mid, ['status' => in_array($_POST['status'] ?? '', ['active', 'paused', 'completed'], true) ? $_POST['status'] : 'active']);
                    flash('success', 'Medication updated.');
                }
            }
            back('medications');

        case 'add_from_enquiry':
            $s = db()->prepare('SELECT items FROM enquiries WHERE id = :id');
            $s->execute([':id' => (int) ($_POST['enquiry'] ?? 0)]);
            $n = 0;
            foreach (json_decode((string) $s->fetchColumn(), true) ?: [] as $item) {
                $p = (new ProductRepository())->getById((int) $item['product_id']);
                if ($p && ($p['category_slug'] ?? '') !== 'devices-care') {
                    $units = preg_match('/^(\d+)\s*(tablets?|capsules?|softgels?)/i', (string) $p['pack_size'], $m) ? (int) $m[1] * (int) $item['qty'] : null;
                    [$ok] = Health::saveMedication($pid, ['product_id' => $p['id'], 'name' => $p['name'], 'dosage' => '1 dose', 'dose_times' => '08:00',
                        'start_date' => date('Y-m-d'), 'units_left' => $units, 'reminders' => 1], null, (int) $user['id']);
                    $n += $ok ? 1 : 0;
                }
            }
            db()->prepare("UPDATE enquiries SET status = 'sold', user_id = :u WHERE id = :id")->execute([':u' => $pid, ':id' => (int) ($_POST['enquiry'] ?? 0)]);
            flash('success', $n . ' medication' . ($n === 1 ? '' : 's') . ' added at 08:00 — adjust doses and times below.');
            redirect('dashboards/admin/patient.php?id=' . $pid . '#medications');

        case 'dose':
            Health::logDose($pid, (int) ($_POST['med_id'] ?? 0), (string) ($_POST['scheduled_for'] ?? ''), ($_POST['status'] ?? '') === 'skipped' ? 'skipped' : 'taken', 'staff')
                ? flash('success', 'Dose logged.') : flash('danger', 'That dose could not be logged.');
            back('today');

        case 'se_reply':
            $sid = (int) ($_POST['se_id'] ?? 0);
            $reply = mb_substr(trim((string) ($_POST['staff_reply'] ?? '')), 0, 3000);
            $status = in_array($_POST['status'] ?? '', ['new', 'reviewed', 'resolved'], true) ? $_POST['status'] : 'reviewed';
            db()->prepare('UPDATE side_effects SET staff_reply = :r, status = :s, reviewed_by = :by, reviewed_at = :at WHERE id = :id AND user_id = :u')
                ->execute([':r' => $reply ?: null, ':s' => $status, ':by' => $user['id'], ':at' => date('Y-m-d H:i:s'), ':id' => $sid, ':u' => $pid]);
            if ($reply !== '' && !empty($_POST['notify']) && $patient['email']) {
                Mailer::send((string) $patient['email'], 'A reply about your side-effect report', email_wrap(
                    '<p>Hi ' . e(explode(' ', $patient['name'])[0]) . ',</p><p>Our team replied to your report:</p>'
                    . '<div style="background:#E8F0FC;border-radius:12px;padding:14px 16px;">' . nl2br(e($reply)) . '</div>'
                    . '<p><a href="' . SITE_URL . '/dashboards/customer/side-effects.php" style="color:#1557D0;font-weight:700;">Open your health tracker</a></p>'));
            }
            flash('success', 'Reply saved' . ($reply !== '' && !empty($_POST['notify']) && $patient['email'] ? ' and emailed to the patient.' : '.'));
            back('side-effects');

        case 'ai_summary':
            $err = '';
            $aiSummary = Groq::sideEffectSummary($patient, Health::medications($pid), Health::sideEffects($pid, null, 15), $err);
            if ($aiSummary === '') { $errors[] = 'AI summary failed: ' . $err; $aiSummary = null; }
            break;

        case 'test_reminder':
            $med = Health::medication((int) ($_POST['med_id'] ?? 0), $pid);
            if ($med && $patient['email']) {
                $times = Health::parseTimes((string) $med['dose_times']);
                $ok = Mailer::send((string) $patient['email'], 'Time for your ' . $med['name'] . ' (test)',
                    Health::doseEmail($med + ['patient_name' => $patient['name']], date('Y-m-d') . ' ' . ($times[0] ?? '08:00') . ':00'));
                $ok ? flash('success', 'Test reminder sent to ' . e($patient['email']) . '.') : flash('danger', 'The mail server refused the email. Check the mailbox settings in config/secrets.php.');
            } else {
                flash('danger', 'The patient needs an email address first.');
            }
            back('medications');
    }
    $patient = $load();
}

$pinReveal = ($_SESSION['new_pin']['id'] ?? 0) === $pid ? $_SESSION['new_pin']['pin'] : null;
unset($_SESSION['new_pin']);

$meds     = Health::medications($pid);
$today    = Health::daySchedule($pid);
$adh7     = Health::adherence($pid, 7);
$adh30    = Health::adherence($pid, 30);
$streak   = Health::streak($pid);
$effects  = Health::sideEffects($pid);
$readings = Health::readings($pid, 10);
$editMed  = isset($_GET['med']) ? Health::medication((int) $_GET['med'], $pid) : null;
$products = db()->query("SELECT id, name, pack_size FROM products WHERE status = 'published' ORDER BY name")->fetchAll();
$fromEnq  = (int) ($_GET['from_enquiry'] ?? 0);
$remLog   = db()->prepare('SELECT r.*, m.name FROM reminder_log r JOIN patient_medications m ON m.id = r.medication_id WHERE r.user_id = :u ORDER BY r.created_at DESC LIMIT 10');
$remLog->execute([':u' => $pid]);
$remLog   = $remLog->fetchAll();
$first    = explode(' ', $patient['name'])[0];
$mform    = $editMed ?: ['dose_times' => '08:00', 'units_per_dose' => 1, 'reminders' => 1, 'start_date' => date('Y-m-d'), 'status' => 'active'];
?>

<div class="dash-head">
  <div>
    <a href="patients.php" class="text-muted-3" style="font-size:.82rem"><i class="fa-solid fa-arrow-left me-1"></i>All patients</a>
    <h1 class="mt-1"><?= e($patient['name']) ?> <?php if (!$patient['is_active']): ?><span class="pill pill--muted" style="font-size:.8rem;vertical-align:middle">Switched off</span><?php endif; ?></h1>
    <p><?= e(Health::displayPhone($patient['phone'])) ?> · <?= $patient['email'] ? e($patient['email']) : '<i>no email — no reminders</i>' ?>
      · joined <?= date('j M Y', strtotime($patient['created_at'])) ?> · last sign-in <?= $patient['last_login_at'] ? time_ago($patient['last_login_at']) : 'never' ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= e(wa_link('Hi ' . $first . ', this is CureMeds. ', (string) $patient['phone'])) ?>" target="_blank" rel="noopener" class="btn-fc btn-fc--whatsapp btn-fc--sm"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset_pin">
      <button class="btn-fc btn-fc--ghost btn-fc--sm" onclick="return confirm('Create a new PIN? Their old PIN stops working.')"><i class="fa-solid fa-key"></i> Reset PIN</button></form>
  </div>
</div>

<?= flash_html() ?>
<?php foreach ($errors as $err): ?>
  <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div>
<?php endforeach; ?>

<?php if ($pinReveal): ?>
  <div class="card-fc card-fc--pad mb-3 d-flex flex-wrap gap-3 align-items-center" style="border-color:var(--leaf)">
    <div><div class="eyebrow">Their PIN — shown once</div><span class="pin-reveal"><?= e($pinReveal) ?></span></div>
    <div style="flex:1;min-width:240px;font-size:.88rem;color:var(--text-2)">
      Send it on WhatsApp now. After this page, nobody can see it — not even you. They sign in at
      <b><?= e(preg_replace('~^https?://~', '', SITE_URL)) ?>/modules/auth/login.php</b> with <b><?= e(Health::displayPhone($patient['phone'])) ?></b>.
    </div>
    <a class="btn-fc btn-fc--whatsapp" target="_blank" rel="noopener" href="<?= e(wa_link("Hi $first, your CureMeds health tracker is ready 💙\n\nSign in: " . SITE_URL . "/modules/auth/login.php\nNumber: " . Health::displayPhone($patient['phone']) . "\nPIN: $pinReveal\n\nYou can change your PIN under My details.", (string) $patient['phone'])) ?>">
      <i class="fa-brands fa-whatsapp"></i> Send PIN on WhatsApp</a>
  </div>
<?php endif; ?>

<?php if (!empty($patient['pin_locked_until']) && strtotime($patient['pin_locked_until']) > time()): ?>
  <div class="alert-fc alert-fc--warn"><i class="fa-solid fa-lock"></i><span>Locked after too many wrong PINs until <?= date('H:i', strtotime($patient['pin_locked_until'])) ?>. Reset the PIN to unlock now.</span></div>
<?php endif; ?>

<?php if ($fromEnq): ?>
  <form method="post" class="alert-fc alert-fc--info"><?= csrf_field() ?>
    <i class="fa-brands fa-whatsapp"></i>
    <span>Add the products from enquiry E<?= $fromEnq ?> to <?= e($first) ?>'s medication?
      <input type="hidden" name="action" value="add_from_enquiry"><input type="hidden" name="enquiry" value="<?= $fromEnq ?>">
      <button class="btn-fc btn-fc--sm ms-2">Add them</button></span>
  </form>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat"><span class="stat__icon stat__icon--leaf"><i class="fa-solid fa-chart-pie"></i></span>
    <span><b><?= $adh7['pct'] === null ? '—' : $adh7['pct'] . '%' ?></b><span>Adherence, 7 days</span>
      <div class="delta"><?= $adh7['taken'] ?> of <?= $adh7['total'] ?> doses</div></span></div>
  <div class="stat"><span class="stat__icon stat__icon--ink"><i class="fa-solid fa-calendar"></i></span>
    <span><b><?= $adh30['pct'] === null ? '—' : $adh30['pct'] . '%' ?></b><span>Adherence, 30 days</span></span></div>
  <div class="stat"><span class="stat__icon stat__icon--amber"><i class="fa-solid fa-fire"></i></span>
    <span><b><?= $streak ?></b><span>Day streak</span></span></div>
  <div class="stat"><span class="stat__icon stat__icon--coral"><i class="fa-solid fa-notes-medical"></i></span>
    <span><b><?= count(array_filter($effects, fn($s) => $s['status'] !== 'resolved')) ?></b><span>Open side effects</span></span></div>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <!-- Today -->
    <div class="card-fc card-fc--pad mb-3" id="today">
      <h2 style="font-size:1.05rem">Today's doses</h2>
      <?php if (!$today): ?><p class="text-muted-2 mb-0">Nothing scheduled today.</p><?php endif; ?>
      <div class="dose-list">
      <?php foreach ($today as $d): $m = $d['medication']; ?>
        <div class="dose dose--<?= e($d['state']) ?>">
          <div class="dose__time"><?= e($d['time']) ?><small><?= ucfirst($d['state']) ?></small></div>
          <div class="dose__body"><b><?= e($m['name']) ?></b><span><?= e((string) $m['dosage']) ?></span></div>
          <?php if ($d['state'] !== 'taken'): ?>
          <form method="post" class="dose__actions"><?= csrf_field() ?>
            <input type="hidden" name="action" value="dose"><input type="hidden" name="med_id" value="<?= (int) $m['id'] ?>">
            <input type="hidden" name="scheduled_for" value="<?= e($d['scheduled_for']) ?>">
            <button class="btn-fc btn-fc--ghost btn-fc--sm" name="status" value="taken" title="Patient confirmed by phone/WhatsApp">Mark taken</button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    </div>

    <!-- Medication -->
    <div class="card-fc card-fc--pad mb-3" id="medications">
      <h2 style="font-size:1.05rem">Medication</h2>
      <?php if (!$meds): ?><p class="text-muted-2">No medication yet. Add what they bought below.</p><?php endif; ?>
      <?php foreach ($meds as $m): $left = Health::daysLeft($m); ?>
        <div class="d-flex flex-wrap gap-2 align-items-center py-2" style="border-top:1px solid var(--line)">
          <div style="flex:1;min-width:220px">
            <b><?= e($m['name']) ?></b> <span class="pill pill--<?= ['active' => 'ok', 'paused' => 'warn', 'completed' => 'muted'][$m['status']] ?>"><?= $m['status'] ?></span>
            <div class="text-muted-2" style="font-size:.82rem"><?= e(trim($m['dosage'] . ' · ' . str_replace(',', ', ', $m['dose_times']) . ($m['instructions'] ? ' · ' . $m['instructions'] : ''), ' ·')) ?>
              <?= $left !== null ? ' · ' . (int) $m['units_left'] . ' left (~' . $left . ' days)' : '' ?><?= !$m['reminders'] ? ' · <i>no reminders</i>' : '' ?></div>
          </div>
          <a href="?id=<?= $pid ?>&med=<?= (int) $m['id'] ?>#medform" class="btn-fc btn-fc--ghost btn-fc--sm">Edit</a>
          <form method="post" class="d-flex gap-1"><?= csrf_field() ?>
            <input type="hidden" name="action" value="test_reminder"><input type="hidden" name="med_id" value="<?= (int) $m['id'] ?>">
            <button class="btn-fc btn-fc--ghost btn-fc--sm" title="Email a sample reminder now"><i class="fa-solid fa-envelope"></i> Test</button>
          </form>
          <form method="post" class="d-flex gap-1"><?= csrf_field() ?>
            <input type="hidden" name="action" value="med_status"><input type="hidden" name="med_id" value="<?= (int) $m['id'] ?>">
            <?php if ($m['status'] === 'active'): ?>
              <button class="btn-fc btn-fc--ghost btn-fc--sm" name="status" value="paused">Pause</button>
            <?php else: ?>
              <button class="btn-fc btn-fc--ghost btn-fc--sm" name="status" value="active">Resume</button>
            <?php endif; ?>
            <button class="btn-fc btn-fc--ghost btn-fc--sm" name="status" value="delete" onclick="return confirm('Remove this medication and its dose history?')" aria-label="Remove"><i class="fa-solid fa-trash-can"></i></button>
          </form>
        </div>
      <?php endforeach; ?>

      <form method="post" id="medform" class="mt-3 pt-3" style="border-top:1px dashed var(--line-strong)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="med_save">
        <?php if (!empty($mform['id'])): ?><input type="hidden" name="med_id" value="<?= (int) $mform['id'] ?>"><?php endif; ?>
        <b style="font-family:var(--font-display)"><?= !empty($mform['id']) ? 'Edit ' . e($mform['name']) : 'Add medication' ?></b>
        <div class="row g-2 mt-1">
          <div class="col-md-6 field"><label>Product</label>
            <select class="input-fc" name="product_id"><option value="">Not a CureMeds product</option>
              <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($mform['product_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-6 field"><label>Name (if not a product)</label><input class="input-fc" name="name" value="<?= e((string) ($mform['name'] ?? '')) ?>"></div>
          <div class="col-md-4 field"><label>Dose</label><input class="input-fc" name="dosage" value="<?= e((string) ($mform['dosage'] ?? '')) ?>" placeholder="1 tablet"></div>
          <div class="col-md-4 field"><label>Times (24h, comma-separated)</label><input class="input-fc" name="dose_times" value="<?= e((string) $mform['dose_times']) ?>" placeholder="08:00, 20:00" required></div>
          <div class="col-md-4 field"><label>Notes</label><input class="input-fc" name="instructions" value="<?= e((string) ($mform['instructions'] ?? '')) ?>" placeholder="With food"></div>
          <div class="col-md-3 field"><label>Start</label><input class="input-fc" type="date" name="start_date" value="<?= e((string) $mform['start_date']) ?>"></div>
          <div class="col-md-3 field"><label>End</label><input class="input-fc" type="date" name="end_date" value="<?= e((string) ($mform['end_date'] ?? '')) ?>"></div>
          <div class="col-md-3 field"><label>Units left</label><input class="input-fc" type="number" min="0" name="units_left" value="<?= e((string) ($mform['units_left'] ?? '')) ?>"></div>
          <div class="col-md-3 field"><label>Per dose</label><input class="input-fc" type="number" min="0.25" step="0.25" name="units_per_dose" value="<?= e((string) $mform['units_per_dose']) ?>"></div>
        </div>
        <input type="hidden" name="status" value="<?= e((string) $mform['status']) ?>">
        <label class="d-flex gap-2 align-items-center mb-2" style="font-size:.88rem"><input type="checkbox" name="reminders" value="1" <?= !empty($mform['reminders']) ? 'checked' : '' ?>> Email reminders at each time</label>
        <button class="btn-fc btn-fc--sm"><?= !empty($mform['id']) ? 'Save changes' : 'Add medication' ?></button>
        <?php if (!empty($mform['id'])): ?><a href="?id=<?= $pid ?>#medications" class="btn-fc btn-fc--light btn-fc--sm">Cancel</a><?php endif; ?>
      </form>
    </div>

    <!-- Side effects -->
    <div class="card-fc card-fc--pad mb-3" id="side-effects">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 style="font-size:1.05rem;margin:0">Side effects</h2>
        <?php if ($effects): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ai_summary">
          <button class="btn-fc btn-fc--ghost btn-fc--sm" <?= Groq::ready() ? '' : 'disabled title="Add a Groq key in AI & social media"' ?>><i class="fa-solid fa-wand-magic-sparkles"></i> AI summary</button></form>
        <?php endif; ?>
      </div>
      <?php if ($aiSummary): ?>
        <div class="ai-box mb-3"><b>AI summary for staff — not a diagnosis</b>
<?= e($aiSummary) ?></div>
      <?php endif; ?>
      <?php if (!$effects): ?><p class="text-muted-2 mb-0">No reports.</p><?php endif; ?>
      <?php foreach ($effects as $s): ?>
        <div class="timeline-item is-<?= e($s['severity']) ?>">
          <div class="d-flex justify-content-between flex-wrap gap-2">
            <b><?= e($s['symptom']) ?></b>
            <span class="d-flex gap-1"><span class="pill pill--<?= Health::SEVERITIES[$s['severity']]['tone'] ?>"><?= ucfirst($s['severity']) ?></span>
              <span class="pill pill--<?= ['new' => 'warn', 'reviewed' => 'info', 'resolved' => 'ok'][$s['status']] ?>"><?= ucfirst($s['status']) ?></span></span>
          </div>
          <div class="text-muted-3" style="font-size:.78rem"><?= date('j M Y, H:i', strtotime($s['created_at'])) ?><?= $s['medication_name'] ? ' · ' . e($s['medication_name']) : '' ?><?= $s['started_on'] ? ' · started ' . date('j M', strtotime($s['started_on'])) : '' ?></div>
          <?php if ($s['details']): ?><p style="font-size:.86rem;margin:.35rem 0 0"><?= nl2br(e($s['details'])) ?></p><?php endif; ?>
          <form method="post" class="mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="se_reply"><input type="hidden" name="se_id" value="<?= (int) $s['id'] ?>">
            <textarea class="input-fc" name="staff_reply" rows="2" placeholder="Reply to <?= e($first) ?> (they see this in their tracker)"><?= e((string) $s['staff_reply']) ?></textarea>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
              <select class="input-fc" name="status" style="max-width:150px">
                <?php foreach (['reviewed' => 'Reviewed', 'resolved' => 'Resolved', 'new' => 'New'] as $k => $l): ?><option value="<?= $k ?>" <?= $s['status'] === $k || ($s['status'] === 'new' && $k === 'reviewed') ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
              </select>
              <?php if ($patient['email']): ?><label style="font-size:.84rem"><input type="checkbox" name="notify" value="1" checked> Email them</label><?php endif; ?>
              <button class="btn-fc btn-fc--sm">Save</button>
              <?php if ($s['reviewer_name']): ?><span class="text-muted-3" style="font-size:.76rem">Last by <?= e($s['reviewer_name']) ?>, <?= time_ago((string) $s['reviewed_at']) ?></span><?php endif; ?>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="col-xl-5">
    <!-- Profile -->
    <form method="post" class="card-fc card-fc--pad mb-3" id="profile">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile">
      <h2 style="font-size:1.05rem">Profile</h2>
      <div class="row g-2">
        <div class="col-12 field"><label>Name</label><input class="input-fc" name="name" value="<?= e($patient['name']) ?>"></div>
        <div class="col-6 field"><label>Cellphone</label><input class="input-fc" name="phone" value="<?= e(Health::displayPhone($patient['phone'])) ?>"></div>
        <div class="col-6 field"><label>Email</label><input class="input-fc" type="email" name="email" value="<?= e((string) $patient['email']) ?>"></div>
        <div class="col-12 field"><label>Managing</label><input class="input-fc" name="conditions" value="<?= e((string) $patient['conditions']) ?>"></div>
        <div class="col-12 field"><label>Allergies</label><input class="input-fc" name="allergies" value="<?= e((string) $patient['allergies']) ?>"></div>
        <div class="col-12 field"><label>Doctor / clinic</label><input class="input-fc" name="doctor_name" value="<?= e((string) $patient['doctor_name']) ?>"></div>
        <div class="col-6 field"><label>Emergency contact</label><input class="input-fc" name="emergency_name" value="<?= e((string) $patient['emergency_name']) ?>"></div>
        <div class="col-6 field"><label>Their number</label><input class="input-fc" name="emergency_phone" value="<?= e(Health::displayPhone($patient['emergency_phone'])) ?>"></div>
      </div>
      <?php if ($patient['date_of_birth']): ?><p class="text-muted-2" style="font-size:.84rem">Born <?= date('j M Y', strtotime($patient['date_of_birth'])) ?> (<?= (int) date_diff(date_create($patient['date_of_birth']), date_create())->y ?>)<?= $patient['gender'] ? ' · ' . e(ucfirst($patient['gender'])) : '' ?></p><?php endif; ?>
      <div class="field"><label>Staff notes <span class="text-muted-3 fw-normal">(never shown to the patient)</span></label>
        <textarea class="input-fc" name="patient_notes" rows="4"><?= e((string) $patient['patient_notes']) ?></textarea></div>
      <label class="d-flex gap-2 align-items-center mb-3" style="font-size:.88rem"><input type="checkbox" name="reminders_enabled" value="1" <?= $patient['reminders_enabled'] ? 'checked' : '' ?>> Send dose and refill reminders</label>
      <button class="btn-fc btn-fc--sm"><i class="fa-solid fa-floppy-disk"></i> Save profile</button>
    </form>

    <!-- Readings -->
    <div class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1.05rem">Recent readings</h2>
      <?php if (!$readings): ?><p class="text-muted-2 mb-0">None logged yet.</p><?php else: ?>
      <table class="table-fc"><thead><tr><th>Date</th><th>BP</th><th>Kg</th><th>Sugar</th><th>Mood</th></tr></thead><tbody>
        <?php foreach ($readings as $r): ?>
          <tr><td><?= date('j M', strtotime($r['recorded_on'])) ?></td>
            <td style="<?= (int) $r['systolic'] >= 140 || (int) $r['diastolic'] >= 90 ? 'color:var(--danger);font-weight:700' : '' ?>"><?= $r['systolic'] ? (int) $r['systolic'] . '/' . (int) $r['diastolic'] : '—' ?></td>
            <td><?= $r['weight_kg'] ? (float) $r['weight_kg'] : '—' ?></td><td><?= $r['glucose'] ? (float) $r['glucose'] : '—' ?></td><td><?= $r['mood'] ? (int) $r['mood'] . '/5' : '—' ?></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </div>

    <!-- Reminders -->
    <div class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1.05rem">Reminder emails</h2>
      <?php if (!$remLog): ?><p class="text-muted-2 mb-0" style="font-size:.88rem"><?= $patient['email'] ? 'None sent yet.' : 'Add an email address to start reminders.' ?></p><?php endif; ?>
      <?php foreach ($remLog as $r): ?>
        <div class="d-flex justify-content-between py-1" style="font-size:.84rem;border-top:1px solid var(--line)">
          <span><?= $r['kind'] === 'refill' ? '<i class="fa-solid fa-box-open me-1"></i>' : '' ?><?= e($r['name']) ?> · <?= date('j M H:i', strtotime($r['scheduled_for'])) ?></span>
          <span class="pill pill--<?= $r['status'] === 'sent' ? 'ok' : 'danger' ?>"><?= $r['status'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Account -->
    <div class="card-fc card-fc--pad">
      <h2 style="font-size:1.05rem">Account</h2>
      <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_active">
        <button class="btn-fc btn-fc--ghost btn-fc--sm"><?= $patient['is_active'] ? '<i class="fa-solid fa-ban"></i> Switch account off' : '<i class="fa-solid fa-check"></i> Switch account on' ?></button></form>
      <form method="post" class="d-flex gap-2 align-items-center flex-wrap"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <input class="input-fc" name="confirm" placeholder="Type DELETE" style="max-width:150px">
        <button class="btn-fc btn-fc--danger btn-fc--sm"><i class="fa-solid fa-trash-can"></i> Delete patient &amp; records</button></form>
      <p class="field__hint mt-2 mb-0">Deleting removes their medication, dose history, side-effect reports and readings for good (a POPIA deletion request).</p>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
