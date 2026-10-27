<?php
declare(strict_types=1);

/**
 * CureMeds additions (October 2026), loaded at the end of includes/functions.php:
 *   - self-applying database upgrades, so a deploy never needs an SQL import
 *   - the Phola catalogue (database/catalogue.json), imported once
 *   - payment details: EFT to our bank account, or cash on delivery
 *   - email templates staff can edit, and the enquiry "how to pay" email
 *   - the live purchase ticker (Admin → Live ticker)
 *   - the weekly product newsletter
 */

const CUREMEDS_SCHEMA_VERSION = 2;

function curemeds_migrate(): void
{
    try {
        $current = (int) get_setting('cm_schema_version', '0');
    } catch (Throwable) {
        return; // no database yet — config/db.php explains what to fix
    }
    if ($current >= CUREMEDS_SCHEMA_VERSION) {
        return;
    }

    $pdo = db();
    $hasCol = function (string $table, string $col) use ($pdo): bool {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $s->execute([':t' => $table, ':c' => $col]);
        return (int) $s->fetchColumn() > 0;
    };
    $addCol = function (string $table, string $col, string $def) use ($pdo, $hasCol): void {
        if (!$hasCol($table, $col)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        }
    };
    $table = fn(string $sql) => $pdo->exec($sql . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    try {
        if ($current < 1) {
            // Automatic articles and reels (classes/ContentEngine.php).
            $addCol('products', 'header_image', 'VARCHAR(500) DEFAULT NULL AFTER `featured_image`');
            $addCol('social_posts', 'media_type', "VARCHAR(10) NOT NULL DEFAULT 'photo'");
            $addCol('social_posts', 'media_url', 'VARCHAR(600) DEFAULT NULL');
            $addCol('blogs', 'auto_generated', 'TINYINT(1) NOT NULL DEFAULT 0');
            $addCol('blogs', 'category_hint', 'INT UNSIGNED DEFAULT NULL');
            $addCol('blogs', 'product_ids', 'VARCHAR(120) DEFAULT NULL');
            $table("CREATE TABLE IF NOT EXISTS `imagekit_files` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `local_path` VARCHAR(500) NOT NULL,
                `local_mtime` INT UNSIGNED NOT NULL DEFAULT 0,
                `file_id` VARCHAR(80) NOT NULL,
                `url` VARCHAR(600) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), KEY `idx_path` (`local_path`(191)))");

            // Testimonials (screenshots, stories, videos) and the customer gallery.
            $addCol('testimonials', 'screenshot', 'VARCHAR(500) DEFAULT NULL');
            $addCol('testimonials', 'story', 'LONGTEXT DEFAULT NULL');
            $addCol('testimonials', 'video_url', 'VARCHAR(300) DEFAULT NULL');
            $table("CREATE TABLE IF NOT EXISTS `customer_gallery` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `image` VARCHAR(500) NOT NULL,
                `caption` VARCHAR(200) DEFAULT NULL,
                `status` ENUM('published','draft') NOT NULL DEFAULT 'published',
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`))");

            // Live ticker: real purchases and deliveries, written in Admin → Live ticker.
            $table("CREATE TABLE IF NOT EXISTS `purchase_feed` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `customer` VARCHAR(80) NOT NULL,
                `town` VARCHAR(80) DEFAULT NULL,
                `product_id` INT UNSIGNED DEFAULT NULL,
                `product_name` VARCHAR(200) NOT NULL,
                `kind` ENUM('bought','received') NOT NULL DEFAULT 'bought',
                `happened_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `is_live` TINYINT(1) NOT NULL DEFAULT 1,
                `enquiry_id` INT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), KEY `idx_live` (`is_live`, `happened_at`))");

            // Enquiries: an email for the payment-details email, and how they want to pay.
            $addCol('enquiries', 'email', 'VARCHAR(180) DEFAULT NULL');
            $addCol('enquiries', 'payment_method', "VARCHAR(10) DEFAULT NULL");
            $addCol('enquiries', 'emailed_at', 'DATETIME DEFAULT NULL');

            $table("CREATE TABLE IF NOT EXISTS `email_templates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `slug` VARCHAR(60) NOT NULL,
                `name` VARCHAR(120) NOT NULL,
                `subject` VARCHAR(250) NOT NULL,
                `body` TEXT NOT NULL,
                `is_system` TINYINT(1) NOT NULL DEFAULT 0,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), UNIQUE KEY `uq_slug` (`slug`))");
            $ins = $pdo->prepare('INSERT IGNORE INTO email_templates (slug, name, subject, body, is_system) VALUES (:s, :n, :sub, :b, 1)');
            foreach (default_email_templates() as $slug => $t) {
                $ins->execute([':s' => $slug, ':n' => $t['name'], ':sub' => $t['subject'], ':b' => $t['body']]);
            }

            foreach ([
                'bank_account_number' => '10266859613', 'bank_name' => 'Standard Bank', 'bank_account_holder' => '', 'bank_branch_code' => '',
                'cod_enabled' => '1', 'cod_note' => 'Pay the driver in cash when your order arrives. Available where our courier offers it — we confirm on WhatsApp.',
                'eft_enabled' => '1', 'articles_per_day' => '1', 'reels_per_day' => '6', 'content_start_hour' => '8', 'content_end_hour' => '20',
                'auto_product_newsletter' => '0', 'product_newsletter_day' => '2', 'ticker_enabled' => '1',
            ] as $k => $v) {
                if (get_setting($k) === null) { set_setting($k, $v); }
            }

            // Button clicks with no name or phone were test clicks, not customers.
            $pdo->exec("DELETE FROM enquiries WHERE status = 'new' AND (name IS NULL OR name = '') AND (phone IS NULL OR phone = '')");

            import_catalogue($pdo);
        }
        if ($current < 2) {
            // Pudo lockers on the enquiry list (classes/Pudo.php).
            $addCol('enquiries', 'delivery_method', "VARCHAR(20) NOT NULL DEFAULT 'delivery'");
            $addCol('enquiries', 'pudo_locker_code', 'VARCHAR(20) DEFAULT NULL');
            $addCol('enquiries', 'pudo_locker_name', 'VARCHAR(255) DEFAULT NULL');
            $addCol('enquiries', 'shipping_fee', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
            $addCol('enquiries', 'courier_waybill', 'VARCHAR(60) DEFAULT NULL');
            $addCol('enquiries', 'courier_shipment', 'VARCHAR(60) DEFAULT NULL');
            $addCol('enquiries', 'courier_pin', 'VARCHAR(20) DEFAULT NULL');
            foreach (['pudo_enabled' => '1', 'pudo_source_type' => 'locker', 'pudo_box' => 'S', 'pudo_fixed_fee' => '0',
                      'pudo_markup' => '0', 'pudo_fallback_fee' => '65', 'pudo_free_threshold' => '1', 'pudo_from_province' => 'GP'] as $k => $v) {
                if (get_setting($k) === null) {
                    set_setting($k, $v);
                }
            }
        }
        set_setting('cm_schema_version', (string) CUREMEDS_SCHEMA_VERSION);
    } catch (Throwable $e) {
        error_log('CureMeds upgrade failed: ' . $e->getMessage());
    }
}

