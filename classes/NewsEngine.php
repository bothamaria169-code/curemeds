<?php
declare(strict_types=1);

/**
 * Health news briefs: picks a fresh headline from the RSS feeds in
 * Admin → AI & social media → Automatic content, has Groq explain it in
 * plain English (crediting and linking the original report), adds an
 * Unsplash photo and links to products from the matching category, then
 * publishes and shares it like any other article (ContentEngine).
 *
 * Feeds are one per line: "Label | category-slug | https://feed-url".
 * The category slug decides which products the brief links to.
 */
final class NewsEngine
{
    /** Google News searches work for any topic and refresh constantly; ScienceDaily adds research news. */
    public const DEFAULT_FEEDS = "HIV research | immune-support | https://news.google.com/rss/search?q=HIV+cure+research&hl=en-ZA&gl=ZA&ceid=ZA:en\n"
        . "HIV research | immune-support | https://www.sciencedaily.com/rss/health_medicine/hiv_and_aids.xml\n"
        . "TB research | lungs-breathing | https://news.google.com/rss/search?q=tuberculosis+research+South+Africa&hl=en-ZA&gl=ZA&ceid=ZA:en\n"
        . "Diabetes | blood-sugar-support | https://news.google.com/rss/search?q=diabetes+research&hl=en-ZA&gl=ZA&ceid=ZA:en\n"
        . "Cancer research | supportive-care | https://news.google.com/rss/search?q=cancer+research+breakthrough&hl=en-ZA&gl=ZA&ceid=ZA:en\n"
        . "Heart health | heart-circulation | https://news.google.com/rss/search?q=blood+pressure+heart+health+study&hl=en-ZA&gl=ZA&ceid=ZA:en\n"
        . "Brain health | brain-energy | https://news.google.com/rss/search?q=dementia+memory+research&hl=en-ZA&gl=ZA&ceid=ZA:en\n"
        . "Weight & metabolism | weight-management | https://news.google.com/rss/search?q=obesity+metabolism+study&hl=en-ZA&gl=ZA&ceid=ZA:en";

