<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = safe_email((string) ($_POST['email'] ?? ''));

    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$email) {
        $error = 'Enter a valid email address.';
    } elseif (!rate_limit('forgot', 5, 900)) {
        $error = 'Too many requests. Try again in fifteen minutes.';
    } else {
        $stmt = db()->prepare('SELECT id, name FROM users WHERE email = :e AND is_active = 1 LIMIT 1');
        $stmt->execute([':e' => $email]);

        if ($user = $stmt->fetch()) {
            $token = bin2hex(random_bytes(32));
            db_update('users', (int) $user['id'], [
                'reset_token'   => $token,
                'reset_expires' => date('Y-m-d H:i:s', time() + 3600),
            ]);

            $link = SITE_URL . '/modules/auth/reset.php?token=' . $token;
            Mailer::send($email, 'Reset your CureMeds password', email_wrap(
                '<p>Hi ' . e((string) $user['name']) . ',</p>'
                . '<p>Use the button below to set a new password. The link works once and expires in an hour.</p>'
                . '<p><a href="' . $link . '" style="display:inline-block;background:#1557D0;color:#fff;padding:11px 22px;border-radius:999px;font-weight:700;">Set a new password</a></p>'
                . '<p style="font-size:13px;color:#7C8899;">If you did not ask for this, ignore the email — nothing changes.</p>'
            ));
        }
        // Always report success: whether an address is registered is not something
        // this form should be willing to tell an anonymous visitor.
        $sent = true;
    }
}

$page_title = 'Reset your password — ' . SITE_NAME;
include __DIR__ . '/../../includes/header.php';
?>
<div class="section">
  <div class="container" style="max-width:480px">
    <div class="card-fc card-fc--pad">
      <h1 style="font-size:1.6rem">Reset your password</h1>

      <?php if ($sent): ?>
        <div class="alert-fc alert-fc--ok mt-3">
          <i class="fa-solid fa-envelope-circle-check"></i>
          <span>If that address has an account, a reset link is on its way. It expires in an hour.</span>
        </div>
        <a href="<?= SITE_URL ?>/modules/auth/login.php" class="btn-fc btn-fc--ghost btn-fc--block mt-2">Back to sign in</a>
      <?php else: ?>
        <p class="text-muted-2">Enter the email on your account and we will send you a link.</p>
        <?php if ($error): ?>
          <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($error) ?></span></div>
        <?php endif; ?>
        <form method="post" novalidate>
          <?= csrf_field() ?>
          <div class="field">
            <label for="email">Email address</label>
            <input class="input-fc" type="email" id="email" name="email" required autofocus>
          </div>
          <button class="btn-fc btn-fc--block" type="submit">Send the reset link</button>
        </form>
        <p class="text-center mt-3 mb-0" style="font-size:.85rem">
          <a href="<?= SITE_URL ?>/modules/auth/login.php" class="text-muted-2">Back to sign in</a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
