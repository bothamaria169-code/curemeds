<?php
declare(strict_types=1);
$dash_role   = 'customer';
$dash_active = 'dashboard';
$page_title  = 'My health tracker — CureMeds';
require_once __DIR__ . '/../../includes/dash-head.php';

$uid = (int) $user['id'];

// Ticking a dose off (or saying it was skipped).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $status = ($_POST['status'] ?? '') === 'skipped' ? 'skipped' : 'taken';
    if (Health::logDose($uid, (int) ($_POST['medication_id'] ?? 0), (string) ($_POST['scheduled_for'] ?? ''), $status)) {
        flash('success', $status === 'taken' ? 'Logged — well done.' : 'Marked as skipped.');
    } else {
        flash('danger', 'That dose could not be logged. Please refresh and try again.');
    }
    back('today');
}

$doses    = Health::daySchedule($uid);
$meds     = Health::medications($uid, true);
$week     = Health::adherence($uid, 7);
$streak   = Health::streak($uid);
$daily    = Health::dailyAdherence($uid, 14);
$effects  = array_slice(Health::sideEffects($uid), 0, 3);
$readings = Health::readings($uid, 1);
$lowStock = array_filter($meds, fn($m) => ($d = Health::daysLeft($m)) !== null && $d <= Health::REFILL_DAYS);
$dueNow   = count(array_filter($doses, fn($d) => $d['state'] === 'due'));
$doneToday = count(array_filter($doses, fn($d) => $d['state'] === 'taken'));

$hour  = (int) date('G');
$hello = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>

<?= flash_html() ?>

<?php if (isset($_GET['welcome'])): ?>
  <div class="alert-fc alert-fc--success"><i class="fa-solid fa-circle-check"></i>
    <span><b>Your tracker is ready.</b> Add the medication you take and its dose times — or WhatsApp us and we'll set it up for you.</span></div>
<?php endif; ?>

<section class="hello-band">
  <div>
    <h1><?= $hello ?>, <?= e(explode(' ', $user['name'])[0]) ?></h1>
    <p>
      <?php if (!$doses): ?>
        No doses scheduled today.
      <?php elseif ($dueNow): ?>
        You have <b style="color:#fff"><?= $dueNow ?> dose<?= $dueNow === 1 ? '' : 's' ?></b> to take now.
      <?php else: ?>
        <?= $doneToday ?> of <?= count($doses) ?> doses logged today.
      <?php endif; ?>
      <?php if ($streak > 1): ?> · <i class="fa-solid fa-fire" style="color:var(--amber)"></i> <?= $streak ?>-day streak<?php endif; ?>
    </p>
  </div>
  <div class="ring" style="--pct:<?= (int) ($week['pct'] ?? 0) ?>">
    <b><?= $week['pct'] === null ? '—' : $week['pct'] . '%' ?><small>THIS WEEK</small></b>
  </div>
