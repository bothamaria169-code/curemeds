<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$viewer = current_user();
$sent   = false;
$errors = [];
$form   = [
    'name'    => $viewer['name']  ?? '',
    'email'   => $viewer['email'] ?? '',
    'phone'   => $viewer['phone'] ?? '',
    'subject' => (string) ($_GET['subject'] ?? ''),
    'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) { $form[$k] = trim((string) ($_POST[$k] ?? '')); }

    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please send again.';
    } elseif (!rate_limit('contact', 5, 900)) {
        $errors[] = 'You have sent a few messages already. Give it fifteen minutes.';
    }
    if (mb_strlen($form['name']) < 2)     { $errors[] = 'Enter your name.'; }
    if (!safe_email($form['email']))      { $errors[] = 'Enter a valid email address so we can reply.'; }
    if (mb_strlen($form['message']) < 10) { $errors[] = 'Tell us a bit more — at least a sentence.'; }

    if (!$errors) {
        // Saved BEFORE email is attempted: an SMTP outage must never lose an
        // enquiry. Admin → Messages is the source of truth, not the inbox.
        db_insert('contact_messages', [
            'name'    => sanitize($form['name']),
            'email'   => $form['email'],
            'phone'   => sanitize($form['phone']) ?: null,
            'subject' => sanitize($form['subject']) ?: null,
            'message' => sanitize($form['message']),
        ]);

        Mailer::send(
            ADMIN_EMAIL,
            'CureMeds enquiry: ' . ($form['subject'] ?: 'No subject'),
            email_wrap(
                '<p><b>' . e($form['name']) . '</b> (' . e($form['email']) . ')'
                . ($form['phone'] ? ' · ' . e($form['phone']) : '') . ' wrote:</p>'
                . '<blockquote style="border-left:3px solid #1557D0;padding-left:12px;color:#4A5768;">'
                . nl2br(e($form['message'])) . '</blockquote>'
            ),
            '',
            $form['email'],
            $form['name']
        );

        Mailer::send($form['email'], 'We got your message — CureMeds', email_wrap(
            '<p>Thanks ' . e($form['name']) . ' — your message is with us and someone will come back to you within one working day.</p>'
            . '<p style="color:#7C8899;font-size:13px;">What you sent:</p>'
            . '<blockquote style="border-left:3px solid #E3E8EF;padding-left:12px;color:#4A5768;">'
            . nl2br(e($form['message'])) . '</blockquote>'
        ));

        $sent = true;
        $form = array_map(fn() => '', $form);
    }
}

$faqs = db()->query("SELECT * FROM faqs WHERE status='published' ORDER BY topic, sort_order LIMIT 6")->fetchAll();

$page_title       = 'Contact us — ' . SITE_NAME;
$meta_description = 'Get hold of CureMeds by phone, WhatsApp or email. Every enquiry is logged, so nothing gets lost.';
include __DIR__ . '/includes/header.php';
?>

<header class="page-head">
  <div class="container">
    <nav class="crumbs"><a href="<?= SITE_URL ?>/index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Contact</span></nav>
    <h1>Talk to a person</h1>
    <p>Every message is saved to our system before it is even emailed, so it cannot quietly disappear. We reply within one working day.</p>
  </div>
</header>

