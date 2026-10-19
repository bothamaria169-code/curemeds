/* CureMeds — newsletter sign-up gate / popup.
 *
 * The markup (includes/newsletter-gate.php) already works without this file:
 * its form posts to modules/newsletter/join.php and redirects back. This
 * script upgrades it — submits in place, shows the welcome code, traps focus
 * while the gate is open, and in popup mode handles the delay and the
 * "don't show again for a week" dismissal.
 */
(function () {
  'use strict';

  const gate = document.getElementById('nlGate');
  if (!gate) return;

  const mode    = gate.dataset.mode;            // 'locked' | 'popup'
  const form    = gate.querySelector('[data-nl-form]');
  const input   = form.querySelector('input[type="email"]');
  const button  = form.querySelector('button[type="submit"]');
  const errorEl = gate.querySelector('[data-nl-error]');
  const DISMISS_KEY  = 'fc_nl_dismissed';
  const DISMISS_DAYS = 7;

  // Storage can throw (private mode, blocked site data) — never let that break the page.
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* ignore */ } }
  };

  /* ── Opening and closing ──────────────────────────────────────────── */

  // Everything that isn't the gate, so it can be made inert while the gate is open:
  // keyboard focus and screen readers then stay inside the dialog.
  const background = () => Array.from(document.body.children).filter(
    (el) => el !== gate && !['SCRIPT', 'STYLE', 'LINK'].includes(el.tagName)
  );

  let lastFocus = null;

  function open() {
    lastFocus = document.activeElement;
    gate.hidden = false;
    document.body.classList.add('nl-open');
    background().forEach((el) => { el.inert = true; });
    requestAnimationFrame(() => gate.classList.add('is-visible'));
    setTimeout(() => input.focus({ preventScroll: true }), 120);
  }

  function close() {
    gate.classList.remove('is-visible');
    document.body.classList.remove('nl-open', 'is-gated');
    background().forEach((el) => { el.inert = false; });
    setTimeout(() => { gate.hidden = true; }, 220);
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
  }

  if (mode === 'locked') {
    open();
  } else {
    const dismissedAt = parseInt(store.get(DISMISS_KEY) || '0', 10);
    const recentlyDismissed = Date.now() - dismissedAt < DISMISS_DAYS * 864e5;

    if (!gate.hidden) {
      open();                                   // came back from join.php with an error
    } else if (!recentlyDismissed) {
      const delay = Math.max(0, parseInt(gate.dataset.delay || '8', 10)) * 1000;
      setTimeout(open, delay);
    }

    gate.querySelectorAll('[data-nl-close]').forEach((btn) =>
      btn.addEventListener('click', () => { store.set(DISMISS_KEY, String(Date.now())); close(); })
    );
    // Click on the dimmed backdrop, outside the panel, also dismisses a popup.
    gate.addEventListener('click', (e) => {
      if (e.target === gate) { store.set(DISMISS_KEY, String(Date.now())); close(); }
    });
  }

  document.addEventListener('keydown', (e) => {
    if (gate.hidden) return;
    if (e.key === 'Escape' && mode === 'popup') {
      store.set(DISMISS_KEY, String(Date.now()));
      close();
    }
  });

  /* ── Submitting ───────────────────────────────────────────────────── */

  const looksLikeEmail = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = input.value.trim();

    if (!looksLikeEmail(email)) {
      errorEl.textContent = 'Please enter a valid email address.';
      input.setAttribute('aria-invalid', 'true');
      input.focus();
      return;
    }

    errorEl.textContent = '';
    input.removeAttribute('aria-invalid');
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Joining…';

    const res = await window.fcPost('/modules/api/newsletter.php', {
      email: email,
      source: form.querySelector('[name="source"]').value
    });

    button.disabled = false;
    button.innerHTML = original;

    if (!res.success) {
      errorEl.textContent = res.message || 'Something went wrong — please try again.';
      input.setAttribute('aria-invalid', 'true');
      input.focus();
      return;
    }

    showDone(res);
  });

  function showDone(res) {
    gate.querySelector('[data-nl-step="form"]').hidden = true;
    const done = gate.querySelector('[data-nl-step="done"]');
    done.hidden = false;

    if (res.message && /already/i.test(res.message)) {
      done.querySelector('[data-nl-done-text]').textContent =
        'You were already on the list — welcome back. Happy shopping.';
    }

    if (res.coupon) {
      done.querySelector('[data-nl-code-box]').hidden = false;
      done.querySelector('[data-nl-code]').textContent = res.coupon;
      done.querySelector('[data-nl-offer]').textContent = res.offer || 'Your welcome code';
    }

    // Any other newsletter form on the page is now moot.
    document.querySelectorAll('[data-newsletter-band]').forEach((el) => el.remove());

    done.querySelector('[data-nl-finish]').focus();
  }

  gate.querySelector('[data-nl-finish]').addEventListener('click', close);

  const copyBtn = gate.querySelector('[data-nl-copy]');
  copyBtn.addEventListener('click', async () => {
    const code = gate.querySelector('[data-nl-code]').textContent;
    const hint = gate.querySelector('[data-nl-copy-hint]');
    try {
      await navigator.clipboard.writeText(code);
      hint.textContent = 'Copied — paste it at checkout';
    } catch (e) {
      hint.textContent = 'Your code: ' + code;
    }
  });
})();
