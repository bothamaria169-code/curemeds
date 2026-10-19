<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';

if (is_logged_in()) {
    redirect('dashboards/customer/index.php');
}

$errors = [];
$values = ['name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['name']  = trim((string) ($_POST['name'] ?? ''));
    $values['email'] = trim((string) ($_POST['email'] ?? ''));
    $values['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $pin             = trim((string) ($_POST['pin'] ?? ''));
    $confirm         = trim((string) ($_POST['confirm'] ?? ''));
    $phone           = Health::normalizePhone($values['phone']);

    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) { $errors[] = 'Your session expired. Please try again.'; }
    if (!rate_limit('register', 5, 3600))                     { $errors[] = 'Too many sign-ups from this device. Try again later.'; }
    if (mb_strlen($values['name']) < 2)                       { $errors[] = 'Enter your name.'; }
    if (!$phone)                                              { $errors[] = 'Enter a South African cellphone number, for example 082 123 4567.'; }
    if ($values['email'] !== '' && !safe_email($values['email'])) { $errors[] = 'That email address doesn\'t look right.'; }
    if (!Health::validPin($pin))                              { $errors[] = 'Choose a 4-digit PIN that isn\'t easy to guess (not 1234 or 0000).'; }
    if ($pin !== $confirm)                                    { $errors[] = 'The two PINs don\'t match.'; }
    if (empty($_POST['consent']))                             { $errors[] = 'Please agree to us keeping your health information so the tracker can work.'; }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM users WHERE phone = :p' . ($values['email'] !== '' ? ' OR email = :e' : '') . ' LIMIT 1');
        $stmt->execute(array_filter([':p' => $phone, ':e' => $values['email'] ?: null]));
        if ($stmt->fetch()) {
            $errors[] = 'That number or email already has a tracker. <a href="login.php" class="fw-bold">Sign in instead</a>, or WhatsApp us if you forgot your PIN.';
        }
    }

    if (!$errors) {
        $userId = db_insert('users', [
            'name'     => mb_substr(strip_tags($values['name']), 0, 120),
            'email'    => $values['email'] !== '' ? $values['email'] : null,
            'phone'    => $phone,
            'pin_hash' => Health::hashPin($pin),
            'role'     => 'customer',
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);
        $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        JWTHandler::setToken($user);
        session_regenerate_id(true);

        if ($values['email'] !== '') {
            $html = '<p>Welcome to CureMeds, ' . e(explode(' ', $values['name'])[0]) . '.</p>'
                  . '<p>Your health tracker is ready. Sign in any time with your cellphone number <b>' . e(Health::displayPhone($phone)) . '</b> and the PIN you chose.</p>'
                  . '<p>Add your medication and dose times, and we\'ll email you a reminder at each one.</p>'
                  . '<p><a href="' . SITE_URL . '/dashboards/customer/medications.php" style="display:inline-block;background:#1557D0;color:#fff;padding:11px 22px;border-radius:999px;font-weight:700;">Add my medication</a></p>';
            Mailer::send($values['email'], 'Your CureMeds health tracker is ready', email_wrap($html));
        }
        Mailer::send(ADMIN_EMAIL, 'New patient sign-up — ' . $values['name'],
            email_wrap('<p><b>' . e($values['name']) . '</b> (' . e(Health::displayPhone($phone)) . ') created a health tracker.</p>'
                . '<p><a href="' . SITE_URL . '/dashboards/admin/patient.php?id=' . $userId . '">Open the patient</a></p>'));

        redirect('dashboards/customer/index.php?welcome=1');
    }
}

