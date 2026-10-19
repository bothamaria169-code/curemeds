<?php
declare(strict_types=1);

/**
 * Shared bootstrap for every JSON endpoint: loads the app, reads the JSON body
 * (falling back to form-encoded), and hands the caller a clean array.
 */
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function api_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }
    return $_POST ?: [];
}

function api_ok(array $extra = []): never
{
    json_response(array_merge(['success' => true], $extra));
}

function api_fail(string $message, int $status = 400): never
{
    json_response(['success' => false, 'message' => $message], $status);
}
