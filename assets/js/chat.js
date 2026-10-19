/* ============================================================================
   CureMeds — shop assistant widget
   ============================================================================ */
(function () {
  'use strict';

  const panel = document.getElementById('chatPanel');
  if (!panel) return;

  const fab   = document.getElementById('chatFab');
  const body  = document.getElementById('chatBody');
  const chips = document.getElementById('chatChips');
  const form  = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');

  let started = false;

  function bubble(html, who) {
    const el = document.createElement('div');
    el.className = 'chat-msg chat-msg--' + who;
    // Bot replies are assembled server-side from database rows and contain only
    // the markup ShopAssistant emits (links, <b>, <br>); user text is escaped.
    if (who === 'bot') { el.innerHTML = html; } else { el.textContent = html; }
    body.appendChild(el);
    body.scrollTop = body.scrollHeight;
    return el;
  }

  function paintChips(list) {
    chips.innerHTML = '';
    (list || []).forEach((label) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'chip';
      b.textContent = label;
      b.addEventListener('click', () => send(label));
      chips.appendChild(b);
    });
  }

  async function send(text) {
    const message = (text || input.value).trim();
    if (!message) return;

    bubble(message, 'me');
    input.value = '';
    paintChips([]);

    const thinking = bubble('<i class="fa-solid fa-ellipsis fa-fade"></i>', 'bot');
    const res = await fcPost('/modules/api/chat.php', { message });
    thinking.remove();

    if (res.success) {
      bubble(res.reply, 'bot');
      paintChips(res.chips);
    } else {
      bubble('Something went wrong on my side. Try again, or use the contact page.', 'bot');
    }
  }

  function open(on) {
    panel.classList.toggle('is-open', on);
    fab.setAttribute('aria-expanded', String(on));
    fab.innerHTML = on ? '<i class="fa-solid fa-xmark"></i>' : '<i class="fa-solid fa-comment-dots"></i>';

    if (on && !started) {
      started = true;
      send('');
    }
    if (on) setTimeout(() => input.focus(), 250);
  }

  fab.addEventListener('click', () => open(!panel.classList.contains('is-open')));
  document.getElementById('chatClose').addEventListener('click', () => open(false));
  form.addEventListener('submit', (e) => { e.preventDefault(); send(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && panel.classList.contains('is-open')) open(false);
  });
})();
