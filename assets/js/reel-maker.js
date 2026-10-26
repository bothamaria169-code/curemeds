/**
 * Admin → AI & social media → Make a reel.
 * Draws a 12-second 1080×1920 product video on a canvas, records it with
 * MediaRecorder, uploads it straight to ImageKit (signed by social.php) and
 * asks social.php to publish it as a Facebook / Instagram reel.
 */
(() => {
  const card = document.getElementById('reel');
  if (!card) return;

  const data     = JSON.parse(document.getElementById('reelData').textContent || '[]');
  const canvas   = document.getElementById('reelCanvas');
  const ctx      = canvas.getContext('2d');
  const select   = document.getElementById('reelProduct');
  const caption  = document.getElementById('reelCaption');
  const makeBtn  = document.getElementById('reelMake');
  const postBtn  = document.getElementById('reelPost');
  const download = document.getElementById('reelDownload');
  const status   = document.getElementById('reelStatus');
  const W = 1080, H = 1920, LENGTH = 12;
  const C = { forest: '#0A1B3D', leaf: '#1557D0', lime: '#1BB3C8', cream: '#E8F0FC', ink: '#0A1B3D', grey: '#3A4D72' };
  const SERIF = 'Fraunces, Georgia, serif', SANS = 'Manrope, system-ui, sans-serif';

  let product = null, images = {}, blob = null, ext = 'mp4';

  const say = (msg, bad) => { status.textContent = msg; status.style.color = bad ? 'var(--danger)' : ''; };
  const load = src => new Promise(ok => {
    if (!src) return ok(null);
    const img = new Image();
    img.onload = () => ok(img);
    img.onerror = () => ok(null);
    img.src = src;
  });
  const ease = x => 1 - Math.pow(1 - Math.min(Math.max(x, 0), 1), 3);

  function wrap(text, font, maxW) {
    ctx.font = font;
    const lines = [];
    let line = '';
    for (const word of String(text).split(/\s+/)) {
      const test = line ? line + ' ' + word : word;
      if (ctx.measureText(test).width > maxW && line) { lines.push(line); line = word; } else { line = test; }
    }
    if (line) lines.push(line);
    return lines;
  }
  function text(lines, x, y, font, color, lh, align = 'center') {
    ctx.font = font; ctx.fillStyle = color; ctx.textAlign = align; ctx.textBaseline = 'alphabetic';
    lines.forEach((l, i) => ctx.fillText(l, x, y + i * lh));
    return y + lines.length * lh;
  }
  function cover(img, scale) {
    const s = Math.max(W / img.width, H / img.height) * scale;
    ctx.drawImage(img, (W - img.width * s) / 2, (H - img.height * s) / 2, img.width * s, img.height * s);
  }
  function contain(img, x, y, w, h, scale) {
    const s = Math.min(w / img.width, h / img.height) * scale;
    ctx.drawImage(img, x + (w - img.width * s) / 2, y + (h - img.height * s) / 2, img.width * s, img.height * s);
  }
  function pill(label, cx, cy, font, bg, fg, padX, h) {
    ctx.font = font;
    const w = ctx.measureText(label).width + padX * 2;
    ctx.fillStyle = bg;
    ctx.beginPath(); ctx.roundRect(cx - w / 2, cy - h / 2, w, h, h / 2); ctx.fill();
    ctx.fillStyle = fg; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(label, cx, cy + 4);
    ctx.textBaseline = 'alphabetic';
  }

  // Scene 1: the in-store photo with the product name.
  function intro(t) {
    const img = images.header || images.shot;
    ctx.fillStyle = C.forest; ctx.fillRect(0, 0, W, H);
    if (img) cover(img, 1 + 0.12 * (t / 3.2));
    const g = ctx.createLinearGradient(0, H * 0.4, 0, H);
    g.addColorStop(0, 'rgba(14,59,34,0)'); g.addColorStop(0.45, 'rgba(14,59,34,.85)'); g.addColorStop(1, 'rgba(14,59,34,.97)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    ctx.globalAlpha = ease(t / 0.8);
    text(['CUREMEDS'], W / 2, 1400, `800 40px ${SANS}`, C.lime, 50);
    text(wrap(product.name, `600 96px ${SERIF}`, 900), W / 2, 1520, `600 96px ${SERIF}`, '#fff', 108);
    ctx.globalAlpha = 1;
  }
  // Scene 2: the pack shot.
  function pack(t) {
    ctx.fillStyle = C.cream; ctx.fillRect(0, 0, W, H);
    ctx.save();
    ctx.shadowColor = 'rgba(14,59,34,.18)'; ctx.shadowBlur = 60; ctx.shadowOffsetY = 24;
    ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.roundRect(90, 160, 900, 1200, 56); ctx.fill();
    ctx.restore();
    if (images.shot) contain(images.shot, 130, 200, 820, 1120, 0.9 + 0.1 * ease(t / 1.2));
    const end = text(wrap(product.name, `600 76px ${SERIF}`, 920), W / 2, 1500, `600 76px ${SERIF}`, C.forest, 88);
    if (product.pack) text([product.pack], W / 2, end + 20, `700 44px ${SANS}`, C.grey, 50);
  }
  // Scene 3: price and benefits.
  function price(t) {
    const g = ctx.createLinearGradient(0, 0, W, H);
    g.addColorStop(0, C.forest); g.addColorStop(1, '#12284F');
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    text(['Only'], W / 2, 520, `600 64px ${SERIF}`, '#fff', 70);
    ctx.save(); ctx.translate(W / 2, 700); const s = 0.8 + 0.2 * ease(t / 0.6); ctx.scale(s, s);
    pill(product.price, 0, 0, `800 150px ${SANS}`, C.lime, C.forest, 70, 230);
    ctx.restore();
    let y = 1060;
    const points = product.points.length ? product.points : wrap(product.pack || 'Quality wellness support', `600 50px ${SANS}`, 800).slice(0, 2);
    points.forEach((p, i) => {
      ctx.globalAlpha = ease((t - 0.5 - i * 0.4) / 0.5);
      text(['✓'], 150, y, `800 56px ${SANS}`, C.lime, 60, 'left');
      y = text(wrap(p, `600 50px ${SANS}`, 760), 230, y, `600 50px ${SANS}`, '#fff', 64, 'left') + 44;
    });
    ctx.globalAlpha = 1;
  }
  // Scene 4: how to order.
  function order(t) {
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
    if (images.logo) contain(images.logo, 140, 380, 800, 320, 0.95 + 0.05 * ease(t / 0.8));
    text(['Order on WhatsApp'], W / 2, 960, `600 84px ${SERIF}`, C.forest, 90);
    pill(card.dataset.wa, W / 2, 1110, `800 70px ${SANS}`, C.leaf, '#fff', 60, 140);
    text(['Courier to your door, nationwide'], W / 2, 1320, `600 46px ${SANS}`, C.grey, 56);
    text([card.dataset.domain], W / 2, 1420, `800 46px ${SANS}`, C.leaf, 56);
  }

  const scenes = [[0, 3.2, intro], [3.2, 7, pack], [7, 10, price], [10, LENGTH, order]];
  function frame(t) {
    const i = scenes.findIndex(([, end]) => t < end);
    const [start, end, draw] = scenes[i === -1 ? scenes.length - 1 : i];
    draw(t - start);
    const next = scenes[i + 1];
    if (next && t > end - 0.4) {
      ctx.globalAlpha = (t - (end - 0.4)) / 0.4;
      next[2](t - next[0]);
      ctx.globalAlpha = 1;
    }
  }

  async function choose() {
    product = data.find(p => String(p.id) === select.value) || null;
    blob = null;
    download.classList.add('d-none'); postBtn.classList.add('d-none');
    if (!product) return;
    caption.value = product.caption;
    say('Loading photos…');
    await Promise.all([document.fonts.load(`600 90px Fraunces`), document.fonts.load(`800 50px Manrope`)]).catch(() => {});
    const [shot, header, logo] = await Promise.all([load(product.shot), load(product.header), load(card.dataset.logo)]);
    images = { shot, header, logo };
    frame(5);
    say('Press Make reel to record it (about 12 seconds).');
  }

  function pickType() {
    const types = ['video/mp4;codecs=avc1.42E01E', 'video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm'];
    return types.find(t => window.MediaRecorder && MediaRecorder.isTypeSupported(t)) || '';
  }

  async function make() {
    if (!product) { say('Choose a product first.', true); return; }
    const type = pickType();
    if (!canvas.captureStream || !type) { say('This browser cannot record video. Use Chrome or Edge.', true); return; }
    ext = type.startsWith('video/mp4') ? 'mp4' : 'webm';
    makeBtn.disabled = true; postBtn.classList.add('d-none'); download.classList.add('d-none');
    const rec = new MediaRecorder(canvas.captureStream(30), { mimeType: type, videoBitsPerSecond: 6_000_000 });
    const chunks = [];
    rec.ondataavailable = e => e.data.size && chunks.push(e.data);
    const done = new Promise(ok => { rec.onstop = ok; });
    rec.start(500);
    const t0 = performance.now();
    // A timer rather than requestAnimationFrame, so recording still finishes if the tab is hidden.
    await new Promise(ok => {
      const tick = () => {
        const t = (performance.now() - t0) / 1000;
        frame(Math.min(t, LENGTH - 0.01));
        say(`Recording… ${Math.min(LENGTH, Math.ceil(t))} / ${LENGTH} s`);
        t < LENGTH ? setTimeout(tick, 1000 / 30) : ok();
      };
      tick();
    });
    rec.stop();
    await done;
    blob = new Blob(chunks, { type: type.split(';')[0] });
    download.href = URL.createObjectURL(blob);
    download.download = `curemeds-reel-${product.id}.${ext}`;
    download.classList.remove('d-none'); postBtn.classList.remove('d-none');
    makeBtn.disabled = false;
    say(`Reel ready (${(blob.size / 1048576).toFixed(1)} MB). Check the caption, then Post reel.`);
  }

  const send = async (fields) => {
    const fd = new FormData();
    fd.append('csrf_token', card.dataset.csrf);
    Object.entries(fields).forEach(([k, v]) => [].concat(v).forEach(x => fd.append(k, x)));
    const res = await fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
    return res.json();
  };

  async function post() {
    const platforms = [...card.querySelectorAll('.reel-platform:checked')].map(c => c.value);
    if (!blob || !product) { say('Make the reel first.', true); return; }
    if (!platforms.length) { say('Tick Facebook Page and/or Instagram. Connect them above first.', true); return; }
    if (!confirm('Post this reel now?')) return;
    postBtn.disabled = true;
    try {
      say('Uploading to ImageKit…');
      const auth = await send({ action: 'ik_auth' });
      if (!auth.ok) throw new Error(auth.error);
      const up = new FormData();
      up.append('file', blob, `curemeds-reel-${product.id}.${ext}`);
      up.append('fileName', `curemeds-reel-${product.id}.${ext}`);
      up.append('folder', '/curemeds/reels');
      up.append('useUniqueFileName', 'true');
      ['publicKey', 'signature', 'expire', 'token'].forEach(k => up.append(k, auth[k]));
      const file = await (await fetch('https://upload.imagekit.io/api/v1/files/upload', { method: 'POST', body: up })).json();
      if (!file.url) throw new Error('ImageKit upload failed: ' + (file.message || 'no reason given'));
      say('ImageKit is preparing the MP4, then Meta fetches it. This can take a minute or two…');
      const r = await send({ action: 'reel', product_id: product.id, file_url: file.url, caption: caption.value, 'platforms[]': platforms });
      say(r.message || r.error || 'Done.', !r.ok);
    } catch (e) {
      say(e.message || 'Something went wrong.', true);
    }
    postBtn.disabled = false;
  }

  select.addEventListener('change', choose);
  makeBtn.addEventListener('click', make);
  postBtn.addEventListener('click', post);
})();
