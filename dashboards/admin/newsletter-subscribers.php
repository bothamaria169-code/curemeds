<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'newsletter';
$page_title  = 'Subscribers — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

NewsletterService::ensureSchema();

$sources = [
    'gate'   => 'Sign-up gate',
    'popup'  => 'Popup',
    'footer' => 'Footer form',
    'admin'  => 'Added by admin',
];
$sourceLabel = fn(string $s) => $sources[$s] ?? ucfirst($s);

// ── Filters (shared by the table and the CSV export) ─────────────────────
$f = [
    'q'        => trim((string) ($_GET['q'] ?? '')),
    'status'   => in_array($_GET['status'] ?? '', ['active', 'unsubscribed', 'all'], true) ? (string) $_GET['status'] : 'active',
    'source'   => preg_replace('/[^a-z0-9_-]/', '', (string) ($_GET['source'] ?? '')),
    'audience' => in_array($_GET['audience'] ?? '', ['retail', 'reseller'], true) ? (string) $_GET['audience'] : '',
];

$where  = ['1 = 1'];
$params = [];
if ($f['status'] !== 'all') {
    $where[] = 'status = :status';
    $params[':status'] = $f['status'];
}
if ($f['source'] !== '') {
    $where[] = 'source = :source';
    $params[':source'] = $f['source'];
}
if ($f['audience'] !== '') {
    $where[] = "audience IN ('all', :audience)";
    $params[':audience'] = $f['audience'];
}
if ($f['q'] !== '') {
    // Two placeholders: with emulated prepares off, one name can't be used twice.
    $where[] = '(email LIKE :q1 OR name LIKE :q2)';
    $params[':q1'] = $params[':q2'] = '%' . $f['q'] . '%';
}
$whereSql = implode(' AND ', $where);

// ── CSV export ───────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = db()->prepare("SELECT email, name, audience, status, source, subscribed_at, unsubscribed_at, consent_text
                           FROM newsletter_subscribers WHERE $whereSql ORDER BY subscribed_at DESC");
    $stmt->execute($params);

    while (ob_get_level() > 0) { ob_end_clean(); }   // drop the dashboard shell
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="curemeds-subscribers-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                    // BOM, so Excel reads the UTF-8 correctly
    fputcsv($out, ['Email', 'Name', 'Audience', 'Status', 'Source', 'Subscribed', 'Unsubscribed', 'Consent']);
    while ($r = $stmt->fetch()) {
        // A leading = + - @ would make Excel treat the cell as a formula.
        $row = array_map(fn($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v, array_values($r));
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ── Actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');
    $sid    = (int) ($_POST['id'] ?? 0);
    $flash  = null;

    if ($action === 'add') {
        $email = safe_email((string) ($_POST['email'] ?? '')) ?: '';
        $stmt  = db()->prepare('SELECT status, unsubscribed_at FROM newsletter_subscribers WHERE email = :e');
        $stmt->execute([':e' => $email]);
        $existing = $stmt->fetch();

        if ($email === '') {
            $flash = ['warn', 'That email address does not look right.'];
        } elseif (empty($_POST['consent'])) {
            $flash = ['warn', 'Only add someone who has agreed to receive marketing email from CureMeds.'];
        } elseif ($existing && $existing['status'] === 'unsubscribed') {
            // Re-adding someone who opted out would override their choice.
            $flash = ['warn', "$email unsubscribed on " . date('j M Y', strtotime((string) $existing['unsubscribed_at']))
                . '. Only they can re-join, through the website.'];
        } else {
            $res = NewsletterService::subscribe(
                $email,
                (string) ($_POST['name'] ?? '') ?: null,
                'admin',
                (string) ($_POST['audience'] ?? 'all'),
                'Added by ' . $user['email'] . ', who confirmed the person agreed to receive CureMeds emails.'
            );
            $flash = [$res['ok'] ? 'ok' : 'warn', $res['ok'] ? ($res['new'] ? "$email added." : "$email is already subscribed.") : $res['message']];
        }

    } elseif ($action === 'unsubscribe' && $sid) {
        $stmt = db()->prepare('SELECT token FROM newsletter_subscribers WHERE id = :id');
        $stmt->execute([':id' => $sid]);
        if ($token = $stmt->fetchColumn()) {
            NewsletterService::unsubscribe((string) $token);
            $flash = ['ok', 'Unsubscribed. They will get no further campaigns.'];
        }

    } elseif ($action === 'delete' && $sid) {
        // A POPIA erasure request: remove the person entirely, send history included.
        db()->prepare('DELETE FROM newsletter_sends WHERE subscriber_id = :id')->execute([':id' => $sid]);
        db()->prepare('DELETE FROM newsletter_subscribers WHERE id = :id')->execute([':id' => $sid]);
        $flash = ['ok', 'Deleted permanently, along with their send history.'];
    }

    $_SESSION['nl_flash'] = $flash ?? ['warn', 'Nothing was changed.'];
    redirect('dashboards/admin/newsletter-subscribers.php?' . http_build_query(array_filter($_GET)));
}

$flash = $_SESSION['nl_flash'] ?? null;
unset($_SESSION['nl_flash']);

// ── Data ─────────────────────────────────────────────────────────────────
$perPage = 50;
$page    = max(1, (int) ($_GET['page'] ?? 1));

$count = db()->prepare("SELECT COUNT(*) FROM newsletter_subscribers WHERE $whereSql");
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);