</section>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="card-fc card-fc--pad" id="today">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 style="font-size:1.1rem;margin:0">Today · <?= date('l j F') ?></h2>
        <a href="medications.php" class="btn-fc btn-fc--ghost btn-fc--sm"><i class="fa-solid fa-pills"></i> My medication</a>
      </div>

      <?php if (!$meds): ?>
        <div class="empty" style="padding:2rem 1rem">
          <div class="empty__icon"><i class="fa-solid fa-pills"></i></div>
          <h3>No medication yet</h3>
          <p class="mb-3">Add what you take and when, and we'll remind you at every dose time.</p>
          <div class="d-flex gap-2 justify-content-center flex-wrap">
            <a href="medications.php#add" class="btn-fc"><i class="fa-solid fa-plus"></i> Add medication</a>
            <a href="<?= e(wa_link('Hi CureMeds, please set up my medication reminders. My number is ' . Health::displayPhone($user['phone'] ?? '') . '.')) ?>" target="_blank" rel="noopener" class="btn-fc btn-fc--whatsapp"><i class="fa-brands fa-whatsapp"></i> Ask us to set it up</a>
          </div>
        </div>
      <?php elseif (!$doses): ?>
        <p class="text-muted-2 mb-0">Nothing scheduled today. Paused or finished medication doesn't show here.</p>
      <?php else: ?>
        <div class="dose-list">
          <?php foreach ($doses as $d): $m = $d['medication']; ?>
          <div class="dose dose--<?= e($d['state']) ?>">
            <div class="dose__time"><?= e($d['time']) ?><small><?= ['taken' => 'Taken', 'skipped' => 'Skipped', 'due' => 'Now', 'missed' => 'Late', 'upcoming' => 'Later'][$d['state']] ?></small></div>
            <img class="dose__img" src="<?= e(product_image($m['featured_image'] ?? null)) ?>" alt="" loading="lazy">
            <div class="dose__body">
              <b><?= e($m['name']) ?></b>
              <span><?= e(trim(($m['dosage'] ?? '') . ($m['instructions'] ? ' · ' . $m['instructions'] : ''), ' ·')) ?></span>
            </div>
            <div class="dose__actions">
              <?php if (in_array($d['state'], ['taken', 'skipped'], true)): ?>
                <span class="dose-state dose-state--<?= e($d['state']) ?>">
                  <i class="fa-solid <?= $d['state'] === 'taken' ? 'fa-circle-check' : 'fa-forward' ?>"></i><?= ucfirst($d['state']) ?>
                </span>
              <?php endif; ?>
              <?php if ($d['state'] !== 'taken'): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="medication_id" value="<?= (int) $m['id'] ?>">
                <input type="hidden" name="scheduled_for" value="<?= e($d['scheduled_for']) ?>">
                <input type="hidden" name="status" value="taken">
                <button class="btn-fc btn-fc--sm" type="submit"><i class="fa-solid fa-check"></i> Took it</button>
              </form>
              <?php endif; ?>
              <?php if (in_array($d['state'], ['due', 'missed', 'upcoming'], true)): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="medication_id" value="<?= (int) $m['id'] ?>">
                <input type="hidden" name="scheduled_for" value="<?= e($d['scheduled_for']) ?>">
                <input type="hidden" name="status" value="skipped">
                <button class="btn-fc btn-fc--ghost btn-fc--sm" type="submit" title="Skipped this dose">Skip</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($daily): ?>
    <div class="card-fc card-fc--pad mt-3">
      <div class="d-flex justify-content-between align-items-baseline">
        <h2 style="font-size:1.05rem;margin:0">Last two weeks</h2>
        <span class="text-muted-3" style="font-size:.8rem">% of doses taken each day</span>
      </div>
      <div class="adh-bars" role="img" aria-label="Daily adherence for the last 14 days">
        <?php for ($i = 13; $i >= 0; $i--):
          $date = date('Y-m-d', strtotime("-$i days"));
          $row = $daily[$date] ?? null;
          $pct = $row['pct'] ?? 0; ?>
          <div class="adh-bars__col" title="<?= date('D j M', strtotime($date)) ?>: <?= $row ? $row['taken'] . ' of ' . $row['total'] . ' taken' : 'nothing due' ?>">
            <?php if ($row): ?>
              <div class="adh-bars__bar <?= $pct < 50 ? 'is-bad' : ($pct < 90 ? 'is-low' : '') ?>" style="height:<?= max(4, $pct) ?>%"></div>
            <?php endif; ?>
            <span><?= date('j', strtotime($date)) ?></span>
          </div>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-xl-5">
    <?php foreach ($lowStock as $m): $left = Health::daysLeft($m); ?>
      <div class="alert-fc alert-fc--warn">
        <i class="fa-solid fa-box-open"></i>
        <span><b><?= e($m['name']) ?></b> — about <?= $left ?> day<?= $left === 1 ? '' : 's' ?> left.
          <a href="<?= e(wa_link('Hi CureMeds, I would like to reorder ' . $m['name'] . '.')) ?>" target="_blank" rel="noopener" class="fw-bold">Reorder on WhatsApp</a></span>
      </div>
    <?php endforeach; ?>

    <div class="card-fc card-fc--pad">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 style="font-size:1.05rem;margin:0">How are you feeling?</h2>
      </div>
      <div class="d-grid gap-2">
        <a href="side-effects.php#report" class="btn-fc btn-fc--ghost"><i class="fa-solid fa-notes-medical"></i> Report a side effect</a>
        <a href="readings.php#add" class="btn-fc btn-fc--ghost"><i class="fa-solid fa-heart-pulse"></i> Log today's readings</a>
      </div>
      <?php if ($readings): $r = $readings[0]; ?>
        <p class="text-muted-3 mt-3 mb-0" style="font-size:.8rem">
          Last reading <?= date('j M', strtotime($r['recorded_on'])) ?>:
          <?= e(implode(' · ', array_filter([
              $r['systolic'] ? 'BP ' . (int) $r['systolic'] . '/' . (int) $r['diastolic'] : null,
              $r['weight_kg'] ? (float) $r['weight_kg'] . ' kg' : null,
              $r['glucose'] ? (float) $r['glucose'] . ' mmol/L' : null,
          ]))) ?>
        </p>
      <?php endif; ?>
    </div>

    <?php if ($effects): ?>
    <div class="card-fc card-fc--pad mt-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 style="font-size:1.05rem;margin:0">Your recent reports</h2>
        <a href="side-effects.php" class="btn-fc btn-fc--ghost btn-fc--sm">All</a>
      </div>
      <?php foreach ($effects as $s): ?>
        <div class="timeline-item is-<?= e($s['severity']) ?>">
          <b><?= e($s['symptom']) ?></b>
          <div class="text-muted-3" style="font-size:.78rem"><?= ucfirst($s['severity']) ?> · <?= date('j M', strtotime($s['created_at'])) ?>
            · <?= $s['status'] === 'new' ? 'Waiting for our team' : ($s['status'] === 'resolved' ? 'Resolved' : 'Reviewed') ?></div>
          <?php if ($s['staff_reply']): ?>
            <div class="staff-reply"><b>CureMeds team</b><div><?= nl2br(e($s['staff_reply'])) ?></div></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($user['email'])): ?>
      <div class="card-fc card-fc--pad mt-3" style="background:var(--leaf-wash);border-color:transparent">
        <b style="font-family:var(--font-display)"><i class="fa-solid fa-bell me-1"></i> Want reminders?</b>
        <p style="font-size:.86rem;color:var(--text-2);margin:.4rem 0 .8rem">Add your email address and we'll remind you at every dose time.</p>
        <a href="account.php" class="btn-fc btn-fc--sm">Add my email</a>
      </div>
    <?php endif; ?>

    <div class="emergency-note mt-3">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <span>Feeling very unwell — trouble breathing, swelling of the face or a spreading rash? <b>Call 10177</b> or go to your nearest clinic now.</span>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
