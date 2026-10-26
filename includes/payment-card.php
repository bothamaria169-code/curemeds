<?php
// How to pay: EFT details and cash on delivery (Admin → Settings → Payment).
$payMethods = payment_methods();
if (!$payMethods) { return; }
?>
<div class="card-fc card-fc--pad pay-card <?= $payCardClass ?? '' ?>">
  <b class="pay-card__title"><i class="fa-solid fa-wallet"></i> How to pay</b>
  <?php foreach ($payMethods as $k => $m): ?>
    <div class="pay-card__method">
      <span class="pay-card__label"><i class="fa-solid <?= e($m['icon']) ?>"></i> <?= e($m['label']) ?></span>
      <?php foreach ($m['rows'] as $label => $value): ?>
        <div class="pay-card__row"><span><?= e($label) ?></span><b class="<?= $label === 'Account number' ? 'money' : '' ?>"><?= e($label === 'Account number' ? format_account_number($value) : $value) ?></b></div>
      <?php endforeach; ?>
      <p><?= e($m['note']) ?></p>
    </div>
  <?php endforeach; ?>
</div>
