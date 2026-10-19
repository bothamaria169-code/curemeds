<?php
declare(strict_types=1);

/**
 * Newsletter templates.
 *
 * Each template turns a small bag of fields (heading, intro, chosen products,
 * a coupon, a button) into email-safe HTML: tables and inline styles only,
 * absolute image URLs, no SVG, no web fonts. That is what Gmail, Outlook and
 * the phone mail apps reliably render — anything cleverer falls apart in one
 * of them.
 *
 * Products are looked up at render time, so a draft always previews with the
 * current price; NewsletterService::queue() freezes the result when it sends.
 */
final class NewsletterTemplates
{
    private const INK   = '#101A2B';
    private const MUTED = '#5B6878';
    private const LEAF  = '#1557D0';
    private const CORAL = '#D9534F';
    private const AMBER = '#F2A93B';

    /**
     * What the compose screen offers. `fields` decides which inputs it shows.
     *
     * @return array<string, array{label:string, icon:string, blurb:string, fields:string[], min:int, max:int, defaults:array}>
     */
    public static function catalog(): array
    {
        return [
            'spotlight' => [
                'label'  => 'Product spotlight',
                'icon'   => 'fa-star',
                'blurb'  => 'One product, front and centre. For a launch, a restock or a hero item.',
                'fields' => ['eyebrow', 'heading', 'intro', 'products', 'cta'],
                'min'    => 1, 'max' => 1,
                'defaults' => ['eyebrow' => 'Back in stock', 'heading' => 'The one everyone has been asking for', 'cta_label' => 'Shop now'],
            ],
            'grid' => [
                'label'  => 'Product grid',
                'icon'   => 'fa-table-cells-large',
                'blurb'  => 'Two to six products side by side. New arrivals, best sellers, a themed edit.',
                'fields' => ['eyebrow', 'heading', 'intro', 'products', 'cta'],
                'min'    => 2, 'max' => 6,
                'defaults' => ['eyebrow' => 'New this week', 'heading' => 'Fresh on the shelves', 'cta_label' => 'See everything new'],
            ],
            'sale' => [
                'label'  => 'Sale + coupon',
                'icon'   => 'fa-tags',
                'blurb'  => 'A discount code in a big, copyable box, with the products it works best on.',
                'fields' => ['eyebrow', 'heading', 'intro', 'coupon', 'sale_note', 'products', 'cta'],
                'min'    => 0, 'max' => 6,
                'defaults' => ['eyebrow' => 'Flash sale', 'heading' => 'Save on your wellness favourites', 'cta_label' => 'Shop the sale', 'sale_note' => 'While stock lasts.'],
            ],
            'announcement' => [
                'label'  => 'Announcement',
                'icon'   => 'fa-bullhorn',
                'blurb'  => 'Words and a button. Delivery changes, holiday hours, a new brand arriving.',
                'fields' => ['eyebrow', 'heading', 'intro', 'cta'],
                'min'    => 0, 'max' => 0,
                'defaults' => ['eyebrow' => 'News', 'heading' => 'A quick update from CureMeds', 'cta_label' => 'Visit the shop'],
            ],
            'custom' => [
                'label'  => 'Custom HTML',
                'icon'   => 'fa-code',
                'blurb'  => 'Write the body yourself. Still wrapped in the CureMeds shell with an unsubscribe link.',
                'fields' => ['body_html'],
                'min'    => 0, 'max' => 0,
                'defaults' => [],
            ],
        ];
    }

    public static function render(string $template, array $data): string
    {
        return match ($template) {
            'spotlight'    => self::spotlight($data),
            'grid'         => self::grid($data),
            'sale'         => self::sale($data),
            'announcement' => self::announcement($data),
            'welcome'      => self::welcome($data),
            default        => '',
        };
    }

    // ── Templates ────────────────────────────────────────────────────────

