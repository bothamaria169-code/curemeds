<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'social';
$page_title  = 'AI & social media — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$repo = new ProductRepository();
$draft = null;
$json  = function (array $reply): never {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo json_encode($reply);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    switch ((string) ($_POST['action'] ?? '')) {
        case 'groq':
            $key = trim((string) ($_POST['groq_api_key'] ?? ''));
            if ($key !== '' && !str_starts_with($key, '••')) {   // the masked value means "unchanged"
                set_setting('groq_api_key', $key);
                set_setting('groq_models_cache', '');
            }
            if (!empty($_POST['groq_remove'])) {
                set_setting('groq_api_key', '');
            }
            set_setting('groq_model', preg_replace('/[^\w\/.\-]/', '', (string) ($_POST['groq_model'] ?? 'llama-3.3-70b-versatile')) ?: 'llama-3.3-70b-versatile');
            if (Groq::ready()) {
                $err = '';
                $reply = Groq::chat([['role' => 'user', 'content' => 'Reply with the single word: ready']], $err, 10, 0);
                $reply !== '' ? flash('success', 'Groq is connected and answering.') : flash('danger', 'Groq said: ' . e($err));
            } else {
                flash('info', 'Groq settings saved.');
            }
            back('groq');

        case 'meta':
            // An App ID is 15-16 digits and nothing else: catch the secret or config ID pasted in the wrong box.
            $appId = trim((string) ($_POST['meta_app_id'] ?? ''));
            if (!preg_match('/^\d{14,17}$/', $appId)) {
                flash('danger', 'That App ID does not look right. Copy the App ID (only digits, e.g. 1663372335417057) from App settings → Basic.');
                back('meta');
            }
            set_setting('meta_app_id', $appId);
            $secret = trim((string) ($_POST['meta_app_secret'] ?? ''));
            if ($secret !== '' && !str_starts_with($secret, '••')) {
                set_setting('meta_app_secret', $secret);
            }
            set_setting('meta_config_id', preg_replace('/\D/', '', (string) ($_POST['meta_config_id'] ?? '')) ?? '');
            flash('success', 'Meta app saved. Now press Connect with Facebook.');
            back('meta');

        case 'imagekit':
            set_setting('imagekit_public_key', trim((string) ($_POST['imagekit_public_key'] ?? '')));
            $priv = trim((string) ($_POST['imagekit_private_key'] ?? ''));
            if ($priv !== '' && !str_starts_with($priv, '••')) {
                set_setting('imagekit_private_key', $priv);
            }
            set_setting('imagekit_url_endpoint', rtrim(trim((string) ($_POST['imagekit_url_endpoint'] ?? '')), '/'));
            if (!ImageKit::ready()) {
                flash('info', 'ImageKit saved. Fill in all three boxes to switch it on.');
            } else {
                $err = ImageKit::test();
                $err === '' ? flash('success', 'ImageKit is connected. Photos and reels for Meta now go through ImageKit.') : flash('danger', 'ImageKit said: ' . e($err));
            }
            back('imagekit');

        // Signed upload so the browser can send the reel straight to ImageKit.
        case 'ffmpeg_install':
            $err = VideoMaker::install();
            $err === '' ? flash('success', 'ffmpeg is installed: ' . e((string) VideoMaker::version()) . '. Reels and videos are now made on our own server. Press <b>Test video</b> to see one.')
                        : flash('danger', e($err));
            back('video');

        case 'ffmpeg_path':
            set_setting('ffmpeg_path', trim((string) ($_POST['ffmpeg_path'] ?? '')));
            VideoMaker::path(true) ? flash('success', 'ffmpeg found: ' . e((string) VideoMaker::version()))
                                   : flash('danger', e(VideoMaker::$error));
            back('video');

        case 'ffmpeg_test':
            set_time_limit(300);
            $p = $repo->getById((int) ($_POST['product_id'] ?? 0)) ?: ($repo->getFeatured(1)[0] ?? null);
            if (!$p) {
                flash('danger', 'Add a product first.');
                back('video');
            }
            $t = microtime(true);
            try {
                $url = VideoMaker::productReel($p);
                flash('success', sprintf('Test reel made in %.0f s: <a href="%s" target="_blank" rel="noopener" class="fw-bold">open the MP4</a>. It should play in your browser — that is the file Facebook and Instagram fetch.', microtime(true) - $t, e($url)));
            } catch (Throwable $ex) {
                flash('danger', 'The test reel failed: ' . e($ex->getMessage()));
            }
            back('video');

        case 'ik_auth':
            $json(ImageKit::ready() ? ['ok' => true] + ImageKit::clientAuth() : ['ok' => false, 'error' => 'Set up ImageKit first.']);

        case 'reel':
            set_time_limit(360);
            $product   = $repo->getById((int) ($_POST['product_id'] ?? 0));
            $fileUrl   = trim((string) ($_POST['file_url'] ?? ''));
            $platforms = array_values(array_intersect(array_keys(SocialPoster::PLATFORMS), (array) ($_POST['platforms'] ?? [])));
            if (!$product || !ImageKit::owns($fileUrl)) {
                $json(['ok' => false, 'error' => 'Choose a product and make the reel first.']);
            }
            $toTikTok  = in_array('tiktok', (array) ($_POST['platforms'] ?? []), true) && TikTok::connected();
            if (!$platforms && !$toTikTok) {
                $json(['ok' => false, 'error' => 'Tick at least one platform.']);
            }
            $mp4 = VideoMaker::mp4($fileUrl, true);
            if (!$mp4['ok']) {
                $json(['ok' => false, 'error' => $mp4['error']]);
            }
            $caption = trim((string) ($_POST['caption'] ?? '')) ?: SocialPoster::caption($product, 'instagram');
            $lines = [];
            $allOk = true;
            foreach ($platforms as $platform) {
                $r = SocialPoster::publishReel($platform, $product, $mp4['url'], $caption);
                $allOk = $allOk && $r['ok'];
                $lines[] = SocialPoster::PLATFORMS[$platform] . ': ' . ($r['ok'] ? 'reel posted ✓' : $r['error']);
            }
            if ($toTikTok) {
                $r = TikTok::uploadDraft($mp4['url']);
                $allOk = $allOk && $r['ok'];
                $lines[] = 'TikTok: ' . ($r['ok'] ? 'draft sent to the TikTok inbox ✓' : $r['error']);
            }
            $json(['ok' => $allOk, 'message' => implode("\n", $lines), 'video' => $mp4['url']]);

        // Create a post: your own photos, carousel, video, or a reel made from your photos.
        case 'compose_caption':
            $product = $repo->getById((int) ($_POST['product_id'] ?? 0));
            $topic   = mb_substr(trim((string) ($_POST['topic'] ?? '')), 0, 300) ?: ($product['name'] ?? '');
            if ($topic === '') {
                $json(['ok' => false, 'error' => 'Type what the post is about, or choose a product.']);
            }
            $err = '';
            $text = Groq::ready() ? Groq::topicCaption($topic, 'instagram', $product ?: null, $err) : '';
            $json($text !== '' ? ['ok' => true, 'caption' => $text] : ['ok' => false, 'error' => Groq::ready() ? 'Groq: ' . ($err ?: 'no reply') : 'Set up Groq first.']);

        case 'compose':
            set_time_limit(600);
            $type    = in_array($_POST['type'] ?? '', ['photo', 'carousel', 'video', 'reel'], true) ? (string) $_POST['type'] : '';
            $urls    = array_values(array_filter(array_map('strval', (array) ($_POST['urls'] ?? [])), fn($u) => ImageKit::owns($u)));
            $caption = trim((string) ($_POST['caption'] ?? ''));
            $product = $repo->getById((int) ($_POST['product_id'] ?? 0));
            $wanted  = array_intersect(['facebook', 'instagram', 'tiktok'], (array) ($_POST['platforms'] ?? []));
            if ($type === '' || !$urls) {
                $json(['ok' => false, 'error' => 'Upload your files first.']);
            }
            if ($caption === '') {
                $json(['ok' => false, 'error' => 'Write a caption first.']);
            }
            if (!$wanted) {
                $json(['ok' => false, 'error' => 'Tick at least one platform.']);
            }
            // The price and shop link go under your words, worded for each platform.
            $footer = function (string $platform) use ($product): string {
                if (empty($_POST['add_link'])) {
                    return '';
                }
                if ($product) {
                    $link = SITE_URL . '/modules/shop/product.php?slug=' . urlencode((string) $product['slug']) . '&utm_source=' . $platform . '&utm_medium=social';
                    return "\n\n💰 " . format_price_short(Pricing::retail($product)) . "\n"
                        . ($platform === 'instagram' ? '🛒 Shop online: link in bio · ' . SITE_DOMAIN : '🛒 Buy online: ' . $link);
                }
                return "\n\n🛒 Shop online: " . ($platform === 'instagram' ? 'link in bio · ' . SITE_DOMAIN : SITE_URL . '/shop.php');
            };
            $lines = [];
            $allOk = true;
            $isVideo = in_array($type, ['video', 'reel'], true);
            $video = null;
            if ($isVideo) {
                // Reels made from photos have no sound track to convert; uploaded videos keep theirs.
                $video = VideoMaker::mp4($urls[0], !empty($_POST['silent']));
                if (!$video['ok']) {
                    $json(['ok' => false, 'error' => $video['error']]);
                }
            }
            foreach ($wanted as $platform) {
                if ($platform === 'tiktok') {
                    if (!$isVideo) {
                        $lines[] = 'TikTok: skipped — TikTok drafts take videos only.';
                        continue;
                    }
                    $r = TikTok::connected() ? TikTok::uploadDraft($video['url']) : ['ok' => false, 'error' => 'TikTok is not connected.'];
                    $lines[] = 'TikTok: ' . ($r['ok'] ? 'draft sent to the TikTok inbox ✓' : $r['error']);
                } else {
                    $text = $caption . $footer($platform);
                    $r = $isVideo
                        ? SocialPoster::publishVideo($platform, $video['url'], $text, $type, (int) ($product['id'] ?? 0))
                        : SocialPoster::publishPhotos($platform, $urls, $text, (int) ($product['id'] ?? 0));
                    $lines[] = SocialPoster::PLATFORMS[$platform] . ': ' . ($r['ok'] ? 'posted ✓' : $r['error']);
                }
                $allOk = $allOk && $r['ok'];
            }
            $json(['ok' => $allOk, 'message' => implode("\n", $lines)]);

        case 'content':
            foreach (['auto_articles', 'auto_reels', 'email_articles', 'auto_news'] as $k) {
                set_setting($k, !empty($_POST[$k]) ? '1' : '0');
            }
            set_setting('articles_per_day', (string) max(1, min(4, (int) ($_POST['articles_per_day'] ?? 1))));
            set_setting('reels_per_day', (string) max(1, min(12, (int) ($_POST['reels_per_day'] ?? 6))));
            set_setting('news_per_day', (string) max(1, min(6, (int) ($_POST['news_per_day'] ?? 2))));
            if (isset($_POST['news_feeds'])) {
                set_setting('news_feeds', trim((string) $_POST['news_feeds']) ?: NewsEngine::DEFAULT_FEEDS);
            }
            $key = trim((string) ($_POST['unsplash_access_key'] ?? ''));
            if ($key !== '' && !str_contains($key, '•')) {
                set_setting('unsplash_access_key', $key);
            }
            $start = max(5, min(20, (int) ($_POST['content_start_hour'] ?? 8)));
            set_setting('content_start_hour', (string) $start);
            set_setting('content_end_hour', (string) max($start + 1, min(23, (int) ($_POST['content_end_hour'] ?? 20))));
            flash('success', 'Automatic content saved. The cron job does the rest.');
            back('content');

        case 'article_now':
            set_time_limit(300);
            flash('info', e(ContentEngine::article()));
            back('content');

        case 'news_now':
            set_time_limit(300);
            flash('info', e(NewsEngine::article()));
            back('content');

        case 'reel_now':
            set_time_limit(600);
            $p = $repo->getById((int) ($_POST['product_id'] ?? 0));
            flash('info', e(ContentEngine::reel($p ?: null)));
            back('content');

        case 'tiktok':
            set_setting('tiktok_client_key', trim((string) ($_POST['tiktok_client_key'] ?? '')));
            $sec = trim((string) ($_POST['tiktok_client_secret'] ?? ''));
            if ($sec !== '' && !str_starts_with($sec, '••')) {
                set_setting('tiktok_client_secret', $sec);
            }
            set_setting('tiktok_auto', !empty($_POST['tiktok_auto']) ? '1' : '0');
            flash('success', TikTok::ready() ? 'TikTok saved. Now press Connect TikTok.' : 'TikTok saved. Fill in both keys to connect.');
            back('tiktok');

        case 'tiktok_disconnect':
            TikTok::disconnect();
            flash('success', 'Disconnected from TikTok.');
            back('tiktok');

        case 'disconnect':
            SocialPoster::disconnect();
            flash('success', 'Disconnected from Facebook and Instagram.');
            back('meta');

        case 'auto':
            set_setting('social_autopost', !empty($_POST['social_autopost']) ? '1' : '0');
            set_setting('social_autopost_hour', (string) max(6, min(21, (int) ($_POST['social_autopost_hour'] ?? 9))));
            flash('success', !empty($_POST['social_autopost']) ? 'Daily posting is on.' : 'Daily posting is off.');
            back('auto');

        case 'generate':
        case 'publish':
            $product = $repo->getById((int) ($_POST['product_id'] ?? 0));
            $platforms = array_values(array_intersect(array_keys(SocialPoster::PLATFORMS), (array) ($_POST['platforms'] ?? [])));
            if (!$product) {
                flash('danger', 'Choose a product.');
                back('post');
            }
            if ($_POST['action'] === 'generate') {
                $err = '';
                $body = Groq::ready() ? Groq::socialCaption($product, $platforms[0] ?? 'facebook', $err) : '';
                $draft = ['product' => $product, 'platforms' => $platforms ?: ['facebook'],
                          'caption' => SocialPoster::caption($product, $platforms[0] ?? 'facebook', $body), 'error' => $err];
                break;
            }
            $caption = trim((string) ($_POST['caption'] ?? '')) ?: SocialPoster::caption($product, 'facebook');
            $out = [];
            $allOk = (bool) $platforms;
            foreach ($platforms as $platform) {
                $r = SocialPoster::publish($platform, $product, $caption);
                $allOk = $allOk && $r['ok'];
                $out[] = SocialPoster::PLATFORMS[$platform] . ': ' . ($r['ok'] ? 'posted ✓' : e($r['error']));
            }
            flash($allOk ? 'success' : 'danger', $out ? implode('<br>', $out) : 'Tick at least one platform.');
            back('post');
    }
}

