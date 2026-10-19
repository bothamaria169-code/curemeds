<?php
declare(strict_types=1);

/**
 * Admin → Products → "Write with AI". Takes the product fields from the form
 * (so it works before the product is saved) and returns a short and a full
 * description written by Groq.
 */
require_once __DIR__ . '/_boot.php';

// The custom header can't be sent cross-site without a CORS preflight, which
// this endpoint never grants — that is what stops another site posting here.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    api_fail('Bad request.', 400);
}
$user = current_user();
if (!$user || $user['role'] !== 'admin') {
    api_fail('Admins only.', 403);
}
if (!rate_limit('ai_write', 30, 3600)) {
    api_fail('That is a lot of AI writing for one hour — try again a little later.', 429);
}

$in = api_input();
$product = [];
foreach (['name', 'pack_size', 'benefits', 'usage_notes', 'short_desc', 'category_name'] as $k) {
    $product[$k] = mb_substr(trim(strip_tags((string) ($in[$k] ?? ''))), 0, 1500);
}
if ($product['name'] === '') {
    api_fail('Type the product name first.');
}

$error = '';
$copy = Groq::productDescription($product, $error);
if (!$copy) {
    api_fail($error ?: 'The AI could not write that. Try again.');
}
api_ok($copy);
