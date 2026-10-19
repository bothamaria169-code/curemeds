<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

$in = api_input();
$q  = trim((string) ($in['q'] ?? $_GET['q'] ?? ''));

if (mb_strlen($q) < 2) {
    api_ok(['results' => []]);
}

$user    = current_user();
$rows    = (new ProductRepository())->suggest($q, 7);
$results = [];

foreach ($rows as $r) {
    // Suggestions quote the price the viewer would actually pay, so the
    // number in the dropdown matches the number on the product page.
    $price = Pricing::isReseller($user)
        ? Pricing::reseller($r, $user)
        : Pricing::retail($r);

    $results[] = [
        'name'  => $r['name'],
        'slug'  => $r['slug'],
        'brand' => $r['brand_name'],
        'pack'  => $r['pack_size'],
        'image' => product_image($r['featured_image']),
        'price' => format_price_short($price),
    ];
}

api_ok(['results' => $results]);