$groqKey   = Groq::key();
$models    = Groq::availableModels();
$meta      = SocialPoster::config();
$products  = db()->query("SELECT id, name FROM products WHERE status = 'published' ORDER BY name")->fetchAll();
$posts     = db()->query('SELECT s.*, p.name AS product FROM social_posts s LEFT JOIN products p ON p.id = s.product_id ORDER BY s.created_at DESC LIMIT 20')->fetchAll();
$mask      = fn(string $v) => $v === '' ? '' : '••••••••' . substr($v, -4);
$isLocal   = !IS_PRODUCTION;
$pages     = $_SESSION['meta_pages'] ?? [];
$ik        = ImageKit::config();

// What the reel maker draws: same-origin image paths (so the canvas can be recorded), price and a caption.
$sameOrigin = fn(?string $img) => $img ? preg_replace('~^https?://[^/]+~', '', product_image($img)) : null;
$reelData = [];
foreach ($products as $row) {
    $p = $repo->getById((int) $row['id']);
    if (!$p || empty($p['featured_image'])) { continue; }
    $reelData[] = [
        'id'      => (int) $p['id'],
        'name'    => (string) $p['name'],
        'pack'    => (string) ($p['pack_size'] ?? ''),
        'price'   => format_price_short(Pricing::retail($p)),
        'shot'    => $sameOrigin($p['featured_image']),
        'header'  => $sameOrigin($p['header_image'] ?? null),
        'points'  => array_slice(array_values(array_filter(array_map(fn($l) => trim(strip_tags($l)), preg_split('/\R/', (string) ($p['benefits'] ?? ''))))), 0, 3),
        'caption' => SocialPoster::caption($p, 'instagram'),
    ];
}
$extra_js = [asset('assets/js/reel-maker.js'), asset('assets/js/compose.js')];
?>

