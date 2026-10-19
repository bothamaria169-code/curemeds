<?php
declare(strict_types=1);

/**
 * Groq AI — writes product descriptions and social-media captions, and
 * summarises a patient's side-effect reports for staff.
 *
 * Set up in Admin → AI & social media (the key can also live in
 * config/secrets.php as groq_api_key). The AI writes words only: prices,
 * links and phone numbers are added by the site afterwards, and every
 * prompt forbids medical claims. Any failure returns '' with $error set, so
 * the caller falls back to its own template and nothing stops working.
 *
 * Adapted from the BidCo Auctions caption writer.
 */
final class Groq
{
    private const CHAT_URL   = 'https://api.groq.com/openai/v1/chat/completions';
    private const MODELS_URL = 'https://api.groq.com/openai/v1/models';

    /** Preferred models, best first. Groq changes its line-up often; see availableModels(). */
    public const PREFERRED = [
        'llama-3.3-70b-versatile'     => 'Llama 3.3 70B — best writing',
        'openai/gpt-oss-120b'         => 'GPT-OSS 120B — strong',
        'moonshotai/kimi-k2-instruct' => 'Kimi K2 — strong',
        'meta-llama/llama-4-scout-17b-16e-instruct' => 'Llama 4 Scout — quick',
        'openai/gpt-oss-20b'          => 'GPT-OSS 20B — quick',
        'llama-3.1-8b-instant'        => 'Llama 3.1 8B — quickest',
    ];

    /** Rules every prompt carries: South African health products are tightly regulated. */
    private const HEALTH_RULES = 'STRICT RULES: never claim a product cures, treats, prevents or diagnoses any disease; '
        . 'say "supports" rather than "treats"; never suggest a supplement replaces prescribed medication (including ARVs, '
        . 'insulin or blood pressure tablets); use only the facts given and invent nothing; no prices unless given; '
        . 'no links, web addresses or phone numbers; no markdown.';

    public static function key(): string
    {
        return trim((string) get_setting('groq_api_key', GROQ_API_KEY));
    }

    public static function model(): string
    {
        return trim((string) get_setting('groq_model', 'llama-3.3-70b-versatile'));
    }

    public static function ready(): bool
    {
        return self::key() !== '';
    }

