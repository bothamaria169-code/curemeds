<?php
declare(strict_types=1);
$dash_role   = 'customer';
$dash_active = 'readings';
$page_title  = 'My readings — CureMeds';
$extra_js    = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'];
require_once __DIR__ . '/../../includes/dash-head.php';

$uid    = (int) $user['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    if (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM health_readings WHERE id = :id AND user_id = :u')->execute([':id' => (int) $_POST['id'], ':u' => $uid]);
        flash('success', 'Reading removed.');
        back();
    }
    $errors = Health::saveReading($uid, $_POST);
    if (!$errors) {
        flash('success', 'Saved. Keep it up — a reading at the same time each day gives the clearest picture.');
        back();
    }
}

$readings = Health::readings($uid, 90);
$chrono   = array_reverse($readings);
$series   = fn(string $col) => array_map(fn($r) => $r[$col] === null ? null : (float) $r[$col], $chrono);
$labels   = array_map(fn($r) => date('j M', strtotime($r['recorded_on'])), $chrono);
$has      = fn(string $col) => count(array_filter($readings, fn($r) => $r[$col] !== null)) > 0;
$latest   = $readings[0] ?? null;
$faces    = [1 => '😞', 2 => '🙁', 3 => '😐', 4 => '🙂', 5 => '😄'];

/** A plain-language label for a blood pressure reading (home-monitor guidance, not a diagnosis). */
$bpLabel = function (?array $r): ?array {
    if (!$r || !$r['systolic']) { return null; }
    [$s, $d] = [(int) $r['systolic'], (int) $r['diastolic']];
    return match (true) {
        $s >= 180 || $d >= 120 => ['danger', 'Very high — seek medical help now'],
        $s >= 140 || $d >= 90  => ['warn', 'High — mention it to your clinic'],
        $s < 90 || $d < 60     => ['info', 'Low — tell your clinic if you feel dizzy'],
        default                => ['ok', 'In the usual range'],
    };
};
$bp = $bpLabel($latest);
?>

<div class="dash-head">
  <div><h1>My readings</h1><p>Log blood pressure, weight, blood sugar and how you feel. Bring the chart to your clinic visits.</p></div>
</div>

<?= flash_html() ?>
<?php foreach ($errors as $err): ?>
  <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div>
<?php endforeach; ?>

