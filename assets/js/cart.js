/* ============================================================================
   CureMeds — cart interactions
   ============================================================================ */
(function () {
  'use strict';

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  function paintBadges(count) {
    $$('[data-cart-badge]').forEach((b) => {
      b.textContent = count;
      b.classList.toggle('d-none', count < 1);
    });
  }

  /* ── Add to basket ──────────────────────────────────────────────────── */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.add-to-cart');
    if (!btn) return;
    e.preventDefault();

    // On the PDP the selected variant lives on a hidden input; cards have none.
    const variantId = btn.dataset.variantId || $('#selectedVariant')?.value || null;
    const qtyInput  = btn.dataset.qtyFrom ? $(btn.dataset.qtyFrom) : null;
    const qty       = parseInt(qtyInput?.value || btn.dataset.qty || '1', 10);

    if (btn.dataset.requiresVariant === '1' && !variantId) {
      fcToast('Choose an option first.', 'info');
      $('#variantRow')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';

    const res = await fcPost('/modules/api/cart.php', {
      action: 'add',
      product_id: btn.dataset.productId,
      variant_id: variantId,
      qty
    });

    btn.disabled = false;
    if (res.success) {
      btn.innerHTML = '<i class="fa-solid fa-check"></i> Added';
      paintBadges(res.count);
      fcToast(res.message || 'Added to your cart.');
      if (btn.dataset.buyNow === '1') { location.href = SITE_URL + '/checkout.php'; return; }
      setTimeout(() => { btn.innerHTML = original; }, 1400);
    } else {
      btn.innerHTML = original;
      fcToast(res.message || 'Could not add that item.', 'danger');
    }
  });

  /* ── Quantity change on the cart page ───────────────────────────────── */
  document.addEventListener('change', async (e) => {
    const input = e.target.closest('[data-cart-qty]');
    if (!input) return;

    const res = await fcPost('/modules/api/cart.php', {
      action: 'set',
      key: input.dataset.cartQty,
      qty: parseInt(input.value, 10)
    });

    if (res.success) {
      paintBadges(res.count);
      location.reload();
    } else {
      fcToast(res.message || 'Could not update that line.', 'danger');
    }
  });

  /* ── Remove a line ──────────────────────────────────────────────────── */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-cart-remove]');
    if (!btn) return;
    e.preventDefault();

    const res = await fcPost('/modules/api/cart.php', { action: 'remove', key: btn.dataset.cartRemove });
    if (res.success) {
      const line = btn.closest('.cart-line');
      if (line) {
        line.style.transition = 'opacity .25s, transform .25s';
        line.style.opacity = '0';
        line.style.transform = 'translateX(-16px)';
      }
      paintBadges(res.count);
      setTimeout(() => location.reload(), 260);
    }
  });

  /* ── Coupon ─────────────────────────────────────────────────────────── */
  $('#couponForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = $('#couponCode');
    const msg   = $('#couponMsg');
    const res   = await fcPost('/modules/api/coupon.php', { code: input.value });

    if (msg) {
      msg.textContent = res.message;
      msg.style.color = res.success ? 'var(--leaf)' : 'var(--coral)';
    }
    if (res.success) setTimeout(() => location.reload(), 700);
  });

  $('#couponRemove')?.addEventListener('click', async (e) => {
    e.preventDefault();
    await fcPost('/modules/api/coupon.php', { remove: true });
    location.reload();
  });

  /* ── Checkout: fulfilment choice drives which fields show and the total ─ */
  const fulfilmentInputs = $$('input[name="fulfilment"]');
  if (fulfilmentInputs.length) {
    const sync = () => {
      const value = $('input[name="fulfilment"]:checked')?.value;
      $('#deliveryFields') && ($('#deliveryFields').hidden = value !== 'delivery');
      $('#pickupFields')   && ($('#pickupFields').hidden   = value !== 'collection');
      $('#pudoFields')     && ($('#pudoFields').hidden     = value !== 'pudo');
      if (value === 'pudo') document.dispatchEvent(new Event('fc:pudo-shown'));

      const addr = $('#shippingAddress');
      if (addr) addr.required = value === 'delivery';

      // Ask the server for the shipping figure rather than duplicating the
      // free-delivery threshold rule in two places.
      fcPost('/modules/api/shipping.php', {
        fulfilment: value,
        pickup_point_id: $('#pickupPoint')?.value || null,
        pudo_locker: $('#pudoLocker')?.value || null
      }).then((res) => {
        if (!res.success) return;
        $('#shipAmount')  && ($('#shipAmount').textContent  = res.shipping_label);
        $('#orderTotal')  && ($('#orderTotal').textContent  = res.total_label);
      });
    };
    fulfilmentInputs.forEach((i) => i.addEventListener('change', sync));
    $('#pickupPoint')?.addEventListener('change', sync);
    // The locker map sets #pudoLocker and fires this, so the live Pudo rate shows straight away.
    document.addEventListener('fc:pudo-locker', sync);
    sync();
  }
})();
