<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'newsletter';
$page_title  = 'Compose campaign — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';
require_once __DIR__ . '/_newsletter-form.php';

NewsletterService::ensureSchema();

$catalog  = NewsletterTemplates::catalog();
$id       = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$campaign = $id ? NewsletterService::find($id) : null;

if ($id && !$campaign) {
    $_SESSION['nl_flash'] = ['warn', 'That campaign no longer exists.'];
    redirect('dashboards/admin/newsletter.php');
}
if ($campaign && $campaign['status'] !== 'draft') {
    $_SESSION['nl_flash'] = ['warn', 'A campaign that has been sent cannot be edited. Duplicate it to send a new version.'];
    redirect('dashboards/admin/newsletter.php');
}

$values = nl_form_values($campaign);
$errors = [];
$flash  = $_SESSION['nl_flash'] ?? null;
unset($_SESSION['nl_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? 'save');
    $parsed = nl_campaign_from_input($_POST);
    $errors = $parsed['errors'];

    // Keep what was typed, so an error never costs the admin their work.
    $values = array_merge($values, array_intersect_key($_POST, $values));
    $values['id'] = $id;

    if (!$errors) {
        if ($campaign) {
            db_update('newsletter_campaigns', $id, $parsed['row']);
        } else {
            $id = db_insert('newsletter_campaigns', $parsed['row'] + ['status' => 'draft', 'created_by' => (int) $user['id']]);
        }

        if ($action === 'test') {
            $to = safe_email((string) ($_POST['test_email'] ?? '')) ?: '';
            if ($to === '') {
                $_SESSION['nl_flash'] = ['warn', 'Draft saved, but the test address does not look right.'];
            } elseif (NewsletterService::sendTest($id, $to)) {
                $_SESSION['nl_flash'] = ['ok', "Draft saved and a test sent to $to. Check the inbox — and the spam folder."];
            } else {
                $_SESSION['nl_flash'] = ['warn', "Draft saved, but the test to $to could not be sent. Check the mail settings in config/config.php."];
            }
            redirect('dashboards/admin/newsletter-compose.php?id=' . $id);
        }

        if ($action === 'send') {
            $n = NewsletterService::queue($id);
            $_SESSION['nl_flash'] = $n > 0
                ? ['ok', "Sending to $n subscriber" . ($n === 1 ? '' : 's') . '. Keep this page open until it finishes.']
                : ['warn', 'Saved, but nobody in that audience is subscribed yet — nothing was sent.'];
            if ($n === 0) {
                db_update('newsletter_campaigns', $id, ['status' => 'draft']);
            }
            redirect('dashboards/admin/newsletter.php');
        }

        $_SESSION['nl_flash'] = ['ok', 'Draft saved.'];
        redirect('dashboards/admin/newsletter-compose.php?id=' . $id);
    }
}

$audiences = [
    'all'      => (int) db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active'")->fetchColumn(),
    'retail'   => (int) db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active' AND audience IN ('all','retail')")->fetchColumn(),
    'reseller' => (int) db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active' AND audience IN ('all','reseller')")->fetchColumn(),
];

$products = db()->query(
    "SELECT p.id, p.name, p.pack_size, p.retail_price, p.sale_price, p.featured_image, p.stock_status,
            p.is_bestseller, p.is_new, p.is_sale, b.name AS brand_name
     FROM products p LEFT JOIN brands b ON b.id = p.brand_id
     WHERE p.status = 'published'
     ORDER BY p.is_bestseller DESC, p.sold_count DESC, p.name"
)->fetchAll();

$coupons = db()->query("SELECT code, description, discount_type, discount_value, min_order, expiry_date, status FROM coupons ORDER BY status, code")->fetchAll();

$picked = array_filter(array_map('intval', explode(',', (string) $values['product_ids'])));
?>

<div class="dash-head">
  <div>
    <h1><?= $campaign ? 'Edit draft' : 'New campaign' ?></h1>
    <p>Pick a template, choose what to feature, and check the preview before anything is sent.</p>
  </div>
  <a href="newsletter.php" class="btn-fc btn-fc--light"><i class="fa-solid fa-arrow-left"></i> All campaigns</a>
</div>

<?php $nl_tab = $campaign ? 'campaigns' : 'compose'; include __DIR__ . '/_newsletter-nav.php'; ?>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= e($flash[0]) ?>"><i class="fa-solid fa-circle-info"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="alert-fc alert-fc--warn">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span><b>Nothing was saved yet.</b> <?= e(implode(' ', $errors)) ?></span>
  </div>
<?php endif; ?>

<form method="post" class="nl-compose" id="nlCompose" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <input type="hidden" name="product_ids" id="nlProductIds" value="<?= e((string) $values['product_ids']) ?>">

  <div class="nl-compose__form">

    <!-- 1. Template -->
    <section class="card-fc card-fc--pad">
      <h2 class="nl-step"><span>1</span> Choose a template</h2>
      <div class="nl-templates" role="radiogroup" aria-label="Template">
        <?php foreach ($catalog as $key => $t): ?>
          <label class="nl-template">
            <input type="radio" name="template" value="<?= e($key) ?>" <?= $values['template'] === $key ? 'checked' : '' ?>>
            <span class="nl-template__card">
              <i class="fa-solid <?= e($t['icon']) ?>"></i>
              <b><?= e($t['label']) ?></b>
              <small><?= e($t['blurb']) ?></small>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 2. Envelope -->
    <section class="card-fc card-fc--pad">
      <h2 class="nl-step"><span>2</span> Subject and audience</h2>
      <div class="field">
        <label for="subject">Subject line</label>
        <input class="input-fc" type="text" id="subject" name="subject" maxlength="255" required
               value="<?= e((string) $values['subject']) ?>" placeholder="Lady Bloom is back — and there's more of it">
        <div class="field__hint" data-nl-subject-hint>Under 50 characters reads in full on a phone.</div>
      </div>
      <div class="field">
        <label for="preheader">Preview text <span class="text-muted-3 fw-normal">(optional)</span></label>
        <input class="input-fc" type="text" id="preheader" name="preheader" maxlength="200"
               value="<?= e((string) $values['preheader']) ?>" placeholder="The grey line after the subject in the inbox">
        <div class="field__hint">Inbox apps show this next to the subject. Leave it blank and they show the first words of the email instead.</div>
      </div>
      <div class="field mb-0">
        <label for="audience">Send to</label>
        <select class="select-fc" id="audience" name="audience">
          <option value="all" <?= $values['audience'] === 'all' ? 'selected' : '' ?>>Everyone — <?= number_format($audiences['all']) ?> subscribers</option>
          <option value="retail" <?= $values['audience'] === 'retail' ? 'selected' : '' ?>>Shoppers — <?= number_format($audiences['retail']) ?></option>
          <option value="reseller" <?= $values['audience'] === 'reseller' ? 'selected' : '' ?>>Resellers — <?= number_format($audiences['reseller']) ?></option>
        </select>
      </div>
    </section>

    <!-- 3. Content -->
    <section class="card-fc card-fc--pad">
      <h2 class="nl-step"><span>3</span> Content</h2>

      <div class="row g-2">
        <div class="col-sm-4 field" data-field="eyebrow">
          <label for="eyebrow">Label</label>
          <input class="input-fc" type="text" id="eyebrow" name="eyebrow" maxlength="60" value="<?= e((string) $values['eyebrow']) ?>" placeholder="New this week">
        </div>
        <div class="col-sm-8 field" data-field="heading">
          <label for="heading">Heading</label>
          <input class="input-fc" type="text" id="heading" name="heading" maxlength="160" value="<?= e((string) $values['heading']) ?>">
        </div>
      </div>

      <div class="field" data-field="intro">
        <label for="intro">Message</label>
        <textarea class="textarea-fc" id="intro" name="intro" rows="4" maxlength="3000"
                  placeholder="A line or two. Each new line becomes a paragraph."><?= e((string) $values['intro']) ?></textarea>
      </div>

      <div class="row g-2" data-field="coupon">
        <div class="col-sm-6 field">
          <label for="coupon_code">Coupon code</label>
          <select class="select-fc" id="coupon_code" name="coupon_code">
            <option value="">No code</option>
            <?php foreach ($coupons as $c):
              $label = $c['discount_type'] === 'percentage'
                  ? rtrim(rtrim(number_format((float) $c['discount_value'], 2), '0'), '.') . '% off'
                  : format_price_short((float) $c['discount_value']) . ' off'; ?>
              <option value="<?= e($c['code']) ?>" <?= $values['coupon_code'] === $c['code'] ? 'selected' : '' ?>
                      <?= $c['status'] !== 'active' ? 'disabled' : '' ?>>
                <?= e($c['code']) ?> — <?= e($label) ?><?= $c['status'] !== 'active' ? ' (inactive)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="field__hint">Make a new code under <a href="coupons.php">Coupons</a>.</div>
        </div>
        <div class="col-sm-6 field" data-field="sale_note">
          <label for="sale_note">Small print under the code</label>
          <input class="input-fc" type="text" id="sale_note" name="sale_note" maxlength="160" value="<?= e((string) $values['sale_note']) ?>" placeholder="Ends Sunday at midnight.">
        </div>
      </div>

      <div class="field" data-field="products">
        <label>Products <span class="text-muted-3 fw-normal" data-nl-pick-rule></span></label>
        <div class="nl-picker" id="nlPicker">
          <div class="nl-picker__bar">
            <input class="input-fc" type="search" placeholder="Search products…" data-nl-search aria-label="Search products">
            <div class="nl-picker__quick">
              <button type="button" class="chip" data-nl-quick="bestseller"><i class="fa-solid fa-fire"></i> Best sellers</button>
              <button type="button" class="chip" data-nl-quick="new"><i class="fa-solid fa-seedling"></i> New</button>
              <button type="button" class="chip" data-nl-quick="sale"><i class="fa-solid fa-tag"></i> On sale</button>
              <button type="button" class="chip" data-nl-quick="clear">Clear</button>
            </div>
          </div>
          <div class="nl-picker__list" role="group" aria-label="Products">
            <?php foreach ($products as $p):
              $flags = trim(($p['is_bestseller'] ? 'bestseller ' : '') . ($p['is_new'] ? 'new ' : '') . (($p['is_sale'] || ((float) $p['sale_price'] > 0 && (float) $p['sale_price'] < (float) $p['retail_price'])) ? 'sale' : ''));
              $price = ((float) $p['sale_price'] > 0 && (float) $p['sale_price'] < (float) $p['retail_price']) ? (float) $p['sale_price'] : (float) $p['retail_price']; ?>
              <label class="nl-pick" data-id="<?= (int) $p['id'] ?>" data-flags="<?= e($flags) ?>"
                     data-search="<?= e(mb_strtolower($p['name'] . ' ' . $p['brand_name'])) ?>">
                <input type="checkbox" value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $picked, true) ? 'checked' : '' ?>>
                <img src="<?= e(product_image($p['featured_image'])) ?>" alt="" loading="lazy">
                <span class="nl-pick__name">
                  <b><?= e($p['name']) ?></b>
                  <small><?= e((string) $p['brand_name']) ?><?= $p['pack_size'] ? ' · ' . e($p['pack_size']) : '' ?>
                    <?php if ($p['stock_status'] === 'out_of_stock'): ?> · <span style="color:var(--danger)">Out of stock</span><?php endif; ?>
                  </small>
                </span>
                <span class="nl-pick__price"><?= format_price_short($price) ?></span>
                <span class="nl-pick__order" aria-hidden="true"></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="nl-picker__foot"><span data-nl-pick-count>0 picked</span> · shown in the order you tick them</div>
        </div>
      </div>

      <div class="row g-2" data-field="cta">
        <div class="col-sm-5 field mb-0">
          <label for="cta_label">Button text</label>
          <input class="input-fc" type="text" id="cta_label" name="cta_label" maxlength="60" value="<?= e((string) $values['cta_label']) ?>" placeholder="Shop now">
        </div>
        <div class="col-sm-7 field mb-0">
          <label for="cta_url">Button link <span class="text-muted-3 fw-normal">(optional)</span></label>
          <input class="input-fc" type="text" id="cta_url" name="cta_url" maxlength="255" value="<?= e((string) $values['cta_url']) ?>" placeholder="/shop.php?brand=blossom">
          <div class="field__hint">Blank goes to the product (spotlight) or the shop.</div>
        </div>
      </div>

      <div class="field mb-0" data-field="body_html">
        <label for="body_html">Body <span class="text-muted-3 fw-normal">(HTML)</span></label>
        <textarea class="textarea-fc" id="body_html" name="body_html" rows="12"
                  style="min-height:260px;font-family:var(--font-mono);font-size:.82rem"
                  placeholder="&lt;h1&gt;Hello&lt;/h1&gt;&#10;&lt;p&gt;…&lt;/p&gt;"><?= e((string) $values['body_html']) ?></textarea>
        <div class="field__hint">Wrapped in the CureMeds header and footer, with an unsubscribe link. Scripts are removed.</div>
      </div>
    </section>

    <!-- 4. Send -->
    <section class="card-fc card-fc--pad nl-actions">
      <h2 class="nl-step"><span>4</span> Test, then send</h2>
      <div class="nl-actions__test">
        <input class="input-fc" type="email" name="test_email" value="<?= e((string) $user['email']) ?>" aria-label="Send a test to">
        <button class="btn-fc btn-fc--light" type="submit" name="action" value="test">
          <i class="fa-solid fa-flask"></i> Send me a test
        </button>
      </div>
      <div class="nl-actions__main">
        <button class="btn-fc btn-fc--ghost" type="submit" name="action" value="save">
          <i class="fa-solid fa-floppy-disk"></i> Save draft
        </button>
        <button class="btn-fc" type="submit" name="action" value="send" data-nl-send>
          <i class="fa-solid fa-paper-plane"></i> Send to <span data-nl-audience-count><?= number_format($audiences[$values['audience']] ?? $audiences['all']) ?></span>
        </button>
      </div>
    </section>
  </div>

  <!-- Live preview -->
  <aside class="nl-compose__preview" aria-label="Email preview">
    <div class="nl-preview">
      <div class="nl-preview__bar">
        <b>Preview</b>
        <div class="nl-preview__size" role="group" aria-label="Preview width">
          <button type="button" class="is-on" data-nl-width="600"><i class="fa-solid fa-desktop"></i></button>
          <button type="button" data-nl-width="375"><i class="fa-solid fa-mobile-screen"></i></button>
        </div>
      </div>
      <div class="nl-inbox" aria-label="How it looks in the inbox">
        <span class="nl-inbox__avatar">F</span>
        <span class="nl-inbox__text">
          <b>CureMeds</b>
          <span><b data-nl-inbox-subject>Your subject line</b> <span data-nl-inbox-pre></span></span>
        </span>
      </div>
      <div class="nl-preview__frame">
        <iframe id="nlFrame" title="Email preview" sandbox="allow-same-origin"></iframe>
        <span class="nl-preview__loading" data-nl-loading><i class="fa-solid fa-circle-notch fa-spin"></i></span>
      </div>
    </div>
  </aside>
