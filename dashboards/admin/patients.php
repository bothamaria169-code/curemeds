<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'patients';
$page_title  = 'Patients — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

/** "60 tablets" bought twice -> 120 units to count down. Null when the pack isn't countable. */
function units_in_pack(?string $pack, int $qty): ?int
{
    return preg_match('/^(\d+)\s*(tablets?|capsules?|softgels?|caps|tabs|sachets?)/i', trim((string) $pack), $m) ? (int) $m[1] * $qty : null;
}

$enquiry = null;
if (!empty($_REQUEST['enquiry'])) {
    $s = db()->prepare('SELECT * FROM enquiries WHERE id = :id');
    $s->execute([':id' => (int) $_REQUEST['enquiry']]);
    $enquiry = $s->fetch() ?: null;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $name  = trim(strip_tags((string) ($_POST['name'] ?? '')));
    $phone = Health::normalizePhone((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    if (mb_strlen($name) < 2)                   { $errors[] = 'Enter the patient\'s name.'; }
    if (!$phone)                                { $errors[] = 'Enter a valid South African cellphone number.'; }
    if ($email !== '' && !safe_email($email))   { $errors[] = 'That email address doesn\'t look right.'; }
    if (!$errors) {
        $dup = db()->prepare('SELECT id, name FROM users WHERE phone = :p' . ($email !== '' ? ' OR email = :e' : '') . ' LIMIT 1');
        $dup->execute(array_filter([':p' => $phone, ':e' => $email ?: null]));
        if ($row = $dup->fetch()) {
            $errors[] = 'That number or email already belongs to <a href="patient.php?id=' . (int) $row['id'] . '" class="fw-bold">' . e($row['name']) . '</a>.';
        }
    }
    if (!$errors) {
        $pin = Health::randomPin();
        // All or nothing: a failure must never leave a patient without their medication.
        db()->beginTransaction();
        $id  = db_insert('users', [
            'name'     => mb_substr($name, 0, 120),
            'phone'    => $phone,
            'email'    => $email !== '' ? $email : null,
            'pin_hash' => Health::hashPin($pin),
            'role'     => 'customer',
            'conditions' => mb_substr(trim(strip_tags((string) ($_POST['conditions'] ?? ''))), 0, 1000) ?: null,
        ]);

        // Products from the enquiry become medications; staff fine-tune times and doses next.
        $added = 0;
        if ($enquiry) {
            foreach (json_decode((string) $enquiry['items'], true) ?: [] as $item) {
                $product = (new ProductRepository())->getById((int) $item['product_id']);
                if (!$product || ($product['category_slug'] ?? '') === 'devices-care') {
                    continue; // monitors and pill boxes aren't taken
                }
                [$medId] = Health::saveMedication($id, [
                    'product_id'   => $product['id'],
                    'name'         => $product['name'],
                    'dosage'       => '1 ' . (preg_match('/capsule/i', (string) $product['pack_size']) ? 'capsule' : (preg_match('/tablet/i', (string) $product['pack_size']) ? 'tablet' : 'dose')),
                    'dose_times'   => '08:00',
                    'start_date'   => date('Y-m-d'),
                    'units_left'   => units_in_pack($product['pack_size'], (int) $item['qty']),
                    'reminders'    => 1,
                ], null, (int) $user['id']);
                $added += $medId ? 1 : 0;
            }
            db_update('enquiries', (int) $enquiry['id'], ['status' => 'sold', 'user_id' => $id, 'phone' => $phone, 'name' => $name]);
        }
        db()->commit();

        $_SESSION['new_pin'] = ['id' => $id, 'pin' => $pin];
        flash('success', 'Patient created' . ($added ? ' with ' . $added . ' medication' . ($added === 1 ? '' : 's') . ' from enquiry E' . (int) $enquiry['id'] . ' — check the dose times below' : '') . '.');
        redirect('dashboards/admin/patient.php?id=' . $id);
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$where = "u.role = 'customer'";
$params = [];
if ($q !== '') {
    $digits = preg_replace('/\D/', '', $q);
    $where .= ' AND (u.name LIKE :q1 OR u.email LIKE :q2' . ($digits !== '' ? ' OR u.phone LIKE :q3' : '') . ')';
    $params[':q1'] = $params[':q2'] = "%$q%";
    if ($digits !== '') { $params[':q3'] = '%' . ltrim($digits, '0') . '%'; }
}
$stmt = db()->prepare("SELECT u.id, u.name, u.phone, u.email, u.last_login_at, u.created_at, u.is_active, u.pin_hash IS NOT NULL AS has_pin,
                         (SELECT COUNT(*) FROM patient_medications m WHERE m.user_id = u.id AND m.status = 'active') AS meds,
                         (SELECT COUNT(*) FROM side_effects s WHERE s.user_id = u.id AND s.status = 'new') AS new_effects,
                         (SELECT MAX(FIELD(s.severity, 'mild', 'moderate', 'severe')) FROM side_effects s WHERE s.user_id = u.id AND s.status <> 'resolved') AS worst
                       FROM users u WHERE $where ORDER BY new_effects DESC, u.created_at DESC LIMIT 300");
$stmt->execute($params);
$patients = $stmt->fetchAll();
$showForm = isset($_GET['new']) || $errors || $enquiry;
$pre = $enquiry ? ['name' => $enquiry['name'], 'phone' => Health::displayPhone($enquiry['phone'])] : [];
?>

<div class="dash-head">
  <div><h1>Patients</h1><p>Everyone with a health tracker. Open a patient to manage their medication, PIN, side effects and readings.</p></div>
  <a href="?new=1#new" class="btn-fc btn-fc--sm"><i class="fa-solid fa-user-plus"></i> New patient</a>
</div>

<?= flash_html() ?>
<?php foreach ($errors as $err): ?>
  <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= $err ?></span></div>
<?php endforeach; ?>

<?php if ($showForm): ?>
<form class="card-fc card-fc--pad mb-3" method="post" id="new">
  <?= csrf_field() ?>
  <?php if ($enquiry): ?><input type="hidden" name="enquiry" value="<?= (int) $enquiry['id'] ?>"><?php endif; ?>
  <h2 style="font-size:1.1rem">New patient<?= $enquiry ? ' from enquiry E' . (int) $enquiry['id'] : '' ?></h2>
  <?php if ($enquiry): ?>
    <p class="text-muted-2" style="font-size:.86rem">These products will be added as medication (dose time 08:00 to start — adjust after):
      <b><?= e(implode(', ', array_map(fn($i) => $i['qty'] . '× ' . $i['name'], json_decode((string) $enquiry['items'], true) ?: []))) ?></b></p>
  <?php endif; ?>
  <div class="row g-2">
    <div class="col-md-3 field"><label>Name</label><input class="input-fc" name="name" required value="<?= e((string) ($_POST['name'] ?? $pre['name'] ?? '')) ?>"></div>
    <div class="col-md-3 field"><label>Cellphone (their sign-in)</label><input class="input-fc" name="phone" required placeholder="082 123 4567" value="<?= e((string) ($_POST['phone'] ?? $pre['phone'] ?? '')) ?>"></div>
    <div class="col-md-3 field"><label>Email (for reminders)</label><input class="input-fc" type="email" name="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>"></div>
    <div class="col-md-3 field"><label>Managing</label><input class="input-fc" name="conditions" placeholder="Optional" value="<?= e((string) ($_POST['conditions'] ?? '')) ?>"></div>
  </div>
  <p class="field__hint">A 4-digit PIN is created for them and shown once on the next page, with a button to WhatsApp it to them. Only add a patient who has agreed to us keeping their health information.</p>
  <button class="btn-fc" type="submit"><i class="fa-solid fa-user-plus"></i> Create patient</button>
</form>
<?php endif; ?>

<div class="card-fc">
  <form class="d-flex gap-2 p-3" method="get">
    <input class="input-fc" name="q" value="<?= e($q) ?>" placeholder="Search by name, cellphone or email" style="max-width:360px">
    <button class="btn-fc btn-fc--ghost btn-fc--sm">Search</button>
    <span class="ms-auto text-muted-3 align-self-center" style="font-size:.82rem"><?= count($patients) ?> patient<?= count($patients) === 1 ? '' : 's' ?></span>
  </form>
  <?php if (!$patients): ?>
    <div class="empty"><div class="empty__icon"><i class="fa-solid fa-hospital-user"></i></div><h3>No patients<?= $q ? ' match' : ' yet' ?></h3></div>
  <?php else: ?>
  <div style="overflow:auto"><table class="table-fc">
    <thead><tr><th>Patient</th><th>Cellphone</th><th>Medication</th><th>7-day adherence</th><th>Side effects</th><th>Last sign-in</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($patients as $p):
      $adh = (int) $p['meds'] ? Health::adherence((int) $p['id'], 7) : ['pct' => null]; ?>
      <tr>
        <td><a href="patient.php?id=<?= (int) $p['id'] ?>" class="fw-bold"><?= e($p['name']) ?></a>
          <?php if (!$p['is_active']): ?><span class="pill pill--muted ms-1">Off</span><?php endif; ?>
          <div class="text-muted-3" style="font-size:.75rem"><?= e((string) $p['email']) ?: '<i>no email — no reminders</i>' ?></div></td>
        <td style="white-space:nowrap"><?= e(Health::displayPhone($p['phone'])) ?></td>
        <td><?= (int) $p['meds'] ?> active</td>
        <td><?php if ($adh['pct'] === null): ?><span class="text-muted-3">—</span>
            <?php else: ?><span class="pill pill--<?= $adh['pct'] >= 80 ? 'ok' : ($adh['pct'] >= 50 ? 'warn' : 'danger') ?>"><?= $adh['pct'] ?>%</span><?php endif; ?></td>
        <td><?php if ((int) $p['new_effects']): ?>
              <span class="pill pill--<?= (int) $p['worst'] === 3 ? 'danger' : 'warn' ?>"><?= (int) $p['new_effects'] ?> new</span>
            <?php else: ?><span class="text-muted-3">—</span><?php endif; ?></td>
        <td style="font-size:.84rem"><?= $p['last_login_at'] ? time_ago($p['last_login_at']) : '<span class="text-muted-3">Never</span>' ?></td>
        <td><a href="patient.php?id=<?= (int) $p['id'] ?>" class="btn-fc btn-fc--ghost btn-fc--sm">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
