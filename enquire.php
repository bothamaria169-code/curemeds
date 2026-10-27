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
$email = '';
$pay   = '';
$source = 'product';
$locker = null;   // the Pudo locker chosen in the enquiry list, if any

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        redirect('cart.php');
    }
    $source = 'list';
    $name   = mb_substr(trim(strip_tags((string) ($_POST['name'] ?? ''))), 0, 120);
    $phone  = Health::normalizePhone((string) ($_POST['phone'] ?? '')) ?? '';
    $email  = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
    $pay    = array_key_exists((string) ($_POST['payment_method'] ?? ''), payment_methods()) ? (string) $_POST['payment_method'] : '';
    if (($_POST['delivery_method'] ?? '') === 'pudo' && Pudo::enabled()) {
        $code = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['pudo_locker'] ?? ''));
        try {
            $locker = $code !== '' ? Pudo::locker($code) : null;
        } catch (Throwable $e) {
            error_log('Pudo locker lookup failed: ' . $e->getMessage());
        }
    }
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
    $email = $email ?: (string) ($user['email'] ?? '');
}

$total = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $items));
$pudoFee = $locker ? Pudo::fee($locker['code'], $total) : 0.0;

// The message the customer sends. Prices are for reference: staff confirm the final total.
$lines = array_map(fn($i) => '• ' . $i['qty'] . ' × ' . $i['name'] . ($i['pack'] ? ' (' . $i['pack'] . ')' : '')
    . ' — ' . format_price_short($i['price'] * $i['qty']), $items);
$message = "Hi CureMeds, I'd like to order:\n" . implode("\n", $lines)
    . (count($items) > 1 ? "\n\nTotal: " . format_price_short($total) : '')
    . ($name !== '' ? "\n\nName: " . $name : '')
    . ($pay !== '' ? "\nPaying by: " . payment_methods()[$pay]['label'] : '')
    . ($locker ? "\nDeliver to Pudo locker: " . $locker['name'] . ' (' . $locker['code'] . ') — ' . ($pudoFee > 0 ? format_price_short($pudoFee) : 'free') : '')
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
            'email'   => $email ?: null,
            'payment_method' => $pay ?: null,
            'delivery_method'  => $locker ? 'pudo' : 'delivery',
            'pudo_locker_code' => $locker['code'] ?? null,
            'pudo_locker_name' => $locker ? mb_substr($locker['name'] . ' — ' . $locker['address'], 0, 255) : null,
            'shipping_fee'     => $pudoFee,
        ]);
        // The order and our bank / cash-on-delivery details, by email (Admin → Email templates).
        if ($email !== '') {
            try {
                $enq = ['id' => $id, 'name' => $name, 'items' => json_encode($items), 'total' => $total];
                if (send_template_email($email, 'enquiry-payment', enquiry_email_vars($enq))) {
                    db_update('enquiries', (int) $id, ['emailed_at' => date('Y-m-d H:i:s')]);
                }
            } catch (Throwable $e) {
                error_log('Enquiry email not sent: ' . $e->getMessage());
            }
        }
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
