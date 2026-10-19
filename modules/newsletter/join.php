<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';

/**
 * Plain-form fallback for the sign-up gate.
 *
 * The gate submits through fetch() when JavaScript is running. Without it —
 * or if the script fails to load — this is where the form posts, so a locked
 * gate can never become a dead end. Redirects back to the page the visitor
 * was on.
 */

/** Only ever redirect to a path on this site, never an arbitrary URL from the form. */
function join_return_path(string $raw): string
{
    $path = parse_url($raw, PHP_URL_PATH) ?: '/';
    $query = parse_url($raw, PHP_URL_QUERY);
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
        $path = '/';
    }
    return $path . ($query ? '?' . $query : '');
}

$back = join_return_path((string) ($_POST['return_to'] ?? '/'));
$host = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? SITE_DOMAIN);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

if (!rate_limit('newsletter', 6, 600)) {
    $res = ['ok' => false, 'message' => 'Too many attempts. Try again in a few minutes.', 'new' => false];
} else {
    $email = (string) ($_POST['email'] ?? '');
    $res   = NewsletterService::subscribe($email, null, (string) ($_POST['source'] ?? 'gate'), 'retail');
    if ($res['ok']) {
        NewsletterService::rememberBrowser();
        if ($res['new']) {
            NewsletterService::sendWelcome((string) safe_email($email));
        }
    }
}

$sep = str_contains($back, '?') ? '&' : '?';
header('Location: ' . $host . $back . ($res['ok'] ? $sep . 'joined=1' : $sep . 'nl_error=' . urlencode($res['message'])));
exit;