    private static function spotlight(array $d): string
    {
        $p = self::products($d['product_ids'] ?? [])[0] ?? null;

        $html = self::header($d);
        if ($p) {
            $url = self::productUrl($p);
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 18px;">'
                . '<tr><td align="center" style="background:#F6F7F9;border-radius:14px;padding:10px;">'
                . '<a href="' . e($url) . '"><img src="' . e(email_image_url($p['featured_image'])) . '" width="340" alt="' . e($p['name']) . '" '
                . 'style="display:block;width:100%;max-width:340px;height:auto;border:0;border-radius:10px;"></a></td></tr>'
                . '<tr><td align="center" style="padding-top:16px;">'
                . '<div style="font-size:20px;font-weight:800;color:' . self::INK . ';">' . e($p['name']) . '</div>'
                . ($p['pack_size'] ? '<div style="font-size:13px;color:' . self::MUTED . ';margin-top:2px;">' . e($p['pack_size']) . '</div>' : '')
                . '<div style="margin-top:10px;">' . self::price($p, 22) . '</div>'
                . ($p['short_desc'] ? '<p style="margin:12px auto 0;max-width:440px;color:' . self::MUTED . ';font-size:14px;">' . e($p['short_desc']) . '</p>' : '')
                . '</td></tr></table>';
            $html .= self::button($d['cta_label'] ?? 'Shop now', $d['cta_url'] ?? '' ?: $url);
        } else {
            $html .= self::button($d['cta_label'] ?? 'Shop now', $d['cta_url'] ?? '');
        }
        return $html;
    }

    private static function grid(array $d): string
    {
        return self::header($d)
            . self::productGrid(self::products($d['product_ids'] ?? []))
            . self::button($d['cta_label'] ?? 'Shop now', $d['cta_url'] ?? '');
    }

    private static function sale(array $d): string
    {
        $coupon = null;
        if (!empty($d['coupon_code'])) {
            $stmt = db()->prepare('SELECT * FROM coupons WHERE code = :c LIMIT 1');
            $stmt->execute([':c' => $d['coupon_code']]);
            $coupon = $stmt->fetch() ?: null;
        }

        return self::header($d)
            . ($coupon ? self::couponBox($coupon, (string) ($d['sale_note'] ?? '')) : '')
            . self::productGrid(self::products($d['product_ids'] ?? []))
            . self::button($d['cta_label'] ?? 'Shop the sale', $d['cta_url'] ?? '', self::CORAL);
    }

    private static function announcement(array $d): string
    {
        return self::header($d) . self::button($d['cta_label'] ?? 'Visit the shop', $d['cta_url'] ?? '');
    }

    /** Sent automatically to every new subscriber. */
    private static function welcome(array $d): string
    {
        $coupon = $d['coupon'] ?? null;

        $html = self::header([
            'eyebrow' => 'Welcome',
            'heading' => 'You are on the list',
            'intro'   => "Thanks for joining CureMeds. You will hear from us when something new lands, when a favourite comes back into stock, and when there is a deal worth opening an email for.\nThat is it. No daily spam.",
        ]);

        if ($coupon) {
            $html .= '<p style="margin:0 0 6px;font-weight:700;color:' . self::INK . ';">As promised, your welcome code:</p>'
                . self::couponBox($coupon, 'Enter it at checkout.');
        }

        $best = db()->query(
            "SELECT * FROM products WHERE status = 'published' AND stock_status <> 'out_of_stock'
             ORDER BY is_bestseller DESC, sold_count DESC LIMIT 4"
        )->fetchAll();

        if ($best) {
            $html .= '<p style="margin:22px 0 10px;font-weight:800;font-size:16px;color:' . self::INK . ';">What people are buying</p>'
                . self::productGrid($best);
        }

        return $html . self::button('Start shopping', SITE_URL . '/shop.php');
    }

    // ── Building blocks ──────────────────────────────────────────────────

    private static function header(array $d): string
    {
        $html = '';
        if (!empty($d['eyebrow'])) {
            $html .= '<div style="font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:' . self::LEAF . ';margin-bottom:6px;">'
                . e((string) $d['eyebrow']) . '</div>';
        }
        if (!empty($d['heading'])) {
            $html .= '<h1 style="margin:0 0 12px;font-size:26px;line-height:1.2;font-weight:800;letter-spacing:-.01em;color:' . self::INK . ';">'
                . e((string) $d['heading']) . '</h1>';
        }
        return $html . self::paragraphs((string) ($d['intro'] ?? ''));
    }

    /** Plain text to paragraphs: a blank line or a newline starts a new one. */
    private static function paragraphs(string $text): string
    {
        $out = '';
        foreach (preg_split('/\R+/', trim($text)) ?: [] as $para) {
            if (trim($para) !== '') {
                $out .= '<p style="margin:0 0 14px;color:#2E3A4B;">' . e(trim($para)) . '</p>';
            }
        }
        return $out;
    }

