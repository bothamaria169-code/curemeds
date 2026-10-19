/* ============================================================================
   CureMeds — app shell: toasts, drawer, dropdowns, search suggest, wishlist,
   compare tray, newsletter, FAQ accordion, tabs, reveal-on-scroll.
   Plain ES2020, no build step — matches the rest of the stack.
   ============================================================================ */
(function () {
  'use strict';

  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  /* ── Toasts ─────────────────────────────────────────────────────────── */
  const ICONS = { ok: 'fa-circle-check', danger: 'fa-circle-exclamation', info: 'fa-circle-info' };

  window.fcToast = function (message, tone = 'ok') {
    const wrap = $('#toastWrap');
    if (!wrap) return;
    const el = document.createElement('div');
    el.className = 'toast-fc toast-fc--' + tone;
    el.innerHTML = `<i class="fa-solid ${ICONS[tone] || ICONS.info}"></i><span></span>`;
    el.querySelector('span').textContent = message;
    wrap.appendChild(el);
    setTimeout(() => {
      el.classList.add('is-out');
      el.addEventListener('animationend', () => el.remove(), { once: true });
    }, 3200);
  };

  /* ── Small fetch helper. Always resolves to an object so callers never
        have to guard against a thrown network error. ─────────────────── */
  window.fcPost = async function (path, data) {
    try {
      const res = await fetch(SITE_URL + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(data)
      });
      return await res.json();
    } catch (err) {
      return { success: false, message: 'Network problem — please try again.' };
    }
  };

  /* ── Mobile drawer ──────────────────────────────────────────────────── */
  const drawer = $('#drawer'), scrim = $('#scrim');
  const openDrawer = (on) => {
    if (!drawer) return;
    drawer.classList.toggle('is-open', on);
    scrim && scrim.classList.toggle('is-on', on);
    document.body.style.overflow = on ? 'hidden' : '';
    const t = $('#menuToggle');
    t && t.setAttribute('aria-expanded', String(on));
  };
  $('#menuToggle')?.addEventListener('click', () => openDrawer(!drawer.classList.contains('is-open')));
  $('#drawerClose')?.addEventListener('click', () => openDrawer(false));
  scrim?.addEventListener('click', () => {
    openDrawer(false);
    $('#shopFilters')?.classList.remove('is-open');
    $('#dashSide')?.classList.remove('is-open');
  });

  /* Filter + dashboard slide-overs share the scrim. */
  $('#filterToggle')?.addEventListener('click', () => {
    $('#shopFilters')?.classList.add('is-open');
    scrim?.classList.add('is-on');
  });
  $('#filterClose')?.addEventListener('click', () => {
    $('#shopFilters')?.classList.remove('is-open');
    scrim?.classList.remove('is-on');
  });
  $('#dashBurger')?.addEventListener('click', () => {
    $('#dashSide')?.classList.add('is-open');
    scrim?.classList.add('is-on');
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    openDrawer(false);
    $('#shopFilters')?.classList.remove('is-open');
    $('#dashSide')?.classList.remove('is-open');
    scrim?.classList.remove('is-on');
    $$('.dd.is-open').forEach((d) => d.classList.remove('is-open'));
  });

  /* ── Dropdowns ──────────────────────────────────────────────────────── */
  $$('[data-dd-toggle]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const dd = btn.closest('.dd');
      const wasOpen = dd.classList.contains('is-open');
      $$('.dd.is-open').forEach((d) => d.classList.remove('is-open'));
      dd.classList.toggle('is-open', !wasOpen);
      btn.setAttribute('aria-expanded', String(!wasOpen));
    });
  });
  document.addEventListener('click', () => $$('.dd.is-open').forEach((d) => d.classList.remove('is-open')));

  /* ── Live search suggest ────────────────────────────────────────────── */
  const searchInput = $('#siteSearch');
  const suggestBox  = $('#searchSuggest');
  if (searchInput && suggestBox) {
    let timer = null, cursor = -1;

    const close = () => { suggestBox.classList.remove('is-open'); cursor = -1; };

    const render = (rows) => {
      if (!rows.length) {
        suggestBox.innerHTML = '<div class="suggest__empty">Nothing matched. Try a brand, or browse the shop.</div>';
      } else {
        suggestBox.innerHTML = rows.map((r) => `
          <a href="${SITE_URL}/modules/shop/product.php?slug=${encodeURIComponent(r.slug)}" role="option">
            <img src="${r.image}" alt="" loading="lazy">
            <span>
              <span class="s-name">${r.name}</span>
              <span class="s-meta">${r.brand || ''}${r.pack ? ' · ' + r.pack : ''}</span>
            </span>
            <span class="s-price money">${r.price}</span>
          </a>`).join('');
      }
      suggestBox.classList.add('is-open');
    };

    searchInput.addEventListener('input', () => {
      const q = searchInput.value.trim();
      clearTimeout(timer);
      if (q.length < 2) { close(); return; }
      timer = setTimeout(async () => {
        const res = await fcPost('/modules/api/search.php', { q });
        if (res.success) render(res.results);
      }, 220);
    });

    searchInput.addEventListener('keydown', (e) => {
      const items = $$('a', suggestBox);
      if (!items.length || !suggestBox.classList.contains('is-open')) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        items[cursor]?.classList.remove('is-active');
        cursor = e.key === 'ArrowDown'
          ? (cursor + 1) % items.length
          : (cursor - 1 + items.length) % items.length;
        items[cursor].classList.add('is-active');
        items[cursor].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' && cursor > -1) {
        e.preventDefault();
        items[cursor].click();
      } else if (e.key === 'Escape') {
        close();
      }
    });

    document.addEventListener('click', (e) => {
      if (!suggestBox.contains(e.target) && e.target !== searchInput) close();
    });
  }

  /* "/" focuses search, the way every catalogue people already use does. */
  document.addEventListener('keydown', (e) => {
    if (e.key !== '/' || /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) return;
    e.preventDefault();
    searchInput?.focus();
  });

  /* ── Wishlist ───────────────────────────────────────────────────────── */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.wish-btn');
    if (!btn) return;
    e.preventDefault();

    if (!FC_USER) {
      fcToast('Sign in to save items.', 'info');
      setTimeout(() => { location.href = SITE_URL + '/modules/auth/login.php?redirect=' + encodeURIComponent(location.pathname + location.search); }, 900);
      return;
    }

    const res = await fcPost('/modules/api/wishlist.php', { product_id: btn.dataset.productId });
    if (!res.success) { fcToast(res.message || 'Could not update saved items.', 'danger'); return; }

    btn.classList.toggle('is-on', res.added);
    btn.setAttribute('aria-pressed', String(res.added));
    const icon = btn.querySelector('i');
    if (icon) icon.className = (res.added ? 'fa-solid' : 'fa-regular') + ' fa-heart';
    fcToast(res.added ? 'Saved for later.' : 'Removed from saved.');
  });

  /* ── Compare tray (per-browser, so it survives a reload) ────────────── */
  const COMPARE_KEY = 'fc_compare';
  const readCompare = () => { try { return JSON.parse(localStorage.getItem(COMPARE_KEY)) || []; } catch { return []; } };
  const writeCompare = (list) => { try { localStorage.setItem(COMPARE_KEY, JSON.stringify(list)); } catch {} };

  function paintCompare() {
    const list = readCompare();
    const bar = $('#compareBar');
    if (!bar) return;
    bar.classList.toggle('is-on', list.length > 0);
    $('#compareThumbs').innerHTML = list.map((i) => `<img src="${i.img}" alt="${i.name}" title="${i.name}">`).join('');
    $('#compareCount').textContent = list.length + (list.length === 1 ? ' item' : ' items');
    $$('.compare-btn').forEach((b) => b.classList.toggle('is-on', list.some((i) => i.id === b.dataset.productId)));
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.compare-btn');
    if (!btn) return;
    e.preventDefault();

    let list = readCompare();
    const id = btn.dataset.productId;

    if (list.some((i) => i.id === id)) {
      list = list.filter((i) => i.id !== id);
      fcToast('Removed from comparison.');
    } else {
      if (list.length >= 4) { fcToast('Compare up to four products at a time.', 'info'); return; }
      list.push({ id, name: btn.dataset.name, img: btn.dataset.img });
      fcToast('Added to comparison.');
    }
    writeCompare(list);
    paintCompare();
  });

  $('#compareClear')?.addEventListener('click', () => { writeCompare([]); paintCompare(); });
  paintCompare();

  /* ── Back-in-stock alerts ───────────────────────────────────────────── */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.notify-btn');
    if (!btn) return;
    const email = prompt('Where should we email you when this is back in stock?');
    if (!email) return;
    const res = await fcPost('/modules/api/stock-alert.php', { product_id: btn.dataset.productId, email });
    fcToast(res.message || (res.success ? 'We will let you know.' : 'Could not save that.'), res.success ? 'ok' : 'danger');
  });

  /* ── Newsletter (footer + anywhere else) ────────────────────────────── */
  $$('[data-newsletter]').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const msg = form.parentElement.querySelector('[data-newsletter-msg]');
      const btn = form.querySelector('button');
      const original = btn.innerHTML;
      btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';
      btn.disabled = true;

      const res = await fcPost('/modules/api/newsletter.php', {
        email: form.email.value,
        source: form.dataset.newsletter || 'footer'
      });

      btn.innerHTML = original;
      btn.disabled = false;
      if (msg) {
        msg.textContent = res.success && res.coupon
          ? res.message + ' Your code for ' + (res.offer || 'your first order') + ': ' + res.coupon
          : res.message;
        msg.style.color = res.success ? 'var(--leaf-light)' : 'var(--coral)';
      }
      if (res.success) form.reset();
    });
  });

  /* ── FAQ accordion ──────────────────────────────────────────────────── */
  $$('.faq-q').forEach((btn) => {
    btn.addEventListener('click', () => {
      const item = btn.closest('.faq-item');
      const open = item.classList.contains('is-open');
      item.classList.toggle('is-open', !open);
      btn.setAttribute('aria-expanded', String(!open));
    });
  });

  /* ── Tabs ───────────────────────────────────────────────────────────── */
  $$('.tabs').forEach((tabs) => {
    tabs.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-tab]');
      if (!btn) return;
      $$('button', tabs).forEach((b) => b.classList.remove('is-on'));
      btn.classList.add('is-on');
      const scope = tabs.parentElement;
      $$('.tab-panel', scope).forEach((p) => p.classList.toggle('is-on', p.dataset.panel === btn.dataset.tab));
    });
  });

  /* ── Quantity steppers ──────────────────────────────────────────────── */
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.qty button');
    if (!btn) return;
    e.preventDefault();
    const input = btn.parentElement.querySelector('input');
    const step  = btn.dataset.step === 'up' ? 1 : -1;
    const min   = parseInt(input.min || '1', 10);
    input.value = Math.max(min, (parseInt(input.value, 10) || min) + step);
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });

  /* ── Reveal on scroll ───────────────────────────────────────────────── */
  const reveals = $$('[data-reveal]');
  if (reveals.length && 'IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    reveals.forEach((el) => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(16px)';
      el.style.transition = 'opacity .55s ease, transform .55s cubic-bezier(.4,0,.2,1)';
    });
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry, i) => {
        if (!entry.isIntersecting) return;
        setTimeout(() => {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'none';
        }, i * 60);
        io.unobserve(entry.target);
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px' });
    reveals.forEach((el) => io.observe(el));
  }

  /* ── Copy-to-clipboard (bank details, reseller code) ────────────────── */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    try {
      await navigator.clipboard.writeText(btn.dataset.copy);
      fcToast('Copied to clipboard.');
    } catch {
      fcToast('Could not copy — select the text instead.', 'info');
    }
  });
})();
