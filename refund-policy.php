<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$email   = e((string) get_setting('contact_email', SITE_EMAIL));
$contact = SITE_URL . '/contact.php?subject=Returns';

$policy = [
    'key'     => 'returns',
    'title'   => 'Returns policy',
    'lede'    => 'What can come back, how to send it, and how quickly your money comes home.',
    'updated' => '18 September 2026',
    'summary' => [
        '<b>Unopened items</b> can be returned within <b>7 days</b> of delivery — no reason needed.',
        '<b>Damaged or wrong?</b> Send us a photo within <b>48 hours</b> and we fix it at our cost.',
        '<b>Faulty items</b> are covered for <b>6 months</b> under the Consumer Protection Act.',
        '<b>Opened</b> drinks, supplements, teas and body products can\'t come back unless they\'re faulty.',
        'Refunds land within <b>5 working days</b> of your return reaching us.',
    ],
    'sections' => [
        [
            'id' => 'time-limits', 'icon' => 'fa-clock', 'title' => 'Time limits',
            'html' => <<<HTML
<div class="policy-table-wrap"><table class="policy-table">
  <thead><tr><th>Your situation</th><th>Tell us within</th><th>What you get</th></tr></thead>
  <tbody>
    <tr><td>Changed your mind (item unopened)</td><td><b>7 days</b> of delivery</td><td>Refund</td></tr>
    <tr><td>Arrived damaged, or not what you ordered</td><td><b>48 hours</b> of delivery</td><td>Replacement or refund, at our cost</td></tr>
    <tr><td>Faulty, unsafe or not fit for purpose</td><td><b>6 months</b> of delivery</td><td>Repair, replacement or refund — your choice</td></tr>
  </tbody>
</table></div>
<p>The clock starts on the day the parcel is delivered or collected, not the day you ordered.</p>
HTML,
        ],
        [
            'id' => 'change-of-mind', 'icon' => 'fa-hand', 'title' => 'Changed your mind',
            'html' => <<<HTML
<p>Because you bought online without seeing the product first, the law gives you a seven-day cooling-off period.
You can send back anything <b>unopened and unused, in its original sealed packaging</b>, within 7 days of delivery,
without giving a reason.</p>
<p>We refund what you paid for the item. If you return your whole order, we also refund the delivery fee you paid.
The only cost to you is sending it back to us.</p>
HTML,
        ],
        [
            'id' => 'not-returnable', 'icon' => 'fa-ban', 'title' => "Items we can't take back",
            'html' => <<<HTML
<div class="policy-callout">
  <i class="fa-solid fa-droplet"></i>
  <p><b>Opened products that you eat, drink or put on your body</b> — tablets, capsules, powders, drops,
  lotions and face masks. Once the seal is broken we cannot guarantee the next person's safety, so these cannot be
  resold. This is a hygiene rule, and it never affects your rights if the product is faulty.</p>
</div>
<p>We also can't accept:</p>
<ul>
  <li>items damaged after delivery through misuse or careless storage;</li>
  <li>opened fragrance, room sprays and cleaning or pest-control products, unless they are faulty;</li>
  <li>stock marked <i>final sale</i> at the time you bought it — though faulty items are still covered.</li>
</ul>
HTML,
        ],
        [
            'id' => 'damaged-or-wrong', 'icon' => 'fa-box-open', 'title' => 'Damaged or wrong items',
            'html' => <<<HTML
<p>Please open your parcel as soon as it arrives. If something is broken, leaking, or not what you ordered,
<a href="{$contact}">message us</a> within <b>48 hours</b> with your order number and a photo.</p>
<p>We replace it free of charge — or refund you if you would rather — and we arrange and pay for the collection.
You never pay to send back our mistake or the courier's.</p>
HTML,
        ],
        [
            'id' => 'faulty', 'icon' => 'fa-shield-halved', 'title' => 'Faulty items (6-month warranty)',
            'html' => <<<HTML
<p>The Consumer Protection Act gives you six months to return goods that are defective, unsafe, or not fit for the
purpose they're normally used for. You choose whether we repair, replace or refund.</p>
<p>For our products, "faulty" includes things like a product that arrived expired, contaminated, spoiled before its
best-before date, or with a broken seal. It doesn't cover normal use, misuse, or a product simply not being to your
taste — for that, see <a href="#change-of-mind">Changed your mind</a>.</p>
<p>Nothing in this policy takes away any right you have under the Consumer Protection Act.</p>
HTML,
        ],
        [
            'id' => 'how-to-return', 'icon' => 'fa-list-ol', 'title' => 'How to return something',
            'html' => <<<HTML
<ol class="policy-steps">
  <li><b>Tell us.</b> <a href="{$contact}">Send us a message</a> or email {$email} with your order number and what you're returning.</li>
  <li><b>Get a return reference.</b> We reply within one working day with the reference and where to send the item.</li>
  <li><b>Pack it well.</b> Use the original packaging if you can, and write the return reference on the parcel.</li>
  <li><b>Send or drop it off.</b> Keep your tracking or drop-off slip until the refund is in your account.</li>
  <li><b>We check and refund.</b> Once it arrives we inspect it and refund you — see <a href="#refunds">Refunds</a>.</li>
</ol>
<p>Please don't send anything back without a return reference: we may not be able to match it to your order.</p>
HTML,
        ],
        [
            'id' => 'refunds', 'icon' => 'fa-money-bill-transfer', 'title' => 'Refunds',
            'html' => <<<HTML
<p>We refund by EFT to the account you paid from, within <b>5 working days</b> of your return reaching us — and
never later than the 30 days the law allows. We email you when it has been paid.</p>
<p>If you paid with a coupon, we refund the amount you actually paid, not the full price.</p>
<div class="policy-callout policy-callout--warn">
  <i class="fa-solid fa-triangle-exclamation"></i>
  <p>To refund you we only ever need your account details. <b>We will never ask for your card number, PIN, online
  banking password or a one-time PIN.</b> If anyone does, claiming to be CureMeds, it isn't us.</p>
</div>
HTML,
        ],
        [
            'id' => 'cancelling', 'icon' => 'fa-xmark', 'title' => 'Cancelling an order',
            'html' => <<<HTML
<p>Contact us before your order is packed and we'll cancel it and refund you in full. Once it's with the courier,
it becomes a return and the steps above apply.</p>
<p>If you've changed your mind before paying, just don't pay — we hold stock for 48 hours while we wait for
payment, and may cancel an unpaid order after that. Nothing is owed on an order you never paid for.</p>
HTML,
        ],
        [
            'id' => 'trade-orders', 'icon' => 'fa-handshake', 'title' => 'Trade orders',
            'html' => <<<HTML
<p>Stock bought on a trade account at wholesale prices can be returned unopened within 7 days on the same terms as
above, and faulty stock is covered in the same way.</p>
<p>We can't take back stock just because it didn't sell — that risk comes with the wholesale price. If you're
unsure about a new product, start with a small order.</p>
HTML,
        ],
    ],
];

include __DIR__ . '/includes/policy-page.php';
