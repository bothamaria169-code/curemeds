<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

$user = current_user();
if (!$user) {
    api_fail('Please sign in to save items.', 401);
}

$productId = (int) (api_input()['product_id'] ?? 0);
if ($productId <= 0) {
    api_fail('Missing product.');
}

$added = wishlist_toggle((int) $user['id'], $productId);
api_ok(['added' => $added, 'count' => count(wishlist_ids((int) $user['id']))]);
