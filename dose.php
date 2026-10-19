<?php
declare(strict_types=1);

/**
 * The "I took it" button in a reminder email lands here. The link is signed,
 * so it works without signing in — but it only shows a confirm button: email
 * scanners open links to check them, and a bare GET must never log a dose.
 */
require_once __DIR__ . '/includes/functions.php';

$medId = (int) ($_REQUEST['m'] ?? 0);
$ts    = (int) ($_REQUEST['t'] ?? 0);
$sig   = (string) ($_REQUEST['s'] ?? '');
$at    = $ts ? date('Y-m-d H:i:s', $ts) : '';
$med   = $medId ? Health::medication($medId) : null;
$valid = $med && $at !== '' && hash_equals(Health::doseSignature($medId, $at, (int) $med['user_id']), $sig)
         && $ts > time() - 3 * 86400;   // links expire after three days

$done = null;
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = ($_POST['status'] ?? '') === 'skipped' ? 'skipped' : 'taken';
    $done = Health::logDose((int) $med['user_id'], $medId, $at, $status, 'email') ? $status : 'error';
}
if ($valid && $done === null) {
    $prev = db()->prepare('SELECT status FROM dose_logs WHERE medication_id = :m AND scheduled_for = :s');
    $prev->execute([':m' => $medId, ':s' => $at]);
    $already = $prev->fetchColumn() ?: null;
}

$page_title  = 'Log your dose — ' . SITE_NAME;
$meta_robots = 'noindex, nofollow';
include __DIR__ . '/includes/header.php';
?>
<section class="section">
  <div class="container" style="max-width:560px">
    <div class="card-fc" style="padding:clamp(1.6rem,4vw,2.6rem);text-align:center">
      <?php if (!$valid): ?>
        <div class="empty__icon mx-auto"><i class="fa-solid fa-link-slash"></i></div>
        <h1 style="font-size:1.6rem">This link has expired</h1>
        <p class="text-muted-2">Reminder links work for three days. Sign in to log your doses in your health tracker.</p>
        <a href="<?= SITE_URL ?>/modules/auth/login.php" class="btn-fc btn-fc--lg mt-2">Open my tracker</a>

      <?php elseif ($done === 'taken' || ($done === null && $already === 'taken')): ?>
        <div class="ring ring--light mx-auto mb-3" style="--pct:100;--size:96px"><b><i class="fa-solid fa-check" style="color:var(--leaf)"></i></b></div>
        <h1 style="font-size:1.6rem">Logged — well done</h1>
        <p class="text-muted-2"><b><?= e($med['name']) ?></b> at <?= date('H:i', $ts) ?> on <?= date('j F', $ts) ?> is marked as taken.</p>
        <div class="d-flex gap-2 justify-content-center flex-wrap mt-3">
          <a href="<?= SITE_URL ?>/dashboards/customer/index.php" class="btn-fc">See my tracker</a>
          <a href="<?= SITE_URL ?>/dashboards/customer/side-effects.php" class="btn-fc btn-fc--ghost">Report a side effect</a>
        </div>

      <?php elseif ($done === 'skipped'): ?>
        <h1 style="font-size:1.6rem">Marked as skipped</h1>
        <p class="text-muted-2">That's fine — try to take your next dose on time. If you're skipping because of a side effect, please tell us.</p>
        <a href="<?= SITE_URL ?>/dashboards/customer/side-effects.php" class="btn-fc mt-2">Report a side effect</a>

      <?php elseif ($done === 'error'): ?>
        <h1 style="font-size:1.6rem">Something went wrong</h1>
        <p class="text-muted-2">We couldn't log that dose. Please sign in and log it in your tracker.</p>
        <a href="<?= SITE_URL ?>/modules/auth/login.php" class="btn-fc mt-2">Sign in</a>

      <?php else: ?>
        <img src="<?= e(product_image($med['featured_image'] ?? null)) ?>" alt="" style="width:88px;height:88px;border-radius:var(--r-lg);object-fit:cover" class="mb-3">
        <h1 style="font-size:1.6rem"><?= e($med['name']) ?></h1>
        <p class="text-muted-2"><?= e(trim(($med['dosage'] ?? '') . ' · ' . date('H:i', $ts) . ' on ' . date('l j F', $ts), ' ·')) ?></p>
        <form method="post" class="d-grid gap-2 mt-3">
          <input type="hidden" name="m" value="<?= $medId ?>"><input type="hidden" name="t" value="<?= $ts ?>"><input type="hidden" name="s" value="<?= e($sig) ?>">
          <button class="btn-fc btn-fc--lg" name="status" value="taken"><i class="fa-solid fa-check"></i> Yes, I took it</button>
          <button class="btn-fc btn-fc--ghost" name="status" value="skipped">I skipped this dose</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
