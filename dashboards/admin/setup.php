<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'setup';
$page_title  = 'Setup checklist — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $done = json_decode((string) get_setting('setup_done', '{}'), true) ?: [];
    $key  = preg_replace('/[^a-z0-9-]/', '', (string) ($_POST['task'] ?? '')) ?? '';
    if ($key !== '') {
        if (!empty($_POST['done'])) {
            $done[$key] = date('Y-m-d H:i');
        } else {
            unset($done[$key]);
        }
        set_setting('setup_done', json_encode($done));
    }
    back('task-' . $key);
}

$tasks  = setup_tasks();
$total  = count($tasks);
$done   = count(array_filter($tasks, fn($t) => $t['done']));
$groups = [];
foreach ($tasks as $t) {
    $groups[$t['group']][] = $t;
}
?>

<div class="dash-head">
  <div>
    <h1>Setup checklist</h1>
    <p>Everything needed to take CureMeds live, in order. Tick each step as you finish it — steps the site can check tick themselves.</p>
  </div>
</div>

<div class="card-fc card-fc--pad mb-3">
  <div class="d-flex justify-content-between align-items-baseline mb-2">
    <b style="font-family:var(--font-display);font-size:1.15rem"><?= $done ?> of <?= $total ?> done</b>
    <span class="text-muted-3" style="font-size:.82rem"><?= $total - $done ?> to go</span>
  </div>
  <div class="setup-progress"><span style="width:<?= $total ? round($done / $total * 100) : 0 ?>%"></span></div>
  <p class="text-muted-2 mt-3 mb-0" style="font-size:.86rem">
    <span class="setup-task__tag setup-task__tag--you">You</span> = a step you do in cPanel, GitHub or Meta.
    <span class="setup-task__tag ms-2">Site</span> = already done in the code. Stuck on a step? Send a screenshot of the screen you're on.
  </p>
</div>

<div class="row g-3">
  <?php foreach ($groups as $group => $items):
    $gDone = count(array_filter($items, fn($t) => $t['done'])); ?>
  <div class="col-xl-6">
    <div class="card-fc card-fc--pad h-100">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <h2 style="font-size:1.05rem;margin:0"><?= e($group) ?></h2>
        <span class="pill pill--<?= $gDone === count($items) ? 'ok' : 'muted' ?>"><?= $gDone ?>/<?= count($items) ?></span>
      </div>
      <?php foreach ($items as $t): ?>
        <div class="setup-task <?= $t['done'] ? 'is-done' : '' ?>" id="task-<?= e($t['key']) ?>">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="task" value="<?= e($t['key']) ?>">
            <input type="hidden" name="done" value="<?= $t['done'] ? '' : '1' ?>">
            <input type="checkbox" <?= $t['done'] ? 'checked' : '' ?> <?= $t['auto'] === true ? 'disabled title="Detected automatically"' : '' ?>
                   onchange="this.form.submit()" aria-label="Mark “<?= e($t['title']) ?>” as <?= $t['done'] ? 'not done' : 'done' ?>">
          </form>
          <div class="setup-task__body">
            <b><?= e($t['title']) ?><?= $t['auto'] === true ? ' <i class="fa-solid fa-wand-magic-sparkles text-leaf" title="Detected automatically" style="font-size:.75rem"></i>' : '' ?></b>
            <p><?= $t['html'] ?></p>
          </div>
          <span class="setup-task__tag <?= $t['who'] === 'you' ? 'setup-task__tag--you' : '' ?>"><?= $t['who'] === 'you' ? 'You' : 'Site' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