    /**
     * Chat models this key can use, as id => label. Asked of Groq and cached for a day.
     *
     * @return array<string, string>
     */
    public static function availableModels(bool $fresh = false): array
    {
        $key = self::key();
        if ($key === '') {
            return [];
        }
        $cached = json_decode((string) get_setting('groq_models_cache', '{}'), true) ?: [];
        if (!$fresh && ($cached['key'] ?? '') === md5($key) && ($cached['at'] ?? 0) > time() - 86400 && !empty($cached['models'])) {
            return $cached['models'];
        }
        $ch = curl_init(self::MODELS_URL);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
        $reply = json_decode((string) curl_exec($ch), true) ?: [];
        curl_close($ch);

        $ids = [];
        foreach ((array) ($reply['data'] ?? []) as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id !== '' && ($m['active'] ?? true) && !preg_match('~whisper|tts|playai|guard|compound|orpheus|distil~i', $id)) {
                $ids[] = $id;
            }
        }
        if (!$ids) {
            return [];
        }
        $models = [];
        foreach (self::PREFERRED as $id => $label) {
            if (in_array($id, $ids, true)) {
                $models[$id] = $label;
            }
        }
        foreach ($ids as $id) {
            $models[$id] ??= $id;
        }
        set_setting('groq_models_cache', json_encode(['key' => md5($key), 'at' => time(), 'models' => $models]));
        return $models;
    }

    /** The saved model if this key can use it, otherwise the best one it can. */
    private static function bestModel(): string
    {
        $wanted = self::model();
        $models = self::availableModels();
        if (!$models || isset($models[$wanted])) {
            return $wanted;
        }
        return (string) array_key_first($models);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public static function chat(array $messages, string &$error = '', int $maxTokens = 500, float $temperature = 0.7): string
    {
        if (!self::ready()) {
            $error = 'Add a Groq API key in Admin → AI & social media first.';
            return '';
        }
        $model = self::bestModel();
        $request = ['model' => $model, 'messages' => $messages, 'temperature' => $temperature];
        // Thinking models spend tokens reasoning first: keep that hidden and give them room.
        if (str_contains($model, 'gpt-oss')) {
            $request += ['max_tokens' => $maxTokens + 1500, 'reasoning_effort' => 'low', 'include_reasoning' => false];
        } elseif (str_contains($model, 'qwen3')) {
            $request += ['max_tokens' => $maxTokens + 1500, 'reasoning_format' => 'hidden'];
        } else {
            $request['max_tokens'] = $maxTokens;
        }

        $ch = curl_init(self::CHAT_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . self::key(), 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($request, JSON_UNESCAPED_UNICODE),
        ]);
        $body = (string) curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($curlError !== '') {
            $error = $curlError;
            return '';
        }
        $reply = json_decode($body, true) ?: [];
        $text  = trim((string) ($reply['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            $error = (string) ($reply['error']['message'] ?? 'Groq gave an empty reply.');
        }
        return $text;
    }

    /** The facts about a product the AI may use — nothing else. */
    private static function productFacts(array $p): string
    {
        return implode("\n", array_filter([
            'Product: ' . $p['name'],
            !empty($p['brand_name']) ? 'Brand: ' . $p['brand_name'] : '',
            !empty($p['category_name']) ? 'Category: ' . $p['category_name'] : '',
            !empty($p['pack_size']) ? 'Pack size: ' . $p['pack_size'] : '',
            !empty($p['short_desc']) ? 'Summary: ' . strip_tags((string) $p['short_desc']) : '',
            !empty($p['benefits']) ? "Benefits (as supplied):\n" . $p['benefits'] : '',
            !empty($p['usage_notes']) ? 'How to use: ' . strip_tags((string) $p['usage_notes']) : '',
            !empty($p['notes']) ? 'Extra notes from staff: ' . strip_tags((string) $p['notes']) : '',
        ]));
    }

    /**
     * A product description for the shop, as simple HTML paragraphs.
     *
     * @return array{short: string, description: string}|null
     */
    public static function productDescription(array $p, string &$error = ''): ?array
    {
        $text = self::chat([
            ['role' => 'system', 'content' => 'You write product copy for ' . SITE_NAME . ', a South African online shop for health products and '
                . 'supplements. Write in clear, warm South African English for ordinary people, not doctors. ' . self::HEALTH_RULES
                . ' Reply as JSON: {"short": "one sentence, max 30 words", "description": "two or three short paragraphs separated by a blank line"}.'],
            ['role' => 'user', 'content' => "Write the shop copy for this product.\n\n" . self::productFacts($p)],
        ], $error, 700, 0.6);
        if ($text === '') {
            return null;
        }
        $json = json_decode((string) preg_replace('/^```(?:json)?|```$/m', '', $text), true);
        if (!is_array($json) || empty($json['description'])) {
            $error = 'The AI reply could not be read. Try again.';
            return null;
        }
        $paras = array_filter(array_map('trim', preg_split('/\n\s*\n/', strip_tags((string) $json['description'])) ?: []));
        return [
            'short'       => trim(strip_tags((string) ($json['short'] ?? ''))),
            'description' => implode('', array_map(fn($para) => '<p>' . e($para) . '</p>', $paras)),
        ];
    }

    /** A Facebook or Instagram caption. The site adds the price, WhatsApp number and hashtags. */
    public static function socialCaption(array $p, string $platform, string &$error = ''): string
    {
        $length = $platform === 'instagram' ? 'up to 70 words' : 'up to 90 words';
        return self::chat([
            ['role' => 'system', 'content' => 'You write social media posts for ' . SITE_NAME . ', a South African health products and supplements shop '
                . 'where customers order on WhatsApp. Warm, trustworthy and friendly, never pushy; a few fitting emojis. ' . self::HEALTH_RULES
                . ' No hashtags. Reply with the post text only.'],
            ['role' => 'user', 'content' => "Write a $platform post ($length) about this product. Open with a hook, give the key facts, "
                . "and end by inviting people to message us on WhatsApp to order.\n\n" . self::productFacts($p)],
        ], $error, 400, 0.8);
    }

    /** A short summary of a patient's recent side-effect reports, for staff only. */
    public static function sideEffectSummary(array $patient, array $meds, array $reports, string &$error = ''): string
    {
        $lines = [];
        foreach ($reports as $r) {
            $lines[] = '- ' . date('j M', strtotime($r['created_at'])) . ': ' . $r['symptom'] . ' (' . $r['severity'] . ')'
                . ($r['medication_name'] ? ' — on ' . $r['medication_name'] : '')
                . ($r['details'] ? ' — "' . mb_substr((string) $r['details'], 0, 200) . '"' : '');
        }
        return self::chat([
            ['role' => 'system', 'content' => 'You help staff at a South African health products shop read patient side-effect reports. '
                . 'Summarise in at most 5 short bullet points: what the patient reports, any pattern in timing or product, and anything '
                . 'the staff member should follow up on WhatsApp. You are NOT diagnosing: never name a diagnosis or prescribe. '
                . 'If any report sounds severe or urgent, say first that the patient should be advised to visit a clinic. Plain text only.'],
            ['role' => 'user', 'content' => 'Medications: ' . (implode(', ', array_column($meds, 'name')) ?: 'none listed')
                . "\nReports, newest first:\n" . implode("\n", $lines)],
        ], $error, 350, 0.3);
    }
}