    private static function productGrid(array $products): string
    {
        if (!$products) {
            return '';
        }
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 6px;">';
        foreach (array_chunk($products, 2) as $pair) {
            $html .= '<tr>';
            foreach ($pair as $i => $p) {
                $url = self::productUrl($p);
                $html .= '<td width="50%" valign="top" style="padding:0 ' . ($i === 0 ? '8px' : '0') . ' 16px ' . ($i === 1 ? '8px' : '0') . ';">'
                    . '<a href="' . e($url) . '" style="text-decoration:none;color:' . self::INK . ';">'
                    . '<img src="' . e(email_image_url($p['featured_image'])) . '" width="260" alt="' . e($p['name']) . '" '
                    . 'style="display:block;width:100%;max-width:260px;height:auto;border:0;border-radius:10px;background:#F6F7F9;">'
                    . '<div style="font-size:14px;font-weight:700;margin-top:10px;line-height:1.35;">' . e($p['name']) . '</div>'
                    . '</a>'
                    . ($p['pack_size'] ? '<div style="font-size:12px;color:' . self::MUTED . ';">' . e($p['pack_size']) . '</div>' : '')
                    . '<div style="margin-top:4px;">' . self::price($p, 15) . '</div>'
                    . '</td>';
            }
            if (count($pair) === 1) {
                $html .= '<td width="50%" style="padding-left:8px;"></td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    private static function price(array $p, int $size): string
    {
        $retail = (float) $p['retail_price'];
        $sale   = (float) ($p['sale_price'] ?? 0);

        if ($sale > 0 && $sale < $retail) {
            return '<span style="font-size:' . $size . 'px;font-weight:800;color:' . self::CORAL . ';">' . format_price($sale) . '</span> '
                . '<span style="font-size:' . max(12, $size - 6) . 'px;color:#9AA5B1;text-decoration:line-through;">' . format_price($retail) . '</span>';
        }
        return '<span style="font-size:' . $size . 'px;font-weight:800;color:' . self::INK . ';">' . format_price($retail) . '</span>';
    }

    private static function couponBox(array $coupon, string $note): string
    {
        $expiry = !empty($coupon['expiry_date'])
            ? 'Valid until ' . date('j F Y', strtotime((string) $coupon['expiry_date'])) . '. '
            : '';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 20px;">'
            . '<tr><td align="center" style="border:2px dashed ' . self::AMBER . ';background:#FFF8EC;border-radius:14px;padding:20px 16px;">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8A5A00;">'
            . e(NewsletterService::describeCoupon($coupon)) . '</div>'
            . '<div style="font-family:Consolas,Menlo,monospace;font-size:30px;font-weight:800;letter-spacing:.14em;color:' . self::INK . ';margin:6px 0;">'
            . e((string) $coupon['code']) . '</div>'
            . '<div style="font-size:12px;color:' . self::MUTED . ';">' . e(trim($expiry . $note)) . '</div>'
            . '</td></tr></table>';
    }

    /** A "bulletproof" button: a table cell with a background, so it survives Outlook. */
    private static function button(string $label, string $url, string $colour = self::LEAF): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        $href = self::absoluteUrl($url ?: '/shop.php');

        return '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:22px auto 4px;">'
            . '<tr><td align="center" bgcolor="' . $colour . '" style="border-radius:10px;">'
            . '<a href="' . e($href) . '" style="display:inline-block;padding:14px 30px;font-size:15px;font-weight:800;color:#FFFFFF;text-decoration:none;border-radius:10px;">'
            . e($label) . '</a></td></tr></table>';
    }

    // ── Data ─────────────────────────────────────────────────────────────

    /** Published products by id, in the order they were picked. */
    private static function products(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT * FROM products WHERE status = 'published' AND id IN ($in)");
        $stmt->execute($ids);

        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['id']] = $row;
        }
        return array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));
    }

    private static function productUrl(array $p): string
    {
        return SITE_URL . '/modules/shop/product.php?slug=' . urlencode((string) $p['slug']);
    }

    /** Lets admins type "/shop.php?brand=blossom" as well as a full https:// link. */
    private static function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return SITE_URL . '/' . ltrim($url, '/');
    }
}