<?php if ($bp && $bp[0] === 'danger'): ?>
  <div class="emergency-note mb-3"><i class="fa-solid fa-triangle-exclamation"></i>
    <span>Your last blood pressure was <b><?= (int) $latest['systolic'] ?>/<?= (int) $latest['diastolic'] ?></b>. If you have a headache, chest pain, blurred vision or confusion, <b>call 10177</b> or go to a clinic now.</span></div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat"><span class="stat__icon stat__icon--leaf"><i class="fa-solid fa-heart-pulse"></i></span>
    <span><b><?= $latest && $latest['systolic'] ? (int) $latest['systolic'] . '/' . (int) $latest['diastolic'] : '—' ?></b><span>Blood pressure</span>
      <?php if ($bp): ?><div class="delta" style="color:var(--<?= $bp[0] === 'ok' ? 'ok' : ($bp[0] === 'info' ? 'info' : $bp[0]) ?>)"><?= e($bp[1]) ?></div><?php endif; ?></span></div>
  <div class="stat"><span class="stat__icon stat__icon--ink"><i class="fa-solid fa-weight-scale"></i></span>
    <span><b><?= $latest && $latest['weight_kg'] ? (float) $latest['weight_kg'] . ' kg' : '—' ?></b><span>Weight</span></span></div>
  <div class="stat"><span class="stat__icon stat__icon--amber"><i class="fa-solid fa-droplet"></i></span>
    <span><b><?= $latest && $latest['glucose'] ? (float) $latest['glucose'] : '—' ?></b><span>Blood sugar (mmol/L)</span></span></div>
  <div class="stat"><span class="stat__icon stat__icon--coral"><i class="fa-solid fa-face-smile"></i></span>
    <span><b><?= $latest && $latest['mood'] ? $faces[(int) $latest['mood']] : '—' ?></b><span>Mood</span></span></div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <form class="card-fc card-fc--pad" method="post" id="add">
      <?= csrf_field() ?>
      <h2 style="font-size:1.1rem">Today's check-in</h2>
      <p class="text-muted-2" style="font-size:.85rem">Fill in only what you measured. Saving again on the same day updates that day.</p>

      <div class="field">
        <label for="recorded_on">Date</label>
        <input class="input-fc" type="date" id="recorded_on" name="recorded_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>Blood pressure</label>
        <div class="d-flex gap-2 align-items-center">
          <input class="input-fc" type="number" name="systolic" min="60" max="260" placeholder="120" inputmode="numeric" aria-label="Top number (systolic)">
          <span class="text-muted-3 fw-bold">/</span>
          <input class="input-fc" type="number" name="diastolic" min="30" max="160" placeholder="80" inputmode="numeric" aria-label="Bottom number (diastolic)">
        </div>
      </div>
      <div class="row g-2">
        <div class="col-6 field">
          <label for="weight_kg">Weight (kg)</label>
          <input class="input-fc" type="number" step="0.1" min="20" max="350" id="weight_kg" name="weight_kg" inputmode="decimal">
        </div>
        <div class="col-6 field">
          <label for="glucose">Blood sugar (mmol/L)</label>
          <input class="input-fc" type="number" step="0.1" min="1" max="40" id="glucose" name="glucose" inputmode="decimal">
        </div>
      </div>
      <?php foreach (['mood' => 'Mood', 'energy' => 'Energy'] as $key => $label): ?>
      <div class="field">
        <label><?= $label ?></label>
        <div class="scale-pick">
          <?php foreach ($faces as $n => $face): ?>
            <label><input type="radio" name="<?= $key ?>" value="<?= $n ?>"><span title="<?= $n ?> of 5"><?= $key === 'mood' ? $face : str_repeat('●', $n) ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="field">
        <label for="notes">Notes <span class="text-muted-3 fw-normal">(optional)</span></label>
        <input class="input-fc" id="notes" name="notes" maxlength="255" placeholder="e.g. Slept badly, measured after walking">
      </div>
      <button class="btn-fc btn-fc--block" type="submit">Save check-in</button>
    </form>
  </div>

  <div class="col-lg-7">
    <?php if (!$readings): ?>
      <div class="card-fc empty">
        <div class="empty__icon"><i class="fa-solid fa-chart-line"></i></div>
        <h3>Your charts appear here</h3>
        <p>Save your first check-in and we'll start drawing your trends.</p>
      </div>
    <?php else: ?>
      <?php foreach ([
          ['bp', 'Blood pressure', $has('systolic')],
          ['weight', 'Weight (kg)', $has('weight_kg')],
          ['glucose', 'Blood sugar (mmol/L)', $has('glucose')],
          ['wellbeing', 'Mood & energy (1–5)', $has('mood') || $has('energy')],
      ] as [$id, $title, $show]): if (!$show) continue; ?>
        <div class="card-fc card-fc--pad mb-3">
          <h2 style="font-size:1rem"><?= $title ?></h2>
          <div style="height:200px"><canvas id="chart-<?= $id ?>"></canvas></div>
        </div>
      <?php endforeach; ?>

      <div class="card-fc" style="overflow:auto">
        <table class="table-fc">
          <thead><tr><th>Date</th><th>BP</th><th>Weight</th><th>Sugar</th><th>Mood</th><th></th></tr></thead>
          <tbody>
          <?php foreach (array_slice($readings, 0, 30) as $r): ?>
            <tr>
              <td><?= date('j M Y', strtotime($r['recorded_on'])) ?><?php if ($r['notes']): ?><div class="text-muted-3" style="font-size:.74rem"><?= e($r['notes']) ?></div><?php endif; ?></td>
              <td><?= $r['systolic'] ? (int) $r['systolic'] . '/' . (int) $r['diastolic'] : '—' ?></td>
              <td><?= $r['weight_kg'] ? (float) $r['weight_kg'] : '—' ?></td>
              <td><?= $r['glucose'] ? (float) $r['glucose'] : '—' ?></td>
              <td><?= $r['mood'] ? $faces[(int) $r['mood']] : '—' ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Remove this reading?')">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn-fc btn-fc--sm" style="background:none;color:var(--text-3)" aria-label="Remove reading"><i class="fa-solid fa-trash-can"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($readings): ?>
<script>
window.addEventListener('load', () => {
  if (!window.Chart) return;
  const css = getComputedStyle(document.documentElement);
  const c = (v) => css.getPropertyValue(v).trim();
  const labels = <?= json_encode($labels) ?>;
  const base = {
    responsive: true, maintainAspectRatio: false, spanGaps: true,
    plugins: { legend: { display: false } },
    scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8, color: c('--text-3') } },
              y: { grid: { color: c('--line') }, ticks: { color: c('--text-3') } } },
    elements: { line: { tension: .3, borderWidth: 2.5 }, point: { radius: 3 } },
  };
  const line = (id, sets, opts = {}) => {
    const el = document.getElementById('chart-' + id);
    if (el) new Chart(el, { type: 'line', data: { labels, datasets: sets }, options: Object.assign({}, base, opts) });
  };
  line('bp', [
    { label: 'Top (systolic)', data: <?= json_encode($series('systolic')) ?>, borderColor: c('--leaf'), backgroundColor: c('--leaf') },
    { label: 'Bottom (diastolic)', data: <?= json_encode($series('diastolic')) ?>, borderColor: c('--leaf-light'), backgroundColor: c('--leaf-light') },
  ], { plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 10 } } } });
  line('weight', [{ label: 'Weight', data: <?= json_encode($series('weight_kg')) ?>, borderColor: c('--ink'), backgroundColor: c('--ink') }]);
  line('glucose', [{ label: 'Sugar', data: <?= json_encode($series('glucose')) ?>, borderColor: c('--amber-dark'), backgroundColor: c('--amber-dark') }]);
  line('wellbeing', [
    { label: 'Mood', data: <?= json_encode($series('mood')) ?>, borderColor: c('--leaf'), backgroundColor: c('--leaf') },
    { label: 'Energy', data: <?= json_encode($series('energy')) ?>, borderColor: c('--amber'), backgroundColor: c('--amber') },
  ], { scales: Object.assign({}, base.scales, { y: { min: 1, max: 5, ticks: { stepSize: 1 } } }),
       plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 10 } } } });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
