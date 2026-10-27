<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/jwt_handler.php';
require_once __DIR__ . '/../classes/Pricing.php';
require_once __DIR__ . '/../classes/ProductRepository.php';
require_once __DIR__ . '/../classes/OrderRepository.php';
require_once __DIR__ . '/../classes/ResellerService.php';
require_once __DIR__ . '/../classes/Mailer.php';
require_once __DIR__ . '/../classes/NewsletterService.php';
require_once __DIR__ . '/../classes/NewsletterTemplates.php';
require_once __DIR__ . '/../classes/ShopAssistant.php';
require_once __DIR__ . '/../classes/Health.php';
require_once __DIR__ . '/../classes/Groq.php';
require_once __DIR__ . '/../classes/ImageKit.php';
require_once __DIR__ . '/../classes/SocialPoster.php';
require_once __DIR__ . '/../classes/TikTok.php';
require_once __DIR__ . '/../classes/Pudo.php';
require_once __DIR__ . '/../classes/GoogleAuth.php';
require_once __DIR__ . '/../classes/ContentEngine.php';

// ── One-time messages across a redirect ──────────────────────────────────
/** Queues a message for the next page view. $type: success | danger | warn | info */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

/** Renders and clears queued messages. $message is trusted HTML written by us. */
function flash_html(): string
{
    $out = '';
    $icons = ['success' => 'fa-circle-check', 'danger' => 'fa-circle-exclamation', 'warn' => 'fa-triangle-exclamation', 'info' => 'fa-circle-info'];
    foreach ($_SESSION['flash'] ?? [] as [$type, $message]) {
        $out .= '<div class="alert-fc alert-fc--' . e($type) . '"><i class="fa-solid ' . ($icons[$type] ?? 'fa-circle-info') . '"></i><span>' . $message . '</span></div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/** POST-redirect-GET back to the current page (plus an optional #anchor). */
function back(string $anchor = ''): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // REQUEST_URI is this same page's own path, so it can't point anywhere else.
    $uri = '/' . ltrim((string) ($_SERVER['REQUEST_URI'] ?? '/'), '/');
    header('Location: ' . $uri . ($anchor !== '' ? '#' . $anchor : ''), true, 303);
    exit;
}

// ── Admin setup checklist (Admin → Setup checklist) ──────────────────────
/**
 * Everything that has to happen before CureMeds is fully live, in order.
 * Each task: [key, group, title, who (you|site), html details, auto-check|null].
 * An auto-check returns true when the site can see the task is done.
 *
 * @return list<array{key:string, group:string, title:string, who:string, html:string, auto:?bool}>
 */
function setup_tasks(): array
{
    $has = fn(string $k) => trim((string) get_setting($k, '')) !== '';
    $count = function (string $sql): int {
        try { return (int) db()->query($sql)->fetchColumn(); } catch (Throwable) { return 0; }
    };
    $cron = SITE_URL . '/scripts/cron.php?key=' . CRON_KEY;
    $tasks = [
        ['hosting-db', 'Hosting', 'Create the live database and user in cPanel', 'you',
            'cPanel → <b>MySQL® Databases</b>: database <code>curemedsco_database</code>, user <code>curemedsco_nexus</code>, then <b>Add User To Database</b> with ALL PRIVILEGES. <code>config/secrets.php</code> uses the same names.', $count('SELECT COUNT(*) FROM settings') > 0 ? true : null],
        ['hosting-import', 'Hosting', 'Import the database', 'you',
            'cPanel → <b>phpMyAdmin</b> → choose the database → <b>Import</b> → <code>database/database.sql</code>, then <code>database/seed.sql</code>. New tables after that add themselves on the next page load.', $count('SELECT COUNT(*) FROM products') > 0 ? true : null],
        ['hosting-secrets', 'Hosting', 'Upload config/secrets.php to the server', 'you',
            'It holds the database and mailbox passwords, so it is <b>not</b> in GitHub. Upload it once with File Manager to <code>public_html/config/</code>. Deploys never overwrite it.', IS_PRODUCTION ? DB_PASS !== '' : null],
        ['hosting-ssl', 'Hosting', 'Switch on HTTPS (free SSL)', 'you',
            'cPanel → <b>SSL/TLS Status</b> → <b>Run AutoSSL</b>. Facebook, Instagram and reminder links need https.', str_starts_with(SITE_URL, 'https://') ? true : null],
        ['github-repo', 'GitHub', 'Push the code to GitHub', 'site',
            'Repository: <code>github.com/bothamaria169-code/curemeds</code>. Every change is committed and pushed there.', null],
        ['github-cpanel', 'GitHub', 'Connect cPanel to the GitHub repository', 'you',
            'cPanel → <b>Git™ Version Control</b> → <b>Create</b> → Clone URL <code>https://github.com/bothamaria169-code/curemeds.git</code>, repository path <code>/home/USER/repositories/curemeds</code>. Then <b>Manage → Pull or Deploy → Deploy HEAD Commit</b>. The <code>.cpanel.yml</code> file copies the site into <code>public_html</code>.', null],
        ['github-actions', 'GitHub', 'Optional: deploy automatically on every push', 'you',
            'GitHub → repository → <b>Settings → Secrets and variables → Actions</b> → add <code>FTP_SERVER</code>, <code>FTP_USERNAME</code>, <code>FTP_PASSWORD</code> (cPanel → FTP Accounts). The workflow in <code>.github/workflows/deploy.yml</code> then uploads every change pushed to <code>main</code>.', null],
        ['mail', 'Email', 'Check the mailbox sends email', 'you',
            'Mailbox <code>admin@curemeds.co.za</code> on <code>mail.curemeds.co.za</code> (port 465). Use <b>Reminders → Send a test email</b> to confirm it arrives.', $count("SELECT COUNT(*) FROM reminder_log WHERE status = 'sent'") > 0 ? true : null],
        ['cron', 'Email', 'Add the reminders cron job', 'you',
            'cPanel → <b>Cron Jobs</b> → every 5 minutes (<code>*/5 * * * *</code>):<br><code>/usr/local/bin/php /home/USER/public_html/scripts/cron.php &gt;/dev/null 2&gt;&amp;1</code><br>No shell access? Use a URL cron (e.g. cron-job.org) on:<br><code>' . e($cron) . '</code>', get_setting('reminders_last_run', '') !== '' && strtotime((string) get_setting('reminders_last_run')) > time() - 3600 ? true : null],
        ['admin-password', 'Store', 'Change the admin password', 'you',
            'Admin sidebar → <b>My account</b> → <b>Change password</b>. Then delete <code>ADMIN-LOGIN.txt</code> from the build computer.', null],
        ['products', 'Store', 'Check every product, price and photo', 'you',
            '<b>Products</b> → open each one. Replace stock photos with your own pack shots when you have them, and use <b>Write with AI</b> for descriptions.', null],
        ['whatsapp', 'Store', 'Confirm the WhatsApp number', 'site',
            'Enquiries go to <code>' . e(Health::displayPhone(whatsapp_number())) . '</code>. Change it in <b>Settings</b> if needed.', whatsapp_number() !== '' ? true : null],
        ['bank', 'Store', 'Add the bank name for EFT payments', 'you',
            '<b>Settings</b> → Payment: the account number is in; add the bank name (and the account holder when you want it shown). Customers see it on the cart page and in the payment email.', trim((string) get_setting('bank_name', '')) !== '' ? true : null],
        ['demo-patient', 'Store', 'Delete the demo patient before launch', 'you',
            '<b>Patients</b> → Demo Patient (082 000 0001) → Delete.', $count("SELECT COUNT(*) FROM users WHERE phone = '27820000001'") === 0 ? true : null],
        ['groq', 'AI & social', 'Add your Groq API key', 'you',
            'Sign up free at <code>console.groq.com</code> → <b>API Keys</b> → <b>Create API Key</b>. Paste it in <b>AI & social media</b>.', $has('groq_api_key') || GROQ_API_KEY !== '' ? true : null],
        ['meta-app', 'AI & social', 'Create the Meta (Facebook) app', 'you',
            '<code>developers.facebook.com</code> → <b>My Apps → Create App</b> → use case <b>Manage everything on your Page</b>. Copy the <b>App ID</b> and <b>App secret</b> (App settings → Basic) into <b>AI & social media</b>. Under Facebook Login → Settings, add the redirect URI shown on that page.', $has('meta_app_id') && $has('meta_app_secret') ? true : null],
        ['meta-connect', 'AI & social', 'Connect the CureMeds Facebook Page and Instagram', 'you',
            'Link Instagram to the Page in Meta Business Suite first (Instagram must be a Business account). Then press <b>Connect with Facebook</b>.', $has('meta_page_token') ? true : null],
        ['imagekit', 'AI & social', 'Add your ImageKit keys (for reels)', 'you',
            '<code>imagekit.io</code> → <b>Developer options</b>: copy the URL endpoint, public key and private key into <b>AI & social media → ImageKit</b>. Full steps in <code>FACEBOOK-TIKTOK-SETUP.md</code>.', ImageKit::ready() ? true : null],
        ['tiktok', 'AI & social', 'Connect TikTok (reels arrive as drafts)', 'you',
            '<code>developers.tiktok.com</code> → your app → <b>Sandbox</b>: add the CureMeds TikTok account as a target user, add Login Kit + Content Posting API, set the redirect URI shown in <b>AI & social media → TikTok</b>, paste the Client key and secret there and press <b>Connect TikTok</b>. Full steps in <code>FACEBOOK-TIKTOK-SETUP.md</code>.', TikTok::connected() ? true : null],
        ['auto-content', 'AI & social', 'Switch on automatic articles, reels and newsletters', 'you',
            '<b>AI & social media → Automatic content</b>: tick articles, reels and "email each article". <b>Email templates</b> → switch on the weekly product newsletter. The cron job does the rest.', get_setting('auto_articles', '0') === '1' || get_setting('auto_reels', '0') === '1' ? true : null],
        ['social-links', 'AI & social', 'Add your Facebook / Instagram / TikTok links', 'you',
            '<b>Settings</b> → social links, so the footer icons work.', $has('facebook_url') || $has('instagram_url') ? true : null],
    ];
    $done = json_decode((string) get_setting('setup_done', '{}'), true) ?: [];
    return array_map(fn($t) => [
        'key' => $t[0], 'group' => $t[1], 'title' => $t[2], 'who' => $t[3], 'html' => $t[4], 'auto' => $t[5],
        'done' => $t[5] === true || !empty($done[$t[0]]),
    ], $tasks);
}

function setup_tasks_left(): int
{
    return count(array_filter(setup_tasks(), fn($t) => !$t['done']));
}

// ── WhatsApp ─────────────────────────────────────────────────────────────
/** The number enquiries go to, digits only. Admin → Settings can change it. */
function whatsapp_number(): string
{
    $n = preg_replace('/\D/', '', (string) get_setting('contact_whatsapp', DEFAULT_WHATSAPP)) ?? '';
    return $n !== '' ? $n : DEFAULT_WHATSAPP;
}

/** A wa.me link that opens WhatsApp with $message typed in, ready to send. */
function wa_link(string $message = '', ?string $number = null): string
{
    return 'https://wa.me/' . ($number ?? whatsapp_number()) . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

/** The ready-made message for one product. */
function wa_product_message(array $product, int $qty = 1): string
{
    return "Hi CureMeds, I'd like to order:\n• " . $qty . ' × ' . $product['name']
        . (!empty($product['pack_size']) ? ' (' . $product['pack_size'] . ')' : '')
        . ' — ' . format_price_short(Pricing::retail($product) * $qty)
        . "\n\n" . SITE_URL . '/modules/shop/product.php?slug=' . urlencode((string) $product['slug']);
}

/**
 * A post-sign-in destination that can only point inside this site. Accepts a
 * site path ("dashboards/x.php") or this site's own REQUEST_URI ("/curemeds/x.php").
 */
function safe_local_path(string $target, string $fallback): string
{
    $target = trim($target);
    if ($target === '' || preg_match('~^[a-z][a-z0-9+.-]*:|^//|\\\\~i', $target)) {
        return $fallback;
    }
    $base = (string) parse_url(SITE_URL, PHP_URL_PATH);
    if ($base !== '' && str_starts_with($target, $base . '/')) {
        $target = substr($target, strlen($base));
    }
    return ltrim($target, '/') ?: $fallback;
}

// ── Formatting ───────────────────────────────────────────────────────────
function format_price(float $amount, bool $decimals = true): string
{
    return CURRENCY . number_format($amount, $decimals ? 2 : 0, '.', ' ');
}

/** Short form for badges and tight columns: R1 250 rather than R1 250.00. */
function format_price_short(float $amount): string
{
    return CURRENCY . number_format($amount, fmod($amount, 1) == 0.0 ? 0 : 2, '.', ' ');
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * URL for a CSS/JS file, stamped with the file's modification time.
 *
 * Browsers cache stylesheets aggressively, so after an upload a returning
 * visitor can keep running the previous CSS against the new markup — which is
 * exactly how the banner carousel came to render as a stack of images on a
 * machine that had visited the site before the carousel shipped.
 *
 * The ?v= changes only when the file does, so the cache still does its job.
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = __DIR__ . '/../' . $path;
    $ver  = is_file($file) ? (string) filemtime($file) : '1';

    return SITE_URL . '/' . $path . '?v=' . $ver;
}

/** Resolves a stored image path to a URL, falling back to the house placeholder. */
function product_image(?string $url): string
{
    if (!$url) {
        return SITE_URL . '/assets/img/placeholder.svg';
    }
    return str_starts_with($url, 'http') ? $url : SITE_URL . '/' . ltrim($url, '/');
}

/**
 * Staff-written HTML (product descriptions) with only formatting tags kept and
 * every attribute except a link's href removed. Plain text gets paragraphs.
 */
function rich_text(string $html): string
{
    if (!str_contains($html, '<')) {
        return '<p>' . nl2br(e($html)) . '</p>';
    }
    $html = strip_tags($html, '<p><br><b><strong><i><em><u><ul><ol><li><h3><h4><a><blockquote>');
    $html = preg_replace('/<(\w+)\s[^>]*?(href\s*=\s*"(https?:\/\/|\/)[^"]*")?[^>]*>/i', '<$1 $2>', $html) ?? '';
    return str_replace([' >', '<a >'], ['>', '<a>'], $html);
}

function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('j M Y', strtotime($datetime));
}

function excerpt(?string $text, int $chars = 140): string
{
    $clean = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? '');
    return mb_strlen($clean) <= $chars ? $clean : mb_substr($clean, 0, $chars) . '…';
}

