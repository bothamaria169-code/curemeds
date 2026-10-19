<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';

/**
 * Unsubscribe.
 *
 * Opening the link only asks; the button does it. Corporate mail scanners and
 * link-preview bots open every URL in an email, and if a plain GET
 * unsubscribed, those scanners would quietly take real people off the list.
 *
 * A POST unsubscribes straight away. That covers the button below and the
 * RFC 8058 one-click POST that Gmail and Yahoo send from their own
 * "Unsubscribe" button — which carries no CSRF token, so the secret token in
 * the URL is the authorisation, exactly as in the link itself.
 */

$token  = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$isTest = $token === 'test';
$state  = 'ask';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isTest) {
    $state = ($token !== '' && NewsletterService::unsubscribe($token)) ? 'done' : 'bad';

    // Mail providers' one-click POST wants a status code, not a page.
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
        http_response_code($state === 'done' ? 200 : 404);
        exit;
    }
} elseif ($token === '') {
    $state = 'bad';
}

if ($state === 'ask' && !$isTest) {
    $stmt = db()->prepare('SELECT email, status FROM newsletter_subscribers WHERE token = :t LIMIT 1');
    $stmt->execute([':t' => $token]);
    $sub = $stmt->fetch();
    if (!$sub) {
        $state = 'bad';
    } elseif ($sub['status'] !== 'active') {
        $state = 'done';
    }
}

$page_title       = 'Unsubscribe — ' . SITE_NAME;
$meta_description = 'Manage your CureMeds email subscription.';
$meta_robots      = 'noindex';
include __DIR__ . '/../../includes/header.php';
?>

<div class="section">
  <div class="container" style="max-width:520px">
    <div class="card-fc card-fc--pad text-center">
      <?php if ($isTest): ?>
        <div class="empty__icon" style="background:var(--leaf-wash);color:var(--leaf)"><i class="fa-solid fa-flask"></i></div>
        <h1 style="font-size:1.5rem">This was a test email</h1>
        <p class="lede">The unsubscribe link works. In a real campaign, each person gets their own link here.</p>
        <a href="<?= SITE_URL ?>/index.php" class="btn-fc mt-2">Back to the shop</a>

      <?php elseif ($state === 'ask'): ?>
        <div class="empty__icon"><i class="fa-solid fa-envelope-open-text"></i></div>
        <h1 style="font-size:1.5rem">Unsubscribe from CureMeds emails?</h1>
        <p class="lede">
          <b><?= e(mask_email((string) $sub['email'])) ?></b> will stop getting new arrivals, restocks and offers.
          Order confirmations and delivery updates still come through.
        </p>
        <form method="post" class="mt-3">
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <button class="btn-fc" type="submit"><i class="fa-solid fa-user-minus"></i> Yes, unsubscribe me</button>
        </form>
        <a href="<?= SITE_URL ?>/index.php" class="d-inline-block mt-3 text-muted-2" style="font-size:.88rem">No, keep me on the list</a>

      <?php elseif ($state === 'done'): ?>
        <div class="empty__icon" style="background:var(--leaf-wash);color:var(--leaf)"><i class="fa-solid fa-circle-check"></i></div>
        <h1 style="font-size:1.5rem">You are unsubscribed</h1>
        <p class="lede">No more marketing emails from us. Your account and order history are untouched.</p>
        <a href="<?= SITE_URL ?>/index.php" class="btn-fc mt-2">Back to the shop</a>

      <?php else: ?>
        <div class="empty__icon"><i class="fa-solid fa-link-slash"></i></div>
        <h1 style="font-size:1.5rem">That link did not work</h1>
        <p class="lede">It may be incomplete. If you are still getting mail from us, tell us and we will take you off by hand.</p>
        <a href="<?= SITE_URL ?>/contact.php" class="btn-fc mt-2">Contact us</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
