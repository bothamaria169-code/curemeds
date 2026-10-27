<?php
/**
 * Dashboard shell — opens <body>, the grid and the sidebar, and leaves
 * <div class="dash-main"> open for the page. Close it with dash-foot.php.
 *
 * Expects the page to set, before including:
 *   $dash_role   'admin' | 'reseller' | 'customer'
 *   $dash_active the slug of the current nav item
 *   $page_title
 */
require_once __DIR__ . '/functions.php';

// Dashboard pages print this shell first and handle their form posts after it,
// then redirect. Without a buffer the redirect fails with "headers already
// sent" as soon as the sidebar outgrows PHP's own small output buffer — and on
// a host with output_buffering off, it fails every time.
ob_start();

$dash_role   = $dash_role   ?? 'customer';
$dash_active = $dash_active ?? '';
$user        = require_auth($dash_role === 'admin' ? 'admin' : '');
$isRes       = Pricing::isReseller($user);

$stats = $dash_role === 'admin' ? (new OrderRepository())->stats() + Health::stats() + ['setup_left' => setup_tasks_left()] : [];

$nav = match ($dash_role) {
    'admin' => [
        'Overview' => [
            ['dashboard', 'index.php',     'fa-gauge-high',      'Dashboard', null],
            ['setup',     'setup.php',     'fa-list-check',      'Setup checklist', $stats['setup_left'] ?? 0],
            ['orders',    'orders.php',    'fa-receipt',         'Orders', $stats['orders_pending'] ?? 0],
            ['enquiries', 'enquiries.php', 'fa-brands fa-whatsapp', 'Enquiries', $stats['enquiries_new'] ?? 0],
            ['purchases', 'purchases.php', 'fa-bolt',          'Live ticker', null],
        ],
        'Patients' => [
            ['patients',     'patients.php',     'fa-hospital-user',  'Patients',     null],
            ['side-effects', 'side-effects.php', 'fa-notes-medical',  'Side effects', $stats['new_side_effects'] ?? 0],
            ['reminders',    'reminders.php',    'fa-bell',           'Reminders',    null],
        ],
        'Catalogue' => [
            ['products',   'products.php',   'fa-boxes-stacked', 'Products',   null],
            ['categories', 'categories.php', 'fa-tags',          'Categories', null],
            ['brands',     'brands.php',     'fa-copyright',     'Brands',     null],
            ['reviews',    'reviews.php',    'fa-star',          'Reviews',    $stats['pending_reviews'] ?? 0],
        ],
        'Marketing' => [
            ['social',     'social.php',     'fa-wand-magic-sparkles', 'AI & social media', null],
            ['banners',    'banners.php',    'fa-images',       'Banners',     null],
            ['blog',       'blog.php',       'fa-newspaper',    'Articles',    null],
            ['newsletter', 'newsletter.php', 'fa-paper-plane',  'Newsletter',  null],
            ['emails',     'email-templates.php', 'fa-envelope-open-text', 'Email templates', null],
            ['testimonials', 'testimonials.php', 'fa-comment-dots', 'Testimonials', null],
            ['gallery',    'gallery.php',    'fa-camera-retro', 'Customer gallery', null],
        ],
        'Admin' => [
            ['users',    'users.php',    'fa-users',   'Accounts', null],
            ['messages', 'messages.php', 'fa-inbox',   'Messages', $stats['unread_msgs'] ?? 0],
            ['settings', 'settings.php', 'fa-sliders', 'Settings', null],
            ['account',  'account.php',  'fa-user-shield', 'My account', null],
        ],
    ],
    default => [
        'My health' => [
            ['dashboard',    'index.php',        'fa-heart-pulse',    'Today',         null],
            ['medications',  'medications.php',  'fa-pills',          'My medication', null],
            ['side-effects', 'side-effects.php', 'fa-notes-medical',  'Side effects',  null],
            ['readings',     'readings.php',     'fa-chart-line',     'Readings',      null],
        ],
        'Account' => [
            ['orders',   'orders.php',   'fa-receipt',   'My orders',   null],
            ['wishlist', 'wishlist.php', 'fa-heart',     'Saved items', null],
            ['account',  'account.php',  'fa-user-gear', 'My details',  null],
        ],
    ],
};

$page_title = $page_title ?? ucfirst($dash_role) . ' dashboard — ' . SITE_NAME;
?>
<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/img/logo/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= asset('assets/css/theme.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/components.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/layout.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/pages.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/responsive.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/curemeds.css') ?>">
</head>
<body>
<div class="scrim" id="scrim"></div>
<div class="dash">

  <aside class="dash-side" id="dashSide">
    <a href="<?= SITE_URL ?>/index.php" class="brand">
      <img src="<?= SITE_URL ?>/assets/img/logo/curemeds-mark.png" alt="" class="brand__mark">
      <span class="brand__word">Cure<em style="color:var(--leaf-light)">Meds</em></span>
    </a>

    <?php foreach ($nav as $group => $links): ?>
      <div class="dash-side__label"><?= e($group) ?></div>
      <?php foreach ($links as [$slug, $href, $icon, $label, $count]): ?>
        <a href="<?= $href ?>" class="<?= $dash_active === $slug ? 'is-active' : '' ?>">
          <i class="<?= str_contains($icon, ' ') ? $icon : 'fa-solid ' . $icon ?>"></i><?= e($label) ?>
          <?php if ($count): ?><span class="n"><?= (int) $count ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="dash-side__label">Session</div>
    <?php if ($dash_role !== 'admin' && $user['role'] === 'admin'): ?>
    <a href="<?= SITE_URL ?>/dashboards/admin/index.php" style="color:var(--leaf-light);font-weight:700"><i class="fa-solid fa-gauge-high"></i>Admin dashboard</a>
    <?php endif; ?>
    <?php if ($dash_role !== 'admin'): ?>
    <a href="<?= e(wa_link('Hi CureMeds, I need help with my health tracker.')) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i>WhatsApp us</a>
    <?php endif; ?>
    <a href="<?= SITE_URL ?>/index.php"><i class="fa-solid fa-store"></i>Back to the shop</a>
    <a href="<?= SITE_URL ?>/modules/auth/logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i>Sign out</a>

    <div style="margin-top:auto;padding:1.2rem .75rem 0;border-top:1px solid var(--line-dark)">
      <div style="font-size:.84rem;color:#fff;font-weight:700"><?= e($user['name']) ?></div>
      <div style="font-size:.74rem;color:var(--on-dark-2)"><?= e($user['email'] ?: Health::displayPhone($user['phone'] ?? '')) ?></div>
      <?php if ($isRes): ?>
        <span class="badge-fc badge-fc--amber mt-2"><?= e(RESELLER_TIERS[$user['reseller_tier']]['label']) ?> · <?= e((string) $user['reseller_code']) ?></span>
      <?php endif; ?>
    </div>
  </aside>

  <div class="dash-main">
    <button class="btn-fc btn-fc--ghost btn-fc--sm dash-burger mb-3" type="button" id="dashBurger">
      <i class="fa-solid fa-bars"></i> Menu
    </button>
