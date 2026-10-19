<?php
declare(strict_types=1);

final class NewsletterService
{
    /** Bump when ensureSchema() learns something new. */
    private const SCHEMA_VERSION = 2;

    /** Cookie that marks a browser as already on the list, so the sign-up gate stays away. */
    public const COOKIE = 'fc_sub';

    /** Default wording recorded against every sign-up as evidence of consent (POPIA s69). */
    public const CONSENT_TEXT = 'I agree to receive CureMeds emails about products, restocks and offers. I can unsubscribe at any time.';

    /**
     * Brings the newsletter tables up to date on first use.
     *
     * Production has no shell and the last manual SQL import silently didn't
     * happen, so rather than ship another file somebody has to remember to
     * import, the code upgrades its own tables. Guarded by a settings flag, so
     * after the first run this costs one lookup in an already-cached array.
     *
     * Columns are added via information_schema checks rather than
     * ADD COLUMN IF NOT EXISTS, which MariaDB has but MySQL 8 does not.
     *
     * Returns whether the tables are ready. Never throws: a failed upgrade is
     * logged and reported as false, so callers can fail safe — in particular
     * the locked sign-up gate, which must not lock the whole shop if nobody
     * can actually sign up.
     */
    public static function ensureSchema(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        if ((int) get_setting('newsletter_schema', '0') >= self::SCHEMA_VERSION) {
            return $ready = true;
        }

        try {
            self::migrate();
            set_setting('newsletter_schema', (string) self::SCHEMA_VERSION);
            return $ready = true;
        } catch (Throwable $e) {
            error_log('CureMeds newsletter schema upgrade failed: ' . $e->getMessage());
            return $ready = false;
        }
    }