<div class="section section--tight">
  <div class="container">
    <div class="row g-4">

      <div class="col-lg-7">
        <div class="card-fc card-fc--pad">
          <?php if ($sent): ?>
            <div class="text-center py-4">
              <div class="empty__icon" style="background:var(--leaf-wash);color:var(--leaf)"><i class="fa-solid fa-paper-plane"></i></div>
              <h2>Message sent</h2>
              <p class="lede">We have it, and a copy is in your inbox. Someone will come back to you within one working day.</p>
              <a href="<?= SITE_URL ?>/shop.php" class="btn-fc mt-2">Back to the shop</a>
            </div>
          <?php else: ?>
            <h2 style="font-size:1.3rem">Send us a message</h2>

            <?php foreach ($errors as $err): ?>
              <div class="alert-fc alert-fc--danger"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div>
            <?php endforeach; ?>

            <form method="post" novalidate>
              <?= csrf_field() ?>
              <div class="row g-3">
                <div class="col-md-6 field">
                  <label for="name">Your name</label>
                  <input class="input-fc" type="text" id="name" name="name" value="<?= e($form['name']) ?>" required>
                </div>
                <div class="col-md-6 field">
                  <label for="email">Email address</label>
                  <input class="input-fc" type="email" id="email" name="email" value="<?= e($form['email']) ?>" required>
                </div>
                <div class="col-md-6 field">
                  <label for="phone">Phone <span class="text-muted-3 fw-normal">(optional)</span></label>
                  <input class="input-fc" type="tel" id="phone" name="phone" value="<?= e($form['phone']) ?>">
                </div>
                <div class="col-md-6 field">
                  <label for="subject">What is it about?</label>
                  <select class="select-fc" id="subject" name="subject">
                    <?php foreach ([
                      '' => 'Choose one…',
                      'Order enquiry' => 'An order I placed',
                      'Product question' => 'A product question',
                      'Trade & wholesale' => 'Trade or wholesale pricing',
                      'Delivery or collection' => 'Delivery or collection',
                      'Returns' => 'A return or refund',
                      'Something else' => 'Something else',
                    ] as $v => $label): ?>
                      <option value="<?= e($v) ?>" <?= $form['subject'] === $v ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12 field">
                  <label for="message">Your message</label>
                  <textarea class="textarea-fc" id="message" name="message" required minlength="10"
                            placeholder="Include your order number if it is about an order — it speeds things up a lot."><?= e($form['message']) ?></textarea>
                </div>
              </div>
              <button class="btn-fc btn-fc--lg" type="submit"><i class="fa-solid fa-paper-plane"></i> Send message</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card-fc card-fc--pad mb-3">
          <h2 style="font-size:1.1rem">Get hold of us directly</h2>
          <div class="d-flex flex-column gap-3 mt-3">
            <?php
            $wa = get_setting('contact_whatsapp', '');
            $channels = array_filter([
              ['fa-phone', 'Phone', get_setting('contact_phone', ''), 'tel:' . preg_replace('/\s/', '', (string) get_setting('contact_phone', ''))],
              $wa ? ['fa-brands fa-whatsapp', 'WhatsApp', 'Chat with us', 'https://wa.me/' . $wa] : null,
              ['fa-envelope', 'Email', get_setting('contact_email', SITE_EMAIL), 'mailto:' . get_setting('contact_email', SITE_EMAIL)],
              ['fa-location-dot', 'Depot', get_setting('contact_address', ''), null],
            ]);
            foreach ($channels as [$icon, $label, $value, $href]): ?>
              <div class="d-flex gap-3 align-items-start">
                <span style="width:40px;height:40px;display:grid;place-items:center;background:var(--leaf-wash);color:var(--leaf);border-radius:var(--r-pill);flex-shrink:0">
                  <i class="<?= str_starts_with($icon, 'fa-brands') ? $icon : 'fa-solid ' . $icon ?>"></i>
                </span>
                <span>
                  <span class="d-block text-muted-3" style="font-size:.74rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase"><?= e($label) ?></span>
                  <?php if ($href): ?>
                    <a href="<?= e($href) ?>" <?= str_starts_with($href, 'http') ? 'target="_blank" rel="noopener"' : '' ?> style="font-weight:700"><?= e((string) $value) ?></a>
                  <?php else: ?>
                    <span style="font-size:.9rem"><?= e((string) $value) ?></span>
                  <?php endif; ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card-fc card-fc--pad">
          <h2 style="font-size:1.1rem">Quick answers</h2>
          <p class="text-muted-3" style="font-size:.84rem">A lot of questions are already answered.</p>
          <?php foreach ($faqs as $f): ?>
            <div class="faq-item">
              <button class="faq-q" type="button" style="font-size:.9rem;padding:.85rem .1rem" aria-expanded="false">
                <?= e($f['question']) ?><i class="fa-solid fa-plus"></i>
              </button>
              <div class="faq-a"><p style="font-size:.86rem"><?= e($f['answer']) ?></p></div>
            </div>
          <?php endforeach; ?>
          <a href="<?= SITE_URL ?>/faq.php" class="btn-fc btn-fc--ghost btn-fc--block btn-fc--sm mt-3">All FAQs</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
