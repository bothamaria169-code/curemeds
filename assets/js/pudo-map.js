/* CureMeds — Pudo locker picker for the enquiry list (ported from FairCart).
 * Leaflet + OpenStreetMap (as Pudo's own plugin uses), clustered markers,
 * a search box, "near me", and a list of the closest lockers. Choosing one
 * fills #pudoLocker and re-quotes the delivery fee from pudo-rate.php.
 * The map only loads the first time the Pudo option is picked. */
(function () {
  const root = document.getElementById('pudoFields');
  if (!root) return;

  const input    = document.getElementById('pudoLocker');
  const chosen   = root.querySelector('[data-pudo-chosen]');
  const listEl   = root.querySelector('[data-pudo-list]');
  const search   = root.querySelector('[data-pudo-search]');
  const statusEl = root.querySelector('[data-pudo-status]');
  let map = null, lockers = [], byCode = {}, markers = {}, started = false;

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function km(a, b) {
    const R = 6371, t = Math.PI / 180;
    const dLat = (b.lat - a.lat) * t, dLng = (b.lng - a.lng) * t;
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * t) * Math.cos(b.lat * t) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
  }

  function choose(code, fly) {
    const l = byCode[code];
    if (!l) return;
    input.value = l.code;
    chosen.hidden = false;
    chosen.innerHTML =
      '<i class="fa-solid fa-circle-check text-leaf"></i><span><b>' + esc(l.name) + '</b>' +
      '<small class="d-block text-muted-2">' + esc(l.address) + '</small>' +
      (l.hours ? '<small class="d-block text-muted-3">' + esc(l.hours) + '</small>' : '') + '</span>';
    listEl.querySelectorAll('[data-code]').forEach((b) => b.classList.toggle('is-on', b.dataset.code === code));
    if (fly && map) {
      map.setView([l.lat, l.lng], Math.max(map.getZoom(), 15));
      markers[code]?.openPopup();
    }
    quote();
  }

  // Delivery row and total in the summary: the live locker fee, or back to the courier estimate.
  const shipLabel = document.getElementById('shipLabel');
  const totalLabel = document.getElementById('totalLabel');
  async function quote() {
    if (!input.value) return;
    try {
      const res = await fetch(SITE_URL + '/modules/api/pudo-rate.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ locker: input.value })
      });
      const data = await res.json();
      if (data.success && pudoPicked()) {
        shipLabel.textContent = data.shipping_label;
        totalLabel.textContent = data.total_label;
      }
    } catch (e) { /* the estimate stays; staff confirm the total anyway */ }
  }
  const radios = document.querySelectorAll('input[name="delivery_method"]');
  const pudoPicked = () => document.querySelector('input[name="delivery_method"]:checked')?.value === 'pudo';
  radios.forEach((r) => r.addEventListener('change', () => {
    root.hidden = !pudoPicked();
    if (pudoPicked()) {
      start();
      setTimeout(() => map && map.invalidateSize(), 60);
      quote();
    } else {
      shipLabel.textContent = shipLabel.dataset.courier;
      totalLabel.textContent = totalLabel.dataset.courier;
    }
  }));
  // A Pudo enquiry needs a locker before it goes to WhatsApp.
  document.getElementById('enquiryForm')?.addEventListener('submit', (e) => {
    if (pudoPicked() && !input.value) {
      e.preventDefault();
      e.stopImmediatePropagation();
      statusEl.textContent = 'Choose a locker first — tap one in the list or on the map.';
      root.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }, true);

  function renderList(from) {
    const centre = from || map.getCenter();
    const q = search.value.trim().toLowerCase();
    let rows = lockers;
    if (q) {
      rows = rows.filter((l) => (l.name + ' ' + l.town + ' ' + l.address + ' ' + l.code).toLowerCase().includes(q));
    }
    rows = rows.map((l) => ({ l, d: km(centre, l) })).sort((a, b) => a.d - b.d).slice(0, 5);
    listEl.innerHTML = rows.length
      ? rows.map(({ l, d }) =>
          '<button type="button" class="pudo-row' + (input.value === l.code ? ' is-on' : '') + '" data-code="' + esc(l.code) + '">' +
          '<span><b>' + esc(l.name) + '</b><small>' + esc(l.address) + '</small></span>' +
          '<em>' + (d < 10 ? d.toFixed(1) : Math.round(d)) + ' km</em></button>').join('')
      : '<p class="text-muted-2 mb-0" style="font-size:.85rem">No lockers match that. Try a suburb or town name.</p>';
  }

  async function start() {
    if (started) return;
    started = true;
    statusEl.textContent = 'Loading lockers…';
    try {
      const res = await fetch(SITE_URL + '/modules/api/pudo-lockers.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await res.json();
      if (!data.success) throw new Error(data.message || 'Could not load lockers.');
      lockers = data.lockers;
    } catch (e) {
      statusEl.textContent = e.message || 'Could not load the locker map.';
      started = false;
      return;
    }
    lockers.forEach((l) => { byCode[l.code] = l; });
    statusEl.textContent = lockers.length.toLocaleString() + ' Pudo lockers across South Africa';

    map = L.map(root.querySelector('[data-pudo-map]'), { scrollWheelZoom: false }).setView([-26.1, 28.05], 10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const icon = L.divIcon({ className: 'pudo-pin', html: '<i class="fa-solid fa-box-archive"></i>', iconSize: [30, 30], iconAnchor: [15, 28], popupAnchor: [0, -26] });
    const group = L.markerClusterGroup ? L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 45 }) : L.layerGroup();
    lockers.forEach((l) => {
      const m = L.marker([l.lat, l.lng], { icon }).bindPopup(
        '<b>' + esc(l.name) + '</b><br><small>' + esc(l.address) + '</small>' +
        (l.hours ? '<br><small style="color:#7C8899">' + esc(l.hours) + '</small>' : '') +
        '<br><button type="button" class="btn-fc btn-fc--sm mt-2" data-pick="' + esc(l.code) + '">Deliver here</button>'
      );
      markers[l.code] = m;
      group.addLayer(m);
    });
    map.addLayer(group);
    map.on('popupopen', (e) => {
      e.popup.getElement().querySelector('[data-pick]')?.addEventListener('click', (ev) => {
        choose(ev.currentTarget.dataset.pick, false);
        map.closePopup();
      });
    });
    map.on('moveend', () => renderList());

    if (input.value && byCode[input.value]) choose(input.value, true);
    renderList();
  }

  listEl.addEventListener('click', (e) => {
    const b = e.target.closest('[data-code]');
    if (b) choose(b.dataset.code, true);
  });

  let t = null;
  search.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => {
      if (!map) return;
      renderList();
      const first = listEl.querySelector('[data-code]');
      if (first && search.value.trim().length > 2) {
        const l = byCode[first.dataset.code];
        map.setView([l.lat, l.lng], 13);
      }
    }, 250);
  });
  // Enter in the search box must not send the enquiry.
  search.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });

  root.querySelector('[data-pudo-near]')?.addEventListener('click', () => {
    if (!navigator.geolocation) { statusEl.textContent = 'Your browser cannot share its location — search instead.'; return; }
    statusEl.textContent = 'Finding lockers near you…';
    navigator.geolocation.getCurrentPosition((pos) => {
      const here = { lat: pos.coords.latitude, lng: pos.coords.longitude };
      map.setView([here.lat, here.lng], 14);
      renderList(here);
      statusEl.textContent = 'Closest lockers to you';
    }, () => { statusEl.textContent = 'Location was not shared — type your suburb instead.'; }, { timeout: 10000 });
  });

  if (!root.hidden) start();
})();