/** Renders a 0–5 rating as filled/empty stars. */
function stars_html(float $rating, int $out_of = 5): string
{
    $full = (int) round($rating);
    $html = '<span class="stars" aria-label="' . number_format($rating, 1) . ' out of 5">';
    for ($i = 1; $i <= $out_of; $i++) {
        $html .= '<i class="fa-solid fa-star' . ($i > $full ? ' off' : '') . '"></i>';
    }
    return $html . '</span>';
}

// ── Settings (key/value, cached per request) ─────────────────────────────
/**
 * Every setting, loaded once per request. Returned by reference so
 * set_setting() can keep it in step — otherwise a page that saves a setting
 * and then renders would show the old value until the next request.
 */
function &settings_cache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT key_name, value FROM settings') as $row) {
                $cache[$row['key_name']] = $row['value'];
            }
        } catch (Throwable) {
            // Table missing (pre-install) — fall through to defaults rather than fataling.
        }
    }
    return $cache;
}

function get_setting(string $key, ?string $default = null): ?string
{
    $v = settings_cache()[$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key_name, value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE value = :v2')
        ->execute([':k' => $key, ':v' => $value, ':v2' => $value]);

    $cache = &settings_cache();
    $cache[$key] = $value;
}

// ── Taxonomy ─────────────────────────────────────────────────────────────
function get_categories(bool $withCounts = true): array
{
    $sql = $withCounts
        ? "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.status='published') AS product_count
           FROM categories c WHERE c.status='active' ORDER BY c.sort_order, c.name"
        : "SELECT * FROM categories WHERE status='active' ORDER BY sort_order, name";
    return db()->query($sql)->fetchAll();
}

function get_brands(bool $withCounts = true): array
{
    $sql = $withCounts
        ? "SELECT b.*, (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.status='published') AS product_count
           FROM brands b WHERE b.status='active' ORDER BY b.sort_order, b.name"
        : "SELECT * FROM brands WHERE status='active' ORDER BY sort_order, name";
    return db()->query($sql)->fetchAll();
}

function get_pickup_points(): array
{
    return db()->query("SELECT * FROM pickup_points WHERE status='active' ORDER BY sort_order, name")->fetchAll();
}

// ── Promotional banners ──────────────────────────────────────────────────
/**
 * Active banners for one page.
 *
 * @param string $page   home | shop | reseller — maps to the show_* flags
 * @param string $aspect wide (carousel slides) | tall (standalone promo tiles)
 */
function get_banners(string $page, string $aspect = 'wide'): array
{
    $column = match ($page) {
        'shop'     => 'show_shop',
        'reseller' => 'show_reseller',
        default    => 'show_home',
    };
    $aspect = $aspect === 'tall' ? 'tall' : 'wide';

    try {
        $stmt = db()->prepare(
            "SELECT * FROM banners
             WHERE status = 'active' AND aspect = :aspect AND `$column` = 1
             ORDER BY sort_order, id"
        );
        $stmt->execute([':aspect' => $aspect]);
        return $stmt->fetchAll();
    } catch (Throwable) {
        // Table not imported yet — a missing banner table should never take
        // the storefront down, so render the page without promos instead.
        return [];
    }
}

/** Resolves a banner's link, allowing both site-relative paths and full URLs. */
function banner_link(?string $url): ?string
{
    if (!$url) {
        return null;
    }
    return str_starts_with($url, 'http') ? $url : SITE_URL . '/' . ltrim($url, '/');
}

// ── Cart (session-held, priced live) ─────────────────────────────────────
// Lines are keyed "productId_variantId" so the same product in two flavours
// stays two lines. Nothing but ids and quantities is stored in the session —
// price and stock are re-read on every render, so a price change or a sellout
// can never be carried through a stale cart into checkout.
function cart_key(int $productId, ?int $variantId): string
{
    return $productId . '_' . ($variantId ?: 0);
}

function cart_add(int $productId, ?int $variantId, int $qty = 1): void
{
    $key = cart_key($productId, $variantId);
    $_SESSION['cart'][$key] = [
        'product_id' => $productId,
        'variant_id' => $variantId ?: null,
        'qty'        => max(1, ($_SESSION['cart'][$key]['qty'] ?? 0) + $qty),
    ];
}

function cart_set(string $key, int $qty): void
{
    if ($qty <= 0) { cart_remove($key); return; }
    if (isset($_SESSION['cart'][$key])) {
        $_SESSION['cart'][$key]['qty'] = min($qty, 999);
    }
}

function cart_remove(string $key): void { unset($_SESSION['cart'][$key]); }
function cart_clear(): void { $_SESSION['cart'] = []; unset($_SESSION['coupon']); }

/** Resolves the session cart against live product data for the current viewer. */
function cart_items(): array
{
    $repo  = new ProductRepository();
    $user  = current_user();
    $items = [];

    foreach ($_SESSION['cart'] ?? [] as $key => $line) {
        $product = $repo->getById((int) $line['product_id']);
        if (!$product || $product['status'] !== 'published') {
            continue;
        }
        $variant = !empty($line['variant_id']) ? $repo->getVariantById((int) $line['variant_id']) : null;

        $unit = Pricing::effective($product, $user) + (float) ($variant['additional_price'] ?? 0);
        $qty  = (int) $line['qty'];

        $items[] = [
            'key'          => $key,
            'product'      => $product,
            'variant'      => $variant,
            'qty'          => $qty,
            'unit_price'   => $unit,
            'unit_rrp'     => Pricing::rrp($product),
            'line_total'   => $unit * $qty,
            'line_rrp'     => Pricing::rrp($product) * $qty,
            'image'        => $variant['image'] ?? $product['featured_image'],
            'variant_text' => $variant ? ($variant['option_label'] . ': ' . $variant['option_value']) : null,
        ];
    }
    return $items;
}

function cart_count(): int
{
    return array_sum(array_column($_SESSION['cart'] ?? [], 'qty'));
}

function cart_subtotal(): float
{
    return array_sum(array_column(cart_items(), 'line_total'));
}

/** What the whole cart is worth at recommended resale — the reseller's upside. */
function cart_resale_value(): float
{
    return array_sum(array_column(cart_items(), 'line_rrp'));
}

/** Delivery methods offered at checkout: Pudo only once its API key is saved, collection only with a collection point. */
function fulfilment_methods(): array
{
    return array_filter(FULFILMENT_METHODS, fn($k) => match ($k) {
        'pudo'       => Pudo::enabled(),
        'collection' => (bool) get_pickup_points(),
        default      => true,
    }, ARRAY_FILTER_USE_KEY);
}

function shipping_cost(string $method, float $subtotal, ?int $pickupPointId = null, ?string $lockerCode = null): float
{
    if ($method === 'pudo') {
        return Pudo::fee($lockerCode, $subtotal);
    }
    if ($method === 'collection') {
        if (!$pickupPointId) { return 0.0; }
        $stmt = db()->prepare('SELECT fee FROM pickup_points WHERE id = :id');
        $stmt->execute([':id' => $pickupPointId]);
        return (float) ($stmt->fetchColumn() ?: 0);
    }
    if ($method === 'runner') {
        return (float) get_setting('runner_fee', '20');
    }
    $free = (float) get_setting('free_shipping_threshold', '1500');
    return $subtotal >= $free ? 0.0 : (float) get_setting('shipping_flat_rate', '120');
}

// ── Wishlist ─────────────────────────────────────────────────────────────
function wishlist_toggle(int $userId, int $productId): bool
{
    $stmt = db()->prepare('SELECT id FROM wishlist WHERE user_id = :u AND product_id = :p');
    $stmt->execute([':u' => $userId, ':p' => $productId]);
    if ($row = $stmt->fetch()) {
        db()->prepare('DELETE FROM wishlist WHERE id = :id')->execute([':id' => $row['id']]);
        return false;
    }
    db_insert('wishlist', ['user_id' => $userId, 'product_id' => $productId]);
    return true;
}

function wishlist_ids(int $userId): array
{
    $stmt = db()->prepare('SELECT product_id FROM wishlist WHERE user_id = :u');
    $stmt->execute([':u' => $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ── Recently viewed (session ring buffer, newest first) ──────────────────
function record_recently_viewed(int $productId): void
{
    $list = array_values(array_filter($_SESSION['recent'] ?? [], fn($id) => (int) $id !== $productId));
    array_unshift($list, $productId);
    $_SESSION['recent'] = array_slice($list, 0, 12);
}

function recently_viewed(int $excludeId = 0, int $limit = 5): array
{
    $ids = array_values(array_filter($_SESSION['recent'] ?? [], fn($id) => (int) $id !== $excludeId));
    if (!$ids) { return []; }
    $rows = (new ProductRepository())->getAll(['ids' => array_slice($ids, 0, $limit)], 1, $limit)['items'];
    // Preserve the recency order the SQL IN() clause loses.
    usort($rows, fn($a, $b) => array_search((int) $a['id'], $ids, true) <=> array_search((int) $b['id'], $ids, true));
    return $rows;
}

// ── Coupons ──────────────────────────────────────────────────────────────
function validate_coupon(string $code, float $subtotal, string $orderType = 'retail'): array
{
    $stmt = db()->prepare("SELECT * FROM coupons WHERE code = :c AND status='active' LIMIT 1");
    $stmt->execute([':c' => strtoupper(trim($code))]);
    $coupon = $stmt->fetch();

    if (!$coupon)                                                    return ['valid' => false, 'message' => 'That code is not recognised.'];
    if ($coupon['expiry_date'] && strtotime($coupon['expiry_date']) < strtotime('today'))
                                                                     return ['valid' => false, 'message' => 'That code has expired.'];
    if ($coupon['max_uses'] !== null && (int) $coupon['used_count'] >= (int) $coupon['max_uses'])
                                                                     return ['valid' => false, 'message' => 'That code has reached its usage limit.'];
    if ($coupon['applies_to'] !== 'all' && $coupon['applies_to'] !== $orderType)
                                                                     return ['valid' => false, 'message' => 'That code does not apply to this order.'];
    if ($subtotal < (float) $coupon['min_order'])
                                                                     return ['valid' => false, 'message' => 'Minimum order of ' . format_price((float) $coupon['min_order']) . ' for this code.'];

    $discount = $coupon['discount_type'] === 'percentage'
        ? $subtotal * ((float) $coupon['discount_value'] / 100)
        : (float) $coupon['discount_value'];

    return ['valid' => true, 'coupon' => $coupon, 'discount' => round(min($discount, $subtotal), 2)];
}

// ── Blog ─────────────────────────────────────────────────────────────────
function get_blogs(array $f = [], int $page = 1, int $perPage = 9): array
{
    $where  = ['1=1'];
    $params = [];
    $status = $f['status'] ?? 'published';
    if ($status !== 'all')       { $where[] = 'b.status = :st'; $params[':st'] = $status; }
    if (!empty($f['category']))  { $where[] = 'b.category = :cat'; $params[':cat'] = $f['category']; }
    if (!empty($f['search'])) {
        // Emulated prepares are off — one placeholder per occurrence.
        $where[] = '(b.title LIKE :q1 OR b.excerpt LIKE :q2)';
        $params[':q1'] = $params[':q2'] = '%' . $f['search'] . '%';
    }

    $whereSql  = implode(' AND ', $where);
    $countStmt = db()->prepare("SELECT COUNT(*) FROM blogs b WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $pages = (int) max(1, ceil($total / $perPage));
    $page  = max(1, min($page, $pages));

    $stmt = db()->prepare("SELECT b.*, u.name AS author_name FROM blogs b
                           LEFT JOIN users u ON u.id = b.author_id
                           WHERE $whereSql ORDER BY b.is_featured DESC, b.created_at DESC
                           LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();

    return ['items' => $stmt->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
}

function get_blog_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT b.*, u.name AS author_name FROM blogs b LEFT JOIN users u ON u.id = b.author_id WHERE b.slug = :s LIMIT 1');
    $stmt->execute([':s' => $slug]);
    return $stmt->fetch() ?: null;
}

// ── Pagination helper ────────────────────────────────────────────────────
/** Builds a URL for $page, preserving every other query parameter. */
function page_url(int $page): string
{
    $q = $_GET;
    $q['page'] = $page;
    return '?' . http_build_query($q);
}

function render_pager(int $page, int $pages): string
{
    if ($pages <= 1) { return ''; }
    $out  = '<nav class="pager" aria-label="Pagination">';
    $out .= '<a class="' . ($page <= 1 ? 'is-off' : '') . '" href="' . e(page_url(max(1, $page - 1))) . '" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></a>';

    $from = max(1, $page - 2);
    $to   = min($pages, $from + 4);
    $from = max(1, $to - 4);
    if ($from > 1) {
        $out .= '<a href="' . e(page_url(1)) . '">1</a>' . ($from > 2 ? '<span class="is-off">…</span>' : '');
    }
    for ($i = $from; $i <= $to; $i++) {
        $out .= $i === $page
            ? '<span class="is-on">' . $i . '</span>'
            : '<a href="' . e(page_url($i)) . '">' . $i . '</a>';
    }
    if ($to < $pages) {
        $out .= ($to < $pages - 1 ? '<span class="is-off">…</span>' : '') . '<a href="' . e(page_url($pages)) . '">' . $pages . '</a>';
    }
    $out .= '<a class="' . ($page >= $pages ? 'is-off' : '') . '" href="' . e(page_url(min($pages, $page + 1))) . '" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></a>';
    return $out . '</nav>';
}

// ── Email ────────────────────────────────────────────────────────────────

/**
 * Absolute URL for an image that will be shown in an email.
 *
 * Gmail and Outlook do not display SVG, so SVG product art is swapped for the
 * JPEG copy made by scripts/make-email-images.php. If there is no copy, the
 * CureMeds icon stands in rather than leaving a broken-image hole.
 */
function email_image_url(?string $path): string
{
    $fallback = SITE_URL . "/assets/img/logo/curemeds-icon.png";
    $path     = trim((string) $path);
    if ($path === "") {
        return $fallback;
    }
    if (str_ends_with(strtolower(parse_url($path, PHP_URL_PATH) ?: $path), ".svg")) {
        $copy = "assets/img/products/email/" . pathinfo($path, PATHINFO_FILENAME) . ".jpg";
        return is_file(__DIR__ . "/../" . $copy) ? SITE_URL . "/" . $copy : $fallback;
    }
    return str_starts_with($path, "http") ? $path : SITE_URL . "/" . ltrim($path, "/");
}

/**
 * The CureMeds email shell.
 *
 * $preheader is the grey preview line inbox apps show after the subject. It is
 * hidden in the body itself, and padded with zero-width spaces so the client
 * does not fill the rest of the preview with the first words of the email.
 */
function email_wrap(string $innerHtml, ?string $unsubscribeUrl = null, string $preheader = ""): string
{
    $siteUrl = SITE_URL;
    $year    = date("Y");
    $tagline = e(SITE_TAGLINE);
    $unsub   = $unsubscribeUrl
        ? "<br>You are receiving this because you joined the CureMeds list. "
          . "<a href=\"" . e($unsubscribeUrl) . "\" style=\"color:#7C8899;text-decoration:underline;\">Unsubscribe</a>"
        : "";
    $pre = $preheader !== ""
        ? "<div style=\"display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;\">"
          . e($preheader) . str_repeat("&#8203;&nbsp;", 60) . "</div>"
        : "";

    return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#EEF1F5;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
{$pre}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF1F5;padding:32px 0;">
<tr><td align="center">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#FFFFFF;border-radius:16px;overflow:hidden;max-width:600px;width:92%;box-shadow:0 2px 12px rgba(16,26,43,.08);">
    <tr><td style="background:#0A1B3D;padding:26px 32px;">
      <a href="{$siteUrl}" style="text-decoration:none;"><span style="color:#FFFFFF;font-size:22px;font-weight:800;letter-spacing:-.02em;">Cure<span style="color:#1BB3C8;">Meds</span></span></a>
      <div style="color:rgba(238,242,247,.6);font-size:12px;margin-top:2px;">{$tagline}</div>
    </td></tr>
    <tr><td style="padding:30px 32px;color:#101A2B;font-size:15px;line-height:1.65;">{$innerHtml}</td></tr>
    <tr><td style="background:#F6F7F9;padding:18px 32px;text-align:center;font-size:12px;line-height:1.6;color:#7C8899;">
      &copy; {$year} CureMeds &middot; <a href="{$siteUrl}" style="color:#1557D0;">curemeds.co.za</a>{$unsub}
    </td></tr>
  </table>
</td></tr></table>
</body></html>
HTML;
}
function notify_order_placed(array $order, array $items): void
{
    $email = $order['customer_email'] ?? null;
    if (!$email) { return; }

    $rows = '';
    foreach ($items as $it) {
        $label = e($it['name']) . ($it['variant_text'] ? ' <span style="color:#7C8899;">(' . e($it['variant_text']) . ')</span>' : '');
        $rows .= '<tr><td style="padding:7px 0;border-bottom:1px solid #EEF1F5;">' . $label . ' × ' . (int) $it['qty'] . '</td>'
               . '<td style="padding:7px 0;border-bottom:1px solid #EEF1F5;text-align:right;white-space:nowrap;">'
               . format_price((float) $it['unit_price'] * (int) $it['qty']) . '</td></tr>';
    }

    $bank = '<div style="background:#F6F7F9;border-radius:10px;padding:14px;margin:16px 0;font-size:14px;">'
          . '<b>Pay by EFT</b><br>'
          . e((string) get_setting('bank_name', '')) . '<br>'
          . 'Account holder: ' . e((string) get_setting('bank_account_holder', '')) . '<br>'
          . 'Account: ' . e((string) get_setting('bank_account_number', '')) . ' &middot; Branch: ' . e((string) get_setting('bank_branch_code', '')) . '<br>'
          . 'Reference: <b>' . e($order['order_number']) . '</b></div>';

    $html = '<p>Thanks ' . e((string) ($order['customer_name'] ?? '')) . ' — your order is in.</p>'
          . '<p style="font-size:18px;"><b>' . e($order['order_number']) . '</b></p>'
          . '<table role="presentation" width="100%" style="font-size:14px;">' . $rows . '</table>'
          . '<p style="margin-top:14px;font-size:17px;"><b>Total: ' . format_price((float) $order['total_amount']) . '</b></p>'
          . $bank
          . '<p>Upload your proof of payment on your order page and we will confirm within one working day. Your stock is held for 48 hours.</p>'
          . '<p><a href="' . SITE_URL . '/order-confirmation.php?order=' . urlencode($order['order_number']) . '" '
          . 'style="display:inline-block;background:#1557D0;color:#fff;padding:11px 22px;border-radius:999px;font-weight:700;">View your order</a></p>';

    Mailer::send($email, 'Your CureMeds order — ' . $order['order_number'], email_wrap($html));

    // Ops copy so nobody has to poll the dashboard.
    Mailer::send(ADMIN_EMAIL, 'New order ' . $order['order_number'] . ' — ' . format_price((float) $order['total_amount']),
        email_wrap('<p>New ' . e($order['order_type']) . ' order from ' . e((string) ($order['customer_name'] ?? '')) . '.</p>'
                 . '<table role="presentation" width="100%" style="font-size:14px;">' . $rows . '</table>'
                 . '<p><b>Total: ' . format_price((float) $order['total_amount']) . '</b></p>'));
}

function notify_order_status(array $order): void
{
    $email = $order['customer_email'] ?? null;
    if (!$email) { return; }
    $label = ORDER_STATUSES[$order['status']]['label'] ?? $order['status'];
    $html  = '<p>Hi ' . e((string) ($order['customer_name'] ?? '')) . ',</p>'
           . '<p>Your order <b>' . e($order['order_number']) . '</b> is now <b>' . e($label) . '</b>.</p>'
           . '<p><a href="' . SITE_URL . '/order-confirmation.php?order=' . urlencode($order['order_number']) . '" '
           . 'style="display:inline-block;background:#1557D0;color:#fff;padding:11px 22px;border-radius:999px;font-weight:700;">Track your order</a></p>';
    Mailer::send($email, 'Order update — ' . $order['order_number'], email_wrap($html));
}

function notify_reseller_approved(array $user, string $code): void
{
    $tier = RESELLER_TIERS[$user['reseller_tier'] ?? 'starter'];
    $html = '<p>Good news ' . e((string) $user['name']) . ' — your CureMeds reseller account is approved.</p>'
          . '<p>Your reseller code is <b style="font-size:18px;letter-spacing:.06em;">' . e($code) . '</b>.</p>'
          . '<p>You are on the <b>' . e($tier['label']) . '</b> tier, which is <b>' . (int) ($tier['off_retail'] * 100)
          . '% off retail</b> across the catalogue. Every product page now shows your cost, the recommended selling price and your rand profit per unit.</p>'
          . '<p><a href="' . SITE_URL . '/dashboards/reseller/index.php" '
          . 'style="display:inline-block;background:#1557D0;color:#fff;padding:11px 22px;border-radius:999px;font-weight:700;">Open your dashboard</a></p>';
    Mailer::send((string) $user['email'], 'Your CureMeds reseller account is approved', email_wrap($html));
}

/** "zama@gmail.com" -> "z***a@gmail.com": enough to recognise, not enough to harvest. */
function mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $len = mb_strlen($local);
    $masked = $len <= 2
        ? mb_substr($local, 0, 1) . '***'
        : mb_substr($local, 0, 1) . '***' . mb_substr($local, -1);
    return $domain !== '' ? $masked . '@' . $domain : $masked;
}

require_once __DIR__ . '/extras.php';
