<?php
/**
 * Sub-navigation shared by the newsletter admin pages.
 * Expects $nl_tab = campaigns | compose | subscribers | settings.
 */
NewsletterService::ensureSchema();

$nl_tab    = $nl_tab ?? 'campaigns';
$nl_active = (int) db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active'")->fetchColumn();
$nl_mode   = NewsletterService::gateMode();
$nl_links  = [
    'campaigns'   => ['newsletter.php',             'fa-paper-plane',   'Campaigns',   null],
    'compose'     => ['newsletter-compose.php',     'fa-pen-to-square', 'New campaign', null],
    'subscribers' => ['newsletter-subscribers.php', 'fa-users',         'Subscribers', $nl_active],
    'settings'    => ['newsletter-settings.php',    'fa-door-open',     'Sign-up form', null],
];
?>
<nav class="tabs nl-admin-tabs" aria-label="Newsletter">
  <?php foreach ($nl_links as $slug => [$href, $icon, $label, $count]): ?>
    <a href="<?= $href ?>" class="<?= $nl_tab === $slug ? 'is-on' : '' ?>" <?= $nl_tab === $slug ? 'aria-current="page"' : '' ?>>
      <i class="fa-solid <?= $icon ?>"></i> <?= $label ?>
      <?php if ($count !== null): ?><span class="nl-admin-tabs__count"><?= number_format($count) ?></span><?php endif; ?>
      <?php if ($slug === 'settings'): ?>
        <span class="nl-mode nl-mode--<?= $nl_mode ?>"><?= ucfirst($nl_mode) ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>
