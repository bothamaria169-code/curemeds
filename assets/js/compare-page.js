/* ============================================================================
   CureMeds — compare page
   The tray lives in localStorage, so on first load the page has to put the ids
   into the URL and reload once for PHP to render the table.
   ============================================================================ */
(function () {
  'use strict';

  const KEY = 'fc_compare';
  const read  = () => { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch { return []; } };
  const write = (l) => { try { localStorage.setItem(KEY, JSON.stringify(l)); } catch {} };

  const params  = new URLSearchParams(location.search);
  const inUrl   = (params.get('ids') || '').split(',').filter(Boolean);
  const stored  = read().map((i) => String(i.id));

  // Sync the URL to the tray exactly once, then let PHP do the rendering.
  if (stored.join(',') !== inUrl.join(',')) {
    if (stored.length) {
      params.set('ids', stored.join(','));
      location.replace(location.pathname + '?' + params.toString());
    } else if (inUrl.length) {
      location.replace(location.pathname);
    }
    return;
  }

  document.querySelectorAll('[data-remove]').forEach((btn) => {
    btn.addEventListener('click', () => {
      write(read().filter((i) => String(i.id) !== btn.dataset.remove));
      location.replace(location.pathname);
    });
  });

  document.getElementById('clearAll')?.addEventListener('click', () => {
    write([]);
    location.replace(location.pathname);
  });
})();
