<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'social';
$page_title  = 'AI & social media — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$repo = new ProductRepository();
$draft = null;

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
            set_setting('meta_app_id', preg_replace('/\D/', '', (string) ($_POST['meta_app_id'] ?? '')) ?? '');
            $secret = trim((string) ($_POST['meta_app_secret'] ?? ''));
            if ($secret !== '' && !str_starts_with($secret, '••')) {
                set_setting('meta_app_secret', $secret);
            }
            set_setting('meta_config_id', preg_replace('/\D/', '', (string) ($_POST['meta_config_id'] ?? '')) ?? '');
            flash('success', 'Meta app saved. Now press Connect with Facebook.');
            back('meta');

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
            <td><i class="fa-brands fa-<?= $p['platform'] === 'instagram' ? 'instagram' : 'facebook' ?>"></i> <?= e((string) $p['product']) ?>
              <?php if ($p['error']): ?><div style="font-size:.75rem;color:var(--danger)"><?= e($p['error']) ?></div><?php endif; ?></td>
            <td><span class="pill pill--<?= $p['status'] === 'posted' ? 'ok' : 'danger' ?>"><?= $p['status'] ?></span></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
