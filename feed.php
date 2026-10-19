<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/rss+xml; charset=utf-8');

$posts = get_blogs([], 1, 20)['items'];
$now   = date(DATE_RSS);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title><?= e(SITE_NAME) ?> — Learn</title>
  <link><?= e(SITE_URL) ?>/modules/blog/index.php</link>
  <description>Pricing your stock, reading labels honestly, and getting the most out of what you buy.</description>
  <language>en-ZA</language>
  <lastBuildDate><?= $now ?></lastBuildDate>
  <atom:link href="<?= e(SITE_URL) ?>/feed.php" rel="self" type="application/rss+xml" />
<?php foreach ($posts as $p): ?>
  <item>
    <title><?= e($p['title']) ?></title>
    <link><?= e(SITE_URL) ?>/modules/blog/post.php?slug=<?= urlencode($p['slug']) ?></link>
    <guid isPermaLink="true"><?= e(SITE_URL) ?>/modules/blog/post.php?slug=<?= urlencode($p['slug']) ?></guid>
    <pubDate><?= date(DATE_RSS, strtotime($p['created_at'])) ?></pubDate>
    <category><?= e($p['category']) ?></category>
    <description><![CDATA[<?= $p['excerpt'] ?>]]></description>
  </item>
<?php endforeach; ?>
</channel>
</rss>
