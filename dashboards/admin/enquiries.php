<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'enquiries';
$page_title  = 'Enquiries — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

const ENQUIRY_STATUSES = ['new' => 'warn', 'contacted' => 'info', 'sold' => 'ok', 'closed' => 'muted'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM enquiries WHERE id = :id')->execute([':id' => $id]);
        flash('success', 'Enquiry E' . $id . ' deleted.');
        redirect('dashboards/admin/enquiries.php');
    }
    if (($_POST['action'] ?? '') === 'clear_anonymous') {
        $n = db()->exec("DELETE FROM enquiries WHERE status = 'new' AND (name IS NULL OR name = '') AND (phone IS NULL OR phone = '') AND (email IS NULL OR email = '')");
        flash('success', $n . ' button click' . ($n === 1 ? '' : 's') . ' without a name, number or email deleted.');
        redirect('dashboards/admin/enquiries.php');
    }
    if (($_POST['action'] ?? '') === 'email_payment') {
        $row = db()->prepare('SELECT * FROM enquiries WHERE id = :id');
        $row->execute([':id' => $id]);
        $enq = $row->fetch();
        if ($enq && $enq['email'] && send_template_email((string) $enq['email'], 'enquiry-payment', enquiry_email_vars($enq))) {
            db_update('enquiries', $id, ['emailed_at' => date('Y-m-d H:i:s')]);
            flash('success', 'Payment details emailed to ' . $enq['email'] . '.');
        } else {
            flash('danger', 'Could not send: add an email address first, or check the mailbox under Reminders → Send a test email.');
        }
        back('detail');
    }
    $status = array_key_exists($_POST['status'] ?? '', ENQUIRY_STATUSES) ? $_POST['status'] : 'new';
    db_update('enquiries', $id, [
        'status'      => $status,
        'admin_notes' => mb_substr(trim((string) ($_POST['admin_notes'] ?? '')), 0, 2000) ?: null,
        'phone'       => Health::normalizePhone((string) ($_POST['phone'] ?? '')) ?: null,
        'name'        => mb_substr(trim(strip_tags((string) ($_POST['name'] ?? ''))), 0, 120) ?: null,
        'email'       => filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null,
    ]);
    // Count a sale against the products, so best-seller rankings stay honest.
    if ($status === 'sold' && ($_POST['was'] ?? '') !== 'sold') {
        $row = db()->prepare('SELECT items FROM enquiries WHERE id = :id');
        $row->execute([':id' => $id]);
        $buyer = trim((string) ($_POST['name'] ?? ''));
        foreach (json_decode((string) $row->fetchColumn(), true) ?: [] as $n => $item) {
            db()->prepare('UPDATE products SET sold_count = sold_count + :q, stock_qty = GREATEST(0, stock_qty - :q2) WHERE id = :p')
                ->execute([':q' => (int) $item['qty'], ':q2' => (int) $item['qty'], ':p' => (int) $item['product_id']]);
            // A real sale also goes into the live ticker on the site (Admin → Live ticker can hide it).
            if ($n === 0 && $buyer !== '' && !empty($_POST['to_ticker'])) {
                db_insert('purchase_feed', ['customer' => ticker_name($buyer), 'town' => clean_text((string) ($_POST['town'] ?? '')) ?: null,
                    'product_id' => (int) $item['product_id'], 'product_name' => (string) $item['name'], 'enquiry_id' => $id]);
            }
        }
    }
    flash('success', 'Enquiry E' . $id . ' saved.' . ($status === 'sold' ? ' Now set them up as a patient so their reminders start.' : ''));
    back();
}

$status = array_key_exists($_GET['status'] ?? '', ENQUIRY_STATUSES) ? $_GET['status'] : '';
$q      = trim((string) ($_GET['q'] ?? ''));
$where  = ['1=1'];
$params = [];
if ($status !== '') { $where[] = 'status = :st'; $params[':st'] = $status; }
if ($q !== '')      { $where[] = '(name LIKE :q1 OR phone LIKE :q2 OR items LIKE :q3)'; $params[':q1'] = $params[':q2'] = $params[':q3'] = "%$q%"; }
$stmt = db()->prepare('SELECT * FROM enquiries WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 200');
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = [];
foreach (db()->query('SELECT status, COUNT(*) n FROM enquiries GROUP BY status') as $r) { $counts[$r['status']] = (int) $r['n']; }

