<?php
// Live ticker: real purchases and deliveries from Admin → Live ticker, one at a time in the corner.
if (get_setting('ticker_enabled', '1') !== '1') { return; }
$tickerRows = array_map(fn($r) => [
    'who'  => $r['customer'] . ($r['town'] ? ' from ' . $r['town'] : ''),
    'what' => ($r['kind'] === 'received' ? 'received ' : 'just bought ') . $r['product_name'],
    'when' => time_ago((string) $r['happened_at']),
    'img'  => product_image($r['featured_image'] ?? null),
    'url'  => $r['slug'] ? SITE_URL . '/modules/shop/product.php?slug=' . rawurlencode((string) $r['slug']) : SITE_URL . '/shop.php',
    'kind' => $r['kind'],
], purchase_feed(12));
if (!$tickerRows) { return; }
?>
<a class="cm-ticker" id="cmTicker" href="#" aria-live="polite">
  <img src="" alt="" width="48" height="48">
  <span class="cm-ticker__text"><b></b> <span></span><span class="cm-ticker__when"><i class="fa-solid fa-circle-check"></i> <em></em></span></span>
  <button type="button" class="cm-ticker__close" aria-label="Hide"><i class="fa-solid fa-xmark"></i></button>
</a>
<script>
(() => {
  const rows = <?= json_encode($tickerRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const el = document.getElementById('cmTicker');
  try { if (sessionStorage.getItem('cmTickerOff')) return; } catch (e) {}
  let i = 0, timer;
  const show = () => {
    const r = rows[i++ % rows.length];
    el.href = r.url;
    el.querySelector('img').src = r.img;
    el.querySelector('b').textContent = r.who;
    el.querySelector('.cm-ticker__text > span').textContent = r.what;
    el.querySelector('em').textContent = (r.kind === 'received' ? 'Delivered · ' : 'Verified order · ') + r.when;
    el.classList.add('is-on');
    timer = setTimeout(() => { el.classList.remove('is-on'); timer = setTimeout(show, 12000); }, 6000);
  };
  el.querySelector('.cm-ticker__close').addEventListener('click', ev => {
    ev.preventDefault(); clearTimeout(timer); el.classList.remove('is-on');
    try { sessionStorage.setItem('cmTickerOff', '1'); } catch (e) {}
  });
  timer = setTimeout(show, 5000);
})();
</script>
