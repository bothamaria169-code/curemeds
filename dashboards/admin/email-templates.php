<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'emails';
$page_title  = 'Email templates — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$flash   = null;
$preview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $action  = (string) ($_POST['action'] ?? '');
    $subject = mb_substr(trim(strip_tags((string) ($_POST['subject'] ?? ''))), 0, 250);
    $body    = trim(strip_tags((string) ($_POST['body'] ?? '')));

    if ($action === 'save_template') {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_POST['slug'] ?? ''))) ?: '';
        $name = mb_substr(clean_text((string) ($_POST['name'] ?? '')), 0, 120);
        if ($slug === '' && $name !== '') {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        }
        if ($slug === '' || $subject === '' || $body === '') {
            $flash = ['danger', 'A template needs a name, a subject and a message.'];
        } else {
            db()->prepare('INSERT INTO email_templates (slug, name, subject, body) VALUES (:s, :n, :sub, :b)
                           ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), name = IF(VALUES(name) = "", name, VALUES(name))')
                ->execute([':s' => $slug, ':n' => $name, ':sub' => $subject, ':b' => $body]);
            $flash = ['ok', 'Template saved.'];
        }
    } elseif ($action === 'delete_template') {
        db()->prepare('DELETE FROM email_templates WHERE slug = :s AND is_system = 0')->execute([':s' => (string) ($_POST['slug'] ?? '')]);
        $flash = ['ok', 'Template deleted.'];
    } elseif ($action === 'reset_template') {
        $slug = (string) ($_POST['slug'] ?? '');
        if ($d = default_email_templates()[$slug] ?? null) {
            db()->prepare('UPDATE email_templates SET subject = :sub, body = :b WHERE slug = :s')->execute([':sub' => $d['subject'], ':b' => $d['body'], ':s' => $slug]);
            $flash = ['ok', 'Template put back to the original wording.'];
        }
    } elseif ($action === 'send' || $action === 'preview') {
        $to   = trim((string) ($_POST['to'] ?? ''));
        $vars = ['name' => clean_text((string) ($_POST['to_name'] ?? '')) ?: 'there', 'reference' => clean_text((string) ($_POST['reference'] ?? ''))];
        $m    = render_email_template($subject, $body, $vars);
        if ($action === 'preview' || $subject === '' || $body === '') {
            $preview = $m;
        } elseif ($to === 'subscribers') {
            // Everyone on the newsletter list, sent in batches by the cron job, each with their unsubscribe link.
            if (NewsletterService::ensureSchema()) {
                $id = db_insert('newsletter_campaigns', [
                    'subject' => $m['subject'], 'body_html' => $m['inner'], 'audience' => 'all', 'status' => 'draft',
                    'created_by' => (int) $user['id'], 'template' => 'custom', 'template_data' => json_encode(['body_html' => $m['inner']]),
                ]);
                $n = NewsletterService::queue($id);
                $flash = ['ok', "Queued for {$n} subscribers. The cron job sends 40 every 5 minutes — progress is under Newsletter."];
            }
        } else {
            $list = array_filter(array_map('trim', preg_split('/[\s,;]+/', $to) ?: []), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL));
            $sent = 0;
            foreach (array_slice($list, 0, 50) as $addr) {
                $sent += Mailer::send($addr, $m['subject'], $m['html'], $m['text']) ? 1 : 0;
            }
            $flash = $list
                ? [$sent ? 'ok' : 'danger', "Sent to {$sent} of " . count($list) . ' address' . (count($list) === 1 ? '' : 'es') . '.']
                : ['danger', 'Type at least one email address, or choose "All newsletter subscribers".'];
        }
    } elseif ($action === 'auto') {
        set_setting('auto_product_newsletter', !empty($_POST['auto_product_newsletter']) ? '1' : '0');
        set_setting('email_articles', !empty($_POST['email_articles']) ? '1' : '0');
        set_setting('product_newsletter_day', (string) max(1, min(7, (int) ($_POST['product_newsletter_day'] ?? 2))));
        $flash = ['ok', 'Automatic newsletters saved.'];
    }
}