$stmt = db()->prepare("SELECT * FROM newsletter_subscribers WHERE $whereSql
                       ORDER BY subscribed_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();

$bySource = db()->query(
    "SELECT source, COUNT(*) n FROM newsletter_subscribers WHERE status = 'active' GROUP BY source ORDER BY n DESC"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$filterUrl = fn(array $over) => '?' . http_build_query(array_filter(array_merge($f, ['page' => null], $over), fn($v) => $v !== '' && $v !== null));
?>

<div class="dash-head">
  <div>
    <h1>Subscribers</h1>
    <p>Everyone who joined the list, where they signed up, and the consent they gave.</p>
  </div>
  <a href="<?= e($filterUrl(['export' => 'csv'])) ?>" class="btn-fc btn-fc--light">
    <i class="fa-solid fa-file-arrow-down"></i> Export CSV
  </a>
</div>

<?php $nl_tab = 'subscribers'; include __DIR__ . '/_newsletter-nav.php'; ?>

<?php if ($flash): ?>
  <div class="alert-fc alert-fc--<?= e($flash[0]) ?>"><i class="fa-solid fa-circle-info"></i><span><?= e($flash[1]) ?></span></div>
<?php endif; ?>

<?php if ($bySource): ?>
  <div class="nl-sources">
    <span class="text-muted-3" style="font-size:.8rem;font-weight:700">Active, by where they joined:</span>
    <?php foreach ($bySource as $src => $n): ?>
      <a class="chip <?= $f['source'] === $src ? 'is-on' : '' ?>" href="<?= e($filterUrl(['source' => $f['source'] === $src ? '' : $src, 'status' => 'active'])) ?>">
        <?= e($sourceLabel((string) $src)) ?> <b><?= number_format((int) $n) ?></b>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card-fc">
      <form class="nl-filter" method="get">
        <input class="input-fc" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search email or name" aria-label="Search">
        <select class="select-fc" name="status" aria-label="Status">
          <option value="active" <?= $f['status'] === 'active' ? 'selected' : '' ?>>Subscribed</option>
          <option value="unsubscribed" <?= $f['status'] === 'unsubscribed' ? 'selected' : '' ?>>Unsubscribed</option>
          <option value="all" <?= $f['status'] === 'all' ? 'selected' : '' ?>>Everyone</option>
        </select>
        <select class="select-fc" name="audience" aria-label="Audience">
          <option value="">Any audience</option>
          <option value="retail" <?= $f['audience'] === 'retail' ? 'selected' : '' ?>>Shoppers</option>
          <option value="reseller" <?= $f['audience'] === 'reseller' ? 'selected' : '' ?>>Resellers</option>
        </select>
        <?php if ($f['source'] !== ''): ?><input type="hidden" name="source" value="<?= e($f['source']) ?>"><?php endif; ?>
        <button class="btn-fc btn-fc--sm" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
      </form>

      <?php if (!$rows): ?>
        <div class="empty" style="padding:2.5rem 1.5rem">
          <div class="empty__icon"><i class="fa-solid fa-user-group"></i></div>
          <p class="mb-0 text-muted-2"><?= $f['q'] !== '' || $f['source'] !== '' ? 'Nobody matches those filters.' : 'Nobody has subscribed yet. The sign-up form on the site will fill this list.' ?></p>
        </div>
      <?php else: ?>
        <div class="table-wrap" style="border:0">
          <table class="table-fc">
            <thead><tr><th>Subscriber</th><th>Joined via</th><th>Status</th><th>Date</th><th style="width:1%"></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $s): ?>
              <tr>
                <td style="min-width:220px">
                  <span style="font-size:.86rem;font-weight:600"><?= e($s['email']) ?></span>
                  <span class="d-block text-muted-3" style="font-size:.72rem">
                    <?= $s['name'] ? e($s['name']) . ' · ' : '' ?><?= e(['all' => 'Everyone', 'retail' => 'Shopper', 'reseller' => 'Reseller'][$s['audience']] ?? $s['audience']) ?>
                  </span>
                </td>
                <td style="font-size:.82rem">
                  <?= e($sourceLabel((string) $s['source'])) ?>
                  <?php if (!empty($s['consent_text'])): ?>
                    <i class="fa-solid fa-shield-halved text-muted-3 ms-1" tabindex="0"
                       title="Consent recorded: <?= e((string) $s['consent_text']) ?><?= $s['consent_ip'] ? ' (from ' . e((string) $s['consent_ip']) . ')' : '' ?>"
                       aria-label="Consent recorded"></i>
                  <?php endif; ?>
                </td>
                <td><span class="pill pill--<?= $s['status'] === 'active' ? 'ok' : 'muted' ?>"><?= $s['status'] === 'active' ? 'Subscribed' : 'Left' ?></span></td>
                <td class="text-muted-2" style="font-size:.8rem;white-space:nowrap">
                  <?= e(date('j M Y', strtotime((string) $s['subscribed_at']))) ?>
                  <?php if ($s['unsubscribed_at']): ?>
                    <span class="d-block text-muted-3" style="font-size:.72rem">left <?= e(date('j M Y', strtotime((string) $s['unsubscribed_at']))) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <?php if ($s['status'] === 'active'): ?>
                      <form method="post" onsubmit="return confirm('Unsubscribe <?= e(addslashes($s['email'])) ?>?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unsubscribe"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button class="btn-fc btn-fc--sm btn-fc--light" type="submit" title="Unsubscribe"><i class="fa-solid fa-user-minus"></i></button>
                      </form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Permanently delete <?= e(addslashes($s['email'])) ?> and their history? Use this for a data-deletion request.')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                      <button class="btn-fc btn-fc--sm btn-fc--light" style="color:var(--coral)" type="submit" title="Delete permanently"><i class="fa-solid fa-trash-can"></i></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="px-3 pb-3 d-flex justify-content-between align-items-center flex-wrap gap-2" style="font-size:.8rem">
          <span class="text-muted-3"><?= number_format($total) ?> <?= $total === 1 ? 'person' : 'people' ?></span>
          <?= render_pager($page, $pages) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-xl-4">
    <form method="post" class="card-fc card-fc--pad">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <h2 style="font-size:1.05rem">Add someone</h2>
      <p class="text-muted-2" style="font-size:.82rem">For a customer who asked in person, on the phone or on WhatsApp.</p>
      <div class="field">
        <label for="add_email">Email</label>
        <input class="input-fc" type="email" id="add_email" name="email" required>
      </div>
      <div class="field">
        <label for="add_name">Name <span class="text-muted-3 fw-normal">(optional)</span></label>
        <input class="input-fc" type="text" id="add_name" name="name" maxlength="120">
      </div>
      <div class="field">
        <label for="add_audience">Audience</label>
        <select class="select-fc" id="add_audience" name="audience">
          <option value="retail">Shopper</option>
          <option value="reseller">Reseller</option>
          <option value="all">Both</option>
        </select>
      </div>
      <label class="nl-consent">
        <input type="checkbox" name="consent" value="1" required>
        <span>They have agreed to receive marketing emails from CureMeds. <small>POPIA requires this — recorded with your name.</small></span>
      </label>
      <button class="btn-fc btn-fc--block mt-3" type="submit"><i class="fa-solid fa-user-plus"></i> Add subscriber</button>
    </form>

    <div class="card-fc card-fc--pad mt-3" style="font-size:.82rem">
      <h3 style="font-size:.92rem"><i class="fa-solid fa-scale-balanced text-muted-3"></i> Unsubscribe or delete?</h3>
      <p class="text-muted-2 mb-2"><b>Unsubscribe</b> when someone asks to stop getting emails. The record stays, so nobody can re-add them by accident.</p>
      <p class="text-muted-2 mb-0"><b>Delete</b> only when someone asks for their personal information to be erased. It removes every trace.</p>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