$open = isset($_GET['id']) ? (function () {
    $s = db()->prepare('SELECT * FROM enquiries WHERE id = :id');
    $s->execute([':id' => (int) $_GET['id']]);
    return $s->fetch() ?: null;
})() : null;

/** The patient account matching this enquiry's phone, if any. */
$patientFor = function (?string $phone): ?array {
    if (!$phone) { return null; }
    $s = db()->prepare('SELECT id, name FROM users WHERE phone = :p');
    $s->execute([':p' => $phone]);
    return $s->fetch() ?: null;
};
?>

<div class="dash-head">
  <div><h1>Enquiries</h1><p>Every "Enquire on WhatsApp" press, saved before the customer reaches WhatsApp — so no lead is lost. A press with no name, number or email is just a click: it may never have become a message.</p></div>
  <form method="post"><?= csrf_field() ?>
    <button class="btn-fc btn-fc--ghost btn-fc--sm" name="action" value="clear_anonymous" onclick="return confirm('Delete every new enquiry that has no name, cellphone or email?')"><i class="fa-solid fa-broom"></i> Clear anonymous clicks</button>
  </form>
</div>

<?= flash_html() ?>

<?php if ($open): $items = json_decode((string) $open['items'], true) ?: []; $patient = $patientFor($open['phone']); ?>
<div class="card-fc card-fc--pad mb-3" id="detail">
  <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h2 style="font-size:1.2rem;margin:0">Enquiry E<?= (int) $open['id'] ?></h2>
      <span class="text-muted-3" style="font-size:.82rem"><?= date('j M Y, H:i', strtotime($open['created_at'])) ?> · from the <?= $open['source'] === 'list' ? 'enquiry list' : 'product page' ?></span>
    </div>
    <a href="enquiries.php" class="btn-fc btn-fc--ghost btn-fc--sm"><i class="fa-solid fa-xmark"></i> Close</a>
  </div>
  <div class="row g-3">
    <div class="col-lg-6">
      <table class="table-fc">
        <thead><tr><th>Product</th><th>Qty</th><th class="text-end">Price</th></tr></thead>
        <tbody>
        <?php foreach ($items as $i): ?>
          <tr><td><?= e($i['name']) ?><?= !empty($i['pack']) ? '<div class="text-muted-3" style="font-size:.75rem">' . e($i['pack']) . '</div>' : '' ?></td>
              <td><?= (int) $i['qty'] ?></td><td class="text-end money"><?= format_price_short((float) $i['price'] * (int) $i['qty']) ?></td></tr>
        <?php endforeach; ?>
          <tr><td colspan="2"><b>Total quoted</b></td><td class="text-end money"><b><?= format_price_short((float) $open['total']) ?></b></td></tr>
        </tbody>
      </table>
      <div class="d-flex gap-2 flex-wrap mt-3">
        <?php if ($open['phone']): ?>
          <a class="btn-fc btn-fc--whatsapp btn-fc--sm" target="_blank" rel="noopener"
             href="<?= e(wa_link('Hi ' . ($open['name'] ? explode(' ', $open['name'])[0] : '') . ', thanks for your CureMeds enquiry (E' . $open['id'] . '). ', (string) $open['phone'])) ?>">
            <i class="fa-brands fa-whatsapp"></i> WhatsApp <?= e(Health::displayPhone($open['phone'])) ?></a>
          <a class="btn-fc btn-fc--ghost btn-fc--sm" target="_blank" rel="noopener"
             href="<?= e(wa_link('Hi ' . ($open['name'] ? explode(' ', $open['name'])[0] : '') . ", here is how to pay for order E" . $open['id'] . ' (' . format_price_short((float) $open['total']) . "):\n\n" . payment_details_text(), (string) $open['phone'])) ?>">
            <i class="fa-solid fa-building-columns"></i> WhatsApp payment details</a>
        <?php endif; ?>
        <?php if (!empty($open['email'])): ?>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $open['id'] ?>">
            <button class="btn-fc btn-fc--ghost btn-fc--sm" name="action" value="email_payment"><i class="fa-solid fa-envelope"></i> <?= $open['emailed_at'] ? 'Email payment details again' : 'Email payment details' ?></button></form>
        <?php endif; ?>
        <?php if ($patient): ?>
          <a href="patient.php?id=<?= (int) $patient['id'] ?>&from_enquiry=<?= (int) $open['id'] ?>#medications" class="btn-fc btn-fc--sm"><i class="fa-solid fa-hospital-user"></i> Open <?= e($patient['name']) ?> &amp; add these products</a>
        <?php else: ?>
          <a href="patients.php?new=1&enquiry=<?= (int) $open['id'] ?>#new" class="btn-fc btn-fc--sm"><i class="fa-solid fa-user-plus"></i> Set up as a patient</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $open['id'] ?>">
        <input type="hidden" name="was" value="<?= e($open['status']) ?>">
        <div class="row g-2">
          <div class="col-6 field"><label>Name</label><input class="input-fc" name="name" value="<?= e((string) $open['name']) ?>"></div>
          <div class="col-6 field"><label>Cellphone</label><input class="input-fc" name="phone" value="<?= e(Health::displayPhone($open['phone'])) ?>" placeholder="Add once they message you"></div>
          <div class="col-6 field"><label>Email</label><input class="input-fc" type="email" name="email" value="<?= e((string) ($open['email'] ?? '')) ?>" placeholder="For the payment email"></div>
          <div class="col-6 field"><label>Paying by</label><input class="input-fc" value="<?= e(payment_methods()[$open['payment_method'] ?? '']['label'] ?? 'Not chosen yet') ?>" disabled></div>
        </div>
        <div class="field"><label>Status</label>
          <select class="input-fc" name="status">
            <?php foreach (ENQUIRY_STATUSES as $s => $tone): ?><option value="<?= $s ?>" <?= $open['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
          </select>
          <div class="field__hint">Marking it <b>Sold</b> counts the units against stock and best-sellers.</div>
        </div>
        <?php if ($open['status'] !== 'sold'): ?>
        <div class="row g-2 align-items-end">
          <div class="col-7 field"><label style="font-weight:500;font-size:.88rem"><input type="checkbox" name="to_ticker" value="1" checked> When sold, show it in the live ticker</label></div>
          <div class="col-5 field"><input class="input-fc" name="town" placeholder="Their town (optional)"></div>
        </div>
        <?php endif; ?>
        <div class="field"><label>Notes</label><textarea class="input-fc" name="admin_notes" rows="3" placeholder="Paid? Courier reference? Follow-up date?"><?= e((string) $open['admin_notes']) ?></textarea></div>
        <div class="d-flex gap-2">
          <button class="btn-fc btn-fc--sm" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
          <button class="btn-fc btn-fc--ghost btn-fc--sm" type="submit" name="action" value="delete" onclick="return confirm('Delete this enquiry?')"><i class="fa-solid fa-trash-can"></i> Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card-fc">
  <form class="d-flex flex-wrap gap-2 p-3 align-items-center" method="get">
    <a href="enquiries.php" class="chip <?= $status === '' ? 'is-on' : '' ?>">All <?= array_sum($counts) ?></a>
    <?php foreach (ENQUIRY_STATUSES as $s => $tone): ?>
      <a href="?status=<?= $s ?>" class="chip <?= $status === $s ? 'is-on' : '' ?>"><?= ucfirst($s) ?> <?= $counts[$s] ?? 0 ?></a>
    <?php endforeach; ?>
    <input class="input-fc ms-auto" style="max-width:260px" name="q" value="<?= e($q) ?>" placeholder="Search name, number, product">
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><div class="empty__icon"><i class="fa-brands fa-whatsapp"></i></div><h3>No enquiries<?= $status || $q ? ' match' : ' yet' ?></h3></div>
  <?php else: ?>
  <div style="overflow:auto"><table class="table-fc">
    <thead><tr><th>Ref</th><th>When</th><th>Who</th><th>Products</th><th>Value</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $items = json_decode((string) $r['items'], true) ?: []; ?>
      <tr>
        <td><b>E<?= (int) $r['id'] ?></b></td>
        <td style="white-space:nowrap"><?= date('j M, H:i', strtotime($r['created_at'])) ?></td>
        <td><?= e($r['name'] ?: '—') ?><div class="text-muted-3" style="font-size:.75rem"><?= e(Health::displayPhone($r['phone'])) ?></div></td>
        <td style="font-size:.84rem;max-width:340px"><?= e(implode(', ', array_map(fn($i) => $i['qty'] . '× ' . $i['name'], $items))) ?></td>
        <td class="money"><?= format_price_short((float) $r['total']) ?></td>
        <td><span class="pill pill--<?= ENQUIRY_STATUSES[$r['status']] ?>"><?= ucfirst($r['status']) ?></span></td>
        <td><a href="?id=<?= (int) $r['id'] ?><?= $status ? '&status=' . $status : '' ?>#detail" class="btn-fc btn-fc--ghost btn-fc--sm">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
