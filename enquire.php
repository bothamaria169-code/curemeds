<?php
declare(strict_types=1);

/**
 * Every "Enquire on WhatsApp" button lands here. The enquiry is saved first
 * (Admin → Enquiries), then the visitor is sent on to WhatsApp with the
 * message already typed — so a lead is never lost even if they don't press Send.
 *
 *   GET  ?product=ID[&qty=N]      one product (product cards and pages)
 *   POST from cart.php            the whole enquiry list, with name and phone
 */
require_once __DIR__ . '/includes/functions.php';

$repo  = new ProductRepository();
$user  = current_user();
$items = [];
$name  = '';
$phone = '';
$source = 'product';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        redirect('cart.php');
    }
    $source = 'list';
    $name   = mb_substr(trim(strip_tags((string) ($_POST['name'] ?? ''))), 0, 120);
    $phone  = Health::normalizePhone((string) ($_POST['phone'] ?? '')) ?? '';
    foreach (cart_items() as $line) {
        $items[] = [
            'product_id' => (int) $line['product']['id'],
            'name'       => $line['product']['name'] . ($line['variant_text'] ? ' (' . $line['variant_text'] . ')' : ''),
            'pack'       => (string) ($line['product']['pack_size'] ?? ''),
            'qty'        => (int) $line['qty'],
            'price'      => (float) $line['unit_price'],
        ];
    }
} else {
    $product = $repo->getById((int) ($_GET['product'] ?? 0));
    if ($product && $product['status'] === 'published') {
        $items[] = [
            'product_id' => (int) $product['id'],
            'name'       => $product['name'],
            'pack'       => (string) ($product['pack_size'] ?? ''),
            'qty'        => max(1, min(99, (int) ($_GET['qty'] ?? 1))),
            'price'      => Pricing::retail($product),
        ];
    }
}

if (!$items) {
    redirect(wa_link("Hi CureMeds, I have a question."));
}

if ($user) {
    $name  = $name ?: (string) $user['name'];
    $phone = $phone ?: (string) ($user['phone'] ?? '');
}

$total = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $items));

// The message the customer sends. Prices are for reference: staff confirm the final total.
$lines = array_map(fn($i) => '• ' . $i['qty'] . ' × ' . $i['name'] . ($i['pack'] ? ' (' . $i['pack'] . ')' : '')
    . ' — ' . format_price_short($i['price'] * $i['qty']), $items);
$message = "Hi CureMeds, I'd like to order:\n" . implode("\n", $lines)
    . (count($items) > 1 ? "\n\nTotal: " . format_price_short($total) : '')
    . ($name !== '' ? "\n\nName: " . $name : '')
    . "\nPlease confirm stock, delivery and payment details.";

// Don't let a reload or a bot fill the enquiries table: one save per list per 10 minutes.
$fingerprint = md5(json_encode($items) . $phone);
if (rate_limit('enquiry', 20, 3600) && ($_SESSION['last_enquiry'] ?? '') !== $fingerprint) {
    try {
        $id = db_insert('enquiries', [
            'user_id' => $user['id'] ?? null,
            'name'    => $name ?: null,
            'phone'   => $phone ?: null,
            'items'   => json_encode($items, JSON_UNESCAPED_UNICODE),
            'total'   => $total,
            'source'  => $source,
        ]);
        $_SESSION['last_enquiry'] = $fingerprint;
        $message .= "\n(Ref: E" . $id . ')';
    } catch (Throwable $e) {
        // Never block the customer from reaching us because the log failed.
        error_log('Enquiry not saved: ' . $e->getMessage());
    }
}

if ($source === 'list') {
    cart_clear();
}
redirect(wa_link($message));
