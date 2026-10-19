/* ============================================================================
   CureMeds — product detail page: gallery, variant switching, review form
   ============================================================================ */
(function () {
  'use strict';

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const mainImage = $('#pdpMainImage');

  /* ── Thumbnails ─────────────────────────────────────────────────────── */
  $$('.pdp-thumb').forEach((thumb) => {
    thumb.addEventListener('click', () => {
      $$('.pdp-thumb').forEach((t) => t.classList.remove('is-on'));
      thumb.classList.add('is-on');
      if (mainImage) mainImage.src = thumb.dataset.full;
    });
  });

  /* ── Variant selection ──────────────────────────────────────────────── */
  const variantBtns = $$('.variant-btn');
  const priceEl     = $('#pdpPrice');
  const hidden      = $('#selectedVariant');
  const stockNote   = $('#variantStock');

  // The base price the server rendered, in cents, so we can add a variant
  // surcharge without a round trip. The server re-prices on add-to-cart
  // regardless, so this is display only.
  const basePrice = priceEl ? parseFloat(priceEl.textContent.replace(/[^\d.]/g, '')) : 0;

  function selectVariant(btn) {
    variantBtns.forEach((b) => b.classList.remove('is-on'));
    btn.classList.add('is-on');
    if (hidden) hidden.value = btn.dataset.variantId;

    if (btn.dataset.image && mainImage) {
      mainImage.src = btn.dataset.image;
      $$('.pdp-thumb').forEach((t) => t.classList.toggle('is-on', t.dataset.full === btn.dataset.image));
    }

    const add = parseFloat(btn.dataset.add || '0');
    if (priceEl && add) {
      priceEl.textContent = 'R' + (basePrice + add).toFixed(2);
    }

    const stock = parseInt(btn.dataset.stock || '0', 10);
    if (stockNote) {
      stockNote.textContent = stock > 10 ? '' : (stock > 0 ? `Only ${stock} left in this option.` : 'This option is sold out.');
      stockNote.style.color = stock > 0 && stock <= 10 ? 'var(--warn)' : 'var(--coral)';
    }
  }

  variantBtns.forEach((btn) => btn.addEventListener('click', () => selectVariant(btn)));
  if (variantBtns.length) {
    const first = variantBtns.find((b) => !b.disabled);
    if (first) selectVariant(first);
  }

  /* ── Deep-link a tab: /product.php?slug=x#reviews ────────────────────── */
  if (location.hash === '#reviews') {
    $('.tabs button[data-tab="reviews"]')?.click();
    setTimeout(() => $('#reviews')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
  }

  /* ── Review submission ──────────────────────────────────────────────── */
  $('#reviewForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const msg  = $('#reviewMsg');
    const btn  = form.querySelector('button[type=submit]');

    btn.disabled = true;
    const res = await fcPost('/modules/api/review.php', {
      product_id: form.product_id.value,
      rating: form.rating.value,
      title: form.title.value,
      comment: form.comment.value
    });
    btn.disabled = false;

    msg.textContent = res.message;
    msg.style.color = res.success ? 'var(--leaf)' : 'var(--coral)';
    if (res.success) form.reset();
  });
})();
