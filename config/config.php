<?php
declare(strict_types=1);

// ── Server check ─────────────────────────────────────────────────────────
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('CureMeds needs PHP 8.1 or newer, but this server runs PHP ' . PHP_VERSION
        . '. In cPanel open "MultiPHP Manager" (or "Select PHP Version"), tick curemeds.co.za and choose PHP 8.2.');
}

// A fatal error on the live site would otherwise be a blank "HTTP ERROR 500".
// Show a plain page instead; the details go to public_html/error_log, and are
// shown on screen only with ?debug=<cron_key from config/secrets.php>.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) || PHP_SAPI === 'cli' || ini_get('display_errors') === '1') {
        return;
    }
    $show = defined('CRON_KEY') && hash_equals(CRON_KEY, (string) ($_GET['debug'] ?? ''));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<div style="font-family:system-ui,Arial,sans-serif;max-width:620px;margin:40px auto;padding:24px;border-radius:14px;background:#fff;box-shadow:0 2px 12px rgba(0,0,0,.08)">'
       . '<h1 style="font-size:20px;margin-top:0">Something went wrong on our side</h1>'
       . '<p>Please try again in a minute, or WhatsApp us on 083 576 1713.</p>'
       . ($show ? '<pre style="white-space:pre-wrap;background:#F6F7F9;padding:12px;border-radius:8px;font-size:13px">'
           . htmlspecialchars($err['message'] . "\n" . $err['file'] . ':' . $err['line'], ENT_QUOTES, 'UTF-8') . '</pre>'
         : '<p style="color:#7C8899;font-size:13px">Site owner: the details are in <code>public_html/error_log</code>.</p>')
       . '</div>';
});

// ── Site identity ─────────────────────────────────────────────────────────
define('SITE_NAME', 'CureMeds');
define('SITE_DOMAIN', 'curemeds.co.za');
define('SITE_TAGLINE', 'Health products and supplements, with care that follows up.');
define('SITE_EMAIL', 'admin@curemeds.co.za');
define('CURRENCY', 'R');
define('CURRENCY_CODE', 'ZAR');

// WhatsApp number every enquiry goes to: international format, digits only.
define('DEFAULT_WHATSAPP', '27835761713');

// ── Environment detection ────────────────────────────────────────────────
// Local = Windows/XAMPP dev machine or a localhost hostname; anything else
// (the Linux cPanel host) is production. Works for both web and CLI.
$httpHost = $_SERVER['HTTP_HOST'] ?? '';
define('IS_PRODUCTION', !(
    PHP_OS_FAMILY === 'Windows'
    || str_starts_with($httpHost, 'localhost')
    || str_starts_with($httpHost, '127.0.0.1')
));

// ── Secrets ──────────────────────────────────────────────────────────────
// Passwords and API keys live in config/secrets.php, which is never committed
// to GitHub (see .gitignore). Copy config/secrets.example.php to make one.
$secrets = is_file(__DIR__ . '/secrets.php') ? require __DIR__ . '/secrets.php' : [];
$secret  = fn(string $key, string $default = '') => (string) ($secrets[$key] ?? $default);

// ── Database ─────────────────────────────────────────────────────────────
if (IS_PRODUCTION) {
    define('DB_HOST', $secret('db_host', 'localhost'));
    define('DB_NAME', $secret('db_name', 'curemedsco_database'));
    define('DB_USER', $secret('db_user', 'database_nexus'));
    define('DB_PASS', $secret('db_pass'));
} else {
    define('DB_HOST', $secret('local_db_host', 'localhost'));
    define('DB_NAME', $secret('local_db_name', 'curemedsco_database'));
    define('DB_USER', $secret('local_db_user', 'root'));
    define('DB_PASS', $secret('local_db_pass'));
}
define('DB_CHARSET', 'utf8mb4');

// ── Auth / JWT ───────────────────────────────────────────────────────────
// The secret comes from secrets.php when set; otherwise DB_PASS is mixed in so
// it can't be derived from readable source alone.
define('JWT_SECRET', $secret('jwt_secret') ?: 'cm_' . hash('sha256', 'curemeds-za-2026|' . DB_PASS));
define('JWT_EXPIRY', 60 * 60 * 24 * 30); // 30 days: patients stay signed in on their phone
define('JWT_COOKIE_NAME', 'cm_token');

// Signs the one-tap "I took it" links in reminder emails.
define('REMINDER_SECRET', $secret('reminder_secret') ?: hash('sha256', 'curemeds-reminders|' . JWT_SECRET));
// Lets a hosting "URL cron" run reminders without shell access: scripts/cron.php?key=…
define('CRON_KEY', $secret('cron_key') ?: substr(hash('sha256', 'curemeds-cron|' . JWT_SECRET), 0, 24));

// ── Uploads ──────────────────────────────────────────────────────────────
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 8 * 1024 * 1024);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml']);
define('ALLOWED_POP_TYPES', ['image/jpeg', 'image/png', 'application/pdf']);
define('ALLOWED_DOC_TYPES', ['image/jpeg', 'image/png', 'application/pdf']);

// ── Catalog ──────────────────────────────────────────────────────────────
define('PRODUCTS_PER_PAGE', 24);

// ── Mail (SMTP via the cPanel mailbox) ───────────────────────────────────
// Until SMTP_PASS is filled in, Mailer falls back to PHP mail(), which
// silently no-ops on localhost. Nothing breaks; mail just isn't sent.
define('SMTP_HOST', $secret('smtp_host', 'mail.curemeds.co.za'));
define('SMTP_PORT', (int) $secret('smtp_port', '465'));
define('SMTP_USER', $secret('smtp_user', 'admin@curemeds.co.za'));
define('SMTP_PASS', $secret('smtp_pass'));
define('SMTP_FROM_NAME', SITE_NAME);
define('ADMIN_EMAIL', $secret('admin_email', 'admin@curemeds.co.za'));

// ── Groq (AI writing) — the key can also be saved in Admin → AI & social ─
define('GROQ_API_KEY', $secret('groq_api_key'));

define('DEBUG_MODE', !IS_PRODUCTION);
unset($secrets, $secret);

// ── SITE_URL auto-detection (works unchanged in an XAMPP subfolder and on a
//    live vhost root) ────────────────────────────────────────────────────
$protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host       = $_SERVER['HTTP_HOST'] ?? SITE_DOMAIN;
$docRootDir = str_replace('\\', '/', dirname(__DIR__));
$basePath   = '';
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $basePath     = str_replace($documentRoot, '', $docRootDir);
}
// Cron jobs run without a web request, so they need the live address spelled out.
define('SITE_URL', PHP_SAPI === 'cli' && IS_PRODUCTION
    ? 'https://' . SITE_DOMAIN
    : rtrim($protocol . $host . $basePath, '/'));
