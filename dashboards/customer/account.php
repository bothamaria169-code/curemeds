<?php
declare(strict_types=1);
$dash_role   = 'customer';
$dash_active = 'account';
$page_title  = 'My details — CureMeds';
require_once __DIR__ . '/../../includes/dash-head.php';

$uid    = (int) $user['id'];
$errors = [];

$row = db()->prepare('SELECT * FROM users WHERE id = :id');
$row->execute([':id' => $uid]);
$me = $row->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'details') {
        $name  = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        if (mb_strlen($name) < 2)                       { $errors[] = 'Enter your name.'; }
        if ($email !== '' && !safe_email($email))       { $errors[] = 'That email address doesn\'t look right.'; }
        if (!$errors && $email !== '' && $email !== (string) $me['email']) {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = :e AND id <> :id LIMIT 1');
            $stmt->execute([':e' => $email, ':id' => $uid]);
            if ($stmt->fetch()) { $errors[] = 'Another account already uses that email address.'; }
        }
        if (!$errors) {
            db_update('users', $uid, [
                'name'              => mb_substr(strip_tags($name), 0, 120),
                'email'             => $email !== '' ? $email : null,
                'reminders_enabled' => !empty($_POST['reminders_enabled']) ? 1 : 0,
            ]);
            flash('success', 'Your details are saved.');
            back();
        }
    }

    if ($action === 'health') {
        $clean = fn(string $k, int $len) => mb_substr(strip_tags(trim((string) ($_POST[$k] ?? ''))), 0, $len) ?: null;
        $dob = (string) ($_POST['date_of_birth'] ?? '');
        db_update('users', $uid, [
            'date_of_birth'   => $dob !== '' && strtotime($dob) && $dob < date('Y-m-d') ? date('Y-m-d', strtotime($dob)) : null,
            'gender'          => $clean('gender', 20),
            'conditions'      => $clean('conditions', 1000),
            'allergies'       => $clean('allergies', 1000),
            'doctor_name'     => $clean('doctor_name', 120),
            'emergency_name'  => $clean('emergency_name', 120),
            'emergency_phone' => Health::normalizePhone((string) ($_POST['emergency_phone'] ?? '')),
        ]);
        flash('success', 'Health profile saved. Our team uses it to give you better advice.');
        back('health');
    }

    if ($action === 'pin') {
        $current = trim((string) ($_POST['current_pin'] ?? ''));
        $new     = trim((string) ($_POST['new_pin'] ?? ''));
        if (empty($me['pin_hash']) || !password_verify($current, (string) $me['pin_hash'])) { $errors[] = 'Your current PIN is not right.'; }
        elseif (!Health::validPin($new))      { $errors[] = 'Choose a 4-digit PIN that isn\'t easy to guess.'; }
        elseif ($new !== trim((string) ($_POST['confirm_pin'] ?? ''))) { $errors[] = 'The two new PINs don\'t match.'; }
        else {
            db_update('users', $uid, ['pin_hash' => Health::hashPin($new)]);
            flash('success', 'PIN changed. Use it next time you sign in.');
            back();
        }
    }

    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        if (empty($me['password']) || !password_verify($current, (string) $me['password'])) { $errors[] = 'Your current password is not right.'; }
        elseif (mb_strlen($new) < 10) { $errors[] = 'The new password needs at least 10 characters.'; }
        elseif ($new !== (string) ($_POST['confirm_password'] ?? '')) { $errors[] = 'The two new passwords do not match.'; }
        else {
            db_update('users', $uid, ['password' => password_hash($new, PASSWORD_BCRYPT, ['cost' => 12])]);
            flash('success', 'Password changed.');
            back();
        }
    }
}
?>

<div class="dash-head">
  <div><h1>My details</h1><p>Your contact details, reminders, health profile and PIN.</p></div>
</div>

<?= flash_html() ?>
<?php foreach ($errors as $err): ?>
  <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div>
