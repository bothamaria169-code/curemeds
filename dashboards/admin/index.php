<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'dashboard';
$page_title  = 'Admin dashboard — CureMeds';
$extra_js    = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'];
require_once __DIR__ . '/../../includes/dash-head.php';

// Enquiries per day, last 30 days.
$since = date('Y-m-d', strtotime('-29 days'));
$stmt = db()->prepare("SELECT DATE(created_at) d, COUNT(*) n, SUM(status = 'sold') sold FROM enquiries WHERE created_at >= :s GROUP BY DATE(created_at)");
$stmt->execute([':s' => "$since 00:00:00"]);
$byDay = [];
foreach ($stmt as $r) { $byDay[$r['d']] = $r; }
$labels = $enq = $sold = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('j M', strtotime($d));
    $enq[]  = (int) ($byDay[$d]['n'] ?? 0);
    $sold[] = (int) ($byDay[$d]['sold'] ?? 0);
}

$recentEnq = db()->query('SELECT * FROM enquiries ORDER BY created_at DESC LIMIT 6')->fetchAll();
$attention = Health::sideEffects(null, 'new', 6);
$lowStock  = db()->query(
    "SELECT id, name, stock_qty, stock_status FROM products
     WHERE status='published' AND stock_status IN ('low_stock','out_of_stock') ORDER BY stock_qty ASC LIMIT 6"
)->fetchAll();
$lowAdherence = db()->query(
    "SELECT u.id, u.name, u.phone FROM users u
     WHERE u.role = 'customer' AND EXISTS (SELECT 1 FROM patient_medications m WHERE m.user_id = u.id AND m.status = 'active')
     ORDER BY u.last_login_at IS NULL DESC, u.last_login_at ASC LIMIT 40"
)->fetchAll();
// Worst seven-day adherence first, only those under 80%.
$watch = [];
foreach ($lowAdherence as $p) {
    $a = Health::adherence((int) $p['id'], 7);
    if ($a['pct'] !== null && $a['pct'] < 80) {
        $watch[] = $p + $a;
    }
}
usort($watch, fn($a, $b) => $a['pct'] <=> $b['pct']);
$watch = array_slice($watch, 0, 6);

$tasksLeft = setup_tasks_left();
$tasksAll  = count(setup_tasks());
$lastCron  = get_setting('reminders_last_run', '');
?>

<div class="dash-head">
  <div>
    <h1>Good <?= (int) date('H') < 12 ? 'morning' : ((int) date('H') < 17 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $user['name'])[0]) ?></h1>
    <p><?= date('l j F Y') ?> · enquiries, patients and anything that needs you.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="patients.php?new=1" class="btn-fc btn-fc--sm"><i class="fa-solid fa-user-plus"></i> New patient</a>
    <a href="product-form.php" class="btn-fc btn-fc--ghost btn-fc--sm"><i class="fa-solid fa-plus"></i> New product</a>
    <a href="<?= SITE_URL ?>/index.php" class="btn-fc btn-fc--ghost btn-fc--sm" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> View site</a>
  </div>
</div>

<?php if ($stats['severe_open'] > 0): ?>
  <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-triangle-exclamation"></i>
    <span><b><?= $stats['severe_open'] ?> severe side effect<?= $stats['severe_open'] === 1 ? '' : 's' ?> still open.</b>
      <a href="side-effects.php?severity=severe" class="fw-bold">Contact the patient now</a> and advise them to visit a clinic.</span></div>
<?php endif; ?>

<?php if ($tasksLeft > 0): ?>
  <a href="setup.php" class="card-fc card-fc--pad card-fc--hover d-flex gap-3 align-items-center mb-3" style="text-decoration:none">
    <span class="stat__icon stat__icon--amber"><i class="fa-solid fa-list-check"></i></span>
    <span style="flex:1">
      <b style="color:var(--ink)">Setup checklist: <?= $tasksAll - $tasksLeft ?> of <?= $tasksAll ?> done</b>
      <span class="d-block text-muted-2" style="font-size:.85rem">Hosting, GitHub, email, cron, Groq and Facebook — finish these to go live.</span>
      <span class="setup-progress d-block mt-2"><span style="width:<?= round(($tasksAll - $tasksLeft) / $tasksAll * 100) ?>%"></span></span>
    </span>
    <i class="fa-solid fa-arrow-right text-leaf"></i>
  </a>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat"><span class="stat__icon stat__icon--leaf"><i class="fa-brands fa-whatsapp"></i></span>
    <span><b><?= array_sum($enq) ?></b><span>Enquiries, 30 days</span>
      <div class="delta<?= $stats['enquiries_new'] ? ' down' : '' ?>"><?= (int) $stats['enquiries_new'] ?> not followed up</div></span></div>
  <div class="stat"><span class="stat__icon stat__icon--ink"><i class="fa-solid fa-hospital-user"></i></span>
    <span><b><?= (int) $stats['patients'] ?></b><span>Patients</span>
      <div class="delta up"><?= (int) $stats['active_meds'] ?> active medications</div></span></div>
  <div class="stat"><span class="stat__icon stat__icon--coral"><i class="fa-solid fa-notes-medical"></i></span>
    <span><b><?= (int) $stats['new_side_effects'] ?></b><span>New side-effect reports</span>
      <?php if ($stats['severe_open']): ?><div class="delta down"><?= (int) $stats['severe_open'] ?> severe</div><?php endif; ?></span></div>
  <div class="stat"><span class="stat__icon stat__icon--amber"><i class="fa-solid fa-bell"></i></span>
    <span><b><?= (int) $stats['reminders_today'] ?></b><span>Reminders sent today</span>
      <div class="delta<?= $lastCron === '' || strtotime($lastCron) < time() - 3600 ? ' down' : ' up' ?>">
        <?= $lastCron === '' ? 'Cron not running yet' : 'Last run ' . time_ago($lastCron) ?></div></span></div>
