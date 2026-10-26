<?php // Opens any [data-lightbox] link (testimonial screenshots, gallery photos) full size. ?>
<dialog class="cm-lightbox" id="cmLightbox" aria-label="Photo">
  <button type="button" class="cm-lightbox__close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
  <img src="" alt="">
  <p></p>
</dialog>
<script>
(() => {
  const box = document.getElementById('cmLightbox');
  if (!box || !box.showModal) return;
  const img = box.querySelector('img'), cap = box.querySelector('p');
  document.querySelectorAll('[data-lightbox]').forEach(a => a.addEventListener('click', ev => {
    ev.preventDefault();
    img.src = a.href; img.alt = a.querySelector('img').alt; cap.textContent = a.dataset.caption || '';
    box.showModal();
  }));
  box.addEventListener('click', ev => { if (ev.target === box || ev.target.closest('.cm-lightbox__close')) box.close(); });
})();
</script>
