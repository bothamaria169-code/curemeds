<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'newsletter';
$page_title  = 'Newsletter — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

NewsletterService::ensureSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $id       = (int) ($_POST['id'] ?? 0);
    $action   = (string) ($_POST['action'] ?? '');
    $campaign = NewsletterService::find($id);
    $flash    = ['warn', 'That campaign no longer exists.'];

    if ($campaign && $action === 'send' && $campaign['status'] === 'draft') {
        $n = NewsletterService::queue($id);
        $flash = $n > 0
            ? ['ok', "Sending to $n subscriber" . ($n === 1 ? '' : 's') . '. Keep this page open until it finishes.']
            : ['warn', 'Nobody in that audience is subscribed yet, so there was no one to send to.'];

    } elseif ($campaign && $action === 'stop' && $campaign['status'] === 'sending') {
        db()->prepare("DELETE FROM newsletter_sends WHERE campaign_id = :c AND status = 'pending'")->execute([':c' => $id]);
        $p = NewsletterService::progress($id);
        if ($p['sent'] === 0 && $p['failed'] === 0) {
            // Nothing went out: put it back to draft so it can be fixed and resent.
            db()->prepare('DELETE FROM newsletter_sends WHERE campaign_id = :c')->execute([':c' => $id]);
            db_update('newsletter_campaigns', $id, ['status' => 'draft', 'total_count' => 0]);
            $flash = ['ok', 'Stopped before anything went out. The campaign is back in drafts.'];
        } else {
            NewsletterService::progress($id, true);
            $flash = ['ok', "Stopped. {$p['sent']} email" . ($p['sent'] === 1 ? ' had' : 's had') . ' already gone out.'];
        }

    } elseif ($campaign && $action === 'duplicate') {
        $copy = db_insert('newsletter_campaigns', [
            'subject'       => mb_substr('Copy of ' . $campaign['subject'], 0, 255),
            'preheader'     => $campaign['preheader'],
            'audience'      => $campaign['audience'],
            'template'      => $campaign['template'],
            'template_data' => $campaign['template_data'],
            'body_html'     => $campaign['body_html'],
            'status'        => 'draft',
            'created_by'    => (int) $user['id'],
        ]);
        redirect('dashboards/admin/newsletter-compose.php?id=' . $copy);

    } elseif ($campaign && $action === 'delete' && $campaign['status'] !== 'sending') {
        db()->prepare('DELETE FROM newsletter_sends WHERE campaign_id = :c')->execute([':c' => $id]);
        db()->prepare('DELETE FROM newsletter_campaigns WHERE id = :id')->execute([':id' => $id]);
        $flash = ['ok', 'Campaign deleted.'];
    }

    $_SESSION['nl_flash'] = $flash;
    redirect('dashboards/admin/newsletter.php');
}

$flash = $_SESSION['nl_flash'] ?? null;
unset($_SESSION['nl_flash']);

$catalog   = NewsletterTemplates::catalog();
$campaigns = db()->query(
    "SELECT * FROM newsletter_campaigns
     ORDER BY FIELD(status, 'sending', 'draft') DESC, COALESCE(sent_at, created_at) DESC LIMIT 60"
)->fetchAll();
$sending   = array_values(array_filter($campaigns, fn($c) => $c['status'] === 'sending'));

$stat = fn(string $sql) => (int) db()->query($sql)->fetchColumn();
$counts = [
    'active' => $stat("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active'"),
    'joined' => $stat("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active' AND subscribed_at >= NOW() - INTERVAL 30 DAY"),
    'left'   => $stat("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'unsubscribed' AND unsubscribed_at >= NOW() - INTERVAL 30 DAY"),
    'sent'   => $stat("SELECT COUNT(*) FROM newsletter_campaigns WHERE status = 'sent'"),
];
?>

<div class="dash-head">
  <div>
    <h1>Newsletter</h1>
    <p>Email your subscribers about new stock, restocks and sales.</p>
  </div>
  <a href="newsletter-compose.php" class="btn-fc"><i class="fa-solid fa-plus"></i> New campaign</a>
</div>

<?php $nl_tab = 'campaigns'; include __DIR__ . '/_newsletter-nav.php'; ?>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= e($flash[0]) ?>"><i class="fa-solid fa-circle-info"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>