/** Adds the products in database/catalogue.json; ones already in the shop (same slug) are left alone. */
function import_catalogue(PDO $pdo): int
{
    $data = json_decode((string) @file_get_contents(__DIR__ . '/../database/catalogue.json'), true);
    if (!is_array($data)) {
        return 0;
    }
    $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM categories')->fetchColumn();
    $cat = $pdo->prepare('INSERT IGNORE INTO categories (name, slug, icon, blurb, image, sort_order) VALUES (:n, :s, :i, :b, :img, :o)');
    foreach ($data['categories'] as $c) {
        $cat->execute([':n' => $c['name'], ':s' => $c['slug'], ':i' => $c['icon'], ':b' => $c['blurb'], ':img' => $c['image'], ':o' => ++$sort]);
    }
    $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM brands')->fetchColumn();
    $brand = $pdo->prepare('INSERT IGNORE INTO brands (name, slug, tagline, blurb, accent, sort_order) VALUES (:n, :s, :t, :b, :a, :o)');
    foreach ($data['brands'] as $b) {
        $brand->execute([':n' => $b['name'], ':s' => $b['slug'], ':t' => $b['tagline'], ':b' => $b['blurb'], ':a' => $b['accent'], ':o' => ++$sort]);
    }

    $idOf = function (string $table, string $slug) use ($pdo): ?int {
        $s = $pdo->prepare("SELECT id FROM `$table` WHERE slug = :s");
        $s->execute([':s' => $slug]);
        return ($id = $s->fetchColumn()) ? (int) $id : null;
    };
    $exists = $pdo->prepare('SELECT COUNT(*) FROM products WHERE slug = :s');
    $gallery = $pdo->prepare('INSERT INTO product_gallery (product_id, image, caption, sort_order) VALUES (:p, :i, :c, :o)');
    $added = 0;
    foreach ($data['products'] as $p) {
        $exists->execute([':s' => $p['slug']]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $gal = $p['gallery'];
        unset($p['gallery']);
        $p['category_id'] = $idOf('categories', $p['category']);
        $p['brand_id']    = $idOf('brands', $p['brand']);
        unset($p['category'], $p['brand']);
        $p += ['type' => 'simple', 'stock_status' => 'in_stock', 'stock_qty' => 50, 'status' => 'published'];
        $id = db_insert('products', $p);
        foreach ($gal as $i => $img) {
            $gallery->execute([':p' => $id, ':i' => $img, ':c' => $p['name'], ':o' => $i + 1]);
        }
        $added++;
    }
    return $added;
}

/** Small helpers the gallery and testimonial pages share with Phola. */
if (!function_exists('clean_text')) {
    function clean_text(?string $s): string
    {
        return trim(strip_tags((string) $s));
    }
}

if (!function_exists('save_public_image')) {
    /** Saves an uploaded photo under uploads/$folder/ and returns its site path. */
    function save_public_image(array $file, string $folder): ?string
    {
        $exts  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $check = validate_upload($file, array_keys($exts));
        if (!$check['ok']) {
            return null;
        }
        $dir = UPLOAD_PATH . $folder . '/';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $name = bin2hex(random_bytes(16)) . '.' . $exts[$check['mime']];
        return move_uploaded_file($file['tmp_name'], $dir . $name) ? 'uploads/' . $folder . '/' . $name : null;
    }
}

// ── Payment ──────────────────────────────────────────────────────────────

/** How customers can pay. The account holder only shows once it is filled in under Settings. */
function payment_methods(): array
{
    $m = [];
    if (get_setting('eft_enabled', '1') === '1' && trim((string) get_setting('bank_account_number', '')) !== '') {
        $rows = array_filter([
            'Bank'           => trim((string) get_setting('bank_name', '')),
            'Account number' => trim((string) get_setting('bank_account_number', '')),
            'Account holder' => trim((string) get_setting('bank_account_holder', '')),
            'Branch code'    => trim((string) get_setting('bank_branch_code', '')),
        ], fn($v) => $v !== '');
        $m['eft'] = ['label' => 'EFT / bank deposit', 'icon' => 'fa-building-columns', 'rows' => $rows,
                     'note' => 'Use your order reference (e.g. E123) or your cellphone number as the payment reference, then send the proof of payment on WhatsApp.'];
    }
    if (get_setting('cod_enabled', '1') === '1') {
        $m['cod'] = ['label' => 'Cash on delivery', 'icon' => 'fa-hand-holding-dollar', 'rows' => [],
                     'note' => (string) get_setting('cod_note', 'Pay the driver in cash when your order arrives.')];
    }
    return $m;
}

/** Plain text for WhatsApp messages and plain emails. */
function payment_details_text(): string
{
    $out = [];
    foreach (payment_methods() as $m) {
        $lines = [$m['label'] . ':'];
        foreach ($m['rows'] as $k => $v) {
            $lines[] = $k . ': ' . ($k === 'Account number' ? format_account_number($v) : $v);
        }
        $lines[] = $m['note'];
        $out[] = implode("\n", $lines);
    }
    return implode("\n\n", $out);
}

/** Email-safe HTML block (inline styles only). */
function payment_details_email(): string
{
    $html = '';
    foreach (payment_methods() as $m) {
        $rows = '';
        foreach ($m['rows'] as $k => $v) {
            $rows .= '<tr><td style="padding:4px 12px 4px 0;color:#5B6B80;font-size:13px;">' . e($k) . '</td><td style="padding:4px 0;font-weight:700;font-size:15px;color:#0A1B3D;">'
                . e($k === 'Account number' ? format_account_number($v) : $v) . '</td></tr>';
        }
        $html .= '<div style="border:1px solid #DCE3EE;border-radius:12px;padding:16px 18px;margin:0 0 12px;">'
            . '<div style="font-weight:800;color:#1557D0;margin-bottom:6px;">' . e($m['label']) . '</div>'
            . ($rows ? '<table role="presentation" cellpadding="0" cellspacing="0">' . $rows . '</table>' : '')
            . '<p style="margin:8px 0 0;font-size:13px;color:#5B6B80;">' . e($m['note']) . '</p></div>';
    }
    return $html;
}

/** 10266859613 → 10 26 68 596 13, the way the bank prints it. */
function format_account_number(string $n): string
{
    $d = preg_replace('/\D/', '', $n) ?? '';
    return strlen($d) === 11 ? substr($d, 0, 2) . ' ' . substr($d, 2, 2) . ' ' . substr($d, 4, 2) . ' ' . substr($d, 6, 3) . ' ' . substr($d, 9) : $n;
}

// ── Email templates ──────────────────────────────────────────────────────

/** The built-in templates. Staff edit the copies in Admin → Email templates. */
function default_email_templates(): array
{
    return [
        'enquiry-payment' => [
            'name' => 'Enquiry: how to pay (sent automatically)',
            'subject' => 'Your CureMeds order {{reference}} — how to pay',
            'body' => "Hi {{name}},\n\nThank you for your order enquiry. Here is what you asked for:\n\n{{items}}\n\nTotal: {{total}} (we confirm stock and delivery on WhatsApp before you pay).\n\n{{payment_details}}\n\nQuestions? WhatsApp us on {{whatsapp}} and quote {{reference}}.\n\nThe CureMeds team",
        ],
        'payment-received' => [
            'name' => 'Payment received',
            'subject' => 'Payment received — order {{reference}}',
            'body' => "Hi {{name}},\n\nWe have received your payment for order {{reference}}. Your order is being packed and we will send the tracking details as soon as it leaves us.\n\nThank you for choosing CureMeds.",
        ],
        'order-dispatched' => [
            'name' => 'Order on its way',
            'subject' => 'Your CureMeds order is on its way',
            'body' => "Hi {{name}},\n\nGood news — order {{reference}} is on its way to you.\n\nIf you chose cash on delivery, please have the exact amount ready for the driver.\n\nTrack your medication and get dose reminders in your free patient portal: {{site_url}}/modules/auth/login.php",
        ],
        'blank' => [
            'name' => 'Blank letter',
            'subject' => 'A message from CureMeds',
            'body' => "Hi {{name}},\n\n\n\nKind regards,\nThe CureMeds team",
        ],
    ];
}

function email_template(string $slug): ?array
{
    try {
        $s = db()->prepare('SELECT * FROM email_templates WHERE slug = :s');
        $s->execute([':s' => $slug]);
        return $s->fetch() ?: null;
    } catch (Throwable) {
        return null;
    }
}

/** Fills {{placeholders}}; the body's blank lines become paragraphs, and {{payment_details}} the payment card. */
function render_email_template(string $subject, string $body, array $vars): array
{
    $vars += ['site_url' => SITE_URL, 'whatsapp' => Health::displayPhone(whatsapp_number()), 'name' => 'there', 'reference' => '', 'items' => '', 'total' => ''];
    $plain = $vars;
    $plain['payment_details'] = payment_details_text();
    $fill = fn(string $s, array $v) => preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn($m) => (string) ($v[$m[1]] ?? ''), $s) ?? $s;

    $html = '';
    foreach (preg_split('/\R{2,}/', trim($body)) ?: [] as $para) {
        if (trim($para) === '{{payment_details}}') {
            $html .= payment_details_email();
            continue;
        }
        $text = $fill($para, $plain);
        $html .= '<p style="margin:0 0 14px;">' . nl2br(e($text)) . '</p>';
    }
    // Links typed in the body become clickable.
    $html = preg_replace('~(?<!["\'=])(https?://[^\s<]+)~', '<a href="$1" style="color:#1557D0;">$1</a>', $html) ?? $html;
    return ['subject' => $fill($subject, $plain), 'html' => email_wrap($html), 'inner' => $html, 'text' => $fill($body, $plain)];
}

