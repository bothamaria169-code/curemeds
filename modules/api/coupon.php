<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

$in = api_input();

if (!empty($in['remove'])) {
    unset($_SESSION['coupon']);
    api_ok(['message' => 'Coupon removed.']);
}

$subtotal = cart_subtotal();
if ($subtotal <= 0) {
    api_fail('Your basket is empty.');
}

$orderType = Pricing::isReseller(current_user()) ? 'reseller' : 'retail';
$result    = validate_coupon((string) ($in['code'] ?? ''), $subtotal, $orderType);

if (!$result['valid']) {
    unset($_SESSION['coupon']);
    api_fail($result['message']);
}

$_SESSION['coupon'] = $result['coupon']['code'];
api_ok([
    'message'  => 'Code applied — you save ' . format_price($result['discount']) . '.',
    'discount' => $result['discount'],
]);