<?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" class="card-fc card-fc--pad">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="details">
      <h2 style="font-size:1.05rem">Contact &amp; reminders</h2>
      <div class="field">
        <label for="name">Name</label>
        <input class="input-fc" type="text" id="name" name="name" value="<?= e((string) $me['name']) ?>" required>
      </div>
      <div class="field">
        <label>Cellphone (your sign-in)</label>
        <input class="input-fc" value="<?= e(Health::displayPhone($me['phone'] ?? '')) ?>" disabled>
        <div class="field__hint">To change it, <a href="<?= e(wa_link('Hi CureMeds, I need to change the cellphone number on my patient portal.')) ?>" target="_blank" rel="noopener">WhatsApp us</a>.</div>
      </div>
      <div class="field">
        <label for="email">Email address</label>
        <input class="input-fc" type="email" id="email" name="email" value="<?= e((string) $me['email']) ?>" placeholder="you@email.co.za">
        <div class="field__hint">Dose reminders and replies to your reports go here.</div>
      </div>
      <label class="opt-card mb-3">
        <input type="checkbox" name="reminders_enabled" value="1" <?= $me['reminders_enabled'] ? 'checked' : '' ?>>
        <span class="opt-card__blurb">Email me dose and refill reminders</span>
      </label>
      <button class="btn-fc" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
    </form>

    <?php if (!empty($me['pin_hash'])): ?>
    <form method="post" class="card-fc card-fc--pad mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="pin">
      <h2 style="font-size:1.05rem">Change PIN</h2>
      <div class="row g-2">
        <?php foreach (['current_pin' => 'Current PIN', 'new_pin' => 'New PIN', 'confirm_pin' => 'New PIN again'] as $k => $label): ?>
        <div class="col-4 field">
          <label for="<?= $k ?>" style="font-size:.8rem"><?= $label ?></label>
          <input class="input-fc input-pin" style="font-size:1.1rem;letter-spacing:.4em" type="password" id="<?= $k ?>" name="<?= $k ?>" inputmode="numeric" maxlength="4" pattern="\d{4}" required autocomplete="<?= $k === 'current_pin' ? 'current-password' : 'new-password' ?>">
        </div>
        <?php endforeach; ?>
      </div>
      <button class="btn-fc btn-fc--ghost" type="submit"><i class="fa-solid fa-key"></i> Change PIN</button>
    </form>
    <?php endif; ?>

    <?php if (!empty($me['password'])): ?>
    <form method="post" class="card-fc card-fc--pad mt-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <h2 style="font-size:1.05rem">Change staff password</h2>
      <div class="field"><label for="current_password">Current password</label>
        <input class="input-fc" type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>
      <div class="field"><label for="new_password">New password</label>
        <input class="input-fc" type="password" id="new_password" name="new_password" required minlength="10" autocomplete="new-password"></div>
      <div class="field"><label for="confirm_password">New password again</label>
        <input class="input-fc" type="password" id="confirm_password" name="confirm_password" required minlength="10" autocomplete="new-password"></div>
      <button class="btn-fc btn-fc--ghost" type="submit"><i class="fa-solid fa-key"></i> Change password</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="col-lg-6">
    <form method="post" class="card-fc card-fc--pad" id="health">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="health">
      <h2 style="font-size:1.05rem">Health profile</h2>
      <p class="text-muted-2" style="font-size:.85rem">Optional. It helps our team answer your questions safely. Only you and authorised CureMeds staff see it.</p>
      <div class="row g-2">
        <div class="col-6 field">
          <label for="date_of_birth">Date of birth</label>
          <input class="input-fc" type="date" id="date_of_birth" name="date_of_birth" value="<?= e((string) $me['date_of_birth']) ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-6 field">
          <label for="gender">Gender</label>
          <select class="input-fc" id="gender" name="gender">
            <?php foreach (['' => 'Prefer not to say', 'female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= (string) $me['gender'] === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="conditions">Conditions you're managing</label>
        <textarea class="input-fc" id="conditions" name="conditions" rows="2" placeholder="e.g. High blood pressure, type 2 diabetes"><?= e((string) $me['conditions']) ?></textarea>
      </div>
      <div class="field">
        <label for="allergies">Allergies</label>
        <textarea class="input-fc" id="allergies" name="allergies" rows="2" placeholder="e.g. Penicillin, shellfish"><?= e((string) $me['allergies']) ?></textarea>
      </div>
      <div class="field">
        <label for="doctor_name">Your doctor or clinic</label>
        <input class="input-fc" id="doctor_name" name="doctor_name" value="<?= e((string) $me['doctor_name']) ?>">
      </div>
      <div class="row g-2">
        <div class="col-6 field">
          <label for="emergency_name">Emergency contact</label>
          <input class="input-fc" id="emergency_name" name="emergency_name" value="<?= e((string) $me['emergency_name']) ?>">
        </div>
        <div class="col-6 field">
          <label for="emergency_phone">Their number</label>
          <input class="input-fc" type="tel" id="emergency_phone" name="emergency_phone" value="<?= e(Health::displayPhone($me['emergency_phone'] ?? '')) ?>">
        </div>
      </div>
      <button class="btn-fc" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save health profile</button>
    </form>

    <div class="card-fc card-fc--pad mt-3">
      <h2 style="font-size:1.05rem">Your information</h2>
      <p style="font-size:.86rem;color:var(--text-2)">You can ask for a copy of everything we hold about you, or for your health records to be deleted, at any time.</p>
      <a href="<?= e(wa_link('Hi CureMeds, I would like a copy / deletion of my health records. My number is ' . Health::displayPhone($me['phone'] ?? '') . '.')) ?>" target="_blank" rel="noopener" class="btn-fc btn-fc--light btn-fc--sm"><i class="fa-brands fa-whatsapp"></i> Ask on WhatsApp</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
