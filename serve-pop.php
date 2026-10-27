<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

/**
 * Streams a proof-of-payment file.
 *
 * POPs are bank screenshots — they carry account numbers and balances — so the
 * directory itself is denied by .htaccess and every read comes through here.
 * Two parties may see one: an admin, or the customer whose order it belongs to.
 * A guest who checked out without an account proves ownership with the order
 * number, which is unguessable and already in their inbox.
 */
$repo   = new OrderRepository();
$number = trim((string) ($_GET['order'] ?? ''));
$order  = $number !== '' ? $repo->getByNumber($number) : null;

if (!$order || !$order['pop_path']) {
    http_response_code(404);
    exit('Not found.');
}

$viewer  = current_user();
$isAdmin = $viewer !== null && $viewer['role'] === 'admin';
$isOwner = $viewer !== null && (int) $order['user_id'] === (int) $viewer['id'];
$isGuest = $order['user_id'] === null; // knowing the order number is the credential

if (!$isAdmin && !$isOwner && !$isGuest) {
    http_response_code(403);
    exit('Not authorised.');
}

// Resolve inside the uploads directory and refuse anything that escapes it.
$base = realpath(UPLOAD_PATH . 'proof-of-payments');
$file = realpath(__DIR__ . '/' . $order['pop_path']);

if ($base === false || $file === false || !str_starts_with($file, $base) || !is_file($file)) {
    http_response_code(404);
    exit('File missing.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file) ?: 'application/octet-stream';
finfo_close($finfo);

// Only ever the types we accepted on upload; anything else downloads rather
// than renders, so a mislabelled file can never execute in the browser.
if (!in_array($mime, ALLOWED_POP_TYPES, true)) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: inline; filename="POP-' . $order['order_number'] . '.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($file);
