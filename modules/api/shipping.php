<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

// Checkout asks the server for the shipping figure so the free-delivery
// threshold rule lives in exactly one place (functions.php).
$in         = api_input();
$fulfilment = (string) ($in['fulfilment'] ?? 'delivery');
$pickupId   = !empty($in['pickup_point_id']) ? (int) $in['pickup_point_id'] : null;

if (!isset(FULFILMENT_METHODS[$fulfilment])) {
    api_fail('Unknown fulfilment method.');
}

$subtotal = cart_subtotal();
$discount = 0.0;
if (!empty($_SESSION['coupon'])) {
    $orderType = Pricing::isReseller(current_user()) ? 'reseller' : 'retail';
    $c = validate_coupon((string) $_SESSION['coupon'], $subtotal, $orderType);
    $discount = $c['valid'] ? (float) $c['discount'] : 0.0;
}

$shipping = shipping_cost($fulfilment, $subtotal, $pickupId);
$total    = max(0.0, $subtotal - $discount + $shipping);

api_ok([
    'shipping'       => $shipping,
    'shipping_label' => $shipping > 0 ? format_price($shipping) : 'Free',
    'total'          => $total,
    'total_label'    => format_price($total),
]);
