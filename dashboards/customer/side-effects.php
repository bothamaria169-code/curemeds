<?php
declare(strict_types=1);
$dash_role   = 'customer';
$dash_active = 'side-effects';
$page_title  = 'Side effects — CureMeds';
require_once __DIR__ . '/../../includes/dash-head.php';

$uid    = (int) $user['id'];
$errors = [];
$old    = ['severity' => 'mild', 'medication_id' => (int) ($_GET['medication'] ?? 0)];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    if (!rate_limit('side_effect', 10, 3600)) {
        $errors[] = 'You have sent a lot of reports in the last hour. Please WhatsApp us instead.';
    } else {
        [$id, $errors] = Health::reportSideEffect($uid, $_POST);
        if ($id) {
            flash(($_POST['severity'] ?? '') === 'severe' ? 'warn' : 'success', ($_POST['severity'] ?? '') === 'severe'
                ? '<b>Thank you — our team has been alerted.</b> Because you said it is severe, please also call 10177 or go to a clinic now if you feel very unwell.'
                : 'Thank you. Our team will read your report and reply here.');
            redirect('dashboards/customer/side-effects.php');
        }
    }
    $old = $_POST;
}

$meds    = Health::medications($uid);
$reports = Health::sideEffects($uid);
?>

<div class="dash-head">
  <div><h1>Side effects</h1><p>Tell us how a product is affecting you. Our team reads every report and replies here.</p></div>
</div>

<?= flash_html() ?>
<?php foreach ($errors as $err): ?>
  <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div>
<?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <form class="card-fc card-fc--pad" method="post" id="report">
      <?= csrf_field() ?>
      <h2 style="font-size:1.1rem">Report a side effect</h2>

      <div class="emergency-note mb-3">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span><b>Emergency?</b> Trouble breathing, swelling of the lips, face or throat, chest pain or a rash that spreads — call <b>10177</b> or go to a clinic now. Don't wait for a reply here.</span>
      </div>

      <div class="field">
        <label for="symptom">What are you feeling?</label>
        <input class="input-fc" id="symptom" name="symptom" maxlength="160" required value="<?= e((string) ($old['symptom'] ?? '')) ?>"
               placeholder="e.g. Upset stomach, headache, itchy skin" list="commonSymptoms">
        <datalist id="commonSymptoms">
          <?php foreach (['Nausea / upset stomach', 'Headache', 'Dizziness', 'Diarrhoea', 'Constipation', 'Skin rash or itching', 'Trouble sleeping', 'Tiredness', 'Heartburn', 'Loss of appetite'] as $s): ?>
            <option value="<?= e($s) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>

      <div class="field">
        <label>How bad is it?</label>
        <div class="sev-options">
          <?php foreach (Health::SEVERITIES as $key => $sev): ?>
            <label>
              <input type="radio" name="severity" value="<?= $key ?>" <?= ($old['severity'] ?? 'mild') === $key ? 'checked' : '' ?>>
              <span class="sev sev--<?= $key ?>"><b><?= e($sev['label']) ?></b><span><?= e($sev['hint']) ?></span></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="row g-2">
        <div class="col-md-7 field">
          <label for="medication_id">Which product do you think it is?</label>
          <select class="input-fc" id="medication_id" name="medication_id">
            <option value="">Not sure</option>
            <?php foreach ($meds as $m): ?>
              <option value="<?= (int) $m['id'] ?>" <?= (int) ($old['medication_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5 field">
          <label for="started_on">When did it start?</label>
          <input class="input-fc" type="date" id="started_on" name="started_on" max="<?= date('Y-m-d') ?>" value="<?= e((string) ($old['started_on'] ?? date('Y-m-d'))) ?>">
        </div>
      </div>

      <div class="field">
        <label for="details">Anything else? <span class="text-muted-3 fw-normal">(optional)</span></label>
        <textarea class="input-fc" id="details" name="details" rows="3" maxlength="2000" placeholder="When it happens, how long it lasts, what helps…"><?= e((string) ($old['details'] ?? '')) ?></textarea>
      </div>

      <button class="btn-fc btn-fc--block" type="submit"><i class="fa-solid fa-paper-plane"></i> Send to the CureMeds team</button>
      <p class="field__hint mt-2 mb-0">Don't stop prescribed medication (like ARVs) without speaking to your clinic first.</p>
    </form>
  </div>

  <div class="col-lg-6">
    <div class="card-fc card-fc--pad">
      <h2 style="font-size:1.1rem">Your reports</h2>
      <?php if (!$reports): ?>
        <p class="text-muted-2 mb-0">Nothing reported yet — we hope it stays that way.</p>
      <?php endif; ?>
      <?php foreach ($reports as $s): ?>
        <div class="timeline-item is-<?= e($s['severity']) ?>">
          <div class="d-flex justify-content-between gap-2 flex-wrap">
            <b><?= e($s['symptom']) ?></b>
            <span class="pill pill--<?= Health::SEVERITIES[$s['severity']]['tone'] ?>"><?= ucfirst($s['severity']) ?></span>
          </div>
          <div class="text-muted-3" style="font-size:.78rem">
            <?= date('j M Y, H:i', strtotime($s['created_at'])) ?>
            <?= $s['medication_name'] ? ' · ' . e($s['medication_name']) : '' ?>
            · <?= $s['status'] === 'new' ? 'Waiting for our team' : ($s['status'] === 'resolved' ? 'Resolved' : 'Reviewed') ?>
          </div>
          <?php if ($s['details']): ?><p style="font-size:.86rem;margin:.4rem 0 0"><?= nl2br(e($s['details'])) ?></p><?php endif; ?>
          <?php if ($s['staff_reply']): ?>
            <div class="staff-reply"><b>CureMeds team<?= $s['reviewed_at'] ? ' · ' . date('j M', strtotime($s['reviewed_at'])) : '' ?></b><div><?= nl2br(e($s['staff_reply'])) ?></div></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