<div class="dash-head">
  <div><h1>AI &amp; social media</h1><p>Groq writes product descriptions, captions and side-effect summaries. Meta posts products to your Facebook Page and Instagram.</p></div>
</div>

<?= flash_html() ?>

<div class="row g-3">
  <div class="col-xl-6">
    <!-- Groq -->
    <form method="post" class="card-fc card-fc--pad mb-3" id="groq">
      <?= csrf_field() ?><input type="hidden" name="action" value="groq">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 style="font-size:1.1rem;margin:0"><i class="fa-solid fa-wand-magic-sparkles text-leaf me-1"></i> Groq AI</h2>
        <span class="pill pill--<?= Groq::ready() ? 'ok' : 'muted' ?>"><?= Groq::ready() ? 'Connected' : 'Not set up' ?></span>
      </div>
      <ol style="font-size:.86rem;color:var(--text-2);padding-left:1.1rem">
        <li>Sign up free at <a href="https://console.groq.com" target="_blank" rel="noopener">console.groq.com</a>.</li>
        <li>Open <b>API Keys</b> → <b>Create API Key</b>, name it "CureMeds", copy it.</li>
        <li>Paste it here and save — we check it straight away.</li>
      </ol>
      <div class="field"><label>API key</label>
        <input class="input-fc" name="groq_api_key" value="<?= e($mask($groqKey)) ?>" placeholder="gsk_…" autocomplete="off" spellcheck="false"></div>
      <div class="field"><label>Model</label>
        <select class="input-fc" name="groq_model">
          <?php foreach ($models ?: Groq::PREFERRED as $id => $label): ?>
            <option value="<?= e($id) ?>" <?= Groq::model() === $id ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="field__hint">Llama 3.3 70B writes best. The list shows what your key can use.</div></div>
      <div class="d-flex gap-2 align-items-center">
        <button class="btn-fc btn-fc--sm">Save &amp; test</button>
        <?php if ($groqKey !== '' && GROQ_API_KEY === ''): ?><label style="font-size:.84rem"><input type="checkbox" name="groq_remove" value="1"> Remove key</label><?php endif; ?>
      </div>
      <p class="field__hint mt-3 mb-0">Where AI is used: <b>Products → Write with AI</b>, captions below, and <b>AI summary</b> on a patient's side effects. It is told never to make medical claims — always read what it writes before publishing.</p>
    </form>

    <!-- Meta -->
    <div class="card-fc card-fc--pad mb-3" id="meta">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 style="font-size:1.1rem;margin:0"><i class="fa-brands fa-meta me-1" style="color:#0866FF"></i> Facebook &amp; Instagram</h2>
        <span class="pill pill--<?= SocialPoster::connected('facebook') ? 'ok' : 'muted' ?>"><?= SocialPoster::connected('facebook') ? 'Connected' : 'Not connected' ?></span>
      </div>

      <?php if (SocialPoster::connected('facebook')): ?>
        <div class="d-flex flex-column gap-2 mb-3" style="font-size:.9rem">
          <span><i class="fa-brands fa-facebook me-2" style="color:#0866FF"></i>Page: <b><?= e($meta['page_name']) ?></b></span>
          <span><i class="fa-brands fa-instagram me-2" style="color:#E1306C"></i>Instagram: <b><?= $meta['instagram_name'] !== '' ? '@' . e($meta['instagram_name']) : 'not linked to this Page' ?></b></span>
        </div>
        <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="disconnect">
          <a href="social-connect.php" class="btn-fc btn-fc--ghost btn-fc--sm">Reconnect</a>
          <button class="btn-fc btn-fc--ghost btn-fc--sm" onclick="return confirm('Stop posting to Facebook and Instagram?')">Disconnect</button></form>
      <?php else: ?>
        <ol style="font-size:.86rem;color:var(--text-2);padding-left:1.1rem;line-height:1.7">
          <li>Make the Instagram account a <b>Business</b> account and link it to the CureMeds Facebook Page (Meta Business Suite → Settings → Linked accounts).</li>
          <li>At <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a> → <b>Create App</b> → use case <b>Manage everything on your Page</b>.</li>
          <li><b>App settings → Basic</b>: copy the App ID and App secret into the form below. Add <code><?= e(SITE_DOMAIN) ?></code> under App domains.</li>
          <li><b>Facebook Login for Business → Settings</b> → Valid OAuth Redirect URIs → add:<br><code style="word-break:break-all"><?= e(SocialPoster::redirectUri()) ?></code></li>
          <li>Save here, then press <b>Connect with Facebook</b> and tick the CureMeds Page and Instagram account.</li>
        </ol>
        <?php if ($isLocal): ?>
          <div class="alert-fc alert-fc--info"><i class="fa-solid fa-circle-info"></i><span>Connect from the <b>live site</b> — Facebook won't send you back to localhost, and Instagram needs public https photos.</span></div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($pages): ?>
        <form method="post" action="social-connect.php" class="mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="pick">
          <label class="fw-bold mb-1">You manage several Pages — which one is CureMeds?</label>
          <div class="d-flex gap-2"><select class="input-fc" name="page"><?php foreach ($pages as $p): ?><option value="<?= e($p['id']) ?>"><?= e($p['name']) ?><?= $p['ig_name'] ? ' (@' . e($p['ig_name']) . ')' : '' ?></option><?php endforeach; ?></select>
          <button class="btn-fc btn-fc--sm">Use this Page</button></div>
        </form>
      <?php endif; ?>

      <form method="post" class="mt-3 pt-3" style="border-top:1px solid var(--line)"><?= csrf_field() ?><input type="hidden" name="action" value="meta">
        <div class="row g-2">
          <div class="col-md-6 field"><label>App ID</label><input class="input-fc" name="meta_app_id" value="<?= e($meta['app_id']) ?>" inputmode="numeric"></div>
          <div class="col-md-6 field"><label>App secret</label><input class="input-fc" name="meta_app_secret" value="<?= e($mask($meta['app_secret'])) ?>" autocomplete="off"></div>
          <div class="col-12 field"><label>Login configuration ID <span class="text-muted-3 fw-normal">(only if Facebook Login for Business asks for one)</span></label>
            <input class="input-fc" name="meta_config_id" value="<?= e($meta['config_id']) ?>" inputmode="numeric"></div>
        </div>
        <div class="d-flex gap-2">
          <button class="btn-fc btn-fc--ghost btn-fc--sm">Save app</button>
          <?php if ($meta['app_id'] !== '' && $meta['app_secret'] !== ''): ?>
            <a href="social-connect.php" class="btn-fc btn-fc--sm" style="background:#0866FF"><i class="fa-brands fa-facebook"></i> Connect with Facebook</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Video converter (ffmpeg on our server) -->
    <?php $ffReady = VideoMaker::ready(); $ffCanRun = VideoMaker::canRun(); ?>
    <div class="card-fc card-fc--pad mb-3" id="video">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 style="font-size:1.1rem;margin:0"><i class="fa-solid fa-film text-leaf me-1"></i> Video converter <span class="text-muted-3 fw-normal" style="font-size:.85rem">(MP4 for Reels &amp; Facebook)</span></h2>
        <span class="pill pill--<?= $ffReady ? 'ok' : 'warn' ?>"><?= $ffReady ? 'Ready' : 'Not installed' ?></span>
      </div>
      <p style="font-size:.86rem;color:var(--text-2)">Facebook and Instagram only accept MP4 video. The site makes it itself with <b>ffmpeg</b>, a free converter, so reels no longer use ImageKit credits. Files are kept 3 days for Meta to fetch, then deleted.</p>
      <?php if ($ffReady): ?>
        <div class="alert-fc alert-fc--success"><i class="fa-solid fa-circle-check"></i><span><?= e((string) VideoMaker::version()) ?></span></div>
        <form method="post" class="d-flex gap-2 flex-wrap align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="ffmpeg_test">
          <div class="field mb-0" style="flex:1;min-width:200px"><label style="font-size:.8rem">Test with</label>
            <select class="input-fc" name="product_id"><?php foreach ($products as $tp): ?><option value="<?= (int) $tp['id'] ?>"><?= e($tp['name']) ?></option><?php endforeach; ?></select></div>
          <button class="btn-fc btn-fc--sm"><i class="fa-solid fa-play"></i> Test video</button>
        </form>
      <?php elseif (!$ffCanRun): ?>
        <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><b>This server doesn't let PHP start programs</b> (the <code>proc_open</code> function is disabled), so ffmpeg can't run.
          In cPanel try <b>Select PHP Version → Options</b> or <b>MultiPHP INI Editor</b> and remove <code>proc_open</code> from <code>disable_functions</code>; if neither is there, ask the host's support to allow <code>proc_open</code>. Until then ImageKit converts videos (if it has credits).</span></div>
      <?php else: ?>
        <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='Installing… about a minute';"><?= csrf_field() ?><input type="hidden" name="action" value="ffmpeg_install">
          <button class="btn-fc"><i class="fa-solid fa-download"></i> Install ffmpeg</button>
          <span class="field__hint d-block mt-2">One click, no SSH needed: downloads ffmpeg 6.1 (about 29 MB) into the site's private <code>bin</code> folder, checks it is the genuine file, and test-runs it.</span>
        </form>
      <?php endif; ?>
      <details class="mt-3" style="font-size:.84rem"><summary class="text-muted-2">ffmpeg is somewhere else?</summary>
        <form method="post" class="d-flex gap-2 mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="ffmpeg_path">
          <input class="input-fc" name="ffmpeg_path" value="<?= e((string) get_setting('ffmpeg_path', '')) ?>" placeholder="/home/curemedsco/bin/ffmpeg" spellcheck="false">
          <button class="btn-fc btn-fc--ghost btn-fc--sm">Save path</button></form>
      </details>
    </div>

    <!-- ImageKit -->
    <form method="post" class="card-fc card-fc--pad mb-3" id="imagekit">
      <?= csrf_field() ?><input type="hidden" name="action" value="imagekit">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 style="font-size:1.1rem;margin:0"><i class="fa-solid fa-cloud-arrow-up text-leaf me-1"></i> ImageKit <span class="text-muted-3 fw-normal" style="font-size:.85rem">(photo &amp; reel hosting)</span></h2>
        <span class="pill pill--<?= ImageKit::ready() ? 'ok' : 'muted' ?>"><?= ImageKit::ready() ? 'Connected' : 'Not set up' ?></span>
      </div>
      <ol style="font-size:.86rem;color:var(--text-2);padding-left:1.1rem;line-height:1.7">
        <li>Sign up free at <a href="https://imagekit.io/registration" target="_blank" rel="noopener">imagekit.io</a>.</li>
        <li>Open <b>Developer options</b> (the <code>&lt;/&gt;</code> icon on the left).</li>
        <li>Copy the <b>URL endpoint</b>, <b>Public key</b> and <b>Private key</b> into the boxes below and save. We check them straight away.</li>
      </ol>
      <div class="field"><label>URL endpoint</label>
        <input class="input-fc" name="imagekit_url_endpoint" value="<?= e($ik['endpoint']) ?>" placeholder="https://ik.imagekit.io/your_id" spellcheck="false"></div>
      <div class="row g-2">
        <div class="col-md-6 field"><label>Public key</label>
          <input class="input-fc" name="imagekit_public_key" value="<?= e($ik['public_key']) ?>" placeholder="public_…" spellcheck="false"></div>
        <div class="col-md-6 field"><label>Private key</label>
          <input class="input-fc" name="imagekit_private_key" value="<?= e($mask($ik['private_key'])) ?>" placeholder="private_…" autocomplete="off" spellcheck="false"></div>
      </div>
      <button class="btn-fc btn-fc--sm">Save &amp; test</button>
      <p class="field__hint mt-3 mb-0">With ImageKit on, every photo posted to Facebook and Instagram is copied to ImageKit and sent as a 1080 × 1350 JPEG, and — only when the video converter above isn't installed — reels are turned into MP4 here.</p>
    </form>

    <!-- TikTok -->
    <?php $tt = TikTok::config(); ?>
    <form method="post" class="card-fc card-fc--pad mb-3" id="tiktok">
      <?= csrf_field() ?><input type="hidden" name="action" value="tiktok">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 style="font-size:1.1rem;margin:0"><i class="fa-brands fa-tiktok me-1"></i> TikTok <span class="text-muted-3 fw-normal" style="font-size:.85rem">(reels as drafts)</span></h2>
        <span class="pill pill--<?= TikTok::connected() ? 'ok' : 'muted' ?>"><?= TikTok::connected() ? 'Connected' . ($tt['name'] !== '' ? ' · ' . e($tt['name']) : '') : 'Not connected' ?></span>
      </div>
      <ol style="font-size:.86rem;color:var(--text-2);padding-left:1.1rem;line-height:1.7">
        <li><code>developers.tiktok.com</code> → <b>Manage apps</b> → your app → <b>Sandbox</b>: add the CureMeds TikTok account as a <b>target user</b>.</li>
        <li>Add products <b>Login Kit</b> and <b>Content Posting API</b>; scopes <code>user.info.basic</code> and <code>video.upload</code>.</li>
        <li>Login Kit → Redirect URI: <code><?= e(TikTok::redirectUri()) ?></code></li>
        <li>Copy the sandbox <b>Client key</b> and <b>Client secret</b> here, save, then press <b>Connect TikTok</b>.</li>
      </ol>
      <div class="row g-2">
        <div class="col-md-6 field"><label>Client key</label>
          <input class="input-fc" name="tiktok_client_key" value="<?= e($tt['client_key']) ?>" spellcheck="false" autocomplete="off"></div>
        <div class="col-md-6 field"><label>Client secret</label>
          <input class="input-fc" name="tiktok_client_secret" value="<?= e($mask($tt['client_secret'])) ?>" spellcheck="false" autocomplete="off"></div>
      </div>
      <label class="d-block mb-2" style="font-size:.9rem"><input type="checkbox" name="tiktok_auto" value="1" <?= get_setting('tiktok_auto', '1') === '1' ? 'checked' : '' ?>> Send every automatic reel to TikTok as a draft too</label>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn-fc btn-fc--sm">Save</button>
        <?php if (TikTok::ready()): ?><a class="btn-fc btn-fc--sm btn-fc--ghost" href="tiktok-connect.php"><i class="fa-brands fa-tiktok"></i> <?= TikTok::connected() ? 'Reconnect' : 'Connect TikTok' ?></a><?php endif; ?>
        <?php if (TikTok::connected()): ?><button class="btn-fc btn-fc--sm btn-fc--light ms-auto" name="action" value="tiktok_disconnect">Disconnect</button><?php endif; ?>
      </div>
      <p class="field__hint mt-3 mb-0">Drafts show up in the TikTok app under <b>Inbox → notifications</b>: open one, add a sound, and post it. Reels need ImageKit set up.</p>
    </form>
  </div>

  <div class="col-xl-6">
    <!-- Post now -->
    <form method="post" class="card-fc card-fc--pad mb-3" id="post">
      <?= csrf_field() ?>
      <h2 style="font-size:1.1rem">Post a product</h2>
      <div class="field"><label>Product</label>
        <select class="input-fc" name="product_id" required>
          <option value="">Choose…</option>
          <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($draft['product']['id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="d-flex gap-3 mb-3">
        <?php foreach (SocialPoster::PLATFORMS as $k => $label): ?>
          <label style="font-size:.9rem"><input type="checkbox" name="platforms[]" value="<?= $k ?>" <?= in_array($k, $draft['platforms'] ?? ['facebook', 'instagram'], true) ? 'checked' : '' ?> <?= SocialPoster::connected($k) ? '' : 'disabled' ?>> <?= $label ?></label>
        <?php endforeach; ?>
      </div>
      <?php if ($draft): ?>
        <div class="d-flex gap-3 mb-2">
          <img src="<?= e(SocialPoster::photoUrl($draft['product'])) ?>" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:var(--r)">
          <div class="text-muted-2" style="font-size:.84rem">This photo is posted with the caption.<?= $draft['error'] ? '<br><span style="color:var(--danger)">AI: ' . e($draft['error']) . ' — a template caption is shown instead.</span>' : '' ?></div>
        </div>
        <div class="field"><label>Caption <span class="text-muted-3 fw-normal">(edit freely)</span></label>
          <textarea class="input-fc" name="caption" rows="11"><?= e($draft['caption']) ?></textarea></div>
      <?php endif; ?>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn-fc btn-fc--ghost btn-fc--sm" name="action" value="generate"><i class="fa-solid fa-wand-magic-sparkles"></i> <?= Groq::ready() ? 'Write caption with AI' : 'Make caption' ?></button>
        <?php if ($draft): ?><button class="btn-fc btn-fc--sm" name="action" value="publish" onclick="return confirm('Post this now?')"><i class="fa-solid fa-paper-plane"></i> Post now</button><?php endif; ?>
      </div>
    </form>

    <!-- Create a post: your own media -->
    <div class="card-fc card-fc--pad mb-3" id="compose" data-csrf="<?= e(csrf_token()) ?>" data-domain="<?= e(SITE_DOMAIN) ?>" data-logo="<?= e($sameOrigin('assets/img/logo/curemeds-icon.png')) ?>">
      <h2 style="font-size:1.1rem">Create a post <span class="text-muted-3 fw-normal" style="font-size:.85rem">(your own photos and videos)</span></h2>
      <?php if (!ImageKit::ready()): ?>
        <div class="alert-fc alert-fc--info"><i class="fa-solid fa-circle-info"></i><span>Set up <a href="#imagekit">ImageKit</a> first: your files are uploaded there before Meta and TikTok fetch them.</span></div>
      <?php endif; ?>
      <div class="d-flex gap-2 flex-wrap mb-3" role="radiogroup" aria-label="Post type">
        <?php foreach (['photo' => ['fa-image', 'Photo'], 'carousel' => ['fa-images', 'Carousel'], 'video' => ['fa-video', 'Video'], 'reel' => ['fa-film', 'Reel from my photos']] as $k => [$icon, $label]): ?>
          <label class="chip <?= $k === 'photo' ? 'is-on' : '' ?>" style="cursor:pointer"><input type="radio" name="composeType" value="<?= $k ?>" <?= $k === 'photo' ? 'checked' : '' ?> hidden><i class="fa-solid <?= $icon ?> me-1"></i><?= $label ?></label>
        <?php endforeach; ?>
      </div>
      <p class="field__hint" id="composeHelp"></p>
      <div class="field">
        <input class="input-fc" type="file" id="composeFiles" multiple>
      </div>
      <div id="composeThumbs" class="d-flex gap-2 flex-wrap mb-3"></div>

      <div id="composeReel" hidden>
        <div class="d-flex gap-3 align-items-start flex-wrap mb-2">
          <canvas id="composeCanvas" width="1080" height="1920" style="width:160px;aspect-ratio:9/16;border-radius:12px;border:1px solid var(--line);background:#0A1B3D"></canvas>
          <div style="flex:1;min-width:220px">
            <div class="field"><label>Text on each photo <span class="text-muted-3 fw-normal">(one line per photo, in order)</span></label>
              <textarea class="input-fc" id="composeSlides" rows="5" placeholder="Feeling run down?&#10;Support your immune system every day&#10;Vitamin C + Zinc — R159"></textarea></div>
            <div class="field mb-2"><label>End card</label>
              <input class="input-fc" id="composeEnd" value="Shop online · <?= e(SITE_DOMAIN) ?>"></div>
            <label style="font-size:.88rem">Seconds per photo <input class="input-fc d-inline-block" type="number" id="composeSecs" min="2" max="6" value="3" style="width:70px;padding:.3rem .5rem"></label>
          </div>
        </div>
      </div>

      <div class="row g-2">
        <div class="col-md-6 field"><label for="composeProduct">Link a product <span class="text-muted-3 fw-normal">(optional)</span></label>
          <select class="input-fc" id="composeProduct">
            <option value="">No product</option>
            <?php foreach ($reelData as $r): ?><option value="<?= $r['id'] ?>" data-name="<?= e($r['name']) ?>" data-price="<?= e($r['price']) ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-6 field"><label for="composeSample">Sample caption</label>
          <select class="input-fc" id="composeSample"><option value="">Choose a starting point…</option></select></div>
      </div>
      <div class="field"><label for="composeCaption">Caption</label>
        <textarea class="input-fc" id="composeCaption" rows="6" placeholder="Write your caption, pick a sample, or let AI write one."></textarea></div>
      <div class="d-flex gap-2 align-items-center flex-wrap mb-2">
        <input class="input-fc" id="composeTopic" placeholder="What is the post about? e.g. winter immune tips" style="flex:1;min-width:200px">
        <button type="button" class="btn-fc btn-fc--ghost btn-fc--sm" id="composeAi" <?= Groq::ready() ? '' : 'disabled title="Set up Groq first"' ?>><i class="fa-solid fa-wand-magic-sparkles"></i> Write with AI</button>
      </div>
      <label class="d-block mb-2" style="font-size:.9rem"><input type="checkbox" id="composeLink" checked> Add the price and shop link under the caption</label>
      <div class="d-flex gap-3 mb-2 flex-wrap">
        <?php foreach (SocialPoster::PLATFORMS as $k => $label): ?>
          <label style="font-size:.9rem"><input type="checkbox" class="compose-platform" value="<?= $k ?>" <?= SocialPoster::connected($k) ? 'checked' : 'disabled' ?>> <?= $label ?></label>
        <?php endforeach; ?>
        <label style="font-size:.9rem"><input type="checkbox" class="compose-platform" value="tiktok" <?= TikTok::connected() ? '' : 'disabled' ?>> TikTok (draft, videos only)</label>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn-fc btn-fc--ghost btn-fc--sm" id="composeSampleData"><i class="fa-solid fa-flask"></i> Fill with sample data</button>
        <button type="button" class="btn-fc btn-fc--ghost btn-fc--sm" id="composeMake" hidden><i class="fa-solid fa-film"></i> Make reel</button>
        <a class="btn-fc btn-fc--ghost btn-fc--sm d-none" id="composeDownload"><i class="fa-solid fa-download"></i> Download</a>
        <button type="button" class="btn-fc btn-fc--sm" id="composePost" <?= ImageKit::ready() ? '' : 'disabled' ?>><i class="fa-solid fa-paper-plane"></i> Post</button>
      </div>
      <p class="field__hint mt-2 mb-0" id="composeStatus" style="white-space:pre-line"></p>
    </div>

    <!-- Reel maker -->
    <div class="card-fc card-fc--pad mb-3" id="reel" data-csrf="<?= e(csrf_token()) ?>" data-wa="<?= e(Health::displayPhone(whatsapp_number())) ?>" data-domain="<?= e(SITE_DOMAIN) ?>" data-logo="<?= e($sameOrigin('assets/img/logo/curemeds-icon.png')) ?>">
      <h2 style="font-size:1.1rem">Make a reel <span class="text-muted-3 fw-normal" style="font-size:.85rem">(12-second video, 9:16)</span></h2>
      <?php if (!ImageKit::ready()): ?>
        <div class="alert-fc alert-fc--info"><i class="fa-solid fa-circle-info"></i><span>Set up <a href="#imagekit">ImageKit</a> to post reels: they are uploaded there before Meta fetches them. You can still make and download a reel.</span></div>
      <?php endif; ?>
      <div class="field"><label for="reelProduct">Product</label>
        <select class="input-fc" id="reelProduct">
          <option value="">Choose…</option>
          <?php foreach ($reelData as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="d-flex gap-3 align-items-start flex-wrap">
        <canvas id="reelCanvas" width="1080" height="1920" style="width:180px;aspect-ratio:9/16;border-radius:12px;border:1px solid var(--line);background:#0A1B3D"></canvas>
        <div style="flex:1;min-width:200px">
          <div class="field"><label for="reelCaption">Caption <span class="text-muted-3 fw-normal">(edit freely)</span></label>
            <textarea class="input-fc" id="reelCaption" rows="7"></textarea></div>
          <div class="d-flex gap-3 mb-2">
            <?php foreach (SocialPoster::PLATFORMS as $k => $label): ?>
              <label style="font-size:.9rem"><input type="checkbox" class="reel-platform" value="<?= $k ?>" <?= SocialPoster::connected($k) ? 'checked' : 'disabled' ?>> <?= $label ?></label>
            <?php endforeach; ?>
            <label style="font-size:.9rem"><input type="checkbox" class="reel-platform" value="tiktok" <?= TikTok::connected() ? 'checked' : 'disabled' ?>> TikTok (draft)</label>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn-fc btn-fc--ghost btn-fc--sm" id="reelMake"><i class="fa-solid fa-film"></i> Make reel</button>
            <a class="btn-fc btn-fc--ghost btn-fc--sm d-none" id="reelDownload"><i class="fa-solid fa-download"></i> Download</a>
            <button type="button" class="btn-fc btn-fc--sm d-none" id="reelPost" <?= ImageKit::ready() ? '' : 'disabled' ?>><i class="fa-solid fa-paper-plane"></i> Post reel</button>
          </div>
          <p class="field__hint mt-2 mb-0" id="reelStatus">Keep this tab open while the reel records (about 12 seconds). Works best in Chrome or Edge.</p>
        </div>
      </div>
      <script type="application/json" id="reelData"><?= json_encode($reelData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    </div>

    <!-- Automatic content -->
    <form method="post" class="card-fc card-fc--pad mb-3" id="content">
      <?= csrf_field() ?><input type="hidden" name="action" value="content">
      <h2 style="font-size:1.1rem">Automatic content</h2>
      <p style="font-size:.86rem;color:var(--text-2)">Needs Groq (articles), ImageKit (reels) and the cron job from <a href="reminders.php">Reminders</a>. Posts are spread evenly between the hours below.</p>
      <div class="d-flex flex-column gap-2 mb-3" style="font-size:.9rem">
        <label><input type="checkbox" name="auto_articles" value="1" <?= get_setting('auto_articles', '0') === '1' ? 'checked' : '' ?>> Write and publish
          <input class="input-fc d-inline-block" type="number" min="1" max="4" name="articles_per_day" value="<?= (int) get_setting('articles_per_day', '1') ?>" style="width:70px;padding:.3rem .5rem"> article(s) a day with products, and share them</label>
        <label><input type="checkbox" name="email_articles" value="1" <?= get_setting('email_articles', '0') === '1' ? 'checked' : '' ?>> Also email each new article to newsletter subscribers</label>
        <label><input type="checkbox" name="auto_reels" value="1" <?= get_setting('auto_reels', '0') === '1' ? 'checked' : '' ?>> Post
          <input class="input-fc d-inline-block" type="number" min="1" max="12" name="reels_per_day" value="<?= (int) get_setting('reels_per_day', '6') ?>" style="width:70px;padding:.3rem .5rem"> product reels a day</label>
        <label><input type="checkbox" name="auto_news" value="1" <?= get_setting('auto_news', '0') === '1' ? 'checked' : '' ?>> Write
          <input class="input-fc d-inline-block" type="number" min="1" max="6" name="news_per_day" value="<?= (int) get_setting('news_per_day', '2') ?>" style="width:70px;padding:.3rem .5rem"> health news brief(s) a day from the feeds below, linking matching products, and share them</label>
        <span>Between
          <select class="input-fc d-inline-block" name="content_start_hour" style="width:95px;padding:.3rem .5rem"><?php for ($h = 5; $h <= 20; $h++): ?><option value="<?= $h ?>" <?= (int) get_setting('content_start_hour', '8') === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?></select>
          and <select class="input-fc d-inline-block" name="content_end_hour" style="width:95px;padding:.3rem .5rem"><?php for ($h = 6; $h <= 23; $h++): ?><option value="<?= $h ?>" <?= (int) get_setting('content_end_hour', '20') === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?></select></span>
      </div>
      <details class="mb-3">
        <summary style="cursor:pointer;font-size:.9rem;font-weight:600">News feeds &amp; Unsplash photos</summary>
        <div class="field mt-2">
          <label>Unsplash access key <span class="text-muted-3 fw-normal">(photos for news briefs)</span></label>
          <?php $uk = (string) get_setting('unsplash_access_key', ''); ?>
          <input class="input-fc" name="unsplash_access_key" value="<?= $uk !== '' ? '••••••••' . e(substr($uk, -4)) : '' ?>" autocomplete="off" spellcheck="false">
          <div class="field__hint">unsplash.com/developers → Your apps → New Application → copy the <b>Access Key</b>. Free: 50 photos an hour. Photos are credited on the article as Unsplash requires.</div>
        </div>
        <div class="field mb-0">
          <label>Feeds — one per line: <code>Label | category-slug | feed URL</code></label>
          <textarea class="input-fc" name="news_feeds" rows="7" style="font-size:.78rem;font-family:monospace" spellcheck="false"><?= e((string) get_setting('news_feeds', NewsEngine::DEFAULT_FEEDS)) ?></textarea>
          <div class="field__hint">The category slug picks which products the brief links to. Any RSS feed works; for a new topic use <code>https://news.google.com/rss/search?q=your+words&amp;hl=en-ZA&amp;gl=ZA&amp;ceid=ZA:en</code>. Each brief credits and links its source and never claims a product treats anything.</div>
        </div>
      </details>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn-fc btn-fc--sm">Save</button>
        <button class="btn-fc btn-fc--ghost btn-fc--sm" name="action" value="news_now" onclick="return confirm('Write and publish a news brief now?')"><i class="fa-solid fa-rss"></i> News brief now</button>
        <button class="btn-fc btn-fc--ghost btn-fc--sm" name="action" value="article_now" onclick="return confirm('Write and publish an article now?')"><i class="fa-solid fa-newspaper"></i> Article now</button>
        <button class="btn-fc btn-fc--ghost btn-fc--sm" name="action" value="reel_now" onclick="return confirm('Build and post a reel now? It takes 1-3 minutes.')"><i class="fa-solid fa-film"></i> Reel now</button>
      </div>
    </form>

    <form method="post" class="card-fc card-fc--pad mb-3" id="auto">
      <?= csrf_field() ?><input type="hidden" name="action" value="auto">
      <h2 style="font-size:1.1rem">Daily automatic post</h2>
      <p style="font-size:.86rem;color:var(--text-2)">Once a day the site picks the product posted longest ago, writes a caption with AI and posts it to everything connected. Needs the cron job from <a href="reminders.php">Reminders</a>.</p>
      <div class="d-flex gap-3 align-items-center flex-wrap">
        <label style="font-size:.9rem"><input type="checkbox" name="social_autopost" value="1" <?= get_setting('social_autopost', '0') === '1' ? 'checked' : '' ?>> Post one product a day at</label>
        <select class="input-fc" name="social_autopost_hour" style="max-width:110px">
          <?php for ($h = 6; $h <= 21; $h++): ?><option value="<?= $h ?>" <?= (int) get_setting('social_autopost_hour', '9') === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?>
        </select>
        <button class="btn-fc btn-fc--sm">Save</button>
      </div>
    </form>

    <div class="card-fc">
      <h2 style="font-size:1.05rem" class="p-3 pb-0">Recent posts</h2>
      <?php if (!$posts): ?><p class="text-muted-2 p-3 mb-0">Nothing posted yet.</p><?php else: ?>
      <table class="table-fc"><tbody>
        <?php foreach ($posts as $p): ?>
          <tr><td style="font-size:.82rem;white-space:nowrap"><?= date('j M H:i', strtotime($p['created_at'])) ?></td>
            <td><i class="fa-brands fa-<?= $p['platform'] === 'instagram' ? 'instagram' : 'facebook' ?>"></i> <?= e((string) $p['product']) ?><?= in_array($p['media_type'] ?? '', ['reel', 'article'], true) ? ' <span class="pill pill--muted">' . e($p['media_type']) . '</span>' : '' ?>
              <?php if ($p['error']): ?><div style="font-size:.75rem;color:var(--danger)"><?= e($p['error']) ?></div><?php endif; ?></td>
            <td><span class="pill pill--<?= $p['status'] === 'posted' ? 'ok' : 'danger' ?>"><?= $p['status'] ?></span></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
