<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

// The enquiry list asks for the locker fee once a locker is picked, so the
// estimate it shows matches what enquire.php saves.
if (!Pudo::enabled()) {
    api_fail('Pudo lockers are not available right now.', 404);
}
$code     = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) (api_input()['locker'] ?? ''));
$subtotal = cart_subtotal();
$fee      = Pudo::fee($code ?: null, $subtotal);
api_ok([
    'shipping'       => $fee,
    'shipping_label' => $fee > 0 ? format_price($fee) : 'Free',
    'total_label'    => format_price($subtotal + $fee),
]);