function send_template_email(string $to, string $slug, array $vars): bool
{
    $t = email_template($slug) ?? (default_email_templates()[$slug] ?? null);
    if (!$t || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $m = render_email_template((string) $t['subject'], (string) $t['body'], $vars);
    return Mailer::send($to, $m['subject'], $m['html'], $m['text']);
}

/** Template values for one enquiry row. */
function enquiry_email_vars(array $enq): array
{
    $items = json_decode((string) $enq['items'], true) ?: [];
    return [
        'name'      => trim((string) $enq['name']) !== '' ? strtok((string) $enq['name'], ' ') : 'there',
        'reference' => 'E' . $enq['id'],
        'items'     => implode("\n", array_map(fn($i) => '• ' . $i['qty'] . ' × ' . $i['name'] . ' — ' . format_price_short((float) $i['price'] * (int) $i['qty']), $items)),
        'total'     => format_price_short((float) $enq['total']),
    ];
}

// ── Live ticker ──────────────────────────────────────────────────────────

/** Recent real purchases for the site ticker (newest first, last 30 days). */
function purchase_feed(int $limit = 12): array
{
    try {
        return db()->query("SELECT f.*, p.slug, p.featured_image FROM purchase_feed f LEFT JOIN products p ON p.id = f.product_id
                            WHERE f.is_live = 1 AND f.happened_at >= NOW() - INTERVAL 30 DAY
                            ORDER BY f.happened_at DESC LIMIT " . max(1, $limit))->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/** "Thandi M." from "Thandi Mokoena" — never the full surname. */
function ticker_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = $parts[0] ?? 'A customer';
    return isset($parts[1]) ? $first . ' ' . mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.' : $first;
}

// ── Weekly product newsletter ────────────────────────────────────────────

/** Queues one product-grid newsletter a week (Admin → Email templates → Automatic newsletters). */
function weekly_product_newsletter(): string
{
    if (get_setting('auto_product_newsletter', '0') !== '1') {
        return 'off';
    }
    if ((int) date('N') !== (int) get_setting('product_newsletter_day', '2') || (int) date('G') < 9) {
        return 'not today';
    }
    if (get_setting('product_newsletter_last', '') === date('Y-m-d') || !NewsletterService::ensureSchema()) {
        return 'already sent';
    }
    set_setting('product_newsletter_last', date('Y-m-d'));
    $ids = db()->query("SELECT id FROM products WHERE status = 'published' AND stock_status <> 'out_of_stock' AND featured_image IS NOT NULL
                        ORDER BY created_at DESC, is_featured DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $more = db()->query("SELECT id FROM products WHERE status = 'published' AND stock_status <> 'out_of_stock' AND featured_image IS NOT NULL
                         ORDER BY RAND() LIMIT 8")->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_slice(array_values(array_unique(array_map('intval', array_merge($ids, $more)))), 0, 4);
    if (count($ids) < 2) {
        return 'not enough products';
    }
    $admin = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    $data  = ['eyebrow' => 'This week at CureMeds', 'heading' => 'Picked for your health this week',
              'intro' => 'A few products our customers are ordering right now. Order on WhatsApp — we deliver nationwide, and you can pay by EFT or cash on delivery.',
              'product_ids' => $ids, 'cta_label' => 'See the full shop', 'cta_url' => SITE_URL . '/shop.php'];
    $id = db_insert('newsletter_campaigns', [
        'subject' => 'This week at CureMeds: ' . date('j F'), 'body_html' => '', 'audience' => 'all', 'status' => 'draft',
        'created_by' => $admin, 'template' => 'grid', 'template_data' => json_encode($data), 'preheader' => 'Picked for your health this week',
    ]);
    return 'queued #' . $id . ' to ' . NewsletterService::queue($id);
}

curemeds_migrate();
