<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'side-effects';
$page_title  = 'Side effects — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

$status   = in_array($_GET['status'] ?? 'open', ['open', 'new', 'reviewed', 'resolved', 'all'], true) ? ($_GET['status'] ?? 'open') : 'open';
$severity = array_key_exists($_GET['severity'] ?? '', Health::SEVERITIES) ? $_GET['severity'] : '';

$where = ['1=1'];
$params = [];
if ($status === 'open')     { $where[] = "s.status <> 'resolved'"; }
elseif ($status !== 'all')  { $where[] = 's.status = :st'; $params[':st'] = $status; }
if ($severity !== '')       { $where[] = 's.severity = :sv'; $params[':sv'] = $severity; }

$stmt = db()->prepare("SELECT s.*, u.name AS patient_name, u.phone AS patient_phone, m.name AS medication_name
                       FROM side_effects s JOIN users u ON u.id = s.user_id
                       LEFT JOIN patient_medications m ON m.id = s.medication_id
                       WHERE " . implode(' AND ', $where) . "
                       ORDER BY s.status = 'new' DESC, FIELD(s.severity, 'severe', 'moderate', 'mild'), s.created_at DESC LIMIT 300");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Which products are reported most — worth watching for a pattern.
$byProduct = db()->query("SELECT COALESCE(m.name, 'Not linked to a product') AS name, COUNT(*) n, SUM(s.severity = 'severe') severe
                          FROM side_effects s LEFT JOIN patient_medications m ON m.id = s.medication_id
                          WHERE s.created_at >= NOW() - INTERVAL 90 DAY GROUP BY m.name ORDER BY n DESC LIMIT 6")->fetchAll();
$tab = fn(string $s, string $label) => '<a href="?status=' . $s . ($severity ? '&severity=' . $severity : '') . '" class="chip ' . ($status === $s ? 'is-on' : '') . '">' . $label . '</a>';
?>

<div class="dash-head">
  <div><h1>Side effects</h1><p>Every report from every patient. Severe ones first — contact those patients straight away.</p></div>
</div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card-fc">
      <div class="d-flex flex-wrap gap-2 p-3">
        <?= $tab('open', 'Open') . $tab('new', 'New') . $tab('reviewed', 'Reviewed') . $tab('resolved', 'Resolved') . $tab('all', 'All') ?>
        <span class="ms-auto d-flex gap-2">
          <?php foreach (Health::SEVERITIES as $k => $s): ?>
            <a href="?status=<?= $status ?>&severity=<?= $severity === $k ? '' : $k ?>" class="chip <?= $severity === $k ? 'is-on' : '' ?>"><?= $s['label'] ?></a>
          <?php endforeach; ?>
        </span>
      </div>
      <?php if (!$rows): ?>
        <div class="empty"><div class="empty__icon"><i class="fa-solid fa-notes-medical"></i></div><h3>Nothing here</h3></div>
      <?php else: ?>
      <div style="overflow:auto"><table class="table-fc">
        <thead><tr><th>Reported</th><th>Patient</th><th>Symptom</th><th>Severity</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s): ?>
          <tr>
            <td style="white-space:nowrap;font-size:.84rem"><?= date('j M, H:i', strtotime($s['created_at'])) ?></td>
            <td><a href="patient.php?id=<?= (int) $s['user_id'] ?>#side-effects" class="fw-bold"><?= e($s['patient_name']) ?></a>
              <div class="text-muted-3" style="font-size:.75rem"><?= e(Health::displayPhone($s['patient_phone'])) ?></div></td>
            <td><?= e($s['symptom']) ?><?php if ($s['medication_name']): ?><div class="text-muted-3" style="font-size:.75rem"><?= e($s['medication_name']) ?></div><?php endif; ?></td>
            <td><span class="pill pill--<?= Health::SEVERITIES[$s['severity']]['tone'] ?>"><?= ucfirst($s['severity']) ?></span></td>
            <td><span class="pill pill--<?= ['new' => 'warn', 'reviewed' => 'info', 'resolved' => 'ok'][$s['status']] ?>"><?= ucfirst($s['status']) ?></span></td>
            <td class="d-flex gap-1">
              <a href="<?= e(wa_link('Hi ' . explode(' ', $s['patient_name'])[0] . ', this is CureMeds about the side effect you reported (' . $s['symptom'] . '). How are you feeling now?', (string) $s['patient_phone'])) ?>" target="_blank" rel="noopener" class="btn-fc btn-fc--whatsapp btn-fc--sm" aria-label="WhatsApp the patient"><i class="fa-brands fa-whatsapp"></i></a>
              <a href="patient.php?id=<?= (int) $s['user_id'] ?>#side-effects" class="btn-fc btn-fc--ghost btn-fc--sm">Reply</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card-fc card-fc--pad mb-3">
      <h2 style="font-size:1.05rem">Most reported, last 90 days</h2>
      <?php if (!$byProduct): ?><p class="text-muted-2 mb-0">No reports yet.</p><?php endif; ?>
      <?php foreach ($byProduct as $p): ?>
        <div class="d-flex justify-content-between py-2" style="border-top:1px solid var(--line);font-size:.88rem">
          <span><?= e($p['name']) ?></span>
          <span><b><?= (int) $p['n'] ?></b><?= (int) $p['severe'] ? ' <span class="pill pill--danger">' . (int) $p['severe'] . ' severe</span>' : '' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="card-fc card-fc--pad" style="background:var(--coral-wash);border-color:transparent">
      <b style="font-family:var(--font-display)">When a report is severe</b>
      <ol style="font-size:.86rem;color:var(--text-2);margin:.5rem 0 0;padding-left:1.1rem;line-height:1.7">
        <li>WhatsApp or call the patient now.</li>
        <li>Advise them to stop the supplement and visit a clinic (or call 10177 in an emergency).</li>
        <li>Never advise stopping prescribed medication such as ARVs — that is for their clinic.</li>
        <li>Record what you advised in your reply, then mark it reviewed.</li>
        <li>Suspected reactions to medicines can be reported to SAHPRA (Med Safety app).</li>
      </ol>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
