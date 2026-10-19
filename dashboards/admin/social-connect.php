<?php
declare(strict_types=1);

/**
 * Admin → AI & social media → Connect with Facebook.
 *
 * Sends the admin to Facebook to approve the CureMeds app, then swaps the code
 * that comes back for a Page token that doesn't expire (see SocialPoster).
 * Someone who manages several Pages picks one on social.php.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_auth('admin');

$meta = SocialPoster::config();
if ($meta['app_id'] === '' || $meta['app_secret'] === '') {
    flash('danger', 'Save the Meta app ID and app secret first.');
    redirect('dashboards/admin/social.php#meta');
}

// Choosing between several Pages.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pick') {
    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        redirect('dashboards/admin/social.php#meta');
    }
    $page = $_SESSION['meta_pages'][(string) ($_POST['page'] ?? '')] ?? null;
    unset($_SESSION['meta_pages']);
    if ($page) {
        SocialPoster::savePage($page);
        flash('success', 'Connected to "' . e($page['name']) . '"' . ($page['ig_name'] ? ' and Instagram @' . e($page['ig_name']) : '') . '.');
    } else {
        flash('danger', 'That Page was not in the list. Please connect again.');
    }
    redirect('dashboards/admin/social.php#meta');
}

// Leaving for Facebook.
if (($_GET['code'] ?? '') === '' && ($_GET['error'] ?? '') === '') {
    $_SESSION['meta_state'] = bin2hex(random_bytes(16));
    redirect(SocialPoster::loginUrl($_SESSION['meta_state']));
}

// Back from Facebook.
$expected = (string) ($_SESSION['meta_state'] ?? '');
unset($_SESSION['meta_state']);
if ($expected === '' || !hash_equals($expected, (string) ($_GET['state'] ?? ''))) {
    flash('danger', 'That Facebook connection expired. Please press Connect with Facebook again.');
    redirect('dashboards/admin/social.php#meta');
}
if (($_GET['error'] ?? '') !== '') {
    flash('danger', 'Facebook connection cancelled: ' . e((string) ($_GET['error_description'] ?? $_GET['error'])));
    redirect('dashboards/admin/social.php#meta');
}

$result = SocialPoster::exchangeCode((string) $_GET['code']);
if (!$result['pages']) {
    flash('danger', e($result['error']));
} elseif (count($result['pages']) === 1) {
    $page = reset($result['pages']);
    SocialPoster::savePage($page);
    flash('success', 'Connected to the Facebook Page "' . e($page['name']) . '"'
        . ($page['instagram'] !== '' ? ' and Instagram @' . e($page['ig_name']) . '.' : '. No Instagram Business account is linked to that Page — link it in Meta Business Suite and reconnect.'));
} else {
    $_SESSION['meta_pages'] = $result['pages'];
    flash('info', 'You manage ' . count($result['pages']) . ' Pages — choose CureMeds below.');
}
redirect('dashboards/admin/social.php#meta');
