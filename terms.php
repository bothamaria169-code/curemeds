<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$company   = e((string) get_setting('company_name', (string) get_setting('bank_account_holder', 'CureMeds Trading (Pty) Ltd')));
$regNo     = (string) get_setting('company_reg_no', '');
$email     = e((string) get_setting('contact_email', SITE_EMAIL));
$phone     = e((string) get_setting('contact_phone', ''));
$address   = e((string) get_setting('contact_address', ''));
$flat      = format_price_short((float) get_setting('shipping_flat_rate', '120'));
$free      = format_price_short((float) get_setting('free_shipping_threshold', '1500'));
$returns   = SITE_URL . '/refund-policy.php';
$privacy   = SITE_URL . '/privacy.php';
$contact   = SITE_URL . '/contact.php';
$regLine   = $regNo !== '' ? ' (registration number ' . e($regNo) . ')' : '';

$policy = [
    'key'     => 'terms',
    'title'   => 'Terms & conditions',
    'lede'    => 'The agreement between you and CureMeds when you shop with us. Written to be read, not skimmed past.',
    'updated' => '18 September 2026',
    'summary' => [
        'Your order is <b>confirmed once we have your payment</b>. We hold the stock for 48 hours while you pay.',
        'Prices are in rand and <b>include VAT</b>. What you see at checkout is what you pay.',
        "Courier delivery is <b>{$flat}</b>, and <b>free on orders over {$free}</b> — usually 2–4 working days.",
        'Unopened items can be returned within <b>7 days</b>; faulty items within <b>6 months</b>.',
        'Our supplements <b>complement your diet</b>. They don\'t diagnose, treat or cure any condition.',
    ],
    'sections' => [
        [
            'id' => 'about', 'icon' => 'fa-circle-info', 'title' => 'About these terms',
            'html' => <<<HTML
<p>curemeds.co.za is run by <b>{$company}</b>{$regLine}, trading as CureMeds. When we say "we" or "us", that's
who we mean. Our full contact details are at the <a href="#legal-notices">end of this page</a>.</p>
<p>These terms apply whenever you browse or buy from CureMeds. By placing an order you agree to them, along with
our <a href="{$returns}">Returns policy</a> and <a href="{$privacy}">Privacy policy</a>. You must be 18 or older to
open an account or place an order.</p>
<p>Nothing in these terms takes away rights you have under the Consumer Protection Act or the Electronic
Communications and Transactions Act.</p>
HTML,
        ],
        [
            'id' => 'account', 'icon' => 'fa-user', 'title' => 'Your account',
            'html' => <<<HTML
<p>You can check out as a guest or open a free account to track orders and reorder quickly. Keep your password to
yourself — you're responsible for what happens on your account, so tell us straight away if you think someone else
has access. We may suspend an account that's used for fraud or abuse.</p>
HTML,
        ],
        [
            'id' => 'ordering', 'icon' => 'fa-cart-shopping', 'title' => 'Ordering',
            'html' => <<<HTML
<p>When you place an order, you're making an offer to buy. The sale is concluded when we confirm your payment, and
we'll email you when that happens.</p>
<ul>
  <li>We hold your items for <b>48 hours</b> while we wait for payment. If payment hasn't arrived by then, we may cancel the order and release the stock.</li>
  <li>If an item turns out to be out of stock, or was clearly mispriced, we'll contact you to fix it with your agreement — or refund you in full. We won't hold you to an obvious pricing mistake, and you can't hold us to one either.</li>
  <li>We may limit quantities on some products so that more customers get a chance to buy them.</li>
</ul>
HTML,
        ],
        [
            'id' => 'prices', 'icon' => 'fa-tag', 'title' => 'Prices, coupons and promotions',
            'html' => <<<HTML
<p>All prices are in South African rand and include VAT where it applies. Prices can change, but never on an
order we've already confirmed.</p>
<p>Coupon codes and promotions:</p>
<ul>
  <li>only one coupon can be used per order;</li>
  <li>each one has its own conditions — such as a minimum order value, an expiry date, or which customers it's for — which are shown when you apply it;</li>
  <li>coupons have no cash value and can't be exchanged for money;</li>
  <li>sale prices apply while stock lasts, or until the date stated.</li>
</ul>
HTML,
        ],
        [
            'id' => 'paying', 'icon' => 'fa-building-columns', 'title' => 'Paying for your order',
            'html' => <<<HTML
<p>You pay by <b>EFT (bank transfer)</b>, using your order number as the payment reference. Then upload your proof
of payment on your order page. We confirm payments within one working day.</p>
<div class="policy-callout policy-callout--warn">
  <i class="fa-solid fa-triangle-exclamation"></i>
  <p>Our bank details appear only on your order page and in your order email. <b>We will never send new bank
  details by WhatsApp or SMS, and we never ask for your card number, PIN, banking password or one-time PIN.</b>
  If you're unsure a message is from us, call {$phone} before paying.</p>
</div>
HTML,
        ],
        [
            'id' => 'delivery', 'icon' => 'fa-truck-fast', 'title' => 'Delivery and collection',
            'html' => <<<HTML
<div class="policy-table-wrap"><table class="policy-table">
  <thead><tr><th>Option</th><th>Cost</th><th>Usually takes</th></tr></thead>
  <tbody>
    <tr><td>Courier to your door</td><td>{$flat} · <b>free over {$free}</b></td><td>2–4 working days to main centres, up to 5 elsewhere</td></tr>
    <tr><td>Collection from a pickup point</td><td>Shown at checkout</td><td>We tell you when it's ready</td></tr>
  </tbody>
</table></div>
<p>Delivery times start once your payment is confirmed. They're honest estimates, not guarantees — couriers
sometimes run late, and we'll keep you updated if yours does.</p>
<p>Orders are placed and confirmed on WhatsApp. Prices on the website are for reference; the total we confirm
with you on WhatsApp before you pay is the price of your order.</p>
<p><b>The patient portal and reminders are a convenience, not medical care.</b> A reminder can be late or fail to
arrive (for example if your email is down), so never rely on it alone for medication you cannot miss. Reporting a
side effect in the portal is not a substitute for seeing a healthcare professional — in an emergency call 10177.</p>
<p>The goods become yours, and your responsibility, once they're delivered or collected. Please check your parcel
when it arrives: if anything is damaged or wrong, see the <a href="{$returns}#damaged-or-wrong">Returns policy</a>.</p>
HTML,
        ],
        [
            'id' => 'returns', 'icon' => 'fa-rotate-left', 'title' => 'Returns and refunds',
            'html' => <<<HTML
<p>In short: unopened items can come back within 7 days of delivery, damaged or wrong items should be reported
within 48 hours, and faulty items are covered for 6 months. Opened products you eat, drink or apply to your body
can't be returned unless they're faulty.</p>
<p>The details, and how to start a return, are in our <a href="{$returns}">Returns policy</a>.</p>
HTML,
        ],
        [
            'id' => 'health-products', 'icon' => 'fa-heart-pulse', 'title' => 'Health and wellness products',
            'html' => <<<HTML
<div class="policy-callout">
  <i class="fa-solid fa-circle-info"></i>
  <p>The supplements we sell are <b>Category D complementary medicines</b>. They have not been evaluated by the
  South African Health Products Regulatory Authority (SAHPRA) for their quality, safety or intended use. They are
  meant to complement your diet — <b>they do not diagnose, treat, cure or prevent any disease</b>, and nothing on
  this site says otherwise.</p>
</div>
<ul>
  <li>They are not a substitute for medical care.</li>
  <li>Speak to a doctor or pharmacist first if you are pregnant, breastfeeding, under 18, or taking chronic medication.</li>
  <li>Read the label, stay within the recommended dose, and stop and get advice if you have a reaction.</li>
</ul>
HTML,
        ],
        [
            'id' => 'emails', 'icon' => 'fa-envelope', 'title' => 'Emails and the sign-up window',
            'html' => <<<HTML
<p>Our site invites you to join our email list for new arrivals, restocks and offers. Joining is free, and you can
leave at any time with the unsubscribe link in every email — leaving never affects your account or your orders.
How we handle your email address is explained in our <a href="{$privacy}#marketing">Privacy policy</a>.</p>
<p>A welcome code, where we offer one, is subject to the coupon conditions <a href="#prices">above</a>, and is one
per person.</p>
HTML,
        ],
        [
            'id' => 'trade', 'icon' => 'fa-handshake', 'title' => 'Trade accounts',
            'html' => <<<HTML
<p>Businesses and individuals who buy to resell can apply for a trade account, which gives wholesale prices. It's
free — there's no joining fee, subscription or minimum you're forced to buy. Your discount tier depends only on your
own paid orders over a rolling 90 days. We don't pay anything for recruiting other people, and a trade account
isn't a promise of income.</p>
<p>With a trade account you agree to:</p>
<ul>
  <li>buy at wholesale only stock you intend to resell to your own customers;</li>
  <li>set your own prices and carry the risk on stock you don't sell;</li>
  <li>make no medical, therapeutic or curative claims about any product;</li>
  <li>not present yourself as an employee, agent or franchisee of CureMeds;</li>
  <li>follow South African law, including the Consumer Protection Act, when you sell.</li>
</ul>
<p>We may suspend a trade account that breaks these terms. It then reverts to normal prices; stock you've already
bought stays yours.</p>
HTML,
        ],
        [
            'id' => 'using-the-site', 'icon' => 'fa-display', 'title' => 'Using our website',
            'html' => <<<HTML
<p>The CureMeds name, logo, design, text and photographs belong to us or to the brands we sell, and may not be
copied for commercial use without permission.</p>
<p>Please don't misuse the site — for example by placing fake orders, trying to get into other people's accounts,
copying the catalogue with automated tools, or interfering with how the site works. We may block access to anyone
who does.</p>
HTML,
        ],
        [
            'id' => 'liability', 'icon' => 'fa-shield-heart', 'title' => 'Our responsibility to you',
            'html' => <<<HTML
<p>We take reasonable care with the site and everything we sell, and we stand behind our products as the Consumer
Protection Act requires. As far as the law allows, we aren't responsible for indirect losses — such as lost profit
or lost business — that weren't a foreseeable result of something we did wrong.</p>
<p>Nothing in these terms limits any liability that can't lawfully be limited.</p>
HTML,
        ],
        [
            'id' => 'disputes', 'icon' => 'fa-people-arrows', 'title' => 'Sorting out problems',
            'html' => <<<HTML
<p>If something goes wrong, please <a href="{$contact}">tell us first</a> — most problems are fixed within a day or
two once we know about them.</p>
<p>If we can't resolve it to your satisfaction, you can take it to the <b>Consumer Goods and Services Ombud</b>
(<a href="https://www.cgso.org.za" target="_blank" rel="noopener">cgso.org.za</a>), or to the
<b>National Consumer Commission</b>. These terms are governed by South African law.</p>
HTML,
        ],
        [
            'id' => 'legal-notices', 'icon' => 'fa-gavel', 'title' => 'Legal notices and changes',
            'html' => <<<HTML
<p>We accept service of legal documents at <b>{$address}</b>, and legal notices by email at <b>{$email}</b>.</p>
<p>We may update these terms from time to time. The version that applies to your order is the one published on the
day you placed it, and the date at the top of this page shows when it last changed.</p>
HTML,
        ],
    ],
];

include __DIR__ . '/includes/policy-page.php';