$templates = db()->query('SELECT * FROM email_templates ORDER BY is_system DESC, name')->fetchAll();
$edit      = null;
foreach ($templates as $t) {
    if ($t['slug'] === ($_GET['edit'] ?? '')) { $edit = $t; }
}
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$subs = NewsletterService::ensureSchema() ? count(NewsletterService::activeSubscribers()) : 0;
?>

<div class="dash-head">
  <div><h1>Email templates</h1><p>The emails the site sends by itself, and a place to write your own. Placeholders: <code>{{name}}</code> <code>{{reference}}</code> <code>{{items}}</code> <code>{{total}}</code> <code>{{payment_details}}</code> <code>{{whatsapp}}</code> <code>{{site_url}}</code>. Put <code>{{payment_details}}</code> on its own line to show the bank and cash-on-delivery card.</p></div>
</div>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= $flash[0] ?>"><i class="fa-solid fa-circle-<?= $flash[0] === 'ok' ? 'check' : 'exclamation' ?>"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-7">
    <!-- Write an email -->
    <form method="post" class="card-fc card-fc--pad mb-3" id="write">
      <?= csrf_field() ?>
      <h2 style="font-size:1.1rem"><i class="fa-solid fa-pen-to-square text-leaf me-1"></i> Write an email</h2>
      <div class="field"><label for="tpl">Start from a template</label>
        <select class="select-fc" id="tpl">
          <option value="">—</option>
          <?php foreach ($templates as $t): ?><option value="<?= e($t['slug']) ?>" data-subject="<?= e($t['subject']) ?>" data-body="<?= e($t['body']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="row g-2">
        <div class="col-md-7 field"><label for="to">To</label>
          <input class="input-fc" id="to" name="to" list="toList" value="<?= e((string) ($_POST['to'] ?? '')) ?>" placeholder="name@example.com (several: separate with commas)">
          <datalist id="toList"><option value="subscribers">All newsletter subscribers (<?= $subs ?>)</option></datalist>
          <div class="field__hint">Type <code>subscribers</code> to send to the whole newsletter list.</div></div>
        <div class="col-md-3 field"><label for="to_name">Their name</label>
          <input class="input-fc" id="to_name" name="to_name" value="<?= e((string) ($_POST['to_name'] ?? '')) ?>" placeholder="Thandi"></div>
        <div class="col-md-2 field"><label for="reference">Ref</label>
          <input class="input-fc" id="reference" name="reference" value="<?= e((string) ($_POST['reference'] ?? '')) ?>" placeholder="E12"></div>
      </div>
      <div class="field"><label for="subject">Subject</label>
        <input class="input-fc" id="subject" name="subject" value="<?= e((string) ($_POST['subject'] ?? '')) ?>" required></div>
      <div class="field"><label for="body">Message</label>
        <textarea class="textarea-fc" id="body" name="body" rows="12" required><?= e((string) ($_POST['body'] ?? '')) ?></textarea>
        <div class="field__hint">Leave a blank line between paragraphs. Links are made clickable.</div></div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn-fc" name="action" value="send" onclick="return confirm('Send this email now?')"><i class="fa-solid fa-paper-plane"></i> Send</button>
        <button class="btn-fc btn-fc--light" name="action" value="preview" formnovalidate><i class="fa-solid fa-eye"></i> Preview</button>
        <button class="btn-fc btn-fc--light" name="action" value="save_template" onclick="const n = this.form.elements['name']; n.value = prompt('Save as a template called:', '') || ''; return n.value !== ''"><i class="fa-solid fa-floppy-disk"></i> Save as template</button>
        <input type="hidden" name="name" value="">
      </div>
    </form>

    <?php if ($preview): ?>
    <div class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1rem">Preview — <?= e($preview['subject']) ?></h2>
      <iframe srcdoc="<?= e($preview['html']) ?>" style="width:100%;height:560px;border:1px solid var(--line);border-radius:10px;background:#fff" title="Email preview"></iframe>
    </div>
    <?php endif; ?>

    <!-- Automatic newsletters -->
    <form method="post" class="card-fc card-fc--pad" id="auto">
      <?= csrf_field() ?><input type="hidden" name="action" value="auto">
      <h2 style="font-size:1.1rem"><i class="fa-solid fa-robot text-leaf me-1"></i> Automatic newsletters</h2>
      <label class="d-block mb-2" style="font-size:.92rem"><input type="checkbox" name="email_articles" value="1" <?= get_setting('email_articles', '0') === '1' ? 'checked' : '' ?>>
        Email every new article to subscribers (articles are written by Groq — switch them on under <a class="text-leaf" href="social.php#content">AI &amp; social media</a>)</label>
      <label class="d-block mb-2" style="font-size:.92rem"><input type="checkbox" name="auto_product_newsletter" value="1" <?= get_setting('auto_product_newsletter', '0') === '1' ? 'checked' : '' ?>>
        Send a weekly product newsletter (four products, with the shop link) every
        <select class="select-fc d-inline-block" style="width:auto" name="product_newsletter_day">
          <?php foreach ($days as $n => $d): ?><option value="<?= $n ?>" <?= (int) get_setting('product_newsletter_day', '2') === $n ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?>
        </select> from 09:00</label>
      <button class="btn-fc btn-fc--sm">Save</button>
      <p class="field__hint mt-2 mb-0">Both go out through the cron job (Setup checklist → cron), 40 emails every 5 minutes. Every email has an unsubscribe link.</p>
    </form>
  </div>

  <div class="col-xl-5">
    <div class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1.05rem">Templates</h2>
      <?php foreach ($templates as $t): ?>
        <div class="d-flex justify-content-between align-items-center py-2" style="border-top:1px solid var(--line)">
          <div><b style="font-size:.92rem"><?= e($t['name']) ?></b><br><span class="text-muted-3" style="font-size:.8rem"><?= e($t['subject']) ?></span></div>
          <a class="btn-fc btn-fc--sm btn-fc--light" href="?edit=<?= e($t['slug']) ?>#edit">Edit</a>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($edit): ?>
    <form method="post" class="card-fc card-fc--pad" id="edit">
      <?= csrf_field() ?><input type="hidden" name="slug" value="<?= e($edit['slug']) ?>"><input type="hidden" name="name" value="<?= e($edit['name']) ?>">
      <h2 style="font-size:1.05rem">Edit: <?= e($edit['name']) ?></h2>
      <div class="field"><label for="esubject">Subject</label><input class="input-fc" id="esubject" name="subject" value="<?= e($edit['subject']) ?>" required></div>
      <div class="field"><label for="ebody">Message</label><textarea class="textarea-fc" id="ebody" name="body" rows="14" required><?= e($edit['body']) ?></textarea></div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn-fc btn-fc--sm" name="action" value="save_template"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        <?php if ($edit['is_system']): ?>
          <button class="btn-fc btn-fc--sm btn-fc--light" name="action" value="reset_template" onclick="return confirm('Put back the original wording?')">Reset</button>
        <?php else: ?>
          <button class="btn-fc btn-fc--sm btn-fc--light ms-auto" style="color:var(--coral)" name="action" value="delete_template" onclick="return confirm('Delete this template?')">Delete</button>
        <?php endif; ?>
      </div>
      <?php if ($edit['slug'] === 'enquiry-payment'): ?><p class="field__hint mt-2 mb-0">Sent automatically when a customer enquires from the cart and gives an email address.</p><?php endif; ?>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
document.getElementById('tpl').addEventListener('change', function () {
  const o = this.selectedOptions[0];
  if (!o.value) return;
  document.getElementById('subject').value = o.dataset.subject;
  document.getElementById('body').value = o.dataset.body;
});
</script>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
