<?php
declare(strict_types=1);

/**
 * CureMeds scheduled jobs. Run every 5 minutes:
 *
 *   cPanel cron:  /usr/local/bin/php /home/USER/public_html/scripts/cron.php
 *   URL cron:     https://curemeds.co.za/scripts/cron.php?key=CRON_KEY   (see Admin → Reminders)
 *
 * Each run: emails dose reminders that have fallen due, sends refill notices,
 * makes the day's automatic Facebook/Instagram post, writes articles and reels,
 * and sends newsletters 40 at a time.
 * Everything is safe to run repeatedly — nothing is sent twice.
 */
require_once __DIR__ . '/../includes/functions.php';

if (PHP_SAPI !== 'cli') {
    // The web route is only for hosts without cron; it needs the secret key.
    if (!hash_equals(CRON_KEY, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// Never let two runs overlap (a slow mail server can make one take a while).
$lock = fopen(sys_get_temp_dir() . '/curemeds-cron-' . md5(__DIR__) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Another run is still going.\n");
}
// Reels wait for ImageKit and Instagram to process the video, which can take a few minutes.
set_time_limit(600);

$started = microtime(true);
$r = Health::sendDueReminders();
echo date('Y-m-d H:i:s') . " reminders: {$r['sent']} sent, {$r['failed']} failed, {$r['refills']} refill\n";

try {
    $social = SocialPoster::runDaily();
    echo 'social: ' . json_encode($social) . "\n";
} catch (Throwable $e) {
    echo 'social: error ' . $e->getMessage() . "\n";
}

// Automatic articles (Groq) and reels (ImageKit), spread through the posting hours.
try {
    echo 'content: ' . json_encode(ContentEngine::run(), JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo 'content: error ' . $e->getMessage() . "\n";
}

// The weekly product newsletter is queued once, on the chosen day.
try {
    echo 'weekly newsletter: ' . weekly_product_newsletter() . "\n";
} catch (Throwable $e) {
    echo 'weekly newsletter: error ' . $e->getMessage() . "\n";
}

// Newsletters that are sending go out 40 at a time, so nobody has to keep the send page open.
try {
    if (NewsletterService::ensureSchema()) {
        foreach (db()->query("SELECT id FROM newsletter_campaigns WHERE status = 'sending'")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            NewsletterService::sendBatch((int) $cid, 40);
            echo "newsletter #{$cid}: " . json_encode(NewsletterService::progress((int) $cid, true)) . "\n";
        }
    }
} catch (Throwable $e) {
    echo 'newsletter: error ' . $e->getMessage() . "\n";
}

printf("done in %.1fs\n", microtime(true) - $started);
flock($lock, LOCK_UN);
