<?php
declare(strict_types=1);

/**
 * Renders a campaign exactly as a subscriber would receive it.
 *
 *   POST (compose form fields) — live preview of unsaved edits
 *   GET  ?id=N                 — a saved campaign; a sent one shows the frozen
 *                                copy that actually went out
 *
 * Read-only, so no CSRF token; admin-only, like every page in this folder.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_newsletter-form.php';

require_auth('admin');
NewsletterService::ensureSchema();

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');

$unsub = SITE_URL . '/modules/newsletter/unsubscribe.php?token=test';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $row = nl_campaign_from_input($_POST)['row'];
    echo email_wrap($row['body_html'], $unsub, (string) $row['preheader']);
    exit;
}

$campaign = NewsletterService::find((int) ($_GET['id'] ?? 0));
if (!$campaign) {
    http_response_code(404);
    exit('Campaign not found.');
}

$body = $campaign['status'] === 'draft'
    ? NewsletterService::renderBody($campaign)
    : (string) $campaign['body_html'];

echo email_wrap($body, $unsub, (string) ($campaign['preheader'] ?? ''));
