/**
 * Admin → AI & social media → Create a post.
 * Your own photo, carousel (2–10 photos), video, or a reel made from your
 * photos: each photo gets a line of text, slow zoom and a crossfade, then an
 * end card, recorded on a canvas with MediaRecorder. Files go straight to
 * ImageKit (signed by social.php), then social.php posts them.
 */
(() => {
  const card = document.getElementById('compose');
  if (!card) return;

  const $ = id => document.getElementById(id);
  const files = $('composeFiles'), thumbs = $('composeThumbs'), help = $('composeHelp');
  const caption = $('composeCaption'), topic = $('composeTopic'), product = $('composeProduct'), sample = $('composeSample');
  const reelBox = $('composeReel'), slides = $('composeSlides'), endText = $('composeEnd'), secs = $('composeSecs');
  const makeBtn = $('composeMake'), postBtn = $('composePost'), download = $('composeDownload'), status = $('composeStatus');
  const canvas = $('composeCanvas'), ctx = canvas.getContext('2d');
  const products = JSON.parse(document.getElementById('reelData')?.textContent || '[]');
  const W = 1080, H = 1920, FADE = 0.4, END = 2.5;
  const C = { navy: '#0A1B3D', blue: '#1557D0', teal: '#1BB3C8', grey: '#3A4D72' };
  const SERIF = 'Fraunces, Georgia, serif', SANS = 'Manrope, system-ui, sans-serif';

  const TYPES = {
    photo:    { accept: 'image/*', multiple: false, min: 1, max: 1,  help: 'One photo. Instagram crops it to 4:5 portrait.' },
    carousel: { accept: 'image/*', multiple: true,  min: 2, max: 10, help: '2 to 10 photos, swiped in order. TikTok is skipped (photos only).' },
    video:    { accept: 'video/mp4,video/quicktime,video/*', multiple: false, min: 1, max: 1, help: 'One video (MP4 or MOV, under 3 minutes). Instagram shows it as a reel; it keeps its sound.' },
    reel:     { accept: 'image/*', multiple: true,  min: 2, max: 10, help: '2 to 10 photos become a 9:16 reel with your text on each one. Press Make reel, check it, then Post.' },
  };
  const SAMPLES = [
    ['Product spotlight', '✨ Meet {name}! Everyday wellness support, made simple.\n\nOne small step for your routine, one big step for feeling your best. 💙'],
    ['Health tip', '💡 Health tip of the day: drink a glass of water first thing in the morning, take a 10-minute walk, and keep your daily supplements next to your toothbrush so you never forget. 🚶‍♀️💧'],
    ['New stock', '📦 Back in stock: {name}!\n\nIt sold out last time — order yours today and we courier it to your door or a Pudo locker near you. 🚚'],
    ['Weekend special', '🎉 Weekend treat for your wellness routine: {name} at {price}.\n\nTake care of yourself this weekend. 💚'],
    ['Customer love', '💬 "My order arrived in two days and the team followed up to check on me." — that\'s what care that follows up looks like. Thank you to every customer who trusts us. 🙏'],
    ['Winter immune', '❄️ Winter is coming — support your immune system with good sleep, warm meals, fresh air and {name}. Stay well, South Africa! 🇿🇦'],
  ];

  let type = 'photo', picked = [], reelBlob = null, reelExt = 'mp4', images = [], logo = null;

  const say = (msg, bad) => { status.textContent = msg; status.style.color = bad ? 'var(--danger)' : ''; };
  const chosenProduct = () => products.find(p => String(p.id) === product.value) || null;
  const fill = s => {
    const p = chosenProduct();
    return s.replaceAll('{name}', p ? p.name : 'our wellness range').replaceAll('{price}', p ? p.price : 'great prices').replaceAll('{domain}', card.dataset.domain);
  };
  const load = src => new Promise(ok => {
    if (!src) return ok(null);
    const img = new Image();
    img.onload = () => ok(img); img.onerror = () => ok(null); img.src = src;
  });
  const ease = x => 1 - Math.pow(1 - Math.min(Math.max(x, 0), 1), 3);

  SAMPLES.forEach(([label], i) => sample.add(new Option(label, String(i))));

  // ── Type and files ──────────────────────────────────────────────────
  function setType(t) {
    type = t;
    const cfg = TYPES[t];
    card.querySelectorAll('input[name="composeType"]').forEach(r => r.closest('label').classList.toggle('is-on', r.value === t));
    files.accept = cfg.accept; files.multiple = cfg.multiple;
    help.textContent = cfg.help;
    reelBox.hidden = t !== 'reel'; makeBtn.hidden = t !== 'reel';
    reset();
  }
  function reset() {
    picked = []; reelBlob = null; images = [];
    files.value = ''; thumbs.innerHTML = '';
    download.classList.add('d-none');
    say('');
  }
  function show() {
    thumbs.innerHTML = '';
    picked.forEach((f, i) => {
      const url = URL.createObjectURL(f);
      const el = f.type.startsWith('video/') ? document.createElement('video') : document.createElement('img');
      el.src = url; el.style.cssText = 'width:84px;height:84px;object-fit:cover;border-radius:10px;border:1px solid var(--line)';
      if (el.tagName === 'VIDEO') { el.muted = true; }
      el.title = `${i + 1}. ${f.name}`;
      thumbs.appendChild(el);
    });
  }
  files.addEventListener('change', async () => {
    const cfg = TYPES[type];
    picked = [...files.files].filter(f => f.type.startsWith(type === 'video' ? 'video/' : 'image/')).slice(0, cfg.max);
    reelBlob = null; download.classList.add('d-none');
    show();
    if (picked.length < cfg.min) say(`Choose at least ${cfg.min} ${type === 'video' ? 'video' : 'photos'}.`, true); else say(`${picked.length} file(s) ready.`);
    if (type === 'reel') { images = await Promise.all(picked.map(f => load(URL.createObjectURL(f)))); preview(); }
  });
  card.querySelectorAll('input[name="composeType"]').forEach(r => r.addEventListener('change', () => setType(r.value)));

  sample.addEventListener('change', () => { if (sample.value !== '') caption.value = fill(SAMPLES[+sample.value][1]); });

  // ── Sample data: caption, topic, slide text — and product photos if none uploaded ──
  $('composeSampleData').addEventListener('click', async () => {
    if (!product.value && products.length) product.value = String(products[0].id);
    const p = chosenProduct();
    topic.value = p ? `Why ${p.name} belongs in your daily routine` : 'Simple daily habits for better wellness';
    caption.value = fill(SAMPLES[0][1]);
    sample.value = '0';
    if (type === 'reel') {
      slides.value = p
        ? [`Looking after yourself?`, p.name, ...(p.points || []).slice(0, 2), `Only ${p.price}`].join('\n')
        : 'Small habits\nBig difference\nStart today';
      if (!images.length && p) {
        images = (await Promise.all([p.header, p.shot].filter(Boolean).map(load))).filter(Boolean);
        if (images.length === 1) images.push(images[0]);
        say('Sample reel uses the product photos. Upload your own to replace them.');
      }
      preview();
    } else {
      say('Sample caption filled in. Upload your files, then Post.');
    }
  });

  // ── AI caption ───────────────────────────────────────────────────────
  const send = async fields => {
    const fd = new FormData();
    fd.append('csrf_token', card.dataset.csrf);
    Object.entries(fields).forEach(([k, v]) => [].concat(v).forEach(x => fd.append(k, x)));
    const res = await fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
    return res.json();
  };
  $('composeAi').addEventListener('click', async e => {
    e.target.disabled = true; say('Writing…');
    try {
      const r = await send({ action: 'compose_caption', topic: topic.value, product_id: product.value });
      if (r.ok) { caption.value = r.caption; say('Caption written — read it before posting.'); } else say(r.error, true);
    } catch { say('Could not reach the server.', true); }
    e.target.disabled = false;
  });

  // ── Reel from photos ─────────────────────────────────────────────────
  function wrap(text, font, maxW) {
    ctx.font = font;
    const lines = []; let line = '';
    for (const word of String(text).split(/\s+/)) {
      const test = line ? line + ' ' + word : word;
      if (ctx.measureText(test).width > maxW && line) { lines.push(line); line = word; } else line = test;
    }
    if (line) lines.push(line);
    return lines;
  }
  function cover(img, scale) {
    const s = Math.max(W / img.width, H / img.height) * scale;
    ctx.drawImage(img, (W - img.width * s) / 2, (H - img.height * s) / 2, img.width * s, img.height * s);
  }
  const lines = () => slides.value.split('\n').map(s => s.trim());
  const per = () => Math.min(6, Math.max(2, +secs.value || 3));
  const length = () => images.length * per() + END;

  function slide(i, t) {
    ctx.fillStyle = C.navy; ctx.fillRect(0, 0, W, H);
    cover(images[i], 1 + 0.1 * (t / per()));
    const text = lines()[i] || '';
    if (!text) return;
    const g = ctx.createLinearGradient(0, H * 0.5, 0, H);
    g.addColorStop(0, 'rgba(10,27,61,0)'); g.addColorStop(1, 'rgba(10,27,61,.92)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    ctx.globalAlpha = Math.min(ctx.globalAlpha, ease(t / 0.6));
    const font = `700 88px ${SERIF}`;
    const ls = wrap(text, font, 920).slice(0, 4);
    ctx.font = font; ctx.fillStyle = '#fff'; ctx.textAlign = 'center';
    ls.forEach((l, k) => ctx.fillText(l, W / 2, H - 260 - (ls.length - 1 - k) * 104));
    ctx.font = `800 36px ${SANS}`; ctx.fillStyle = C.teal; ctx.fillText('CUREMEDS', W / 2, H - 140);
  }
  function endCard(t) {
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
    if (logo) { const s = Math.min(760 / logo.width, 300 / logo.height) * (0.95 + 0.05 * ease(t / 0.8)); ctx.drawImage(logo, (W - logo.width * s) / 2, 520 + (300 - logo.height * s) / 2, logo.width * s, logo.height * s); }
    const font = `700 72px ${SERIF}`;
    ctx.font = font; ctx.fillStyle = C.navy; ctx.textAlign = 'center';
    wrap(endText.value || card.dataset.domain, font, 900).slice(0, 3).forEach((l, k) => ctx.fillText(l, W / 2, 1060 + k * 88));
  }
  function frame(t) {
    const n = images.length, p = per();
    ctx.globalAlpha = 1;
    if (t >= n * p) { endCard(t - n * p); return; }
    const i = Math.floor(t / p), local = t - i * p;
    slide(i, local);
    if (local > p - FADE) {   // crossfade into the next photo, or the end card
      ctx.globalAlpha = (local - (p - FADE)) / FADE;
      i + 1 < n ? slide(i + 1, 0) : endCard(0);
      ctx.globalAlpha = 1;
    }
  }
  function preview() { if (images.length) frame(Math.min(1, per() - 0.5)); }
  slides.addEventListener('input', preview);

  function pickType() {
    const types = ['video/mp4;codecs=avc1.42E01E', 'video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm'];
    return types.find(t => window.MediaRecorder && MediaRecorder.isTypeSupported(t)) || '';
  }
  makeBtn.addEventListener('click', async () => {
    if (images.length < 2) { say('Upload at least 2 photos (or press Fill with sample data).', true); return; }
    const mime = pickType();
    if (!canvas.captureStream || !mime) { say('This browser cannot record video. Use Chrome or Edge.', true); return; }
    reelExt = mime.startsWith('video/mp4') ? 'mp4' : 'webm';
    await Promise.all([document.fonts.load(`700 88px Fraunces`), document.fonts.load(`800 36px Manrope`)]).catch(() => {});
    logo = logo || await load(card.dataset.logo);
    makeBtn.disabled = true; download.classList.add('d-none');
    const rec = new MediaRecorder(canvas.captureStream(30), { mimeType: mime, videoBitsPerSecond: 6_000_000 });
    const chunks = [];
    rec.ondataavailable = e => e.data.size && chunks.push(e.data);
    const done = new Promise(ok => { rec.onstop = ok; });
    const total = length();
    rec.start(500);
    const t0 = performance.now();
    // A timer rather than requestAnimationFrame, so recording still finishes if the tab is hidden.
    await new Promise(ok => {
      const tick = () => {
        const t = (performance.now() - t0) / 1000;
        frame(Math.min(t, total - 0.01));
        say(`Recording… ${Math.min(Math.ceil(total), Math.ceil(t))} / ${Math.ceil(total)} s`);
        t < total ? setTimeout(tick, 1000 / 30) : ok();
      };
      tick();
    });
    rec.stop(); await done;
    reelBlob = new Blob(chunks, { type: mime.split(';')[0] });
    download.href = URL.createObjectURL(reelBlob);
    download.download = `curemeds-reel.${reelExt}`;
    download.classList.remove('d-none');
    makeBtn.disabled = false;
    say(`Reel ready (${(reelBlob.size / 1048576).toFixed(1)} MB). Check the caption, then Post.`);
  });

  // ── Upload and post ──────────────────────────────────────────────────
  async function upload(blob, name, folder) {
    const auth = await send({ action: 'ik_auth' });   // one signature per upload: ImageKit tokens are single-use
    if (!auth.ok) throw new Error(auth.error);
    const up = new FormData();
    up.append('file', blob, name); up.append('fileName', name); up.append('folder', folder); up.append('useUniqueFileName', 'true');
    ['publicKey', 'signature', 'expire', 'token'].forEach(k => up.append(k, auth[k]));
    const file = await (await fetch('https://upload.imagekit.io/api/v1/files/upload', { method: 'POST', body: up })).json();
    if (!file.url) throw new Error('ImageKit upload failed: ' + (file.message || 'no reason given'));
    return file.url;
  }

  postBtn.addEventListener('click', async () => {
    const cfg = TYPES[type];
    const platforms = [...card.querySelectorAll('.compose-platform:checked')].map(c => c.value);
    if (type === 'reel' ? !reelBlob : picked.length < cfg.min) {
      say(type === 'reel' ? 'Make the reel first.' : `Choose at least ${cfg.min} ${type === 'video' ? 'video' : 'photos'}.`, true); return;
    }
    if (!caption.value.trim()) { say('Write a caption first.', true); return; }
    if (!platforms.length) { say('Tick at least one platform.', true); return; }
    if (!confirm(`Post this ${type} to ${platforms.join(', ')} now?`)) return;
    postBtn.disabled = true;
    try {
      const urls = [];
      if (type === 'reel') {
        say('Uploading the reel…');
        urls.push(await upload(reelBlob, `curemeds-reel.${reelExt}`, '/curemeds/reels'));
      } else {
        for (const [i, f] of picked.entries()) {
          say(`Uploading ${i + 1} of ${picked.length}…`);
          urls.push(await upload(f, f.name.replace(/[^\w.\-]+/g, '-'), type === 'video' ? '/curemeds/videos' : '/curemeds/posts'));
        }
      }
      say(type === 'video' || type === 'reel' ? 'Posting — videos take a minute or two while ImageKit and Meta process them…' : 'Posting…');
      const r = await send({
        action: 'compose', type, 'urls[]': urls, caption: caption.value, product_id: product.value,
        add_link: $('composeLink').checked ? '1' : '', silent: type === 'reel' ? '1' : '', 'platforms[]': platforms,
      });
      say(r.message || r.error || 'Done.', !r.ok);
    } catch (e) {
      say(e.message || 'Something went wrong.', true);
    }
    postBtn.disabled = false;
  });

  setType('photo');
})();
