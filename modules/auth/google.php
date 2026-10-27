<?php
declare(strict_types=1);

/**
 * "Continue with Google": sends the visitor to Google, and on the way back
 * signs them in (see classes/GoogleAuth.php).
 */
require_once __DIR__ . '/../../includes/functions.php';

if (!GoogleAuth::enabled()) {
    flash('danger', 'Google sign-in is not switched on yet.');
    redirect('modules/auth/login.php?mode=email');
}

// Leaving for Google.
if (($_GET['code'] ?? '') === '' && ($_GET['error'] ?? '') === '') {
    $_SESSION['google_state']    = bin2hex(random_bytes(16));
    $_SESSION['google_redirect'] = (string) ($_GET['redirect'] ?? '');
    redirect(GoogleAuth::loginUrl($_SESSION['google_state']));
}

// Back from Google.
$state  = (string) ($_SESSION['google_state'] ?? '');
$target = (string) ($_SESSION['google_redirect'] ?? '');
unset($_SESSION['google_state'], $_SESSION['google_redirect']);
$back = 'modules/auth/login.php?mode=email' . ($target !== '' ? '&redirect=' . urlencode($target) : '');

if ($state === '' || !hash_equals($state, (string) ($_GET['state'] ?? ''))) {
    flash('danger', 'That Google sign-in had expired. Please try again.');
    redirect($back);
}
if (($_GET['error'] ?? '') !== '') {
    flash('info', 'Google sign-in was cancelled.');
    redirect($back);
}
try {
    $user = GoogleAuth::userFor(GoogleAuth::profile((string) $_GET['code']));
} catch (Throwable $e) {
    error_log('Google sign-in failed: ' . $e->getMessage());
    flash('danger', e($e->getMessage()));
    redirect($back);
}
if (!$user['is_active']) {
    flash('danger', 'That account is disabled.');
    redirect($back);
}

JWTHandler::setToken($user);
session_regenerate_id(true);
$home = $user['role'] === 'admin' ? 'dashboards/admin/index.php' : 'dashboards/customer/orders.php';
redirect(safe_local_path($target, $home));
