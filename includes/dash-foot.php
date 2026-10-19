<?php $extra_js = $extra_js ?? []; ?>
  </div><!-- /.dash-main -->
</div><!-- /.dash -->

<div class="toast-wrap" id="toastWrap" aria-live="polite"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const SITE_URL = <?= json_encode(SITE_URL) ?>;
  const FC_USER  = <?= json_encode(['id' => (int) $user['id'], 'reseller' => $isRes]) ?>;
</script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<script src="<?= asset('assets/js/cart.js') ?>"></script>
<?php foreach ($extra_js as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
