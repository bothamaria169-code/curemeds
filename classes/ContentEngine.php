<?php
declare(strict_types=1);

/**
 * The automatic content run, driven by scripts/cron.php:
 *
 *  - articles: Groq writes a health article around two or three catalogue
 *    products, it is published on the blog with those products listed under
 *    it, and shared to the Facebook Page (link post) and Instagram (photo).
 *  - reels: product reels are built entirely on ImageKit (photo and text
 *    layers on assets/video/reel-base.mp4) and posted as Instagram and
 *    Facebook Page reels, spread evenly through the posting hours.
 *
 * Settings (Admin → AI & social media → Automatic content): auto_articles,
 * articles_per_day, auto_reels, reels_per_day, content_start_hour, content_end_hour.
 */
final class ContentEngine
{
    public const BASE_VIDEO = 'assets/video/reel-base.mp4';
    private const LENGTH = 12;

    /** How many of something should have gone out by now, spread evenly over the posting hours. */
    private static function dueByNow(int $perDay): int
    {
        $start = (int) get_setting('content_start_hour', '8');
        $end   = max($start + 1, (int) get_setting('content_end_hour', '20'));
        $now   = (int) date('G') + (int) date('i') / 60;
        if ($perDay <= 0 || $now < $start) {
            return 0;
        }
        $slot = ($end - $start) / $perDay;
        return min($perDay, 1 + (int) floor(($now - $start) / $slot));
    }

    private static function doneToday(string $type): int
    {
        // One reel goes to both platforms, and each reel uses a different product.
        $stmt = db()->prepare("SELECT COUNT(DISTINCT product_id) FROM social_posts WHERE media_type = :t AND created_at >= CURDATE()");
        $stmt->execute([':t' => $type]);
        return (int) $stmt->fetchColumn();
    }

    /** Called every cron run: posts at most one article and one reel when one is due. */
    public static function run(): array
    {
        $out = [];
        if (get_setting('auto_articles', '0') === '1') {
            $done = (int) db()->query("SELECT COUNT(*) FROM blogs WHERE auto_generated = 1 AND source_url IS NULL AND created_at >= CURDATE()")->fetchColumn();
            $out['article'] = $done < self::dueByNow((int) get_setting('articles_per_day', '1')) ? self::article() : 'not due';
        }
        if (get_setting('auto_news', '0') === '1') {
            $done = (int) db()->query("SELECT COUNT(*) FROM blogs WHERE auto_generated = 1 AND source_url IS NOT NULL AND created_at >= CURDATE()")->fetchColumn();
            $out['news'] = $done < self::dueByNow((int) get_setting('news_per_day', '2')) ? NewsEngine::article() : 'not due';
        }
        if (get_setting('auto_reels', '0') === '1') {
            // After a failed attempt, wait 20 minutes before building another reel.
            $retryOk = (int) get_setting('reel_last_attempt', '0') < time() - 1200;
            if (self::doneToday('reel') >= self::dueByNow((int) get_setting('reels_per_day', '6'))) {
                $out['reel'] = 'not due';
            } elseif (!$retryOk) {
                $out['reel'] = 'waiting after a failed attempt';
            } else {
                set_setting('reel_last_attempt', (string) time());
                $out['reel'] = self::reel();
            }
        }
        return $out;
    }

    // ── Articles ─────────────────────────────────────────────────────────

