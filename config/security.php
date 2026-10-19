<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && $token !== '' && hash_equals($_SESSION['csrf_token'], $token);
}

function sanitize(mixed $data): mixed
{
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    if (!is_string($data)) {
        return $data;
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function safe_int(mixed $val): int
{
    return (int) filter_var($val, FILTER_SANITIZE_NUMBER_INT);
}

function safe_float(mixed $val): float
{
    return (float) filter_var($val, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

function safe_email(string $email): string|false
{
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL);
}

function rate_limit(string $key, int $max, int $window = 300): bool
{
    $bucket = '_rl_' . $key;
    $now    = time();
    $data   = $_SESSION[$bucket] ?? ['count' => 0, 'reset' => $now + $window];

    if ($now > $data['reset']) {
        $data = ['count' => 0, 'reset' => $now + $window];
    }
    $data['count']++;
    $_SESSION[$bucket] = $data;

    return $data['count'] <= $max;
}

function validate_upload(array $file, array $allowed_types, int $max_size = MAX_FILE_SIZE): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }
    if ($file['size'] > $max_size) {
        return ['ok' => false, 'error' => 'File is too large.'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_types, true)) {
        return ['ok' => false, 'error' => 'Unsupported file type.'];
    }
    return ['ok' => true, 'mime' => $mime];
}

function secure_filename(string $original_name): string
{
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    return bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
}

function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim(iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text, '-');
    $text = strtolower(preg_replace('~[^-\w]+~', '', $text));
    return $text !== '' ? $text : 'n-a';
}

function redirect(string $url): never
{
    // Drop any half-built page: it would never be seen, and a buffered page is
    // exactly what lets this header still be sent.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . (str_starts_with($url, 'http') ? $url : SITE_URL . '/' . ltrim($url, '/')));
    exit;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function require_auth(string $role = ''): array
{
    $user = JWTHandler::getCurrentUser();
    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    if (!$user) {
        if ($isAjax) {
            json_response(['success' => false, 'message' => 'Please sign in.'], 401);
        }
        redirect('modules/auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
    if ($role !== '' && $user['role'] !== $role && $user['role'] !== 'admin') {
        if ($isAjax) {
            json_response(['success' => false, 'message' => 'Not authorised.'], 403);
        }
        redirect('index.php');
    }
    return $user;
}

function current_user(): ?array
{
    return JWTHandler::getCurrentUser();
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}
