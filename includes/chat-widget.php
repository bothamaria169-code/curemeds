<?php
/**
 * The shop assistant widget. Answers come from ShopAssistant via
 * modules/api/chat.php — real rows from the live database, no model call,
 * so it works offline and cannot quote a price that does not exist.
 */
?>
<button class="chat-fab" id="chatFab" type="button" aria-label="Open the shop assistant" aria-expanded="false">
  <i class="fa-solid fa-comment-dots"></i>
</button>

<section class="chat-panel" id="chatPanel" role="dialog" aria-label="Shop assistant" aria-modal="false">
  <header class="chat-head">
    <span style="width:32px;height:32px;display:grid;place-items:center;background:var(--leaf);border-radius:50%;">
      <i class="fa-solid fa-headset"></i>
    </span>
    <span>
      <b>CureMeds assistant</b>
      <span style="display:block">Answers from the live catalogue</span>
    </span>
    <button type="button" id="chatClose" aria-label="Close assistant"><i class="fa-solid fa-xmark"></i></button>
  </header>

  <div class="chat-body" id="chatBody"></div>

  <div class="chat-chips" id="chatChips"></div>

  <form class="chat-foot" id="chatForm" autocomplete="off">
    <input type="text" id="chatInput" placeholder="Ask about a price, delivery, margins…" aria-label="Your message">
    <button type="submit" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
  </form>
</section>
