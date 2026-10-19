<?php
declare(strict_types=1);

/**
 * The on-site shop assistant.
 *
 * Deliberately rule-based and answering from the live database rather than a
 * language model: it can quote a real price, a real stock level and a real
 * order status, it costs nothing per message, it works with no API key, and it
 * cannot invent a product that does not exist. Every answer it gives is a row
 * someone can go and check.
 *
 * It never gives medical advice: health questions go to WhatsApp, and anything
 * that sounds like an emergency is answered with 10177 before anything else.
 */
final class ShopAssistant
{
    /**
     * @return array{reply:string,chips:string[]}
     */
    public static function answer(string $message, ?array $user = null): array
    {
        $q = mb_strtolower(trim($message));

        if ($q === '') {
            return self::greeting($user);
        }

        foreach (self::intents() as $intent => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($q, $pattern)) {
                    return self::{$intent}($q, $user);
                }
            }
        }

        // No intent matched — try the catalogue, then the FAQ, then give up honestly.
        $found = self::searchCatalogue($q);
        if ($found !== null) {
            return $found;
        }
        $faq = self::searchFaq($q);
        if ($faq !== null) {
            return $faq;
        }

        return [
            'reply' => "I couldn't find that one. I can help with products, prices, delivery and the patient portal — "
                     . "or " . self::wa('ask a person on WhatsApp') . ".",
            'chips' => ['What do you sell?', 'How do I order?', 'Patient portal', 'Delivery options'],
        ];
    }

    /** intent method => trigger substrings, checked in order. */
    private static function intents(): array
    {
        return [
            // Checked first: anything that sounds like an emergency gets a clinic, not a product.
            'emergency'  => ['emergency', 'can\'t breathe', 'cant breathe', 'difficulty breathing', 'chest pain', 'swelling', 'swollen face', 'fainted', 'overdose', 'suicid', 'unconscious', 'severe'],
            'greeting'   => ['hello', 'hi ', 'hey', 'howzit', 'sawubona', 'good morning', 'good afternoon', 'molo', 'dumela'],
            'catalogue'  => ['what do you sell', 'what products', 'catalogue', 'catalog', 'show me', 'browse'],
            'priceOf'    => ['how much', 'price of', 'cost of', 'what does it cost', 'price list', 'pricelist'],
            'howToOrder' => ['order', 'buy', 'purchase', 'how do i get'],
            'delivery'   => ['deliver', 'shipping', 'courier', 'collect', 'how long'],
            'payment'    => ['pay', 'payment', 'eft', 'card', 'bank', 'proof of payment'],
            'portal'     => ['portal', 'tracker', 'reminder', 'pin', 'log in', 'login', 'sign in', 'dose', 'remind'],
            'returns'    => ['return', 'refund', 'exchange', 'broken', 'damaged'],
            'safety'     => ['safe', 'side effect', 'pregnan', 'breastfeed', 'sahpra', 'registered', 'medication', 'doctor', 'arv', 'interact', 'cure'],
            'contact'    => ['contact', 'phone', 'whatsapp', 'email', 'where are you', 'speak to', 'human', 'person'],
            'stock'      => ['in stock', 'available', 'sold out', 'out of stock'],
        ];
    }

    // ── Intent handlers ──────────────────────────────────────────────────

    private static function greeting(string $q = '', ?array $user = null): array
    {
        $name = $user ? ' ' . explode(' ', (string) $user['name'])[0] : '';
        return [
            'reply' => "Hi{$name} — I'm the CureMeds assistant. I can look up products and prices, explain how ordering works, "
                     . "and help with the patient portal. For anything about your own health, " . self::wa('chat to our team on WhatsApp') . '.',
            'chips' => ['What do you sell?', 'How do I order?', 'Immune support', 'Patient portal'],
        ];
    }

    private static function catalogue(string $q = '', ?array $user = null): array
    {
        $rows = db()->query(
            "SELECT c.name, c.slug, COUNT(p.id) n FROM categories c
             LEFT JOIN products p ON p.category_id = c.id AND p.status='published'
             WHERE c.status='active' GROUP BY c.id HAVING n > 0 ORDER BY c.sort_order"
        )->fetchAll();

        $lines = [];
        foreach ($rows as $r) {
            $lines[] = '• ' . self::link('/shop.php?category=' . $r['slug'], $r['name']) . " ({$r['n']})";
        }

        return [
            'reply' => "Here is what we stock:<br>" . implode('<br>', $lines)
                     . "<br><br>" . self::link('/shop.php', 'Browse everything') . '.',
            'chips' => ['Best sellers', 'Delivery options', 'How do I pay?'],
        ];
    }

    private static function priceOf(string $q, ?array $user = null): array
    {
        $found = self::searchCatalogue($q);
        if ($found !== null) {
            return $found;
        }
        return [
            'reply' => "Tell me which product and I will quote the current price. You can also open the full "
                     . self::link('/price-list.php', 'price list') . " — every product and price on one print-friendly page.",
            'chips' => ['ShieldWell', 'Vitamin C', 'Blood pressure monitor', 'Full price list'],
        ];
    }

    private static function delivery(string $q = '', ?array $user = null): array
    {
        $flat = format_price((float) get_setting('shipping_flat_rate', '100'));
        $free = format_price((float) get_setting('free_shipping_threshold', '1000'));
        return [
            'reply' => "We courier anywhere in South Africa in 2–4 working days for {$flat} — free on orders over {$free}. "
                     . "We send the tracking number on WhatsApp. Collection can be arranged on request.",
            'chips' => ['How do I order?', 'How do I pay?', 'Patient portal'],
        ];
    }

    private static function howToOrder(string $q = '', ?array $user = null): array
    {
        return [
            'reply' => "Ordering happens on WhatsApp:<br>"
                     . "1. Tap <b>Enquire</b> on a product (or add a few to your " . self::link('/cart.php', 'enquiry list') . ").<br>"
                     . "2. WhatsApp opens with your order typed in — press send.<br>"
                     . "3. We confirm stock, the total and delivery, and send payment details.<br><br>"
                     . "Or just " . self::wa('message us now') . '.',
            'chips' => ['Delivery options', 'How do I pay?', 'What do you sell?'],
        ];
    }

    private static function payment(string $q = '', ?array $user = null): array
    {
        return [
            'reply' => "Once we've confirmed your order on WhatsApp, we send our banking details for an EFT. "
                     . "Send the proof of payment back on the same chat and we dispatch the same or next working day. "
                     . "We never ask for card details on the website.",
            'chips' => ['Delivery options', 'How do I order?', 'Returns policy'],
        ];
    }

    private static function portal(string $q = '', ?array $user = null): array
    {
        if ($user) {
            return [
                'reply' => "You're signed in. " . self::link('/dashboards/customer/index.php', 'Open your health tracker')
                         . " to see today's doses, log how you feel or report a side effect.",
                'chips' => ['Report a side effect', 'Contact support'],
            ];
        }
        return [
            'reply' => "Every patient gets a free health tracker. Sign in with your cellphone number and 4-digit PIN to tick off doses, "
                     . "track readings and report side effects — and we email you at every dose time.<br><br>"
                     . self::link('/modules/auth/login.php', 'Sign in') . ' · ' . self::link('/modules/auth/register.php', 'Create my tracker')
                     . "<br>Forgot your PIN? " . self::wa('WhatsApp us from your registered number') . ' and we will reset it.',
            'chips' => ['How do I order?', 'Is my information private?'],
        ];
    }

    private static function emergency(string $q = '', ?array $user = null): array
    {
        return [
            'reply' => "<b>If this is an emergency, call 10177 or go to your nearest clinic or hospital emergency unit now.</b><br><br>"
                     . "Difficulty breathing, swelling of the face or throat, chest pain or fainting need a medical professional straight away. "
                     . "Once you're safe, you can report what happened in your " . self::link('/dashboards/customer/side-effects.php', 'health tracker') . '.',
            'chips' => ['Contact support'],
        ];
    }

    private static function returns(string $q = '', ?array $user = null): array
    {
        return [
            'reply' => "Unopened, unused stock in its original packaging can be returned within 7 days of delivery for a refund or exchange. Opened consumables cannot be returned for hygiene reasons. Anything damaged in transit is replaced free — send us a photo within 48 hours.<br><br>"
                     . self::link('/refund-policy.php', 'Full returns policy') . '.',
            'chips' => ['Contact support', 'Track my order'],
        ];
    }

    private static function safety(string $q = '', ?array $user = null): array
    {
        return [
            'reply' => "Supplements support your health; they don't treat or cure disease, and they never replace prescribed medication — "
                     . "including ARVs, blood pressure or diabetes treatment. Keep taking your treatment exactly as prescribed.<br><br>"
                     . "Some supplements interact with medication, so tell your clinic what you take. If you're pregnant, breastfeeding or under 18, "
                     . "speak to a healthcare professional first. I can't give medical advice, but our team can help on " . self::wa('WhatsApp') . '.<br><br>'
                     . "Feeling a side effect? " . self::link('/dashboards/customer/side-effects.php', 'Report it in your tracker') . '.',
            'chips' => ['Report a side effect', 'Contact support'],
        ];
    }

    private static function contact(string $q = '', ?array $user = null): array
    {
        $email = get_setting('contact_email', SITE_EMAIL);
        return [
            'reply' => "The fastest way to reach us is " . self::wa('WhatsApp on ' . Health::displayPhone(whatsapp_number())) . ".<br>"
                     . "Email <b>" . htmlspecialchars((string) $email) . "</b>, or " . self::link('/contact.php', 'send a message') . '.',
            'chips' => ['How do I order?', 'Delivery options'],
        ];
    }

    private static function stock(string $q, ?array $user = null): array
    {
        $found = self::searchCatalogue($q);
        if ($found !== null) {
            return $found;
        }
        $n = (int) db()->query("SELECT COUNT(*) FROM products WHERE status='published' AND stock_status='out_of_stock'")->fetchColumn();
        return [
            'reply' => $n === 0
                ? "Everything in the catalogue is in stock right now."
                : "{$n} line(s) are out of stock. Open any product page and use <b>Notify me</b> and we will email you the moment it lands.",
            'chips' => ['Browse the shop', 'Best sellers'],
        ];
    }

    // ── Lookups ──────────────────────────────────────────────────────────

    /** Matches the message against product names, brands and tags. */
    private static function searchCatalogue(string $q): ?array
    {
        // Strip filler so "how much is the rise cleaner" searches for "rise cleaner".
        $stop  = ['how','much','is','the','a','price','of','cost','do','you','have','sell','can','i','buy','what','for','me','get','tell','about','your','does','and','in','stock','it','there','any'];
        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', $q) ?: [],
            fn($w) => $w !== '' && strlen($w) > 2 && !in_array($w, $stop, true)
        ));
        if (!$words) {
            return null;
        }

        $repo    = new ProductRepository();
        $matches = [];
        foreach ($words as $w) {
            foreach ($repo->suggest($w, 4) as $row) {
                $matches[$row['id']] = ($matches[$row['id']] ?? ['row' => $row, 'hits' => 0]);
                $matches[$row['id']]['hits']++;
            }
        }
        if (!$matches) {
            return null;
        }

        usort($matches, fn($a, $b) => $b['hits'] <=> $a['hits']);
        $top  = array_slice($matches, 0, 3);
        $user = current_user();

        $lines = [];
        foreach ($top as $m) {
            $p    = $repo->getById((int) $m['row']['id']);
            $name = self::link('/modules/shop/product.php?slug=' . urlencode($p['slug']), $p['name']);
            $line = "• {$name} — <b>" . format_price(Pricing::retail($p)) . "</b>";
            if ($p['pack_size']) {
                $line .= " ({$p['pack_size']})";
            }
            if ($p['stock_status'] === 'out_of_stock') {
                $line .= ' — <i>out of stock</i>';
            }
            if (Pricing::isReseller($user)) {
                $mg = Pricing::margin($p, $user);
                $line .= "<br>&nbsp;&nbsp;&nbsp;Your cost " . format_price($mg['cost'])
                       . " · profit <b>" . format_price($mg['profit']) . "</b> per unit";
            }
            $lines[] = $line;
        }

        return [
            'reply' => (count($lines) === 1 ? "Here it is:<br>" : "Closest matches:<br>") . implode('<br>', $lines)
                     . "<br><br>Tap a product, then <b>Enquire</b> to order on WhatsApp.",
            'chips' => ['How do I order?', 'Delivery options', 'Patient portal'],
        ];
    }

    private static function searchFaq(string $q): ?array
    {
        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', $q) ?: [],
            fn($w) => strlen($w) > 3
        ));
        if (!$words) {
            return null;
        }
        $like = '%' . implode('%', array_slice($words, 0, 3)) . '%';
        $stmt = db()->prepare("SELECT question, answer FROM faqs WHERE status='published' AND (question LIKE :q1 OR answer LIKE :q2) LIMIT 1");
        $stmt->execute([':q1' => $like, ':q2' => $like]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'reply' => "<b>" . htmlspecialchars($row['question']) . "</b><br>" . htmlspecialchars($row['answer']),
            'chips' => ['See all FAQs', 'Contact support'],
        ];
    }

    private static function wa(string $text): string
    {
        return '<a href="' . htmlspecialchars(wa_link('Hi CureMeds, I have a question.')) . '" target="_blank" rel="noopener">' . htmlspecialchars($text) . '</a>';
    }

    private static function link(string $path, string $text): string
    {
        return '<a href="' . SITE_URL . $path . '">' . htmlspecialchars($text) . '</a>';
    }
}
