<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

date_default_timezone_set('Africa/Johannesburg');

// ── Order lifecycle ──────────────────────────────────────────────────────
const ORDER_STATUSES = [
    'pending'    => ['label' => 'Awaiting payment', 'tone' => 'warn',    'icon' => 'fa-hourglass-half'],
    'confirmed'  => ['label' => 'Payment confirmed', 'tone' => 'info',   'icon' => 'fa-circle-check'],
    'packing'    => ['label' => 'Being packed',      'tone' => 'info',   'icon' => 'fa-box-open'],
    'ready'      => ['label' => 'Ready for pickup',  'tone' => 'info',   'icon' => 'fa-store'],
    'shipped'    => ['label' => 'On the way',        'tone' => 'info',   'icon' => 'fa-truck-fast'],
    'delivered'  => ['label' => 'Delivered',         'tone' => 'ok',     'icon' => 'fa-circle-check'],
    'cancelled'  => ['label' => 'Cancelled',         'tone' => 'danger', 'icon' => 'fa-circle-xmark'],
];

// ── Fulfilment methods (mirrors how the suppliers actually move stock) ───
const FULFILMENT_METHODS = [
    'delivery'   => ['label' => 'Courier delivery', 'blurb' => 'Door-to-door anywhere in South Africa, 2–4 working days. Tracking number sent on WhatsApp.', 'icon' => 'fa-truck-fast'],
    'collection' => ['label' => 'Collection',       'blurb' => 'Collect by arrangement — ask on WhatsApp where and when.', 'icon' => 'fa-store'],
    'pudo'       => ['label' => 'Pudo locker',      'blurb' => 'The Courier Guy delivers to a Pudo smart locker you pick on the map. Collect with a PIN, 2–4 working days.', 'icon' => 'fa-box-archive'],
];

// ── Reseller tiers ───────────────────────────────────────────────────────
// `off_retail` is the discount off the RETAIL price a reseller at this tier
// pays. Starter is set at the level the supplier sheets themselves quote
// (the ML Group sheet says buy R55, sell R110), so a brand-new reseller is
// never worse off than the printed wholesale price — and each tier above it
// genuinely improves on that. See Pricing::reseller() for how the two
// interact with a product's explicit reseller_price override.
const RESELLER_TIERS = [
    'starter' => ['label' => 'Starter', 'off_retail' => 0.45, 'min_spend' => 0,     'colour' => '#8A94A3'],
    'bronze'  => ['label' => 'Bronze',  'off_retail' => 0.50, 'min_spend' => 2500,  'colour' => '#B5793C'],
    'silver'  => ['label' => 'Silver',  'off_retail' => 0.55, 'min_spend' => 7500,  'colour' => '#7E8A99'],
    'gold'    => ['label' => 'Gold',    'off_retail' => 0.60, 'min_spend' => 20000, 'colour' => '#D6A21B'],
];

const RESELLER_STATUSES = ['none', 'pending', 'approved', 'suspended'];