<?php if (SMTP_USER === ''): ?>
  <div class="alert-fc alert-fc--warn">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span>
      <b>Email is going out through the server's basic mailer.</b> That works on most cPanel hosts, but mail
      is far more likely to land in spam. Set <code>SMTP_USER</code> and <code>SMTP_PASS</code> in
      <code>config/config.php</code> to your cPanel mailbox before sending to your whole list.
    </span>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat"><span class="stat__icon stat__icon--leaf"><i class="fa-solid fa-users"></i></span>
    <span><b><?= number_format($counts['active']) ?></b><span>Subscribers</span></span></div>
  <div class="stat"><span class="stat__icon stat__icon--amber"><i class="fa-solid fa-user-plus"></i></span>
    <span><b>+<?= number_format($counts['joined']) ?></b><span>Joined, last 30 days</span></span></div>
  <div class="stat"><span class="stat__icon stat__icon--ink"><i class="fa-solid fa-user-minus"></i></span>
    <span><b><?= number_format($counts['left']) ?></b><span>Left, last 30 days</span></span></div>
  <div class="stat"><span class="stat__icon stat__icon--leaf"><i class="fa-solid fa-paper-plane"></i></span>
    <span><b><?= number_format($counts['sent']) ?></b><span>Campaigns sent</span></span></div>
</div>

<?php foreach ($sending as $s):
  $p = NewsletterService::progress((int) $s['id']);
  $pct = $p['total'] > 0 ? (int) floor(($p['sent'] + $p['failed']) / $p['total'] * 100) : 0; ?>
  <div class="card-fc card-fc--pad nl-sending" data-nl-sending="<?= (int) $s['id'] ?>">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
      <div>
        <span class="eyebrow" style="margin:0"><i class="fa-solid fa-circle-notch fa-spin"></i> Sending now</span>
        <h2 style="font-size:1.05rem;margin:.3rem 0 0"><?= e($s['subject']) ?></h2>
      </div>
      <form method="post" onsubmit="return confirm('Stop sending? Emails already sent cannot be recalled.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="stop"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <button class="btn-fc btn-fc--sm btn-fc--light" type="submit"><i class="fa-solid fa-stop"></i> Stop</button>
      </form>
    </div>
    <div class="nl-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>">
      <span style="width:<?= $pct ?>%"></span>
    </div>
    <div class="nl-sending__meta">
      <span data-nl-count><?= number_format($p['sent']) ?> of <?= number_format($p['total']) ?> sent</span>
      <span data-nl-failed class="<?= $p['failed'] ? '' : 'd-none' ?>" style="color:var(--danger)"><?= number_format($p['failed']) ?> failed</span>
      <span class="text-muted-3">Keep this page open. If you close it, sending pauses and resumes when you come back.</span>
    </div>
  </div>
<?php endforeach; ?>

