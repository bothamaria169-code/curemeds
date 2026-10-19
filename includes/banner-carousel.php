<?php
/**
 * Rotating promotional banner carousel.
 *
 * Usage:  $carousel_page = 'home'; include __DIR__ . '/includes/banner-carousel.php';
 *
 * Renders nothing at all when the page has no active wide banners, so a page
 * can include it unconditionally without leaving an empty strip behind.
 */
$carousel_page   = $carousel_page   ?? 'home';
$carousel_banners = get_banners($carousel_page, 'wide');

if ($carousel_banners):
    $carousel_id = 'carousel-' . $carousel_page;
?>
<section class="banner-carousel<?= count($carousel_banners) < 2 ? ' banner-carousel--single' : '' ?>"
         id="<?= e($carousel_id) ?>"
         data-carousel
         aria-roledescription="carousel"
         aria-label="Promotions">

  <div class="banner-carousel__viewport">
    <div class="banner-carousel__track" data-carousel-track>
      <?php foreach ($carousel_banners as $i => $b):
        $href = banner_link($b['link_url']);
        $tag  = $href ? 'a' : 'div';
      ?>
        <<?= $tag ?> class="banner-carousel__slide"
           <?= $href ? 'href="' . e($href) . '"' : '' ?>
           role="group"
           aria-roledescription="slide"
           aria-label="<?= e($b['title']) ?> (<?= $i + 1 ?> of <?= count($carousel_banners) ?>)"
           <?= $i > 0 ? 'aria-hidden="true" tabindex="-1"' : '' ?>>
          <img src="<?= e(product_image($b['image'])) ?>"
               alt="<?= e($b['alt_text'] ?: $b['title']) ?>"
               <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>
               decoding="async">
        </<?= $tag ?>>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (count($carousel_banners) > 1): ?>
    <button class="banner-carousel__arrow banner-carousel__arrow--prev" type="button"
            data-carousel-prev aria-label="Previous promotion">
      <i class="fa-solid fa-chevron-left"></i>
    </button>
    <button class="banner-carousel__arrow banner-carousel__arrow--next" type="button"
            data-carousel-next aria-label="Next promotion">
      <i class="fa-solid fa-chevron-right"></i>
    </button>

    <div class="banner-carousel__dots" role="tablist" aria-label="Choose promotion">
      <?php foreach ($carousel_banners as $i => $b): ?>
        <button class="banner-carousel__dot<?= $i === 0 ? ' is-on' : '' ?>" type="button"
                role="tab" data-carousel-dot="<?= $i ?>"
                aria-label="<?= e($b['title']) ?>"
                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>
