<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'reminders';
$page_title  = 'Reminders — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    if (($_POST['action'] ?? '') === 'run') {
        $r = Health::sendDueReminders();
        flash('success', "Reminder run finished: {$r['sent']} sent, {$r['failed']} failed, {$r['refills']} refill notice" . ($r['refills'] === 1 ? '' : 's') . '.');
    }
    if (($_POST['action'] ?? '') === 'test') {
        $to = trim((string) ($_POST['to'] ?? ''));
        if (!safe_email($to)) {
            flash('danger', 'Enter a valid email address.');
        } else {
            $ok = Mailer::send($to, 'CureMeds test email', email_wrap('<p>This is a test from your CureMeds site.</p><p>If you can read this, email is working and reminders will be delivered.</p>'));
            $ok ? flash('success', 'Test email sent to ' . e($to) . '. Check the inbox (and spam folder).')
                : flash('danger', 'The mail server refused the email. Check smtp_host, smtp_user and smtp_pass in config/secrets.php.');
        }
    }
    back();
}

$lastRun = get_setting('reminders_last_run', '');
$healthy = $lastRun !== '' && strtotime($lastRun) > time() - 30 * 60;
$log = db()->query("SELECT r.*, m.name AS med, u.name AS patient, u.email FROM reminder_log r
                    JOIN patient_medications m ON m.id = r.medication_id JOIN users u ON u.id = r.user_id
                    ORDER BY r.created_at DESC LIMIT 60")->fetchAll();
$counts = db()->query("SELECT
    SUM(status = 'sent' AND created_at >= CURDATE()) today,
    SUM(status = 'sent' AND created_at >= CURDATE() - INTERVAL 7 DAY) week,
    SUM(status = 'failed' AND created_at >= CURDATE() - INTERVAL 7 DAY) failed
    FROM reminder_log")->fetch();
$noEmail = (int) db()->query("SELECT COUNT(DISTINCT u.id) FROM users u JOIN patient_medications m ON m.user_id = u.id AND m.status = 'active'
                              WHERE u.email IS NULL OR u.email = ''")->fetchColumn();
$cronUrl = SITE_URL . '/scripts/cron.php?key=' . CRON_KEY;
?>

<div class="dash-head">
  <div><h1>Reminders</h1><p>Dose reminders are emailed at each dose time; refill reminders go out when about a week's supply is left.</p></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="run">
    <button class="btn-fc btn-fc--sm"><i class="fa-solid fa-play"></i> Run now</button></form>
</div>

<?= flash_html() ?>

<div class="alert-fc alert-fc--<?= $healthy ? 'success' : 'warn' ?>">
  <i class="fa-solid <?= $healthy ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
  <span><?= $healthy
      ? 'The reminder job is running — last run ' . time_ago($lastRun) . '.'
      : ($lastRun === '' ? '<b>The reminder job has never run.</b> Add the cron job below so reminders go out by themselves.'
                         : '<b>The reminder job last ran ' . time_ago($lastRun) . '.</b> Check the cron job below is still set up.') ?></span>
</div>

<div class="stat-grid">
  <div class="stat"><span class="stat__icon stat__icon--leaf"><i class="fa-solid fa-paper-plane"></i></span><span><b><?= (int) $counts['today'] ?></b><span>Sent today</span></span></div>
  <div class="stat"><span class="stat__icon stat__icon--ink"><i class="fa-solid fa-calendar-week"></i></span><span><b><?= (int) $counts['week'] ?></b><span>Sent this week</span></span></div>
  <div class="stat"><span class="stat__icon stat__icon--coral"><i class="fa-solid fa-circle-xmark"></i></span><span><b><?= (int) $counts['failed'] ?></b><span>Failed this week</span></span></div>
  <div class="stat"><span class="stat__icon stat__icon--amber"><i class="fa-solid fa-envelope-circle-check"></i></span><span><b><?= $noEmail ?></b><span>Patients with no email</span>
    <?php if ($noEmail): ?><div class="delta down">They get no reminders</div><?php endif; ?></span></div>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="card-fc">
      <h2 style="font-size:1.05rem" class="p-3 pb-0">Recent reminders</h2>
      <?php if (!$log): ?><p class="text-muted-2 p-3 mb-0">Nothing sent yet.</p><?php else: ?>
      <div style="overflow:auto"><table class="table-fc">
        <thead><tr><th>Sent</th><th>Patient</th><th>For</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($log as $r): ?>
          <tr><td style="font-size:.84rem;white-space:nowrap"><?= date('j M H:i', strtotime($r['created_at'])) ?></td>
            <td><a href="patient.php?id=<?= (int) $r['user_id'] ?>"><?= e($r['patient']) ?></a></td>
            <td style="font-size:.84rem"><?= $r['kind'] === 'refill' ? '<b>Refill:</b> ' : '' ?><?= e($r['med']) ?> · <?= date('H:i', strtotime($r['scheduled_for'])) ?></td>
            <td><span class="pill pill--<?= $r['status'] === 'sent' ? 'ok' : ($r['status'] === 'failed' ? 'danger' : 'muted') ?>"><?= $r['status'] ?></span></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1.05rem">Set up the cron job</h2>
      <p style="font-size:.86rem;color:var(--text-2)">cPanel → <b>Cron Jobs</b> → <i>Common settings: Once per five minutes</i> → command:</p>
      <code style="display:block;font-size:.78rem;background:var(--paper-2);padding:.7rem;border-radius:var(--r-sm);word-break:break-all">/usr/local/bin/php /home/USER/public_html/scripts/cron.php &gt;/dev/null 2&gt;&amp;1</code>
      <p style="font-size:.82rem;color:var(--text-3);margin:.6rem 0">Replace <b>USER</b> with your cPanel username (shown top-right in cPanel).</p>
      <p style="font-size:.86rem;color:var(--text-2);margin-bottom:.4rem">No cron in your hosting? Point a free URL cron (cron-job.org) at this address every 5 minutes. Keep it private:</p>
      <code style="display:block;font-size:.74rem;background:var(--paper-2);padding:.7rem;border-radius:var(--r-sm);word-break:break-all"><?= e($cronUrl) ?></code>
    </div>
    <form method="post" class="card-fc card-fc--pad">
      <?= csrf_field() ?><input type="hidden" name="action" value="test">
      <h2 style="font-size:1.05rem">Send a test email</h2>
      <p style="font-size:.86rem;color:var(--text-2)">Sent from <b><?= e(SMTP_USER) ?></b> via <b><?= e(SMTP_HOST) ?>:<?= (int) SMTP_PORT ?></b><?= SMTP_PASS === '' ? ' — <span style="color:var(--danger)">no mailbox password set in config/secrets.php</span>' : '' ?>.</p>
      <div class="d-flex gap-2"><input class="input-fc" type="email" name="to" value="<?= e((string) $user['email']) ?>" required>
        <button class="btn-fc btn-fc--sm">Send</button></div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
