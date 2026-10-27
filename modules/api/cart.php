<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

$in     = api_input();
$action = $in['action'] ?? 'add';

if ($action === 'add') {
    $productId = (int) ($in['product_id'] ?? 0);
    $variantId = !empty($in['variant_id']) ? (int) $in['variant_id'] : null;
    $qty       = max(1, (int) ($in['qty'] ?? 1));

    $repo    = new ProductRepository();
    $product = $repo->getById($productId);

    if (!$product || $product['status'] !== 'published') {
        api_fail('That product is no longer available.');
    }
    if ($product['stock_status'] === 'out_of_stock') {
        api_fail('That product is out of stock.');
    }

    // A variable product must arrive with a variant, or the cart line is ambiguous.
    if ($product['type'] === 'variable') {
        if (!$variantId) {
            api_fail('Choose an option first.');
        }
        $variant = $repo->getVariantById($variantId);
        if (!$variant || (int) $variant['product_id'] !== $productId) {
            api_fail('That option does not belong to this product.');
        }
        if ((int) $variant['stock_qty'] < $qty) {
            api_fail('Only ' . (int) $variant['stock_qty'] . ' left in that option.');
        }
    }

    // Approved resellers buy in case quantities — enforce it here rather than
    // letting it surface as a surprise at checkout.
    $user = current_user();
    if (Pricing::isReseller($user)) {
        $min = (int) $product['min_reseller_qty'];
        $already = (int) ($_SESSION['cart'][cart_key($productId, $variantId)]['qty'] ?? 0);
        if ($already + $qty < $min) {
            $qty = $min - $already;
        }
    }

    cart_add($productId, $variantId, $qty);
    api_ok([
        'count'   => cart_count(),
        'message' => $qty > 1 ? "$qty added to your cart." : 'Added to your cart.',
    ]);
}

if ($action === 'set') {
    cart_set((string) ($in['key'] ?? ''), (int) ($in['qty'] ?? 1));
    api_ok(['count' => cart_count(), 'subtotal' => format_price(cart_subtotal())]);
}

if ($action === 'remove') {
    cart_remove((string) ($in['key'] ?? ''));
    api_ok(['count' => cart_count(), 'subtotal' => format_price(cart_subtotal())]);
}

if ($action === 'clear') {
    cart_clear();
    api_ok(['count' => 0]);
}

api_fail('Unknown cart action.');