</div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card-fc card-fc--pad">
      <div class="d-flex justify-content-between align-items-baseline">
        <h2 style="font-size:1.1rem;margin:0">WhatsApp enquiries, last 30 days</h2>
        <a href="enquiries.php" class="btn-fc btn-fc--ghost btn-fc--sm">All enquiries</a>
      </div>
      <div style="height:240px" class="mt-2"><canvas id="enqChart"></canvas></div>
    </div>

    <div class="card-fc mt-3">
      <div class="d-flex justify-content-between align-items-center p-3 pb-0">
        <h2 style="font-size:1.05rem;margin:0">Latest enquiries</h2>
      </div>
      <?php if (!$recentEnq): ?>
        <p class="text-muted-2 p-3 mb-0">No enquiries yet. Each "Enquire on WhatsApp" press shows up here.</p>
      <?php else: ?>
      <div style="overflow:auto"><table class="table-fc">
        <thead><tr><th>When</th><th>Who</th><th>Products</th><th>Value</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recentEnq as $q): $items = json_decode((string) $q['items'], true) ?: []; ?>
          <tr>
            <td><a href="enquiries.php?id=<?= (int) $q['id'] ?>" class="fw-bold">E<?= (int) $q['id'] ?></a><div class="text-muted-3" style="font-size:.75rem"><?= time_ago($q['created_at']) ?></div></td>
            <td><?= e($q['name'] ?: 'Visitor') ?><div class="text-muted-3" style="font-size:.75rem"><?= e(Health::displayPhone($q['phone'])) ?></div></td>
            <td style="font-size:.84rem"><?= e(implode(', ', array_map(fn($i) => $i['qty'] . '× ' . $i['name'], $items))) ?></td>
            <td class="money"><?= format_price_short((float) $q['total']) ?></td>
            <td><span class="pill pill--<?= ['new' => 'warn', 'contacted' => 'info', 'sold' => 'ok', 'closed' => 'muted'][$q['status']] ?>"><?= ucfirst($q['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card-fc card-fc--pad mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 style="font-size:1.05rem;margin:0">Side effects to review</h2>
        <a href="side-effects.php" class="btn-fc btn-fc--ghost btn-fc--sm">All</a>
      </div>
      <?php if (!$attention): ?><p class="text-muted-2 mb-0" style="font-size:.88rem">Nothing new. 🎉</p><?php endif; ?>
      <?php foreach ($attention as $s): ?>
        <a href="patient.php?id=<?= (int) $s['user_id'] ?>#side-effects" class="timeline-item is-<?= e($s['severity']) ?> d-block" style="color:inherit">
          <b><?= e($s['symptom']) ?></b>
          <div class="text-muted-3" style="font-size:.76rem"><?= e($s['patient_name']) ?> · <?= ucfirst($s['severity']) ?> · <?= time_ago($s['created_at']) ?></div>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1.05rem">Patients slipping this week</h2>
      <?php if (!$watch): ?><p class="text-muted-2 mb-0" style="font-size:.88rem">Everyone is above 80% adherence.</p><?php endif; ?>
      <?php foreach ($watch as $p): ?>
        <div class="d-flex justify-content-between align-items-center py-2" style="border-top:1px solid var(--line)">
          <a href="patient.php?id=<?= (int) $p['id'] ?>" class="fw-bold" style="font-size:.88rem"><?= e($p['name']) ?></a>
          <span class="d-flex gap-2 align-items-center">
            <span class="pill pill--<?= $p['pct'] < 50 ? 'danger' : 'warn' ?>"><?= $p['pct'] ?>%</span>
            <a href="<?= e(wa_link('Hi ' . explode(' ', $p['name'])[0] . ', this is CureMeds checking in. How are you getting on with your medication?', (string) $p['phone'])) ?>" target="_blank" rel="noopener" title="WhatsApp <?= e($p['name']) ?>" style="color:var(--wa)"><i class="fa-brands fa-whatsapp"></i></a>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card-fc card-fc--pad">
      <h2 style="font-size:1.05rem">Stock to watch</h2>
      <?php if (!$lowStock): ?><p class="text-muted-2 mb-0" style="font-size:.88rem">Everything is well stocked.</p><?php endif; ?>
      <?php foreach ($lowStock as $p): ?>
        <div class="d-flex justify-content-between py-2" style="border-top:1px solid var(--line);font-size:.88rem">
          <a href="product-form.php?id=<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a>
          <span class="pill pill--<?= $p['stock_status'] === 'out_of_stock' ? 'danger' : 'warn' ?>"><?= (int) $p['stock_qty'] ?> left</span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
window.addEventListener('load', () => {
  if (!window.Chart) return;
  const c = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
  new Chart(document.getElementById('enqChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($labels) ?>, datasets: [
      { label: 'Enquiries', data: <?= json_encode($enq) ?>, backgroundColor: c('--leaf-light'), borderRadius: 4 },
      { label: 'Became sales', data: <?= json_encode($sold) ?>, backgroundColor: c('--leaf'), borderRadius: 4 },
    ] },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } },
      scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: c('--line') } } } },
  });
});
</script>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
