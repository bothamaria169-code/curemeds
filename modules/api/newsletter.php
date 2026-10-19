<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

// A single browser session should not be able to hammer the subscriber table.
if (!rate_limit('newsletter', 6, 600)) {
    api_fail('Too many attempts. Try again in a few minutes.', 429);
}

$in  = api_input();
$res = NewsletterService::subscribe(
    (string) ($in['email'] ?? ''),
    $in['name'] ?? null,
    (string) ($in['source'] ?? 'footer'),
    Pricing::isReseller(current_user()) ? 'reseller' : 'retail'
);

$coupon = null;
if ($res['ok']) {
    // Unlocks the gate and stops the popup, on this and every later page.
    NewsletterService::rememberBrowser();

    if ($res['new']) {
        NewsletterService::sendWelcome((string) safe_email((string) $in['email']));
    }
    $coupon = NewsletterService::welcomeCoupon();
}

json_response([
    'success' => $res['ok'],
    'message' => $res['message'],
    'coupon'  => $coupon ? $coupon['code'] : null,
    'offer'   => $coupon ? NewsletterService::describeCoupon($coupon) : null,
]);
