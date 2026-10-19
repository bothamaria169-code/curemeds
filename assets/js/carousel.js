/* ============================================================================
   CureMeds — promotional banner carousel
   Autoplay with arrows, dots, swipe and keyboard. Multiple carousels can live
   on one page (each [data-carousel] runs independently).
   ============================================================================ */
(function () {
  'use strict';

  const AUTOPLAY_MS = 6000;
  const SWIPE_MIN_PX = 40;

  document.querySelectorAll('[data-carousel]').forEach(initCarousel);

  function initCarousel(root) {
    const track = root.querySelector('[data-carousel-track]');
    const slides = Array.from(root.querySelectorAll('.banner-carousel__slide'));
    const dots = Array.from(root.querySelectorAll('[data-carousel-dot]'));
    if (!track || slides.length < 2) return;

    let index = 0;
    let timer = null;
    let paused = false;

    function paint() {
      track.style.transform = `translateX(-${index * 100}%)`;

      slides.forEach((slide, i) => {
        const hidden = i !== index;
        slide.setAttribute('aria-hidden', String(hidden));
        // A link inside a slide the viewer can't see must not be tabbable,
        // otherwise keyboard focus disappears off-screen mid-carousel.
        if (hidden) {
          slide.setAttribute('tabindex', '-1');
        } else {
          slide.removeAttribute('tabindex');
        }
      });

      dots.forEach((dot, i) => {
        dot.classList.toggle('is-on', i === index);
        dot.setAttribute('aria-selected', String(i === index));
      });
    }

    function go(to) {
      index = (to + slides.length) % slides.length;
      paint();
    }

    const next = () => go(index + 1);
    const prev = () => go(index - 1);

    function start() {
      stop();
      if (paused) return;
      timer = setInterval(next, AUTOPLAY_MS);
    }
    function stop() {
      if (timer) { clearInterval(timer); timer = null; }
    }

    root.querySelector('[data-carousel-next]')?.addEventListener('click', () => { next(); start(); });
    root.querySelector('[data-carousel-prev]')?.addEventListener('click', () => { prev(); start(); });
    dots.forEach((dot, i) => dot.addEventListener('click', () => { go(i); start(); }));

    // Pause while the visitor is actually looking at / interacting with it.
    root.addEventListener('mouseenter', () => { paused = true; stop(); });
    root.addEventListener('mouseleave', () => { paused = false; start(); });
    root.addEventListener('focusin', () => { paused = true; stop(); });
    root.addEventListener('focusout', (e) => {
      if (!root.contains(e.relatedTarget)) { paused = false; start(); }
    });

    // Don't burn cycles rotating a carousel on a background tab.
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) { stop(); } else { start(); }
    });

    root.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') { e.preventDefault(); next(); start(); }
      if (e.key === 'ArrowLeft')  { e.preventDefault(); prev(); start(); }
    });

    // Swipe. Tracked on the viewport so a horizontal drag doesn't fight the
    // page's own vertical scroll on a phone.
    let startX = 0, startY = 0, dragging = false;
    const viewport = root.querySelector('.banner-carousel__viewport') || root;

    viewport.addEventListener('touchstart', (e) => {
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      dragging = true;
      stop();
    }, { passive: true });

    viewport.addEventListener('touchend', (e) => {
      if (!dragging) return;
      dragging = false;
      const dx = e.changedTouches[0].clientX - startX;
      const dy = e.changedTouches[0].clientY - startY;
      // Only treat it as a swipe if it was mostly sideways.
      if (Math.abs(dx) > SWIPE_MIN_PX && Math.abs(dx) > Math.abs(dy)) {
        dx < 0 ? next() : prev();
      }
      start();
    }, { passive: true });

    if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
      track.style.transition = 'none';
    }

    paint();
    start();
  }
})();
