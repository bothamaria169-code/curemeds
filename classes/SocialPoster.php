<?php
declare(strict_types=1);

/**
 * Posts products to the CureMeds Facebook Page and its linked Instagram
 * Business account through Meta's Graph API.
 *
 * Connecting: Admin → AI & social media → save the Meta app ID and secret →
 * "Connect with Facebook". admin/social-connect.php swaps the login for a
 * long-lived user token and then a Page token that does not expire, which is
 * what the daily auto-post needs. (Adapted from the BidCo Auctions poster.)
 *
 * Instagram fetches the photo from our server, so the site must be live on a
 * public https address before Instagram posts can work; Facebook is the same.
 */
final class SocialPoster
{
    public const GRAPH_VERSION = 'v21.0';
    public const GRAPH = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/';

    /** What the app asks for: list the Pages, post to them, publish to the linked Instagram. */
    public const SCOPES = ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement',
        'instagram_basic', 'instagram_content_publish', 'business_management'];

    public const PLATFORMS = ['facebook' => 'Facebook Page', 'instagram' => 'Instagram'];

    /** @return array{app_id: string, app_secret: string, config_id: string, page_id: string, page_name: string, token: string, instagram_id: string, instagram_name: string} */
    public static function config(): array
    {
        return [
            'app_id'         => preg_replace('/\D/', '', (string) get_setting('meta_app_id', '')) ?? '',
            'app_secret'     => trim((string) get_setting('meta_app_secret', '')),
            'config_id'      => preg_replace('/\D/', '', (string) get_setting('meta_config_id', '')) ?? '',
            'page_id'        => preg_replace('/\D/', '', (string) get_setting('meta_page_id', '')) ?? '',
            'page_name'      => (string) get_setting('meta_page_name', ''),
            'token'          => trim((string) get_setting('meta_page_token', '')),
            'instagram_id'   => preg_replace('/\D/', '', (string) get_setting('meta_instagram_id', '')) ?? '',
            'instagram_name' => (string) get_setting('meta_instagram_name', ''),
        ];
    }

    public static function connected(string $platform): bool
    {
        $c = self::config();
        return match ($platform) {
            'facebook'  => $c['page_id'] !== '' && $c['token'] !== '',
            'instagram' => $c['instagram_id'] !== '' && $c['token'] !== '',
            default     => false,
        };
    }

    /** Must be listed under Facebook Login → Settings → Valid OAuth Redirect URIs, exactly. */
    public static function redirectUri(): string
    {
        return SITE_URL . '/dashboards/admin/social-connect.php';
    }

    public static function loginUrl(string $state): string
    {
        $c = self::config();
        return 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth?' . http_build_query([
            'client_id'     => $c['app_id'],
            'redirect_uri'  => self::redirectUri(),
            'state'         => $state,
            'response_type' => 'code',
        ] + ($c['config_id'] !== '' ? ['config_id' => $c['config_id']] : ['scope' => implode(',', self::SCOPES)]));
    }

    public static function graphGet(string $path, array $query): array
    {
        $ch = curl_init(self::GRAPH . $path . '?' . http_build_query($query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10]);
        $body = (string) curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error !== '') {
            return ['error' => ['message' => $error]];
        }
        return json_decode($body, true) ?: ['error' => ['message' => 'Unexpected reply from Facebook']];
    }

    public static function graphError(array $reply): string
    {
        return (string) ($reply['error']['message'] ?? 'no reason given');
    }

    /** @return array{ok: bool, id: string, error: string} */
    private static function post(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $body  = (string) curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error !== '') {
            return ['ok' => false, 'id' => '', 'error' => $error];
        }
        $json = json_decode($body, true) ?: [];
        if (isset($json['error'])) {
            return ['ok' => false, 'id' => '', 'error' => (string) ($json['error']['message'] ?? 'Facebook said no')];
        }
        $id = (string) ($json['post_id'] ?? $json['id'] ?? '');
        return ['ok' => $id !== '', 'id' => $id, 'error' => $id === '' ? 'No post id came back: ' . mb_substr($body, 0, 150) : ''];
    }

    /**
     * Swaps the code Facebook sends back for the Pages this person manages.
     *
     * @return array{pages: array<string, array>, error: string}
     */
    public static function exchangeCode(string $code): array
    {
        $c = self::config();
        $short = self::graphGet('oauth/access_token', [
            'client_id' => $c['app_id'], 'client_secret' => $c['app_secret'],
            'redirect_uri' => self::redirectUri(), 'code' => $code,
        ]);
        $long = empty($short['access_token']) ? $short : self::graphGet('oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => $c['app_id'],
            'client_secret' => $c['app_secret'], 'fb_exchange_token' => $short['access_token'],
        ]);
        if (empty($long['access_token'])) {
            return ['pages' => [], 'error' => 'Facebook did not give us access: ' . self::graphError($long)];
        }
        // A Page token fetched with a long-lived user token never expires.
        $accounts = self::graphGet('me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'limit' => 50, 'access_token' => $long['access_token'],
        ]);
        $pages = [];
        foreach ($accounts['data'] ?? [] as $row) {
            if (!empty($row['id']) && !empty($row['access_token'])) {
                $pages[(string) $row['id']] = [
                    'id'        => (string) $row['id'],
                    'name'      => (string) ($row['name'] ?? $row['id']),
                    'token'     => (string) $row['access_token'],
                    'instagram' => (string) ($row['instagram_business_account']['id'] ?? ''),
                    'ig_name'   => (string) ($row['instagram_business_account']['username'] ?? ''),
                ];
            }
        }
        return ['pages' => $pages, 'error' => $pages ? '' : (isset($accounts['error'])
            ? 'Facebook said: ' . self::graphError($accounts)
            : 'No Facebook Pages came back. When Facebook asks which Pages the app may use, tick the CureMeds Page and its Instagram account.')];
    }

    public static function savePage(array $page): void
    {
        set_setting('meta_page_id', $page['id']);
        set_setting('meta_page_token', $page['token']);
        set_setting('meta_page_name', mb_substr($page['name'], 0, 120));
        set_setting('meta_instagram_id', $page['instagram']);
        set_setting('meta_instagram_name', mb_substr($page['ig_name'], 0, 120));
    }

    public static function disconnect(): void
    {
        foreach (['meta_page_id', 'meta_page_token', 'meta_page_name', 'meta_instagram_id', 'meta_instagram_name'] as $k) {
            set_setting($k, '');
        }
    }

    /** A JPEG on a public address — Instagram accepts nothing else. */
    public static function photoUrl(array $product): string
    {
        return product_image($product['featured_image'] ?? null);
    }

    /** The finished caption: AI (or template) text plus price, WhatsApp and hashtags. */
    public static function caption(array $p, string $platform, string $body = ''): string
    {
        if (trim($body) === '') {
            $body = '💙 ' . $p['name'] . ($p['pack_size'] ? ' (' . $p['pack_size'] . ')' : '') . "\n\n" . strip_tags((string) $p['short_desc']);
        }
        $price = Pricing::retail($p);
        $wa    = Health::displayPhone(whatsapp_number());
        $link  = SITE_URL . '/modules/shop/product.php?slug=' . urlencode((string) $p['slug']) . '&utm_source=' . $platform . '&utm_medium=social';
        $cta   = $platform === 'instagram' ? '🔗 Link in bio · ' . SITE_DOMAIN : '🔗 ' . $link;
        $tags  = '#CureMeds #HealthSA #Wellness #Supplements #SouthAfrica' . (!empty($p['category_name']) ? ' #' . preg_replace('/[^A-Za-z]/', '', ucwords((string) $p['category_name'])) : '');
        return trim($body) . "\n\n💰 " . format_price_short($price) . "\n💬 Order on WhatsApp: " . $wa . "\n" . $cta
            . "\n\n" . $tags . "\n\nSupplements are not a substitute for prescribed medication.";
    }

    /** @return array{ok: bool, id: string, error: string} */
    public static function publish(string $platform, array $product, string $caption): array
    {
        $c = self::config();
        $photo = self::photoUrl($product);
        if (!self::connected($platform)) {
            $result = ['ok' => false, 'id' => '', 'error' => self::PLATFORMS[$platform] . ' is not connected.'];
        } elseif (!str_starts_with($photo, 'https://')) {
            $result = ['ok' => false, 'id' => '', 'error' => 'The photo must be on a public https address. Post from the live site, not localhost.'];
        } elseif ($platform === 'facebook') {
            $result = self::post(self::GRAPH . $c['page_id'] . '/photos', ['url' => $photo, 'caption' => $caption, 'access_token' => $c['token']]);
        } else {
            $result = self::publishInstagram($c, $photo, mb_substr($caption, 0, 2100));
        }
        self::log($platform, (int) $product['id'], $result, $caption);
        return $result;
    }

    private static function publishInstagram(array $c, string $photo, string $caption): array
    {
        $account = self::GRAPH . $c['instagram_id'];
        $container = self::post($account . '/media', ['image_url' => $photo, 'caption' => $caption, 'access_token' => $c['token']]);
        if (!$container['ok']) {
            return $container;
        }
        // Instagram fetches the photo in the background: wait until it is ready.
        for ($try = 0; $try < 15; $try++) {
            sleep(2);
            $status = (string) (self::graphGet($container['id'], ['fields' => 'status_code', 'access_token' => $c['token']])['status_code'] ?? '');
            if ($status === 'FINISHED') {
                break;
            }
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                return ['ok' => false, 'id' => '', 'error' => 'Instagram could not use the photo (' . $status . ')'];
            }
        }
        return self::post($account . '/media_publish', ['creation_id' => $container['id'], 'access_token' => $c['token']]);
    }

    private static function log(string $platform, int $productId, array $result, string $caption): void
    {
        db_insert('social_posts', [
            'platform'   => $platform,
            'product_id' => $productId,
            'status'     => $result['ok'] ? 'posted' : 'failed',
            'remote_id'  => $result['id'] !== '' ? mb_substr($result['id'], 0, 120) : null,
            'error'      => $result['error'] !== '' ? mb_substr($result['error'], 0, 255) : null,
            'caption'    => $caption,
        ]);
    }

    /**
     * The product for today's automatic post: published, in stock, with a photo,
     * and the one posted longest ago (never-posted first).
     */
    public static function pickProduct(): ?array
    {
        $row = db()->query("SELECT p.id FROM products p
                            LEFT JOIN (SELECT product_id, MAX(created_at) AS last FROM social_posts WHERE status = 'posted' GROUP BY product_id) s
                              ON s.product_id = p.id
                            WHERE p.status = 'published' AND p.stock_status <> 'out_of_stock' AND p.featured_image IS NOT NULL
                            ORDER BY s.last IS NOT NULL, s.last, p.is_featured DESC, RAND() LIMIT 1")->fetchColumn();
        return $row ? (new ProductRepository())->getById((int) $row) : null;
    }

    /** Daily auto-post, called by scripts/cron.php. Posts once a day at or after the chosen hour. */
    public static function runDaily(): array
    {
        if (get_setting('social_autopost', '0') !== '1') {
            return ['skipped' => 'auto-post is off'];
        }
        $hour = (int) get_setting('social_autopost_hour', '9');
        if ((int) date('G') < $hour || get_setting('social_autopost_last', '') === date('Y-m-d')) {
            return ['skipped' => 'not due'];
        }
        set_setting('social_autopost_last', date('Y-m-d'));
        $product = self::pickProduct();
        if (!$product) {
            return ['skipped' => 'no product to post'];
        }
        $out = [];
        foreach (array_keys(self::PLATFORMS) as $platform) {
            if (!self::connected($platform)) {
                continue;
            }
            $err  = '';
            $body = Groq::ready() ? Groq::socialCaption($product, $platform, $err) : '';
            $out[$platform] = self::publish($platform, $product, self::caption($product, $platform, $body));
        }
        return $out;
    }
}
