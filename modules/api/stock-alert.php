<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

if (!rate_limit('stock_alert', 8, 600)) {
    api_fail('Too many requests. Try again shortly.', 429);
}

$in        = api_input();
$productId = (int) ($in['product_id'] ?? 0);
$email     = safe_email((string) ($in['email'] ?? ''));

if (!$email)      { api_fail('That email address does not look right.'); }
if (!$productId)  { api_fail('Missing product.'); }

try {
    db_insert('stock_alerts', ['product_id' => $productId, 'email' => $email]);
} catch (PDOException $e) {
    // Unique key on (product_id, email) — a repeat request is a success, not an error.
    if ($e->getCode() !== '23000') { throw $e; }
}

api_ok(['message' => 'We will email you the moment it is back.']);
