<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$company = e((string) get_setting('company_name', (string) get_setting('bank_account_holder', 'CureMeds Trading (Pty) Ltd')));
$officer = e((string) get_setting('information_officer', ''));
$email   = e((string) get_setting('contact_email', SITE_EMAIL));
$address = e((string) get_setting('contact_address', ''));
$contact = SITE_URL . '/contact.php?subject=Privacy';
$officerLine = $officer !== ''
    ? "Our Information Officer is <b>{$officer}</b>."
    : 'Our Information Officer is responsible for how we handle personal information.';

$policy = [
    'key'     => 'privacy',
    'title'   => 'Privacy policy',
    'lede'    => 'What we collect, why we need it, who sees it, and how to take it back — in plain language.',
    'updated' => '18 September 2026',
    'summary' => [
        'We collect what we need to sell to you and deliver your order — <b>nothing more</b>.',
        'We <b>never sell</b> your information, and there are <b>no advertising or tracking cookies</b> on this site.',
        'Marketing emails go only to people who agreed to them. <b>Unsubscribe in one click</b>, any time.',
        'You can see, correct or delete what we hold about you. Ask, and we act within <b>30 days</b>.',
    ],
    'sections' => [
        [
            'id' => 'who-we-are', 'icon' => 'fa-building', 'title' => 'Who we are',
            'html' => <<<HTML
<p>CureMeds is run by <b>{$company}</b>, {$address}. Under the Protection of Personal Information Act (POPIA),
we are the "responsible party" for the personal information you give us through curemeds.co.za.</p>
<p>{$officerLine} You can reach them at <b>{$email}</b> about anything in this policy.</p>
HTML,
        ],
        [
            'id' => 'what-we-collect', 'icon' => 'fa-clipboard-list', 'title' => 'What we collect',
            'html' => <<<HTML
<ul>
  <li><b>Your account</b> — name, email address, phone number and a scrambled (hashed) version of your password. We never store the password itself.</li>
  <li><b>Your orders</b> — what you bought, what you paid, and your delivery address or chosen pickup point.</li>
  <li><b>Proof of payment</b> — the file you upload. These often show bank details, so they are kept out of public reach and only you and our administrators can open them.</li>
  <li><b>Your email list sign-up</b> — your email address, when and where you joined, and the wording you agreed to. See <a href="#marketing">Marketing emails</a>.</li>
  <li><b>Trade account applications</b> — trading name, area and contact number, and optionally an ID number, used only to verify who you are.</li>
  <li><b>Messages</b> you send us through the contact form, so we can answer them.</li>
  <li><b>Basic technical details</b> — the cookies listed <a href="#cookies">below</a>, and your IP address when you sign up to our list.</li>
</ul>
<p><b>We never collect</b> card numbers, CVV codes, online banking passwords or one-time PINs. You pay by EFT from your own bank.</p>
HTML,
        ],
        [
            'id' => 'health-information', 'icon' => 'fa-heart-pulse', 'title' => 'Your health information',
            'html' => <<<HTML
<p>If you use the patient portal, we keep the medication you take and when, the doses you log, side effects you
report, and any readings you add (such as blood pressure, weight or blood sugar). POPIA calls this
<b>special personal information</b>, and we handle it with extra care:</p>
<ul>
  <li>We only process it <b>with your consent</b>, which you give when you create your tracker or ask us to set it up. You can withdraw it at any time.</li>
  <li>It is used only to remind you about your doses, to follow up on side effects and to support you — never for marketing and never sold.</li>
  <li>Only you and the CureMeds staff who look after patients can see it. Your PIN is stored scrambled (hashed); nobody at CureMeds can read it.</li>
  <li>Reminder emails show the product name only — think about who else can read your inbox.</li>
  <li>Ask us on WhatsApp to download or delete your health records, and we will do it within 30 days.</li>
</ul>
HTML,
        ],
        [
            'id' => 'why', 'icon' => 'fa-bullseye', 'title' => 'Why we collect it',
            'html' => <<<HTML
<p>POPIA only lets us use your information when there's a lawful reason. Ours are:</p>
<div class="policy-table-wrap"><table class="policy-table">
  <thead><tr><th>What we do</th><th>Why we're allowed to</th></tr></thead>
  <tbody>
    <tr><td>Process, deliver and support your orders</td><td>To carry out our contract with you</td></tr>
    <tr><td>Keep invoices and payment records</td><td>Tax law requires it</td></tr>
    <tr><td>Prevent fraud and keep the site secure</td><td>Our legitimate interest in running a safe shop</td></tr>
    <tr><td>Send you news, restocks and offers</td><td>Your consent — which you can withdraw at any time</td></tr>
    <tr><td>Answer your messages</td><td>You asked us to</td></tr>
  </tbody>
</table></div>
HTML,
        ],
        [
            'id' => 'marketing', 'icon' => 'fa-envelope-open-text', 'title' => 'Marketing emails and your consent',
            'html' => <<<HTML
<p>You join our email list when you enter your email in the sign-up window on our site, in the form at the bottom
of every page, or when you ask us in person to add you. When you do, we record <b>the date and time, where you
signed up, your IP address, and the exact words you agreed to</b> — so we can always show that you asked to hear
from us.</p>
<p>We'll send you new arrivals, restocks and offers — and, if we're running one, a welcome code. We don't share
our list with anyone, and we never email you marketing without your consent.</p>
<p><b>Leaving is one click.</b> Every email has an unsubscribe link at the bottom, and most mail apps show their own
unsubscribe button too. Leaving the list doesn't affect your account or your orders. Order confirmations and
delivery updates aren't marketing, so they still come through.</p>
HTML,
        ],
        [
            'id' => 'sharing', 'icon' => 'fa-share-nodes', 'title' => 'Who we share it with',
            'html' => <<<HTML
<p>Only the people who need it to get your order to you, and nobody else:</p>
<ul>
  <li><b>Couriers</b> — your name, phone number and delivery address, to deliver your parcel.</li>
  <li><b>WhatsApp (Meta)</b> — when you message us, your messages are carried by WhatsApp under its own privacy policy.</li>
  <li><b>Our email provider</b> — to send your order emails and, if you've joined, our newsletter.</li>
  <li><b>Our hosting provider</b> — who stores the website and its database on secure servers.</li>
  <li><b>Authorities</b> — only when the law requires it, for example a court order.</li>
</ul>
<p>Everyone we share information with may use it only to do their job for us. <b>We never sell or rent your personal information.</b></p>
HTML,
        ],
        [
            'id' => 'overseas', 'icon' => 'fa-globe-africa', 'title' => 'Does it leave South Africa?',
            'html' => <<<HTML
<p>Some of the service providers above may store information on servers outside South Africa. When that
happens, we only use providers who are bound — by law or by contract — to protect it to a standard at least as
strong as POPIA, as section 72 of the Act requires.</p>
HTML,
        ],
        [
            'id' => 'security', 'icon' => 'fa-lock', 'title' => 'How we keep it safe',
            'html' => <<<HTML
<ul>
  <li>Passwords are scrambled with bcrypt, so even we can't read them.</li>
  <li>Your sign-in is held in a secure, signed cookie that scripts on the page can't read.</li>
  <li>Every form is protected against cross-site request forgery.</li>
  <li>Uploads are checked for their real file type, renamed, and served only to people allowed to see them.</li>
  <li>Only staff who need access to do their job have it.</li>
</ul>
<p>If there's ever a security breach that affects your information, we'll tell you and the Information Regulator
as soon as reasonably possible, as POPIA requires.</p>
HTML,
        ],
        [
            'id' => 'account-safety', 'icon' => 'fa-user-lock', 'title' => 'Keeping your account safe',
            'html' => <<<HTML
<div class="policy-callout policy-callout--warn">
  <i class="fa-solid fa-triangle-exclamation"></i>
  <p><b>CureMeds will never ask for your password, card number, PIN, online banking login or a one-time PIN</b> —
  not by email, SMS, WhatsApp or phone. If someone does, it isn't us. Please report it to {$email}.</p>
</div>
<p>Use a password you don't use anywhere else, and sign out on shared devices.</p>
HTML,
        ],
        [
            'id' => 'retention', 'icon' => 'fa-box-archive', 'title' => 'How long we keep it',
            'html' => <<<HTML
<div class="policy-table-wrap"><table class="policy-table">
  <thead><tr><th>Information</th><th>Kept for</th></tr></thead>
  <tbody>
    <tr><td>Orders, invoices and proofs of payment</td><td>5 years — tax law requires it</td></tr>
    <tr><td>Your account</td><td>Until you ask us to delete it</td></tr>
    <tr><td>Email list sign-up</td><td>Until you unsubscribe — then only a note that you left, so you're never added back by mistake</td></tr>
    <tr><td>Messages to us</td><td>2 years</td></tr>
  </tbody>
</table></div>
HTML,
        ],
        [
            'id' => 'your-rights', 'icon' => 'fa-scale-balanced', 'title' => 'Your rights under POPIA',
            'html' => <<<HTML
<p>You have the right to:</p>
<ul>
  <li><b>know</b> what personal information we hold about you, and get a copy;</li>
  <li><b>correct</b> anything that's wrong or out of date;</li>
  <li><b>delete</b> your information — except records the law makes us keep;</li>
  <li><b>object</b> to us using your information, including for marketing, at any time and without giving a reason;</li>
  <li><b>complain</b> to the Information Regulator — see <a href="#complaints">Complaints</a>.</li>
</ul>
<p>To use any of these, <a href="{$contact}">send us a message</a> or email {$email}. We may ask you to confirm
who you are first, so nobody else can get your information. We'll respond within 30 days, and there's no charge.</p>
HTML,
        ],
        [
            'id' => 'cookies', 'icon' => 'fa-cookie-bite', 'title' => 'Cookies and browser storage',
            'html' => <<<HTML
<p>This is the complete list. There are no analytics, advertising or tracking cookies on CureMeds.</p>
<div class="policy-table-wrap"><table class="policy-table">
  <thead><tr><th>Name</th><th>What it does</th><th>How long</th></tr></thead>
  <tbody>
    <tr><td><code>PHPSESSID</code></td><td>Keeps your basket and protects forms while you shop</td><td>Until you close the browser</td></tr>
    <tr><td><code>cm_token</code></td><td>Keeps you signed in</td><td>7 days, or until you sign out</td></tr>
    <tr><td><code>fc_sub</code></td><td>Remembers that you joined our email list, so we don't ask again</td><td>12 months</td></tr>
    <tr><td>Browser storage</td><td>Your product comparison list, and whether you closed the sign-up window. Stays on your device — we never see it.</td><td>Until you clear it</td></tr>
  </tbody>
</table></div>
HTML,
        ],
        [
            'id' => 'assistant', 'icon' => 'fa-comments', 'title' => 'The shop assistant',
            'html' => <<<HTML
<p>The chat assistant on our site runs on our own server and answers from our product catalogue and your order
records. Your messages are <b>not sent to any outside artificial-intelligence service</b>. Each one is answered and
then discarded — we don't keep a copy of the conversation.</p>
HTML,
        ],
        [
            'id' => 'children', 'icon' => 'fa-child', 'title' => 'Children',
            'html' => <<<HTML
<p>CureMeds is for adults. You must be 18 or older to create an account or place an order, and our supplements
aren't intended for anyone under 18. We don't knowingly collect information about children; if you think we have,
tell us and we'll delete it.</p>
HTML,
        ],
        [
            'id' => 'complaints', 'icon' => 'fa-flag', 'title' => 'Complaints',
            'html' => <<<HTML
<p>If you're unhappy with how we've handled your information, please tell us first at {$email} — we'd like the
chance to put it right.</p>
<p>You can also complain to the <b>Information Regulator (South Africa)</b> at any time, through
<a href="https://inforegulator.org.za" target="_blank" rel="noopener">inforegulator.org.za</a>.</p>
HTML,
        ],
        [
            'id' => 'changes', 'icon' => 'fa-pen-to-square', 'title' => 'Changes to this policy',
            'html' => <<<HTML
<p>When we change this policy, we'll update the date at the top of the page. If the change is significant — for
example, a new way of using your information — we'll tell you by email or on the site before it takes effect.</p>
HTML,
        ],
    ],
];

include __DIR__ . '/includes/policy-page.php';