    /** @return list<array{label:string,category:string,url:string}> */
    public static function feeds(): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string) get_setting('news_feeds', self::DEFAULT_FEEDS)) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) === 3 && filter_var($parts[2], FILTER_VALIDATE_URL)) {
                $out[] = ['label' => $parts[0], 'category' => $parts[1], 'url' => $parts[2]];
            }
        }
        return $out;
    }

    /** Headlines from one feed, newest first: title, link, summary, source. */
    private static function items(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20,
                                CURLOPT_USERAGENT => 'CureMeds news reader (+' . SITE_URL . ')']);
        $body = (string) curl_exec($ch);
        curl_close($ch);
        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml || !isset($xml->channel->item)) {
            return [];
        }
        $items = [];
        foreach ($xml->channel->item as $it) {
            $title = trim(html_entity_decode(strip_tags((string) $it->title), ENT_QUOTES, 'UTF-8'));
            // Google News puts the outlet after " - " in the title and in <source>.
            $source = trim((string) ($it->source ?? ''));
            if ($source !== '' && str_ends_with($title, ' - ' . $source)) {
                $title = trim(substr($title, 0, -strlen(' - ' . $source)));
            }
            $items[] = [
                'title'   => $title,
                'link'    => trim((string) $it->link),
                'summary' => mb_substr(trim(html_entity_decode(strip_tags((string) $it->description), ENT_QUOTES, 'UTF-8')), 0, 800),
                'source'  => $source ?: (string) parse_url((string) $it->link, PHP_URL_HOST),
                'date'    => strtotime((string) $it->pubDate) ?: time(),
            ];
        }
        return $items;
    }

    /** The freshest headline (last 7 days) not written about yet, rotating through the feeds. */
    private static function pick(): ?array
    {
        $feeds = self::feeds();
        if (!$feeds) {
            return null;
        }
        // Start with the feed after the one used last, so every topic gets a turn.
        $start = ((int) get_setting('news_feed_cursor', '0') + 1) % count($feeds);
        $seen  = db()->prepare('SELECT COUNT(*) FROM blogs WHERE source_url = :u OR title = :t');
        for ($i = 0; $i < count($feeds); $i++) {
            $n    = ($start + $i) % count($feeds);
            $feed = $feeds[$n];
            foreach (self::items($feed['url']) as $item) {
                if ($item['title'] === '' || $item['link'] === '' || $item['date'] < time() - 7 * 86400) {
                    continue;
                }
                $seen->execute([':u' => $item['link'], ':t' => $item['title']]);
                if ((int) $seen->fetchColumn() === 0) {
                    set_setting('news_feed_cursor', (string) $n);
                    return $item + ['feed' => $feed];
                }
            }
        }
        return null;
    }

    /**
     * A landscape photo for the brief from Unsplash, with the credit their
     * API terms require. Null without an access key or a match.
     *
     * @return array{url:string,credit:string}|null
     */
    public static function photo(string $query): ?array
    {
        $key = trim((string) get_setting('unsplash_access_key', ''));
        if ($key === '') {
            return null;
        }
        $ch = curl_init('https://api.unsplash.com/search/photos?' . http_build_query(['query' => $query, 'orientation' => 'landscape', 'per_page' => 10, 'content_filter' => 'high']));
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Client-ID ' . $key, 'Accept-Version: v1'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $res = json_decode((string) curl_exec($ch), true) ?: [];
        curl_close($ch);
        $results = $res['results'] ?? [];
        if (!$results) {
            return null;
        }
        $p = $results[array_rand($results)];
        // Unsplash asks apps to register each use of a photo.
        if (!empty($p['links']['download_location'])) {
            $dl = curl_init($p['links']['download_location']);
            curl_setopt_array($dl, [CURLOPT_HTTPHEADER => ['Authorization: Client-ID ' . $key], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($dl);
            curl_close($dl);
        }
        $utm = '?utm_source=curemeds&utm_medium=referral';
        return [
            'url'    => (string) $p['urls']['regular'],
            'credit' => 'Photo by <a href="' . e((string) $p['user']['links']['html']) . $utm . '" target="_blank" rel="noopener">' . e((string) $p['user']['name'])
                      . '</a> on <a href="https://unsplash.com/' . $utm . '" target="_blank" rel="noopener">Unsplash</a>',
        ];
    }

    /** Products from the feed's category for the "from our shop" links. */
    private static function products(string $categorySlug): array
    {
        $ids = db()->prepare("SELECT p.id FROM products p JOIN categories c ON c.id = p.category_id
                              WHERE c.slug = :s AND p.status = 'published' AND p.featured_image IS NOT NULL
                              ORDER BY p.is_featured DESC, RAND() LIMIT 3");
        $ids->execute([':s' => $categorySlug]);
        $repo = new ProductRepository();
        return array_values(array_filter(array_map(fn($id) => $repo->getById((int) $id), $ids->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** Writes, publishes and shares one news brief. Returns a short status line. */
    public static function article(): string
    {
        if (!Groq::ready()) {
            return 'news: set up Groq first';
        }
        $item = self::pick();
        if (!$item) {
            return 'news: no new headlines in the feeds';
        }
        $label = $item['feed']['label'];

        $err   = '';
        $reply = Groq::chat([
            ['role' => 'system', 'content' => 'You write short health news briefs for CureMeds, a South African online health store, in warm plain English. '
                . 'You are summarising someone else\'s reporting: use ONLY the headline and summary given plus well-established background facts. '
                . 'Never invent numbers, names, quotes or results. Say clearly when research is early (lab, animal or small trials) and that it is not yet a treatment. '
                . 'For HIV: antiretroviral treatment (ART) works and must never be stopped; there is no approved cure yet. '
                . 'Do not mention or recommend any product or supplement. Write in your own words, never copy sentences. Reply with JSON only.'],
            ['role' => 'user', 'content' => "Topic: {$label}\nHeadline: {$item['title']}\nSource: {$item['source']}\nSummary: {$item['summary']}\n\n"
                . 'Write a 300-450 word brief: what was reported, why it matters for South Africans, and what it does not mean yet. '
                . 'End by telling readers to read the full report at the source and talk to their clinic about their own treatment. '
                . 'Return JSON: {"title": "a clear headline in your own words", "excerpt": "one sentence", "html": "<h2>, <p>, <ul><li> only", "caption": "a 2-3 sentence social media teaser with 2 emojis, no health claims"}'],
        ], $err, 1400, 0.5);
        $json = json_decode((string) preg_replace('/^```(?:json)?|```$/m', '', trim($reply)), true);
        if (!is_array($json) || empty($json['title']) || empty($json['html'])) {
            return 'news: Groq ' . ($err ?: 'reply was not usable');
        }

        $title = mb_substr(clean_text((string) $json['title']), 0, 240);
        $slug  = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: 'news';
        $taken = db()->prepare('SELECT COUNT(*) FROM blogs WHERE slug = :s');
        $taken->execute([':s' => $slug]);
        if ($taken->fetchColumn()) {
            $slug .= '-' . date('ymdHi');
        }
        $products = self::products($item['feed']['category']);
        $photo    = self::photo($label . ' health');
        $html     = strip_tags((string) $json['html'], '<h2><h3><p><ul><ol><li><strong><em><b><i>')
                  . '<p class="text-muted-3" style="font-size:.88rem">Source: <a href="' . e($item['link']) . '" target="_blank" rel="noopener nofollow">' . e($item['source'] ?: 'original report') . '</a>. '
                  . 'This brief summarises their reporting; it is general information, not medical advice.</p>';
        $admin = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        $id = db_insert('blogs', [
            'title' => $title, 'slug' => $slug,
            'image' => $photo['url'] ?? ($products[0]['header_image'] ?? $products[0]['featured_image'] ?? null),
            'image_credit' => $photo['credit'] ?? null,
            'excerpt' => mb_substr(clean_text((string) ($json['excerpt'] ?? '')), 0, 300),
            'content' => $html, 'category' => mb_substr('News: ' . $label, 0, 60), 'author_id' => $admin,
            'read_mins' => max(2, (int) ceil(str_word_count(strip_tags($html)) / 200)),
            'status' => 'published', 'auto_generated' => 1,
            'category_hint' => (int) ($products[0]['category_id'] ?? 0) ?: null,
            'product_ids' => implode(',', array_column($products, 'id')) ?: null,
            'source_url' => mb_substr($item['link'], 0, 600), 'source_name' => mb_substr($item['source'], 0, 120),
        ]);

        $link   = SITE_URL . '/modules/blog/post.php?slug=' . urlencode($slug);
        $teaser = trim((string) ($json['caption'] ?? '')) ?: $title;
        $shared = [];
        foreach (array_keys(SocialPoster::PLATFORMS) as $platform) {
            if (!SocialPoster::connected($platform)) { continue; }
            // Instagram needs a photo of ours; without a product there is nothing to post there.
            if ($platform === 'instagram' && !$products) { continue; }
            $caption = '📰 ' . $teaser . "\n\n" . ($platform === 'facebook' ? '📖 Read the brief: ' . $link : '📖 Full brief: link in bio · ' . SITE_DOMAIN)
                . "\n💬 WhatsApp: " . Health::displayPhone(whatsapp_number()) . "\n\n#CureMeds #HealthNews #SouthAfrica";
            $r = $platform === 'facebook'
                ? SocialPoster::publishLink($products[0] ?? ['id' => 0, 'name' => $title], $link, $caption)
                : SocialPoster::publish('instagram', $products[0], $caption, 'article');
            $shared[] = $platform . ($r['ok'] ? ' ✓' : ' ✗ ' . $r['error']);
        }
        return "news #{$id} \"{$title}\"" . ($shared ? ' — ' . implode(', ', $shared) : '');
    }
}