</form>

<script>
(function () {
  const form     = document.getElementById('nlCompose');
  const catalog  = <?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const counts   = <?= json_encode($audiences) ?>;
  const idsInput = document.getElementById('nlProductIds');
  const picker   = document.getElementById('nlPicker');
  const frame    = document.getElementById('nlFrame');
  const loading  = document.querySelector('[data-nl-loading]');
  const isNew    = <?= $campaign ? 'false' : 'true' ?>;

  /* ── Template: which fields show, how many products ─────────────── */
  let lastDefaults = {};

  function currentTemplate() {
    return form.querySelector('input[name="template"]:checked').value;
  }

  function applyTemplate(fillDefaults) {
    const spec = catalog[currentTemplate()];
    form.querySelectorAll('[data-field]').forEach((el) => {
      el.hidden = !spec.fields.includes(el.dataset.field);
    });

    // Swap in the new template's suggested wording, but never over something the admin typed.
    if (fillDefaults) {
      Object.entries(spec.defaults || {}).forEach(([k, v]) => {
        const input = form.elements[k];
        if (input && (input.value === '' || input.value === lastDefaults[k])) input.value = v;
      });
    }
    lastDefaults = spec.defaults || {};

    const rule = form.querySelector('[data-nl-pick-rule]');
    rule.textContent = spec.max === 0 ? '' : spec.min === spec.max
      ? '— pick ' + spec.min
      : '— pick ' + (spec.min || 'up to') + (spec.min ? ' to ' : ' ') + spec.max;
    syncPicks();
  }

  form.querySelectorAll('input[name="template"]').forEach((r) =>
    r.addEventListener('change', () => { applyTemplate(true); schedulePreview(); })
  );

  /* ── Product picker, in tick order ──────────────────────────────── */
  let order = idsInput.value.split(',').map(Number).filter(Boolean);

  function syncPicks() {
    const spec = catalog[currentTemplate()];
    const max = spec.max;
    // A template with room for fewer products keeps the first ones picked.
    if (max > 0 && order.length > max) order = order.slice(0, max);
    idsInput.value = order.join(',');

    picker.querySelectorAll('.nl-pick').forEach((row) => {
      const id = Number(row.dataset.id);
      const pos = order.indexOf(id);
      const box = row.querySelector('input');
      box.checked = pos !== -1;
      row.classList.toggle('is-picked', pos !== -1);
      row.querySelector('.nl-pick__order').textContent = pos !== -1 ? pos + 1 : '';
      // Once full, the rest can't be ticked — except for a single-product template,
      // where ticking another simply swaps it in.
      box.disabled = pos === -1 && max > 1 && order.length >= max;
    });
    const n = order.length;
    picker.querySelector('[data-nl-pick-count]').textContent =
      n + ' picked' + (max ? ' of ' + (spec.min === max ? max : 'up to ' + max) : '');
  }

  picker.addEventListener('change', (e) => {
    if (!e.target.matches('.nl-pick input')) return;
    const id = Number(e.target.value);
    const spec = catalog[currentTemplate()];
    if (e.target.checked) {
      if (spec.max === 1) order = [id]; else order.push(id);
    } else {
      order = order.filter((x) => x !== id);
    }
    syncPicks();
    schedulePreview();
  });

  picker.querySelector('[data-nl-search]').addEventListener('input', (e) => {
    const q = e.target.value.trim().toLowerCase();
    picker.querySelectorAll('.nl-pick').forEach((row) => {
      row.hidden = q !== '' && !row.dataset.search.includes(q);
    });
  });

  picker.querySelectorAll('[data-nl-quick]').forEach((btn) => btn.addEventListener('click', () => {
    const kind = btn.dataset.nlQuick;
    const max = catalog[currentTemplate()].max || 6;
    if (kind === 'clear') {
      order = [];
    } else {
      order = Array.from(picker.querySelectorAll('.nl-pick'))
        .filter((row) => row.dataset.flags.split(' ').includes(kind))
        .map((row) => Number(row.dataset.id))
        .slice(0, max);
    }
    syncPicks();
    schedulePreview();
  }));

  /* ── Subject hint, inbox line, audience count ───────────────────── */
  const subject = form.elements.subject, pre = form.elements.preheader;
  function syncEnvelope() {
    const n = subject.value.length;
    const hint = form.querySelector('[data-nl-subject-hint]');
    hint.textContent = n === 0 ? 'Under 50 characters reads in full on a phone.'
      : n <= 50 ? n + ' characters — reads in full on a phone.'
      : n + ' characters — phones will cut this short.';
    hint.style.color = n > 50 ? 'var(--warn)' : '';
    document.querySelector('[data-nl-inbox-subject]').textContent = subject.value || 'Your subject line';
    document.querySelector('[data-nl-inbox-pre]').textContent = pre.value ? '— ' + pre.value : '';
    document.querySelector('[data-nl-audience-count]').textContent =
      (counts[form.elements.audience.value] || 0).toLocaleString();
  }
  [subject, pre, form.elements.audience].forEach((el) => el.addEventListener('input', syncEnvelope));
  form.elements.audience.addEventListener('change', syncEnvelope);

  /* ── Live preview ───────────────────────────────────────────────── */
  let timer = null, inflight = null;
  function schedulePreview() {
    clearTimeout(timer);
    timer = setTimeout(renderPreview, 350);
  }

  async function renderPreview() {
    if (inflight) inflight.abort();
    inflight = new AbortController();
    loading.hidden = false;
    try {
      const res = await fetch('newsletter-preview.php', { method: 'POST', body: new FormData(form), signal: inflight.signal });
      frame.srcdoc = await res.text();
    } catch (e) {
      if (e.name !== 'AbortError') loading.hidden = true;
    }
  }

  frame.addEventListener('load', () => {
    loading.hidden = true;
    const doc = frame.contentDocument;
    if (!doc) return;
    frame.style.height = doc.documentElement.scrollHeight + 'px';
    // Links in the preview would load the whole site inside the frame; keep it a picture.
    doc.addEventListener('click', (e) => { if (e.target.closest('a')) e.preventDefault(); });
  });

  form.addEventListener('input', (e) => {
    if (e.target.matches('[data-nl-search]') || e.target.name === 'test_email') return;
    schedulePreview();
  });

  document.querySelectorAll('[data-nl-width]').forEach((btn) => btn.addEventListener('click', () => {
    document.querySelectorAll('[data-nl-width]').forEach((b) => b.classList.toggle('is-on', b === btn));
    frame.style.width = btn.dataset.nlWidth + 'px';
    setTimeout(() => {
      if (frame.contentDocument) frame.style.height = frame.contentDocument.documentElement.scrollHeight + 'px';
    }, 50);
  }));

  /* ── Sending needs a deliberate yes ─────────────────────────────── */
  form.querySelector('[data-nl-send]').addEventListener('click', (e) => {
    const n = counts[form.elements.audience.value] || 0;
    const msg = n === 0
      ? 'Nobody in this audience is subscribed yet. Save it anyway?'
      : 'Send "' + (subject.value || 'this campaign') + '" to ' + n.toLocaleString() + ' subscriber' + (n === 1 ? '' : 's') + ' now?\n\nThis cannot be undone.';
    if (!confirm(msg)) e.preventDefault();
  });

  applyTemplate(isNew);
  syncEnvelope();
  renderPreview();
})();
</script>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
