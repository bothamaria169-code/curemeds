<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'users';
$page_title  = 'Customers — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $uid    = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    // An admin must not be able to lock themselves out or demote themselves.
    if ($uid === (int) $user['id']) {
        $flash = ['warn', 'You cannot change your own account from here.'];
    } elseif ($action === 'toggle' && $uid) {
        db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = :id')->execute([':id' => $uid]);
        $flash = ['ok', 'Account access updated.'];
    } elseif ($action === 'role' && $uid) {
        $role = (string) ($_POST['role'] ?? 'customer');
        if (in_array($role, ['customer', 'reseller', 'editor', 'admin'], true)) {
            db_update('users', $uid, ['role' => $role]);
            $flash = ['ok', 'Role changed to ' . $role . '.'];
        }
    }
}

$q     = trim((string) ($_GET['q'] ?? ''));
$role  = (string) ($_GET['role'] ?? '');
$page  = max(1, (int) ($_GET['page'] ?? 1));
$per   = 30;

$where  = ['1=1'];
$params = [];
// Emulated prepares are off — one placeholder per occurrence.
if ($q !== '')    { $where[] = '(u.name LIKE :q1 OR u.email LIKE :q2)'; $params[':q1'] = $params[':q2'] = '%' . $q . '%'; }
if ($role !== '') { $where[] = 'u.role = :r'; $params[':r'] = $role; }
$whereSql = implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = (int) max(1, ceil($total / $per));
$page  = min($page, $pages);

$stmt = db()->prepare(
    "SELECT u.*,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS orders_n,
            (SELECT COALESCE(SUM(o.total_amount),0) FROM orders o WHERE o.user_id = u.id AND o.payment_status='paid') AS spent
     FROM users u WHERE $whereSql ORDER BY u.created_at DESC LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':lim', $per, PDO::PARAM_INT);
$stmt->bindValue(':off', ($page - 1) * $per, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
?>

<div class="dash-head">
  <div><h1>Customers</h1><p><?= $total ?> account<?= $total === 1 ? '' : 's' ?>.</p></div>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= $flash[0] ?>"><i class="fa-solid fa-circle-info"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>

<div class="card-fc card-fc--pad mb-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-6 field mb-0">
      <label for="q">Search</label>
      <input class="input-fc" type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Name or email">
    </div>
    <div class="col-md-4 field mb-0">
      <label for="role">Role</label>
      <select class="select-fc" id="role" name="role">
        <option value="">All roles</option>
        <?php foreach (['customer' => 'Customer', 'reseller' => 'Reseller', 'editor' => 'Editor', 'admin' => 'Admin'] as $k => $label): ?>
          <option value="<?= $k ?>" <?= $role === $k ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn-fc btn-fc--block" type="submit"><i class="fa-solid fa-filter"></i> Filter</button></div>
  </form>
</div>

<div class="table-wrap">
  <table class="table-fc">
    <thead><tr><th>Account</th><th>Role</th><th>Reseller</th><th class="text-center">Orders</th><th class="text-end">Spent</th><th>Joined</th><th>Access</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): $self = (int) $u['id'] === (int) $user['id']; ?>
      <tr>
        <td>
          <b><?= e($u['name']) ?></b><?php if ($self): ?><span class="badge-fc badge-fc--leaf ms-2">You</span><?php endif; ?>
          <span class="d-block text-muted-3" style="font-size:.76rem"><?= e($u['email']) ?></span>
          <?php if ($u['phone']): ?><span class="d-block text-muted-3" style="font-size:.76rem"><?= e($u['phone']) ?></span><?php endif; ?>
        </td>
        <td>
          <?php if ($self): ?>
            <span class="pill pill--info"><?= e($u['role']) ?></span>
          <?php else: ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <select class="select-fc" name="role" onchange="this.form.submit()" style="padding:.32rem 1.8rem .32rem .55rem;font-size:.8rem;width:auto">
              <?php foreach (['customer' => 'Customer', 'reseller' => 'Reseller', 'editor' => 'Editor', 'admin' => 'Admin'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </form>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($u['reseller_status'] === 'none'): ?>
            <span class="text-muted-3" style="font-size:.82rem">—</span>
          <?php else:
            $tone = ['pending' => 'warn', 'approved' => 'ok', 'suspended' => 'danger'][$u['reseller_status']] ?? 'muted'; ?>
            <a href="resellers.php?q=<?= urlencode($u['email']) ?>">
              <span class="pill pill--<?= $tone ?>"><?= e(ucfirst($u['reseller_status'])) ?></span>
            </a>
            <?php if ($u['reseller_status'] === 'approved'): ?>
              <span class="d-block text-muted-3" style="font-size:.73rem"><?= e(RESELLER_TIERS[$u['reseller_tier']]['label']) ?> · <?= e((string) $u['reseller_code']) ?></span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td class="text-center"><?= (int) $u['orders_n'] ?></td>
        <td class="text-end money"><?= format_price_short((float) $u['spent']) ?></td>
        <td style="font-size:.82rem"><?= date('j M Y', strtotime($u['created_at'])) ?></td>
        <td>
          <?php if ($self): ?>
            <span class="pill pill--ok">Active</span>
          <?php else: ?>
          <form method="post" onsubmit="return confirm('<?= $u['is_active'] ? 'Disable' : 'Re-enable' ?> access for <?= e($u['name']) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <button class="pill pill--<?= $u['is_active'] ? 'ok' : 'danger' ?>" style="border:0;cursor:pointer" type="submit">
              <?= $u['is_active'] ? 'Active' : 'Disabled' ?>
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= render_pager($page, $pages) ?>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