    private static function migrate(): void
    {
        $pdo = db();

        $pdo->exec("CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email`           VARCHAR(180) NOT NULL UNIQUE,
            `name`            VARCHAR(120) DEFAULT NULL,
            `audience`        ENUM('all','retail','reseller') NOT NULL DEFAULT 'all',
            `status`          ENUM('active','unsubscribed') NOT NULL DEFAULT 'active',
            `token`           VARCHAR(64) NOT NULL,
            `source`          VARCHAR(40) NOT NULL DEFAULT 'footer',
            `subscribed_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `unsubscribed_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`),
            KEY `idx_token` (`token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `newsletter_campaigns` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `subject`    VARCHAR(255) NOT NULL,
            `body_html`  LONGTEXT NOT NULL,
            `audience`   ENUM('all','retail','reseller') NOT NULL DEFAULT 'all',
            `status`     ENUM('draft','sending','sent','failed') NOT NULL DEFAULT 'draft',
            `sent_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `sent_at`    DATETIME DEFAULT NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // One row per recipient per campaign. Sending works through this queue
        // in small batches so a big list can't hit the host's execution time
        // limit, and the unique key means a refresh or a resumed send can never
        // mail the same person twice.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `newsletter_sends` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `campaign_id`   INT UNSIGNED NOT NULL,
            `subscriber_id` INT UNSIGNED NOT NULL,
            `email`         VARCHAR(180) NOT NULL,
            `status`        ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            `error`         VARCHAR(255) DEFAULT NULL,
            `sent_at`       DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_campaign_sub` (`campaign_id`, `subscriber_id`),
            KEY `idx_campaign_status` (`campaign_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = [
            'newsletter_subscribers' => [
                'consent_ip'   => 'VARCHAR(45) DEFAULT NULL',
                'consent_text' => 'VARCHAR(255) DEFAULT NULL',
            ],
            'newsletter_campaigns' => [
                'template'      => "VARCHAR(30) NOT NULL DEFAULT 'custom'",
                'template_data' => 'LONGTEXT DEFAULT NULL',
                'preheader'     => 'VARCHAR(200) DEFAULT NULL',
                'total_count'   => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'failed_count'  => 'INT UNSIGNED NOT NULL DEFAULT 0',
            ],
        ];

        $has = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        foreach ($columns as $table => $cols) {
            foreach ($cols as $col => $definition) {
                $has->execute([':t' => $table, ':c' => $col]);
                if ((int) $has->fetchColumn() === 0) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
                }
            }
        }
    }

    // ── Subscribing ──────────────────────────────────────────────────────

    /**
     * @return array{ok:bool,message:string,new:bool}
     *   `new` is true when this call actually added (or re-added) someone, so
     *   the caller knows whether a welcome email is owed.
     */
    public static function subscribe(
        string $email,
        ?string $name = null,
        string $source = 'footer',
        string $audience = 'all',
        ?string $consentText = null
    ): array {
        if (!self::ensureSchema()) {
            return ['ok' => false, 'message' => 'Sign-up is not available right now. Please try again later.', 'new' => false];
        }

        $email = safe_email($email) ?: '';
        if ($email === '') {
            return ['ok' => false, 'message' => 'That email address does not look right.', 'new' => false];
        }

        $source   = preg_replace('/[^a-z0-9_-]/', '', strtolower($source)) ?: 'footer';
        $audience = in_array($audience, ['all', 'retail', 'reseller'], true) ? $audience : 'all';
        $consent  = [
            'consent_ip'   => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            'consent_text' => mb_substr($consentText ?? self::CONSENT_TEXT, 0, 255),
        ];

        $stmt = db()->prepare('SELECT id, status FROM newsletter_subscribers WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['status'] === 'active') {
                return ['ok' => true, 'message' => 'You are already on the list.', 'new' => false];
            }
            // A fresh, explicit sign-up from someone who previously left. Record
            // the new consent rather than reviving the old one.
            db_update('newsletter_subscribers', (int) $existing['id'], $consent + [
                'status'          => 'active',
                'source'          => $source,
                'subscribed_at'   => date('Y-m-d H:i:s'),
                'unsubscribed_at' => null,
            ]);
            return ['ok' => true, 'message' => 'Welcome back — you are subscribed again.', 'new' => true];
        }

        db_insert('newsletter_subscribers', $consent + [
            'email'    => $email,
            'name'     => $name ? mb_substr(trim($name), 0, 120) : null,
            'audience' => $audience,
            'token'    => bin2hex(random_bytes(24)),
            'source'   => $source,
        ]);
        return ['ok' => true, 'message' => 'You are on the list.', 'new' => true];
    }

    /** Remembers this browser as subscribed, so the sign-up gate and popup stop appearing. */
    public static function rememberBrowser(): void
    {
        setcookie(self::COOKIE, '1', [
            'expires'  => time() + 60 * 60 * 24 * 365,
            'path'     => '/',
            'secure'   => IS_PRODUCTION,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = '1';
    }

    public static function browserIsSubscribed(): bool
    {
        return ($_COOKIE[self::COOKIE] ?? '') === '1';
    }

    // ── Sign-up gate ─────────────────────────────────────────────────────

    /**
     * Pages that stay open even in locked mode.
     *
     * Someone has to be able to read the privacy policy before agreeing to it,
     * reach the information officer to exercise their POPIA rights, and leave
     * the list, all without first joining it. Sign-in stays open so existing
     * customers are never asked to subscribe just to reach their account.
     */
    private const GATE_EXEMPT = [
        'privacy.php', 'terms.php', 'refund-policy.php', 'reseller-programme.php', 'contact.php',
        'invoice.php', 'serve-pop.php', 'order-confirmation.php',
        'modules/auth/', 'modules/newsletter/',
    ];

    /** locked = no browsing without an email · popup = dismissible offer · off */
    public static function gateMode(): string
    {
        $mode = (string) get_setting('newsletter_gate_mode', 'locked');
        return in_array($mode, ['locked', 'popup', 'off'], true) ? $mode : 'locked';
    }

    /**
     * Which gate, if any, this request should render: 'locked', 'popup' or null.
     *
     * Signed-in users never see it: they are already known to us, and an
     * existing customer being told to subscribe to reach their own orders
     * would be absurd.
     */
    public static function gateFor(?array $user): ?string
    {
        // Admins can see either version on the live site via ?preview_gate=locked|popup.
        $preview = (string) ($_GET['preview_gate'] ?? '');
        if ($user && ($user['role'] ?? '') === 'admin' && in_array($preview, ['locked', 'popup'], true)) {
            return $preview;
        }

        $mode = self::gateMode();
        if ($mode === 'off' || $user !== null || self::browserIsSubscribed()) {
            return null;
        }

        // Fail open: if sign-ups can't be stored, a locked gate would lock
        // every visitor out of the shop with no way through.
        if (!self::ensureSchema()) {
            return null;
        }

        $root   = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
        $script = str_replace('\\', '/', (string) realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
        $rel    = ltrim(substr($script, strlen($root)), '/');
        foreach (self::GATE_EXEMPT as $exempt) {
            if ($rel === $exempt || (str_ends_with($exempt, '/') && str_starts_with($rel, $exempt))) {
                return null;
            }
        }

        // A dismissible offer must never interrupt someone who is about to pay.
        if ($mode === 'popup' && in_array($rel, ['cart.php', 'checkout.php'], true)) {
            return null;
        }

        return $mode;
    }

    public static function unsubscribe(string $token): bool
    {
        self::ensureSchema();

        $stmt = db()->prepare('SELECT id FROM newsletter_subscribers WHERE token = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        db_update('newsletter_subscribers', (int) $row['id'], [
            'status'          => 'unsubscribed',
            'unsubscribed_at' => date('Y-m-d H:i:s'),
        ]);
        // Anything still queued for them must not go out after they've left.
        db()->prepare("DELETE FROM newsletter_sends WHERE subscriber_id = :s AND status = 'pending'")
            ->execute([':s' => (int) $row['id']]);
        return true;
    }

    public static function unsubscribeUrl(string $token): string
    {
        return SITE_URL . '/modules/newsletter/unsubscribe.php?token=' . urlencode($token);
    }

    /**
     * RFC 2369 + RFC 8058 headers. Gmail and Yahoo expect these on marketing
     * mail and show their own "Unsubscribe" button beside the sender; without
     * them a campaign is far more likely to be filed as spam. The one-click
     * POST lands on unsubscribe.php, which honours it without a confirm step.
     *
     * @return array<string,string>
     */
    public static function unsubscribeHeaders(string $token): array
    {
        return [
            'List-Unsubscribe'      => '<' . self::unsubscribeUrl($token) . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /**
     * The coupon offered for joining, if one is configured and still usable.
     * Returns null rather than advertising a code that checkout would reject.
     */
    public static function welcomeCoupon(): ?array
    {
        // Stored as 'none' to switch the offer off: get_setting() treats an
        // empty string as unset and would fall back to the default code.
        $code = trim((string) get_setting('newsletter_welcome_coupon', 'WELCOME10'));
        if ($code === '' || $code === 'none') {
            return null;
        }
        try {
            $stmt = db()->prepare(
                "SELECT * FROM coupons WHERE code = :c AND status = 'active'
                 AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                 AND (max_uses IS NULL OR max_uses = 0 OR used_count < max_uses) LIMIT 1"
            );
            $stmt->execute([':c' => $code]);
            return $stmt->fetch() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** "10% off orders over R250" — the offer in words, for the popup and the welcome email. */
    public static function describeCoupon(array $coupon): string
    {
        $value = (float) $coupon['discount_value'];
        $off   = $coupon['discount_type'] === 'percentage'
            ? rtrim(rtrim(number_format($value, 2), '0'), '.') . '% off'
            : format_price_short($value) . ' off';
        $min = (float) $coupon['min_order'];

        return $off . ($min > 0 ? ' orders over ' . format_price_short($min) : ' your order');
    }

    /** Sends the welcome email. Quietly does nothing if the admin has switched it off. */
    public static function sendWelcome(string $email): bool
    {
        if (get_setting('newsletter_welcome_email', '1') !== '1') {
            return false;
        }

        $stmt = db()->prepare('SELECT token FROM newsletter_subscribers WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $token = (string) $stmt->fetchColumn();
        if ($token === '') {
            return false;
        }

        $coupon = self::welcomeCoupon();
        $inner  = NewsletterTemplates::render('welcome', [
            'coupon' => $coupon,
        ]);
        $subject = $coupon
            ? 'Welcome to CureMeds — here is your ' . self::describeCoupon($coupon)
            : 'Welcome to CureMeds';

        return Mailer::send($email, $subject, email_wrap($inner, self::unsubscribeUrl($token)), '', '', '', self::unsubscribeHeaders($token));
    }

    public static function activeSubscribers(string $audience = 'all'): array
    {
        $sql = "SELECT * FROM newsletter_subscribers WHERE status = 'active'";
        if ($audience !== 'all') {
            $sql .= " AND audience IN ('all', :a)";
        }
        $stmt = db()->prepare($sql);
        if ($audience !== 'all') {
            $stmt->bindValue(':a', $audience);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── Campaigns ────────────────────────────────────────────────────────

    public static function find(int $id): ?array
    {
        self::ensureSchema();
        $stmt = db()->prepare('SELECT * FROM newsletter_campaigns WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** The campaign's body as it will appear inside the email shell. */
    public static function renderBody(array $campaign): string
    {
        if (($campaign['template'] ?? 'custom') === 'custom') {
            return (string) $campaign['body_html'];
        }
        $data = json_decode((string) ($campaign['template_data'] ?? ''), true) ?: [];
        return NewsletterTemplates::render((string) $campaign['template'], $data);
    }

    /** The complete email for one recipient: body, shell, preheader, their own unsubscribe link. */
    public static function renderEmail(array $campaign, string $unsubscribeUrl): string
    {
        return email_wrap(self::renderBody($campaign), $unsubscribeUrl, (string) ($campaign['preheader'] ?? ''));
    }

    public static function sendTest(int $campaignId, string $to): bool
    {
        $campaign = self::find($campaignId);
        if (!$campaign || !safe_email($to)) {
            return false;
        }
        // A test link that goes nowhere harmful rather than unsubscribing a real person.
        $html = self::renderEmail($campaign, SITE_URL . '/modules/newsletter/unsubscribe.php?token=test');
        return Mailer::send($to, '[TEST] ' . $campaign['subject'], $html);
    }

    /**
     * Snapshots the audience into the send queue and marks the campaign as
     * sending. Safe to call twice: INSERT IGNORE on the unique key means nobody
     * is queued twice.
     */
    public static function queue(int $campaignId): int
    {
        $campaign = self::find($campaignId);
        if (!$campaign) {
            return 0;
        }

        $sql = "INSERT IGNORE INTO newsletter_sends (campaign_id, subscriber_id, email)
                SELECT :cid, id, email FROM newsletter_subscribers WHERE status = 'active'";
        $params = [':cid' => $campaignId];
        if ($campaign['audience'] !== 'all') {
            $sql .= " AND audience IN ('all', :a)";
            $params[':a'] = $campaign['audience'];
        }
        db()->prepare($sql)->execute($params);

        $total = self::countSends($campaignId);
        db_update('newsletter_campaigns', $campaignId, [
            'status'      => 'sending',
            'total_count' => $total,
            // Freeze what actually went out, so the record stays accurate even
            // if product prices or the template change later.
            'body_html'   => self::renderBody($campaign),
        ]);
        return $total;
    }

    /**
     * Sends the next batch from the queue.
     *
     * @return array{total:int,sent:int,failed:int,pending:int,done:bool}
     */
    public static function sendBatch(int $campaignId, int $limit = 20): array
    {
        $campaign = self::find($campaignId);
        if (!$campaign) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'done' => true];
        }

        if ($campaign['status'] === 'sending') {
            $stmt = db()->prepare(
                "SELECT s.id, s.email, n.token FROM newsletter_sends s
                 JOIN newsletter_subscribers n ON n.id = s.subscriber_id
                 WHERE s.campaign_id = :cid AND s.status = 'pending' AND n.status = 'active'
                 ORDER BY s.id LIMIT " . max(1, min(100, $limit))
            );
            $stmt->execute([':cid' => $campaignId]);

            // Render the body once per batch; only the unsubscribe link differs per person.
            // The body was frozen into body_html when the campaign was queued.
            $frozen = ['template' => 'custom'] + $campaign;
            $mark   = db()->prepare('UPDATE newsletter_sends SET status = :st, error = :er, sent_at = NOW() WHERE id = :id');

            foreach ($stmt->fetchAll() as $row) {
                $ok = false;
                $error = null;
                try {
                    $ok = Mailer::send(
                        $row['email'],
                        $campaign['subject'],
                        self::renderEmail($frozen, self::unsubscribeUrl($row['token'])),
                        '', '', '',
                        self::unsubscribeHeaders($row['token'])
                    );
                    if (!$ok) { $error = 'Mail server refused the message.'; }
                } catch (Throwable $e) {
                    $error = mb_substr($e->getMessage(), 0, 250);
                }
                $mark->execute([':st' => $ok ? 'sent' : 'failed', ':er' => $error, ':id' => (int) $row['id']]);
            }
        }

        return self::progress($campaignId, true);
    }

    /** @return array{total:int,sent:int,failed:int,pending:int,done:bool} */
    public static function progress(int $campaignId, bool $finalise = false): array
    {
        $stmt = db()->prepare(
            "SELECT
               SUM(s.status = 'sent')   AS sent,
               SUM(s.status = 'failed') AS failed,
               SUM(s.status = 'pending' AND n.status = 'active') AS pending,
               COUNT(*) AS total
             FROM newsletter_sends s
             JOIN newsletter_subscribers n ON n.id = s.subscriber_id
             WHERE s.campaign_id = :cid"
        );
        $stmt->execute([':cid' => $campaignId]);
        $r = $stmt->fetch() ?: [];

        $p = [
            'total'   => (int) ($r['total'] ?? 0),
            'sent'    => (int) ($r['sent'] ?? 0),
            'failed'  => (int) ($r['failed'] ?? 0),
            'pending' => (int) ($r['pending'] ?? 0),
        ];
        $p['done'] = $p['pending'] === 0;

        if ($finalise && $p['done']) {
            $campaign = self::find($campaignId);
            if ($campaign && $campaign['status'] === 'sending') {
                db_update('newsletter_campaigns', $campaignId, [
                    'status'       => ($p['sent'] > 0 || $p['total'] === 0) ? 'sent' : 'failed',
                    'sent_count'   => $p['sent'],
                    'failed_count' => $p['failed'],
                    'sent_at'      => date('Y-m-d H:i:s'),
                ]);
            }
        }

        return $p;
    }

    private static function countSends(int $campaignId): int
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM newsletter_sends WHERE campaign_id = :cid');
        $stmt->execute([':cid' => $campaignId]);
        return (int) $stmt->fetchColumn();
    }
}