    /** Products for the next article: a category featured least recently, two or three of its products. */
    private static function articleProducts(): array
    {
        $cat = db()->query("SELECT p.category_id FROM products p
                            LEFT JOIN (SELECT b.category_hint, MAX(b.created_at) last FROM blogs b WHERE b.auto_generated = 1 GROUP BY b.category_hint) x
                              ON x.category_hint = p.category_id
                            WHERE p.status = 'published' AND p.featured_image IS NOT NULL
                            GROUP BY p.category_id ORDER BY MAX(x.last) IS NOT NULL, MAX(x.last), RAND() LIMIT 1")->fetchColumn();
        if (!$cat) {
            return [];
        }
        $ids = db()->prepare("SELECT id FROM products WHERE status = 'published' AND featured_image IS NOT NULL AND category_id = :c ORDER BY is_featured DESC, RAND() LIMIT 3");
        $ids->execute([':c' => $cat]);
        $repo = new ProductRepository();
        return array_values(array_filter(array_map(fn($id) => $repo->getById((int) $id), $ids->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** Writes, publishes and shares one article. Returns a short status line. */
    public static function article(): string
    {
        $products = self::articleProducts();
        if (!$products) {
            return 'no products to write about';
        }
        $facts = implode("\n\n", array_map(fn($p) => '- ' . $p['name'] . ' (' . ($p['pack_size'] ?: 'pack') . '): ' . strip_tags((string) $p['short_desc'])
            . ($p['benefits'] ? ' Benefits: ' . str_replace("\n", '; ', strip_tags((string) $p['benefits'])) : ''), $products));
        $topic = (string) ($products[0]['category_name'] ?? 'wellness');
        $recent = implode('; ', db()->query("SELECT title FROM blogs ORDER BY id DESC LIMIT 12")->fetchAll(PDO::FETCH_COLUMN));

        $err = '';
        $reply = Groq::chat([
            ['role' => 'system', 'content' => 'You write for CureMeds, a South African online health store. Warm, plain English for everyday South Africans. '
                . 'Supplements SUPPORT wellness: never say they treat, cure or prevent any disease, never tell anyone to stop prescribed medicine, and suggest talking to a clinic or pharmacist where it matters. '
                . 'Use only the product facts given. Reply with JSON only.'],
            ['role' => 'user', 'content' => "Write a helpful 500-700 word blog article on the theme \"{$topic}\" that naturally mentions these products:\n{$facts}\n\n"
                . "Avoid repeating these recent titles: {$recent}\n\n"
                . 'Return JSON: {"title": "...", "excerpt": "one sentence", "html": "<h2>, <p>, <ul><li> only", "caption": "a 2-3 sentence social media teaser with 2 emojis"}'],
        ], $err, 1800, 0.7);
        $json = json_decode((string) preg_replace('/^```(?:json)?|```$/m', '', trim($reply)), true);
        if (!is_array($json) || empty($json['title']) || empty($json['html'])) {
            return 'Groq: ' . ($err ?: 'reply was not usable');
        }

        $title = mb_substr(clean_text((string) $json['title']), 0, 240);
        $slug  = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: 'article';
        $taken = db()->prepare('SELECT COUNT(*) FROM blogs WHERE slug = :s');
        $taken->execute([':s' => $slug]);
        if ($taken->fetchColumn()) {
            $slug .= '-' . date('ymdHi');
        }
        $html  = strip_tags((string) $json['html'], '<h2><h3><p><ul><ol><li><strong><em><b><i>');
        $admin = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        $id = db_insert('blogs', [
            'title' => $title, 'slug' => $slug,
            'image' => $products[0]['header_image'] ?: $products[0]['featured_image'],
            'excerpt' => mb_substr(clean_text((string) ($json['excerpt'] ?? '')), 0, 300),
            'content' => $html, 'category' => mb_substr($topic, 0, 60), 'author_id' => $admin,
            'read_mins' => max(2, (int) ceil(str_word_count(strip_tags($html)) / 200)),
            'status' => 'published', 'auto_generated' => 1, 'category_hint' => (int) $products[0]['category_id'],
            'product_ids' => implode(',', array_column($products, 'id')),
        ]);

        $link = SITE_URL . '/modules/blog/post.php?slug=' . urlencode($slug);
        if (get_setting('email_articles', '0') === '1') {
            self::emailArticle($title, (string) ($json['excerpt'] ?? ''), $link, (string) ($products[0]['header_image'] ?: $products[0]['featured_image']), array_column($products, 'id'), $admin);
        }
        $teaser = trim((string) ($json['caption'] ?? '')) ?: $title;
        $shared = [];
        foreach (array_keys(SocialPoster::PLATFORMS) as $platform) {
            if (!SocialPoster::connected($platform)) { continue; }
            $caption = $teaser . "\n\n" . ($platform === 'facebook' ? '📖 Read it: ' . $link : '📖 Full article: link in bio · ' . SITE_DOMAIN)
                . "\n🛒 Featured: " . implode(', ', array_column($products, 'name'))
                . "\n💬 WhatsApp: " . Health::displayPhone(whatsapp_number()) . "\n\n#CureMeds #HealthSA #Wellness";
            $r = $platform === 'facebook'
                ? SocialPoster::publishLink($products[0], $link, $caption)
                : SocialPoster::publish('instagram', $products[0], $caption, 'article');
            $shared[] = $platform . ($r['ok'] ? ' ✓' : ' ✗ ' . $r['error']);
        }
        return "article #{$id} \"{$title}\"" . ($shared ? ' — ' . implode(', ', $shared) : '');
    }

    /** Queues the article as a newsletter; cron sends it out in batches. */
    private static function emailArticle(string $title, string $excerpt, string $link, string $image, array $productIds, int $admin): void
    {
        if (!NewsletterService::ensureSchema()) {
            return;
        }
        $data = ['eyebrow' => 'New on the CureMeds blog', 'heading' => $title, 'intro' => $excerpt, 'image' => $image,
                 'product_ids' => array_map('intval', $productIds), 'cta_label' => 'Read the article', 'cta_url' => $link];
        $id = db_insert('newsletter_campaigns', [
            'subject' => mb_substr($title, 0, 250), 'body_html' => '', 'audience' => 'all', 'status' => 'draft',
            'created_by' => $admin, 'template' => 'article', 'template_data' => json_encode($data), 'preheader' => mb_substr($excerpt, 0, 190),
        ]);
        NewsletterService::queue($id);
    }

    // ── Reels ────────────────────────────────────────────────────────────

    /** The product reeled longest ago (never first). */
    private static function reelProduct(): ?array
    {
        $id = db()->query("SELECT p.id FROM products p
                           LEFT JOIN (SELECT product_id, MAX(created_at) last FROM social_posts WHERE media_type = 'reel' GROUP BY product_id) s ON s.product_id = p.id
                           WHERE p.status = 'published' AND p.stock_status <> 'out_of_stock' AND p.featured_image IS NOT NULL
                           ORDER BY s.last IS NOT NULL, s.last, p.is_featured DESC, RAND() LIMIT 1")->fetchColumn();
        return $id ? (new ProductRepository())->getById((int) $id) : null;
    }

    /** ImageKit file path of a hosted URL in layer form: /curemeds/photos/a.jpg → curemeds@@photos@@a.jpg (base64). */
    private static function layerSource(string $hostedUrl): string
    {
        $path = ltrim(substr((string) parse_url($hostedUrl, PHP_URL_PATH), strlen((string) parse_url(ImageKit::config()['endpoint'], PHP_URL_PATH))), '/');
        return 'ie-' . rawurlencode(base64_encode($path));
    }

    private static function text(string $s, int $size, string $colour, int $x, int $y, int $from, int $to, array $extra = []): string
    {
        return implode(',', array_merge(['l-text', 'ie-' . rawurlencode(base64_encode($s)), 'fs-' . $size, 'co-' . $colour,
            'lx-' . $x, 'ly-' . $y, 'lso-' . $from, 'leo-' . $to], $extra, ['l-end']));
    }

    private static function block(string $bg, int $w, int $h, int $x, int $y, int $from, int $to): string
    {
        return "l-image,i-ik_canvas,bg-{$bg},w-{$w},h-{$h},lx-{$x},ly-{$y},lso-{$from},leo-{$to},l-end";
    }

    private static function image(string $src, string $size, int $x, int $y, int $from, int $to): string
    {
        return "l-image,{$src},{$size},lx-{$x},ly-{$y},lso-{$from},leo-{$to},l-end";
    }

    /** The ImageKit layer recipe for one product reel: same four scenes as the reel maker. */
    public static function reelLayers(array $p): ?string
    {
        $shot   = ImageKit::hosted((string) $p['featured_image']);
        $header = !empty($p['header_image']) ? ImageKit::hosted((string) $p['header_image']) : null;
        $logo   = ImageKit::hosted('assets/img/logo/curemeds-icon.png');
        if (!$shot || !$logo) {
            return null;
        }
        $name   = mb_substr((string) $p['name'], 0, 60);
        $wrap   = ['w-940', 'ia-center'];
        $points = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', strip_tags((string) ($p['benefits'] ?? '')))))), 0, 3);
        $L = [];
        // 1 · 0–4s: in-store photo (or pack shot) with the name on a green band.
        $L[] = self::image(self::layerSource($header ?: $shot), 'w-1080,h-1920', 0, 0, 0, 4);
        $L[] = self::block('0A1B3D', 1080, 560, 0, 1360, 0, 4);
        $L[] = self::text('CUREMEDS', 38, '1BB3C8', 70, 1430, 0, 4, $wrap);
        $L[] = self::text($name, 80, 'FFFFFF', 70, 1510, 0, 4, $wrap);
        // 2 · 4–8s: pack shot on a white card.
        $L[] = self::block('E8F0FC', 1080, 1920, 0, 0, 4, 8);
        $L[] = self::block('FFFFFF', 900, 1200, 90, 160, 4, 8);
        $L[] = self::image(self::layerSource($shot), 'w-820,h-1120,cm-pad_resize,bg-FFFFFF', 130, 200, 4, 8);
        $L[] = self::text($name, 68, '0A1B3D', 70, 1440, 4, 8, $wrap);
        if (!empty($p['pack_size'])) {
            $L[] = self::text((string) $p['pack_size'], 44, '3A4D72', 70, 1640, 4, 8, $wrap);
        }
        // 3 · 8–10s: price and benefits on the green base video.
        $L[] = self::text('Only', 64, 'FFFFFF', 70, 460, 8, 10, $wrap);
        $L[] = self::text(format_price_short(Pricing::retail($p)), 150, '0A1B3D', 290, 580, 8, 10, ['bg-1BB3C8', 'pa-30_60', 'r-40']);
        foreach ($points as $i => $pt) {
            $L[] = self::text('✓  ' . mb_substr($pt, 0, 60), 48, 'FFFFFF', 120, 1000 + $i * 150, 8, 10, ['w-860']);
        }
        // 4 · 10–12s: how to order.
        $L[] = self::block('FFFFFF', 1080, 1920, 0, 0, 10, self::LENGTH);
        $L[] = self::image(self::layerSource($logo), 'w-800', 140, 420, 10, self::LENGTH);
        $L[] = self::text('Order on WhatsApp', 80, '0A1B3D', 70, 900, 10, self::LENGTH, $wrap);
        $L[] = self::text(Health::displayPhone(whatsapp_number()), 70, 'FFFFFF', 250, 1060, 10, self::LENGTH, ['bg-1557D0', 'pa-24_50', 'r-60']);
        $L[] = self::text('Courier to your door, nationwide', 46, '3A4D72', 70, 1300, 10, self::LENGTH, $wrap);
        $L[] = self::text(SITE_DOMAIN, 46, '1557D0', 70, 1390, 10, self::LENGTH, $wrap);
        return implode(',', $L);
    }

    /** The finished reel URL for a product (layers on the base video, encoded to MP4). */
    public static function reelUrl(array $p): array
    {
        if (!ImageKit::ready()) {
            return ['ok' => false, 'url' => '', 'error' => 'ImageKit is not set up.'];
        }
        $base   = ImageKit::hosted(self::BASE_VIDEO);
        $layers = self::reelLayers($p);
        if (!$base || !$layers) {
            return ['ok' => false, 'url' => '', 'error' => 'Could not copy the photos to ImageKit.'];
        }
        return ImageKit::reelUrl($base, 150, $layers);
    }

    /** Builds and posts one reel to everything connected. */
    public static function reel(?array $product = null): string
    {
        $product = $product ?? self::reelProduct();
        if (!$product) {
            return 'no product to reel';
        }
        $toTikTok = TikTok::connected() && get_setting('tiktok_auto', '1') === '1';
        if (!SocialPoster::connected('facebook') && !SocialPoster::connected('instagram') && !$toTikTok) {
            return 'reel: connect Facebook, Instagram or TikTok first';
        }
        $video = self::reelUrl($product);
        if (!$video['ok']) {
            return 'reel: ' . $video['error'];
        }
        $caption = SocialPoster::caption($product, 'instagram', Groq::ready() ? Groq::socialCaption($product, 'instagram') : '');
        $done = [];
        foreach (array_keys(SocialPoster::PLATFORMS) as $platform) {
            if (SocialPoster::connected($platform)) {
                $r = SocialPoster::publishReel($platform, $product, $video['url'], $caption);
                $done[] = $platform . ($r['ok'] ? ' ✓' : ' ✗ ' . $r['error']);
            }
        }
        if ($toTikTok) {
            $r = TikTok::uploadDraft($video['url']);
            $done[] = 'tiktok draft' . ($r['ok'] ? ' ✓' : ' ✗ ' . $r['error']);
            if ($r['ok'] && !SocialPoster::connected('facebook') && !SocialPoster::connected('instagram')) {
                // Counted like a posted reel, so the same product is not picked again straight away.
                db_insert('social_posts', ['product_id' => (int) $product['id'], 'platform' => 'tiktok', 'caption' => $caption, 'status' => 'posted', 'media_type' => 'reel', 'media_url' => $video['url']]);
            }
        }
        return 'reel ' . $product['name'] . ': ' . ($done ? implode(', ', $done) : 'nothing connected');
    }
}
