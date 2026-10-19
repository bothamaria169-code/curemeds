<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$done  = false;

$stmt = db()->prepare('SELECT id, name, email FROM users WHERE reset_token = :t AND reset_expires > NOW() LIMIT 1');
$stmt->execute([':t' => $token]);
$user = $stmt->fetch();

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (mb_strlen($password) < 8) {
        $error = 'Your password needs at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        db_update('users', (int) $user['id'], [
            'password'      => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'reset_token'   => null,
            'reset_expires' => null,
        ]);
        $done = true;
    }
}

$page_title = 'Set a new password — ' . SITE_NAME;
include __DIR__ . '/../../includes/header.php';
?>
<div class="section">
  <div class="container" style="max-width:480px">
    <div class="card-fc card-fc--pad">
      <?php if ($done): ?>
        <h1 style="font-size:1.6rem">Password updated</h1>
        <div class="alert-fc alert-fc--ok"><i class="fa-solid fa-circle-check"></i><span>You can sign in with your new password now.</span></div>
        <a href="<?= SITE_URL ?>/modules/auth/login.php" class="btn-fc btn-fc--block">Sign in</a>

      <?php elseif (!$user): ?>
        <h1 style="font-size:1.6rem">This link has expired</h1>
        <p class="text-muted-2">Reset links work once and last an hour. Request a fresh one.</p>
        <a href="<?= SITE_URL ?>/modules/auth/forgot.php" class="btn-fc btn-fc--block">Request a new link</a>

      <?php else: ?>
        <h1 style="font-size:1.6rem">Set a new password</h1>
        <p class="text-muted-2">For <b><?= e($user['email']) ?></b>.</p>
        <?php if ($error): ?>
          <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($error) ?></span></div>
        <?php endif; ?>
        <form method="post" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="field">
            <label for="password">New password</label>
            <input class="input-fc" type="password" id="password" name="password" required minlength="8" autofocus autocomplete="new-password">
          </div>
          <div class="field">
            <label for="confirm">Confirm new password</label>
            <input class="input-fc" type="password" id="confirm" name="confirm" required minlength="8" autocomplete="new-password">
          </div>
          <button class="btn-fc btn-fc--block" type="submit">Save new password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
