<?php
declare(strict_types=1);
$dash_role   = 'admin';
$dash_active = 'settings';
$page_title  = 'Settings — CureMeds admin';
require_once __DIR__ . '/../../includes/dash-head.php';

/**
 * Every editable site setting, grouped. Keys map 1:1 onto the `settings`
 * key/value table, so adding a row here is all it takes to expose a new one.
 */
$groups = [
    'Storefront' => [
        'site_name'        => ['Site name', 'text'],
        'site_tagline'     => ['Tagline', 'text'],
        'announcement'     => ['Announcement bar', 'text', 'Shown at the very top of every page. Leave empty to hide the bar.'],
        'maintenance_mode' => ['Maintenance mode', 'toggle', 'Not yet enforced by the front end — reserved.'],
    ],
    'Pricing & delivery' => [
        'free_shipping_threshold' => ['Free delivery from', 'number', 'Enquiry total at which courier delivery is quoted as free.'],
        'shipping_flat_rate'      => ['Courier flat rate', 'number'],
    ],
    'Payment (shown in the cart, on product pages and in the payment email)' => [
        'eft_enabled'         => ['Accept EFT / bank deposit', 'toggle'],
        'bank_name'           => ['Bank', 'text', 'e.g. Standard Bank, FNB, Capitec.'],
        'bank_account_number' => ['Account number', 'text'],
        'bank_account_holder' => ['Account holder', 'text', 'Leave empty to keep the name hidden from customers.'],
        'bank_branch_code'    => ['Branch code', 'text', 'Optional.'],
        'cod_enabled'         => ['Offer cash on delivery', 'toggle'],
        'cod_note'            => ['Cash on delivery note', 'text', 'What customers read about paying on delivery.'],
    ],
    'Legal details (shown on the terms, privacy and returns pages)' => [
        'company_name'        => ['Registered company name', 'text', 'The legal entity, e.g. CureMeds Trading (Pty) Ltd.'],
        'company_reg_no'      => ['Company registration number', 'text', 'The CIPC number, e.g. 2024/123456/07. South African law requires online shops to show it.'],
        'company_vat_no'      => ['VAT number', 'text', 'Leave empty if not VAT-registered.'],
        'information_officer' => ['Information Officer', 'text', 'The person responsible for privacy under POPIA. Named on the privacy policy.'],
    ],
    'Contact & social' => [
        'contact_phone'    => ['Phone', 'text'],
        'contact_whatsapp' => ['WhatsApp number', 'text', 'Every enquiry goes here. Digits only with the country code, e.g. 27835761713.'],
        'contact_email'    => ['Email', 'text'],
        'contact_address'  => ['Address', 'text'],
        'facebook_url'     => ['Facebook URL', 'text'],
        'instagram_url'    => ['Instagram URL', 'text'],
        'tiktok_url'       => ['TikTok URL', 'text'],
    ],
];

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    foreach ($groups as $keys) {
        foreach ($keys as $key => $meta) {
            if ($meta[1] === 'toggle') {
                set_setting($key, !empty($_POST[$key]) ? '1' : '0');
            } elseif (array_key_exists($key, $_POST)) {
                $value = $key === 'contact_whatsapp' ? (preg_replace('/\D/', '', (string) $_POST[$key]) ?: DEFAULT_WHATSAPP) : sanitize((string) $_POST[$key]);
                set_setting($key, $value);
            }
        }
    }
    $flash = 'Settings saved. Changes are live immediately.';
    // get_setting() caches per request — reload the page so the form reflects reality.
    redirect('dashboards/admin/settings.php?saved=1');
}

$points = get_pickup_points();
?>

<div class="dash-head">
  <div>
    <h1>Settings</h1>
    <p>Everything here is read live by the storefront. No deploy needed.</p>
  </div>
</div>

<?php if (!empty($_GET['saved'])): ?>
  <div class="alert-fc alert-fc--ok"><i class="fa-solid fa-circle-check"></i><span>Settings saved. Changes are live immediately.</span></div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="row g-3">
    <?php foreach ($groups as $groupName => $keys): ?>
    <div class="col-lg-6">
      <div class="card-fc card-fc--pad h-100">
        <h2 style="font-size:1.05rem"><?= e($groupName) ?></h2>
        <?php foreach ($keys as $key => $meta):
          [$label, $type] = $meta;
          $hint  = $meta[2] ?? null;
          $value = (string) get_setting($key, ''); ?>
          <div class="field">
            <label for="<?= $key ?>"><?= e($label) ?></label>
            <?php if ($type === 'toggle'): ?>
              <label style="display:flex;gap:.55rem;align-items:center;font-size:.87rem;cursor:pointer">
                <input type="checkbox" id="<?= $key ?>" name="<?= $key ?>" value="1" <?= $value === '1' ? 'checked' : '' ?> style="accent-color:var(--leaf)">
                Enabled
              </label>
            <?php elseif ($type === 'number'): ?>
              <div class="d-flex align-items-center gap-2">
                <span class="text-muted-3">R</span>
                <input class="input-fc" type="number" step="1" min="0" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($value) ?>">
              </div>
            <?php else: ?>
              <input class="input-fc" type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($value) ?>">
            <?php endif; ?>
            <?php if ($hint): ?><div class="field__hint"><?= e($hint) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="d-flex gap-2 mt-3">
    <button class="btn-fc btn-fc--lg" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save all settings</button>
  </div>
</form>

<div class="card-fc card-fc--pad mt-4">
  <h2 style="font-size:1.05rem">Collection points</h2>
  <p class="text-muted-2" style="font-size:.86rem">Offered at checkout for depot collection and Runner pickup.</p>
  <div class="table-wrap">
    <table class="table-fc">
      <thead><tr><th>Name</th><th>Address</th><th>Hours</th><th class="text-end">Fee</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($points as $p): ?>
        <tr>
          <td><b><?= e($p['name']) ?></b></td>
          <td style="font-size:.85rem"><?= e($p['address']) ?><span class="d-block text-muted-3" style="font-size:.76rem"><?= e((string) $p['city']) ?>, <?= e((string) $p['province']) ?></span></td>
          <td style="font-size:.82rem"><?= e((string) $p['hours']) ?></td>
          <td class="text-end"><?= (float) $p['fee'] > 0 ? format_price_short((float) $p['fee']) : 'Free' ?></td>
          <td><span class="pill pill--ok">Active</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="field__hint mt-2 mb-0">
    Collection points are edited directly in the <code>pickup_points</code> table for now — they change rarely enough
    that a form was not worth the surface area.
  </p>
</div>

<div class="card-fc card-fc--pad mt-3">
  <h2 style="font-size:1.05rem">Environment</h2>
  <div class="table-wrap">
    <table class="table-fc">
      <tbody>
        <tr><td style="width:200px"><b>Mode</b></td><td><?= IS_PRODUCTION ? 'Production' : 'Local development' ?></td></tr>
        <tr><td><b>Database</b></td><td style="font-family:var(--font-mono);font-size:.82rem"><?= e(DB_NAME) ?> @ <?= e(DB_HOST) ?></td></tr>
        <tr><td><b>Site URL</b></td><td style="font-family:var(--font-mono);font-size:.82rem"><?= e(SITE_URL) ?></td></tr>
        <tr><td><b>Outgoing mail</b></td><td><?= SMTP_USER !== '' ? 'SMTP via ' . e(SMTP_HOST) : 'Not configured — falling back to PHP mail(), which no-ops on localhost' ?></td></tr>
        <tr><td><b>PHP</b></td><td><?= e(PHP_VERSION) ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/dash-foot.php'; ?>
