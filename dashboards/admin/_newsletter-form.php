<?php
/**
 * Turns compose-form input into a campaign row.
 *
 * Shared by the compose page (to save) and the preview endpoint (to render
 * without saving), so the preview can never drift from what gets stored.
 *
 * @return array{row: array, errors: string[]}
 */
function nl_campaign_from_input(array $in): array
{
    $catalog  = NewsletterTemplates::catalog();
    $template = (string) ($in['template'] ?? 'grid');
    if (!isset($catalog[$template])) {
        $template = 'grid';
    }
    $spec   = $catalog[$template];
    $errors = [];

    $text = fn(string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);

    $subject   = $text('subject', 255);
    $preheader = $text('preheader', 200);
    $audience  = in_array($in['audience'] ?? 'all', ['all', 'retail', 'reseller'], true) ? (string) $in['audience'] : 'all';

    if (mb_strlen($subject) < 3) {
        $errors[] = 'Give the email a subject line — it is the first thing people see.';
    }

    // Product ids arrive as "12,4,9" in the order they were ticked.
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($in['product_ids'] ?? ''))))));
    if ($spec['max'] === 0) {
        $ids = [];
    } elseif (count($ids) < $spec['min'] || count($ids) > $spec['max']) {
        $errors[] = $spec['min'] === $spec['max']
            ? "{$spec['label']} needs exactly {$spec['min']} product."
            : "{$spec['label']} takes {$spec['min']} to {$spec['max']} products — you picked " . count($ids) . '.';
    }

    $ctaUrl = $text('cta_url', 255);
    if ($ctaUrl !== '' && !preg_match('#^(https?://|/)#i', $ctaUrl)) {
        $errors[] = 'The button link should start with / (a page on this site) or https://.';
    }

    $couponCode = strtoupper($text('coupon_code', 60));
    if ($couponCode !== '' && in_array('coupon', $spec['fields'], true)) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM coupons WHERE code = :c');
        $stmt->execute([':c' => $couponCode]);
        if ((int) $stmt->fetchColumn() === 0) {
            $errors[] = "There is no coupon called $couponCode.";
        }
    }

    $data = [
        'eyebrow'     => $text('eyebrow', 60),
        'heading'     => $text('heading', 160),
        'intro'       => $text('intro', 3000),
        'product_ids' => $ids,
        'coupon_code' => in_array('coupon', $spec['fields'], true) ? $couponCode : '',
        'sale_note'   => $text('sale_note', 160),
        'cta_label'   => $text('cta_label', 60),
        'cta_url'     => $ctaUrl,
    ];

    if ($template === 'custom') {
        $body = nl_clean_html((string) ($in['body_html'] ?? ''));
        if (trim(strip_tags($body)) === '') {
            $errors[] = 'Write something in the body.';
        }
        $templateData = null;
    } else {
        if ($data['heading'] === '') {
            $errors[] = 'Add a heading.';
        }
        $body         = NewsletterTemplates::render($template, $data);
        $templateData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return [
        'row' => [
            'subject'       => $subject,
            'preheader'     => $preheader !== '' ? $preheader : null,
            'audience'      => $audience,
            'template'      => $template,
            'template_data' => $templateData,
            'body_html'     => $body,
        ],
        'errors' => $errors,
    ];
}

/**
 * Custom HTML is admin-written, but it still goes out to every subscriber and
 * renders in the admin preview, so strip scripts, event handlers and
 * javascript: links. Email clients would drop most of these anyway.
 */
function nl_clean_html(string $html): string
{
    $html = strip_tags($html, '<p><br><h1><h2><h3><ul><ol><li><strong><b><em><i><a><img><table><tr><td><th><tbody><thead><span><div><hr><blockquote>');
    $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
    return preg_replace('/(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*/i', '$1=$2#', $html) ?? '';
}

/** The fields to repopulate the form with, from a saved campaign. */
function nl_form_values(?array $campaign): array
{
    $catalog  = NewsletterTemplates::catalog();
    $template = $campaign['template'] ?? 'grid';
    $data     = $campaign && $campaign['template_data']
        ? (json_decode((string) $campaign['template_data'], true) ?: [])
        : ($catalog[$template]['defaults'] ?? []);

    return [
        'id'          => (int) ($campaign['id'] ?? 0),
        'template'    => $template,
        'subject'     => (string) ($campaign['subject'] ?? ''),
        'preheader'   => (string) ($campaign['preheader'] ?? ''),
        'audience'    => (string) ($campaign['audience'] ?? 'all'),
        'eyebrow'     => (string) ($data['eyebrow'] ?? ''),
        'heading'     => (string) ($data['heading'] ?? ''),
        'intro'       => (string) ($data['intro'] ?? ''),
        'product_ids' => implode(',', array_map('intval', $data['product_ids'] ?? [])),
        'coupon_code' => (string) ($data['coupon_code'] ?? ''),
        'sale_note'   => (string) ($data['sale_note'] ?? ''),
        'cta_label'   => (string) ($data['cta_label'] ?? ''),
        'cta_url'     => (string) ($data['cta_url'] ?? ''),
        'body_html'   => ($campaign['template'] ?? '') === 'custom' ? (string) $campaign['body_html'] : '',
    ];
}
