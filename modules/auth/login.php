<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';

if (is_logged_in()) {
    redirect(current_user()['role'] === 'admin' ? 'dashboards/admin/index.php' : 'dashboards/customer/index.php');
}

// Patients sign in with cellphone + PIN; shoppers and staff with email + password or Google.
$mode     = in_array($_GET['mode'] ?? $_POST['mode'] ?? '', ['staff', 'email'], true) ? 'staff' : 'patient';
$redirect = (string) ($_GET['redirect'] ?? $_POST['redirect'] ?? '');
$errors   = [];
$email    = '';
$phone    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = null;
    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($mode === 'patient') {
        $phone  = trim((string) ($_POST['phone'] ?? ''));
        $result = Health::pinLogin($phone, trim((string) ($_POST['pin'] ?? '')));
        $result['ok'] ? $user = $result['user'] : $errors[] = $result['error'];
    } else {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!rate_limit('login_' . md5($email), 8, 900)) {
            $errors[] = 'Too many attempts. Wait fifteen minutes and try again.';
        } else {
            $stmt = db()->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
            $stmt->execute([':e' => $email]);
            $row = $stmt->fetch();
            // One message for both branches, so the form can't reveal which emails have accounts.
            if (!$row || empty($row['password']) || !password_verify($password, $row['password'])) {
                $errors[] = 'Those details do not match an account.';
            } elseif (!$row['is_active']) {
                $errors[] = 'That account is disabled.';
            } else {
                $user = $row;
                db()->prepare('UPDATE users SET last_login_at = :n WHERE id = :id')->execute([':n' => date('Y-m-d H:i:s'), ':id' => $row['id']]);
            }
        }
    }

    if ($user) {
        JWTHandler::setToken($user);
        session_regenerate_id(true);
        $home = $user['role'] === 'admin' ? 'dashboards/admin/index.php' : 'dashboards/customer/index.php';
        // Guest orders placed with this email join the account.
        if (!empty($user['email'])) {
            db()->prepare('UPDATE orders SET user_id = :u WHERE user_id IS NULL AND customer_email = :e')->execute([':u' => (int) $user['id'], ':e' => (string) $user['email']]);
        }
        $target = safe_local_path($redirect, $home);
        // Staff who followed a "Patient portal" link still belong in the admin.
        if ($user['role'] === 'admin' && str_starts_with($target, 'dashboards/customer/')) {
            $target = $home;
        }
        redirect($target);
    }
}

