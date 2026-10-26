<?php
declare(strict_types=1);

/**
 * Admin → AI & social media → Connect TikTok.
 *
 * Sends the admin to TikTok to approve the CureMeds app, then saves the tokens
 * that let the site send reels to the TikTok inbox as drafts (see TikTok).
 */
require_once __DIR__ . '/../../includes/functions.php';
require_auth('admin');

if (!TikTok::ready()) {
    flash('danger', 'Save the TikTok Client key and Client secret first.');
    redirect('dashboards/admin/social.php#tiktok');
}

// Leaving for TikTok.
if (($_GET['code'] ?? '') === '' && ($_GET['error'] ?? '') === '') {
    $_SESSION['tiktok_state'] = bin2hex(random_bytes(16));
    redirect(TikTok::loginUrl($_SESSION['tiktok_state']));
}

// Back from TikTok.
$state = (string) ($_SESSION['tiktok_state'] ?? '');
unset($_SESSION['tiktok_state']);
if ($state === '' || !hash_equals($state, (string) ($_GET['state'] ?? ''))) {
    flash('danger', 'That TikTok sign-in had expired. Please press Connect TikTok again.');
} elseif (($_GET['error'] ?? '') !== '') {
    flash('danger', 'TikTok said: ' . e((string) ($_GET['error_description'] ?? $_GET['error'])));
} elseif (($err = TikTok::exchangeCode((string) $_GET['code'])) !== '') {
    flash('danger', e($err));
} else {
    $name = TikTok::config()['name'];
    flash('success', 'TikTok connected' . ($name !== '' ? ' as ' . e($name) : '') . '. Reels now go to its inbox as drafts.');
}
redirect('dashboards/admin/social.php#tiktok');
