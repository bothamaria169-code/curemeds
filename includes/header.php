<?php
require_once __DIR__ . '/functions.php';

$current_user   = current_user();
$cart_count     = cart_count();
$wishlist_count = $current_user ? count(wishlist_ids((int) $current_user['id'])) : 0;
$is_reseller    = Pricing::isReseller($current_user);

$page_title       = $page_title       ?? SITE_NAME . ' — ' . SITE_TAGLINE;
$meta_description = $meta_description ?? 'Shop South African wellness, fragrance, cleaning and pest-control brands in one place. Real stock, honest prices, free courier delivery over R1,500.';
$body_class       = $body_class       ?? '';
$og_image         = $og_image         ?? SITE_URL . '/assets/img/stock/shieldwell.jpg';
$extra_css        = $extra_css        ?? [];
$json_ld          = $json_ld          ?? [];
$canonical        = $canonical        ?? SITE_URL . ($_SERVER['REQUEST_URI'] ?? '/');

// Newsletter sign-up gate: 'locked', 'popup' or null. Locked also freezes page scroll
// from the first paint, before any script has run.
$nl_gate = NewsletterService::gateFor($current_user);
if ($nl_gate === 'locked') {
    $body_class = trim($body_class . ' is-gated');
}
?>
<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($page_title) ?></title>
<meta name="description" content="<?= e($meta_description) ?>">
<?php if (!empty($meta_robots)): ?><meta name="robots" content="<?= e($meta_robots) ?>"><?php endif; ?>
<meta name="theme-color" content="#0A1B3D">
<link rel="canonical" href="<?= e($canonical) ?>">

<meta property="og:site_name" content="<?= e(SITE_NAME) ?>">
<meta property="og:title" content="<?= e($page_title) ?>">
<meta property="og:description" content="<?= e($meta_description) ?>">
<meta property="og:image" content="<?= e($og_image) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="en_ZA">
<meta name="twitter:card" content="summary_large_image">

<link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/img/logo/favicon.png">
<link rel="alternate" type="application/rss+xml" title="<?= e(SITE_NAME) ?> — Health news &amp; guides" href="<?= SITE_URL ?>/rss.xml">
<link rel="apple-touch-icon" href="<?= SITE_URL ?>/assets/img/logo/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<link rel="stylesheet" href="<?= asset('assets/css/theme.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/components.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/layout.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/pages.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/responsive.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/curemeds.css') ?>">
<?php foreach ($extra_css as $href): ?>
<link rel="stylesheet" href="<?= e($href) ?>">
<?php endforeach; ?>

<script type="application/ld+json"><?= json_encode(array_merge([
    '@context' => 'https://schema.org',
    '@type'    => 'Organization',
    'name'     => SITE_NAME,
    'url'      => SITE_URL,
    'logo'     => SITE_URL . '/assets/img/logo/curemeds-logo.png',
    'slogan'   => SITE_TAGLINE,
    'address'  => [
        '@type'          => 'PostalAddress',
        'streetAddress'  => get_setting('contact_address', ''),
        'addressCountry' => 'ZA',
    ],
    'contactPoint' => [
        '@type'       => 'ContactPoint',
        'telephone'   => get_setting('contact_phone', ''),
        'email'       => get_setting('contact_email', SITE_EMAIL),
        'contactType' => 'customer service',
        'areaServed'  => 'ZA',
    ],
], $json_ld), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
</head>
<body class="has-tabbar <?= e($body_class) ?>">
<?php if ($nl_gate) { include __DIR__ . '/newsletter-gate.php'; } ?>
<a class="skip-link" href="#main">Skip to content</a>

<?php include __DIR__ . '/navbar.php'; ?>

<main id="main">
