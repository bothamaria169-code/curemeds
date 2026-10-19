<?php
declare(strict_types=1);

/**
 * Sends the next batch of a campaign and reports progress (JSON).
 *
 * The campaigns page calls this in a loop while a send is running. Small
 * batches keep every request well inside shared hosting's execution limit,
 * and if the admin closes the tab the queue simply waits — reopening the
 * campaigns page picks it up where it stopped.
 */
require_once __DIR__ . '/../../includes/functions.php';

require_auth('admin');

$in = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
if (!verify_csrf((string) ($in['csrf_token'] ?? ''))) {
    json_response(['success' => false, 'message' => 'Your session expired. Reload the page.'], 419);
}

$id = (int) ($in['id'] ?? 0);
if (!NewsletterService::find($id)) {
    json_response(['success' => false, 'message' => 'Campaign not found.'], 404);
}

@set_time_limit(90);
$progress = NewsletterService::sendBatch($id, 15);

json_response(['success' => true] + $progress);