$page_title = 'Create your health tracker — ' . SITE_NAME;
?>
<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?></title>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0A1B3D">
<link rel="icon" type="image/svg+xml" href="<?= SITE_URL ?>/assets/img/logo/curemeds-mark.svg">
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

  <aside class="auth-aside" style="background-image:linear-gradient(160deg,rgba(10,27,61,.94),rgba(14,64,160,.86)),url('<?= SITE_URL ?>/assets/img/stock/pill-box.jpg');background-size:cover;background-position:center">
    <a href="<?= SITE_URL ?>/index.php" class="brand" style="position:relative;z-index:1">
      <img src="<?= SITE_URL ?>/assets/img/logo/curemeds-mark.svg" alt="" class="brand__mark">
      <span class="brand__word" style="color:#fff">Cure<em style="color:var(--leaf-light)">Meds</em></span>
    </a>

    <div style="position:relative;z-index:1">
      <h2>Never miss a dose.</h2>
      <p style="color:var(--on-dark-2);max-width:40ch;">A free health tracker for every CureMeds patient. No password — just your cellphone number and a PIN.</p>
      <ul class="list-unstyled mt-4">
        <li><i class="fa-solid fa-check"></i><span>Email reminders at every dose time</span></li>
        <li><i class="fa-solid fa-check"></i><span>Your adherence score and streak</span></li>
        <li><i class="fa-solid fa-check"></i><span>Side-effect reports our team reads and answers</span></li>
        <li><i class="fa-solid fa-check"></i><span>Blood pressure, weight and sugar charts</span></li>
      </ul>
    </div>

    <p style="position:relative;z-index:1;font-size:.8rem;color:var(--on-dark-2);margin:0">
      &copy; <?= date('Y') ?> CureMeds · Private and POPIA-compliant
    </p>
  </aside>

  <div class="auth-form">
    <div class="auth-form__inner">
      <a href="<?= SITE_URL ?>/index.php" class="d-lg-none brand mb-4">
        <img src="<?= SITE_URL ?>/assets/img/logo/curemeds-mark.svg" alt="" class="brand__mark">
        <span class="brand__word">Cure<em>Meds</em></span>
      </a>

      <h1 style="font-size:1.9rem">Create your health tracker</h1>
      <p class="text-muted-2 mb-4">Already have one? <a href="<?= SITE_URL ?>/modules/auth/login.php" class="text-leaf fw-bold">Sign in</a></p>

      <?php foreach ($errors as $err): ?>
        <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= $err ?></span></div>
      <?php endforeach; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label for="name">Your name</label>
          <input class="input-fc" type="text" id="name" name="name" value="<?= e($values['name']) ?>" required autocomplete="name" autofocus>
        </div>
        <div class="field">
          <label for="phone">Cellphone number</label>
          <input class="input-fc" type="tel" id="phone" name="phone" value="<?= e($values['phone']) ?>" placeholder="082 123 4567" inputmode="tel" required autocomplete="tel">
          <div class="field__hint">You'll sign in with this. Use the number you WhatsApp us from.</div>
        </div>
        <div class="field">
          <label for="email">Email address <span class="text-muted-3 fw-normal">(for dose reminders)</span></label>
          <input class="input-fc" type="email" id="email" name="email" value="<?= e($values['email']) ?>" autocomplete="email" placeholder="you@email.co.za">
        </div>
        <div class="row g-3">
          <div class="col-6 field">
            <label for="pin">Choose a 4-digit PIN</label>
            <input class="input-fc input-pin" type="password" id="pin" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="••••" required autocomplete="new-password">
          </div>
          <div class="col-6 field">
            <label for="confirm">Type it again</label>
            <input class="input-fc input-pin" type="password" id="confirm" name="confirm" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="••••" required autocomplete="new-password">
          </div>
        </div>

        <label class="opt-card mb-3">
          <input type="checkbox" name="consent" value="1" required>
          <span class="opt-card__blurb">
            I agree that CureMeds may keep my medication, readings and side-effect reports to run my tracker, as set out in the
            <a href="<?= SITE_URL ?>/privacy.php#health-information" target="_blank" class="text-leaf fw-bold">privacy policy</a>,
            and I accept the <a href="<?= SITE_URL ?>/terms.php" target="_blank" class="text-leaf fw-bold">terms</a>.
          </span>
        </label>

        <button class="btn-fc btn-fc--block btn-fc--lg" type="submit">Create my tracker</button>
      </form>

      <p class="text-center mt-4 mb-0" style="font-size:.85rem">
        <a href="<?= SITE_URL ?>/index.php" class="text-muted-2"><i class="fa-solid fa-arrow-left me-1"></i>Back to the shop</a>
      </p>
    </div>
  </div>
</div>
<script>
  document.querySelectorAll('.input-pin').forEach((el) => el.addEventListener('input', () => { el.value = el.value.replace(/\D/g, '').slice(0, 4); }));
</script>
</body>
</html>
