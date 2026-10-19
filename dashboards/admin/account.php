<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'account';
$page_title  = 'My account — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$uid    = (int) $user['id'];
$errors = [];

$row = db()->prepare('SELECT * FROM users WHERE id = :id');
$row->execute([':id' => $uid]);
$me = $row->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'details') {
        $name  = trim(strip_tags((string) ($_POST['name'] ?? '')));
        $email = trim((string) ($_POST['email'] ?? ''));
        if (mb_strlen($name) < 2)  { $errors[] = 'Enter your name.'; }
        if (!safe_email($email))   { $errors[] = 'Enter a valid email address — you sign in with it.'; }
        if (!$errors && $email !== (string) $me['email']) {
            $dup = db()->prepare('SELECT id FROM users WHERE email = :e AND id <> :id LIMIT 1');
            $dup->execute([':e' => $email, ':id' => $uid]);
            if ($dup->fetch()) { $errors[] = 'Another account already uses that email address.'; }
        }
        if (!$errors) {
            db_update('users', $uid, ['name' => mb_substr($name, 0, 120), 'email' => $email]);
            flash('success', 'Your details are saved.' . ($email !== (string) $me['email'] ? ' Sign in with <b>' . e($email) . '</b> from now on.' : ''));
            back();
        }
    }

    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        if (!rate_limit('admin_pw_change', 10, 900))                 { $errors[] = 'Too many attempts. Wait fifteen minutes.'; }
        elseif (empty($me['password']) || !password_verify($current, (string) $me['password'])) { $errors[] = 'Your current password is not right.'; }
        elseif (mb_strlen($new) < 10)                                { $errors[] = 'The new password needs at least 10 characters.'; }
        elseif ($new === $current)                                   { $errors[] = 'Choose a password different from the current one.'; }
        elseif ($new !== (string) ($_POST['confirm_password'] ?? '')) { $errors[] = 'The two new passwords do not match.'; }
        else {
            db_update('users', $uid, ['password' => password_hash($new, PASSWORD_BCRYPT, ['cost' => 12])]);
            // Tick the setup step off: the temporary password is gone.
            $done = json_decode((string) get_setting('setup_done', '{}'), true) ?: [];
            $done['admin-password'] = date('Y-m-d H:i');
            set_setting('setup_done', json_encode($done));
            flash('success', 'Password changed. Use the new one next time you sign in, and delete ADMIN-LOGIN.txt from the build computer.');
            back();
        }
    }
}
?>

<div class="dash-head">
  <div><h1>My account</h1><p>Your staff sign-in: name, email and password.</p></div>
</div>

<?= flash_html() ?>
<?php foreach ($errors as $err): ?>
  <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div>
<?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" class="card-fc card-fc--pad">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <h2 style="font-size:1.1rem"><i class="fa-solid fa-key text-leaf me-1"></i> Change password</h2>
      <div class="field">
        <label for="current_password">Current password</label>
        <input class="input-fc" type="password" id="current_password" name="current_password" required autocomplete="current-password">
        <div class="field__hint">The first time, this is the password in ADMIN-LOGIN.txt.</div>
      </div>
      <div class="field">
        <label for="new_password">New password</label>
        <input class="input-fc" type="password" id="new_password" name="new_password" required minlength="10" autocomplete="new-password">
        <div class="field__hint">At least 10 characters. A short sentence is easiest to remember.</div>
      </div>
      <div class="field">
        <label for="confirm_password">New password again</label>
        <input class="input-fc" type="password" id="confirm_password" name="confirm_password" required minlength="10" autocomplete="new-password">
      </div>
      <button class="btn-fc" type="submit"><i class="fa-solid fa-floppy-disk"></i> Change password</button>
    </form>
  </div>

  <div class="col-lg-6">
    <form method="post" class="card-fc card-fc--pad">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="details">
      <h2 style="font-size:1.1rem">Your details</h2>
      <div class="field">
        <label for="name">Name</label>
        <input class="input-fc" id="name" name="name" value="<?= e((string) $me['name']) ?>" required>
      </div>
      <div class="field">
        <label for="email">Email address (your sign-in)</label>
        <input class="input-fc" type="email" id="email" name="email" value="<?= e((string) $me['email']) ?>" required autocomplete="email">
        <div class="field__hint">Side-effect alerts and new-patient emails go to <?= e(ADMIN_EMAIL) ?>, set in config/secrets.php.</div>
      </div>
      <button class="btn-fc btn-fc--ghost" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save details</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