$page_title = 'Sign in — ' . SITE_NAME;
$tabUrl = fn(string $m) => '?' . http_build_query(array_filter(['mode' => $m === 'staff' ? 'staff' : null, 'redirect' => $redirect ?: null]));
?>
<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?></title>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0A1B3D">
<link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/img/logo/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<?php foreach (['theme', 'components', 'layout', 'pages', 'responsive', 'curemeds'] as $css): ?>
<link rel="stylesheet" href="<?= asset("assets/css/$css.css") ?>">
<?php endforeach; ?>
</head>
<body>
<div class="auth-shell">

  <aside class="auth-aside" style="background-image:linear-gradient(160deg,rgba(10,27,61,.94),rgba(14,64,160,.86)),url('<?= SITE_URL ?>/assets/img/stock/patient-care.jpg');background-size:cover;background-position:center">
    <a href="<?= SITE_URL ?>/index.php" class="brand" style="position:relative;z-index:1">
      <img src="<?= SITE_URL ?>/assets/img/logo/curemeds-mark.png" alt="" class="brand__mark">
      <span class="brand__word" style="color:#fff">Cure<em style="color:var(--leaf-light)">Meds</em></span>
    </a>

    <div style="position:relative;z-index:1">
      <h2>Your health tracker.</h2>
      <p style="color:var(--on-dark-2);max-width:40ch;">Today's doses, your readings and anything you've told us — all in one place.</p>
      <ul class="list-unstyled mt-4">
        <li><i class="fa-solid fa-check"></i><span>Tick off each dose and build your streak</span></li>
        <li><i class="fa-solid fa-check"></i><span>Email reminders at every dose time</span></li>
        <li><i class="fa-solid fa-check"></i><span>Report side effects — our team replies</span></li>
      </ul>
    </div>

    <p style="position:relative;z-index:1;font-size:.8rem;color:var(--on-dark-2);margin:0">
      &copy; <?= date('Y') ?> CureMeds · Private and POPIA-compliant
    </p>
  </aside>

  <div class="auth-form">
    <div class="auth-form__inner">
      <a href="<?= SITE_URL ?>/index.php" class="d-lg-none brand mb-4">
        <img src="<?= SITE_URL ?>/assets/img/logo/curemeds-mark.png" alt="" class="brand__mark">
        <span class="brand__word">Cure<em>Meds</em></span>
      </a>

      <h1 style="font-size:1.9rem">Sign in</h1>
      <p class="text-muted-2 mb-4">Shopping? Use <b>Continue with Google</b> or check out as a guest. New patient? <a href="<?= SITE_URL ?>/modules/auth/register.php" class="text-leaf fw-bold">Create your tracker</a></p>

      <nav class="auth-tabs" aria-label="Sign-in type">
        <a href="<?= e($tabUrl('patient')) ?>" class="<?= $mode === 'patient' ? 'is-on' : '' ?>"><i class="fa-solid fa-mobile-screen me-1"></i> Patient</a>
        <a href="<?= e($tabUrl('staff')) ?>" class="<?= $mode === 'staff' ? 'is-on' : '' ?>"><i class="fa-solid fa-envelope me-1"></i> Email</a>
      </nav>

      <?= flash_html() ?>
      <?php foreach ($errors as $err): ?>
        <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div>
      <?php endforeach; ?>

      <?php if (google_login_enabled()): ?>
        <a class="btn-fc btn-fc--ghost btn-fc--block btn-fc--lg mb-3" href="<?= SITE_URL ?>/modules/auth/google.php<?= $redirect !== '' ? '?redirect=' . urlencode($redirect) : '' ?>">
          <i class="fa-brands fa-google"></i> Continue with Google
        </a>
        <p class="text-center text-muted-3 mb-3" style="font-size:.8rem">or</p>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="<?= e($mode) ?>">
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">

        <?php if ($mode === 'patient'): ?>
          <div class="field">
            <label for="phone">Cellphone number</label>
            <input class="input-fc" type="tel" id="phone" name="phone" value="<?= e($phone) ?>" placeholder="082 123 4567"
                   inputmode="tel" required autocomplete="tel" autofocus>
          </div>
          <div class="field">
            <label for="pin">4-digit PIN</label>
            <input class="input-fc input-pin" type="password" id="pin" name="pin" inputmode="numeric" pattern="\d{4}"
                   maxlength="4" placeholder="••••" required autocomplete="current-password">
            <div class="field__hint">
              Forgot it? <a href="<?= e(wa_link('Hi CureMeds, I forgot my patient portal PIN. Please reset it for this number.')) ?>" target="_blank" rel="noopener">WhatsApp us from your registered number</a> and we'll reset it.
            </div>
          </div>
        <?php else: ?>
          <div class="field">
            <label for="email">Email address</label>
            <input class="input-fc" type="email" id="email" name="email" value="<?= e($email) ?>" required autocomplete="email" autofocus>
          </div>
          <div class="field">
            <label for="password">Password</label>
            <input class="input-fc" type="password" id="password" name="password" required autocomplete="current-password">
            <div class="field__hint"><a href="<?= SITE_URL ?>/modules/auth/forgot.php">Forgot your password?</a></div>
          </div>
        <?php endif; ?>

        <button class="btn-fc btn-fc--block btn-fc--lg" type="submit">Sign in</button>
      </form>

      <p class="text-center mt-4 mb-0" style="font-size:.85rem">
        <a href="<?= SITE_URL ?>/index.php" class="text-muted-2"><i class="fa-solid fa-arrow-left me-1"></i>Back to the shop</a>
      </p>
    </div>
  </div>
</div>
<script>
  // Submit as soon as the fourth digit is in: one less tap on a phone.
  document.getElementById('pin')?.addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 4);
    if (this.value.length === 4 && document.getElementById('phone').value.trim()) this.form.requestSubmit();
  });
</script>
</body>
</html>
