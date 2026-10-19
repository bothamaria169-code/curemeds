<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

$user = current_user();
if (!$user) {
    api_fail('Please sign in to leave a review.', 401);
}

$in        = api_input();
$productId = (int) ($in['product_id'] ?? 0);
$rating    = max(1, min(5, (int) ($in['rating'] ?? 5)));
$title     = sanitize((string) ($in['title'] ?? ''));
$comment   = sanitize((string) ($in['comment'] ?? ''));

if (!$productId)                { api_fail('Missing product.'); }
if (mb_strlen($comment) < 10)   { api_fail('Tell us a little more — at least a sentence.'); }

try {
    db_insert('reviews', [
        'product_id' => $productId,
        'user_id'    => (int) $user['id'],
        'rating'     => $rating,
        'title'      => $title ?: null,
        'comment'    => $comment,
        'is_approved'=> 0,
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        api_fail('You have already reviewed this product.');
    }
    throw $e;
}

api_ok(['message' => 'Thanks — your review is in the queue and appears once approved.']);