<div class="card-fc">
  <div class="p-3 pb-0 d-flex justify-content-between align-items-center">
    <h2 style="font-size:1.05rem;margin:0">Campaigns</h2>
  </div>

  <?php if (!$campaigns): ?>
    <div class="empty" style="padding:2.5rem 1.5rem">
      <div class="empty__icon"><i class="fa-solid fa-envelope-open-text"></i></div>
      <h3 style="font-size:1.1rem">No campaigns yet</h3>
      <p class="text-muted-2" style="max-width:440px;margin:0 auto 1rem">
        Pick a template — a product spotlight, a grid of new arrivals, a sale with a coupon code or a plain
        announcement — choose your products, and preview it before anything is sent.
      </p>
      <a href="newsletter-compose.php" class="btn-fc"><i class="fa-solid fa-plus"></i> Create your first campaign</a>
    </div>
  <?php else: ?>
    <div class="table-wrap" style="border:0">
      <table class="table-fc">
        <thead>
          <tr><th>Campaign</th><th>Audience</th><th>Status</th><th class="text-end">Delivered</th><th>Date</th><th style="width:1%"></th></tr>
        </thead>
        <tbody>
        <?php foreach ($campaigns as $c):
          $tpl  = $catalog[$c['template'] ?? 'custom'] ?? $catalog['custom'];
          $pill = ['sent' => 'ok', 'failed' => 'danger', 'sending' => 'warn', 'draft' => 'muted'][$c['status']] ?? 'muted'; ?>
          <tr>
            <td style="min-width:240px">
              <b style="font-size:.88rem"><?= e($c['subject']) ?></b>
              <span class="d-block text-muted-3" style="font-size:.74rem">
                <i class="fa-solid <?= e($tpl['icon']) ?>"></i> <?= e($tpl['label']) ?>
                <?php if (!empty($c['preheader'])): ?> · <?= e(excerpt((string) $c['preheader'], 60)) ?><?php endif; ?>
              </span>
            </td>
            <td><span class="badge-fc badge-fc--muted"><?= e(['all' => 'Everyone', 'retail' => 'Shoppers', 'reseller' => 'Resellers'][$c['audience']] ?? $c['audience']) ?></span></td>
            <td><span class="pill pill--<?= $pill ?>"><?= e(ucfirst($c['status'])) ?></span></td>
            <td class="text-end" style="white-space:nowrap">
              <?php if ($c['status'] === 'draft'): ?>
                <span class="text-muted-3">—</span>
              <?php else: ?>
                <b><?= number_format((int) $c['sent_count']) ?></b><span class="text-muted-3"> / <?= number_format((int) $c['total_count']) ?></span>
                <?php if ((int) $c['failed_count'] > 0): ?>
                  <span class="d-block" style="font-size:.72rem;color:var(--danger)"><?= (int) $c['failed_count'] ?> failed</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="text-muted-2" style="font-size:.8rem;white-space:nowrap">
              <?= $c['sent_at'] ? 'Sent ' . e(date('j M Y, H:i', strtotime((string) $c['sent_at']))) : 'Created ' . e(time_ago((string) $c['created_at'])) ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <?php if ($c['status'] === 'draft'): ?>
                  <a class="btn-fc btn-fc--sm" href="newsletter-compose.php?id=<?= (int) $c['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
                <?php endif; ?>
                <a class="btn-fc btn-fc--sm btn-fc--light" href="newsletter-preview.php?id=<?= (int) $c['id'] ?>" target="_blank" rel="noopener" title="Preview"><i class="fa-solid fa-eye"></i></a>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn-fc btn-fc--sm btn-fc--light" type="submit" title="Duplicate"><i class="fa-regular fa-copy"></i></button>
                </form>
                <?php if ($c['status'] !== 'sending'): ?>
                  <form method="post" onsubmit="return confirm('Delete this campaign and its send history?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <button class="btn-fc btn-fc--sm btn-fc--light" style="color:var(--coral)" type="submit" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($sending): ?>
<script>
/* Drives any running send: one small batch per request until the queue is empty. */
(function () {
  const csrf = <?= json_encode(csrf_token()) ?>;
  document.querySelectorAll('[data-nl-sending]').forEach(async (card) => {
    const id = card.dataset.nlSending;
    const bar = card.querySelector('.nl-progress span');
    const count = card.querySelector('[data-nl-count]');
    const failed = card.querySelector('[data-nl-failed]');
    let errors = 0;

    while (true) {
      let res;
      try {
        const r = await fetch('newsletter-send.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ id: id, csrf_token: csrf })
        });
        res = await r.json();
      } catch (e) {
        res = { success: false };
      }

      if (!res.success) {
        // A dropped request is not fatal — the queue is on the server. Retry a few times, then stop.
        if (++errors >= 3) {
          count.textContent = 'Paused — connection problem. Reload the page to resume.';
          return;
        }
        await new Promise((r) => setTimeout(r, 2000));
        continue;
      }
      errors = 0;

      const pct = res.total ? Math.floor((res.sent + res.failed) / res.total * 100) : 100;
      bar.style.width = pct + '%';
      bar.parentElement.setAttribute('aria-valuenow', pct);
      count.textContent = res.sent.toLocaleString() + ' of ' + res.total.toLocaleString() + ' sent';
      if (res.failed) { failed.classList.remove('d-none'); failed.textContent = res.failed + ' failed'; }

      if (res.done) {
        setTimeout(() => location.reload(), 900);
        return;
      }
    }
  });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
