# CureMeds: Facebook, Instagram, TikTok and automatic content setup

This guide connects curemeds.co.za to Facebook, Instagram and TikTok, and turns on automatic articles, reels and newsletters.

Every step says where you do it:

- 🟦 **ACCOUNT**: on Facebook, Meta, TikTok, Groq or ImageKit (their websites or apps)
- 🟩 **WEBSITE**: in the CureMeds admin at `https://curemeds.co.za/dashboards/admin/`
- 🟧 **CPANEL**: in your hosting control panel

Work in this order. Each part only needs the parts before it.

| Part | What it gives you | Time |
|---|---|---|
| 1. Deploy and cron job | Everything below runs on the cron job | 5 min |
| 2. Groq | AI writes the articles and captions | 3 min |
| 3. ImageKit | Turns product photos into reels (MP4) | 5 min |
| 4. Meta (Facebook + Instagram) | Posts, articles and reels on the Page and Instagram | 20 min |
| 5. TikTok (sandbox) | Reels arrive in the TikTok inbox as drafts | 15 min |
| 6. Switch on automatic content | Articles, reels and newsletters run by themselves | 2 min |

> Do parts 4 and 5 on the **live site** (https://curemeds.co.za). Facebook and TikTok will not send you back to `localhost`.

---

## Part 1: Deploy and add the cron job

**🟧 CPANEL: pull the new code**

1. cPanel → **Git™ Version Control** → `curemeds` → **Manage** → **Pull or Deploy**.
2. Press **Update from Remote**, then **Deploy HEAD Commit**.
3. Open https://curemeds.co.za once. New tables and the 25 new products are added automatically on that first page load. You don't need to import any SQL.

**🟧 CPANEL: add the cron job**

cPanel → **Cron Jobs** → **Add New Cron Job**:

- Common settings: **Once Per Five Minutes** (`*/5 * * * *`)
- Command (paste exactly; change `curemedsco` if your cPanel username is different. It's shown top-right in cPanel):

```bash
/usr/local/bin/php /home/curemedsco/public_html/scripts/cron.php >/dev/null 2>&1
```

Each run sends dose reminders, the daily product post, automatic articles and reels, TikTok drafts, the weekly product newsletter, and queued newsletters (40 emails per run).

If your host has no cron, use a URL cron such as cron-job.org every 5 minutes on the link shown in **🟩 WEBSITE → Setup checklist → Add the reminders cron job** (it contains your secret key).

**🟩 WEBSITE: check it ran**

Open **Setup checklist**. After 5–10 minutes, "Add the reminders cron job" ticks itself.

---

## Part 2: Groq (AI writing)

1. **🟦 ACCOUNT**: go to https://console.groq.com, sign up (free) → **API Keys** → **Create API Key** → name it `CureMeds` → **copy** the key (it starts with `gsk_`). Groq only shows it once.
2. **🟩 WEBSITE**: **AI & social media** → **Groq** card → paste the key → **Save**. The site tests it right away and shows "connected".

---

## Part 3: ImageKit (reels)

Reels are built on ImageKit from the product photo, name, price and benefits, then sent as an MP4 to Facebook, Instagram and TikTok.

1. **🟦 ACCOUNT**: go to https://imagekit.io and sign up (the free plan is enough).
2. **🟦 ACCOUNT**: Dashboard → **Developer options** (left menu). Copy three things:
   - **URL endpoint**, for example `https://ik.imagekit.io/abc123`
   - **Public key** (`public_…`)
   - **Private key** (`private_…`). Press the eye icon to reveal it.
3. **🟩 WEBSITE**: **AI & social media** → **ImageKit** card → paste all three → **Save & test**. It should say "ImageKit is connected".

---

## Part 4: Meta (Facebook Page + Instagram)

### 4a. Get the accounts ready (🟦 ACCOUNT, phone or computer)

1. Make sure you are an **admin of the CureMeds Facebook Page**.
2. In the Instagram app: **Settings → Account type and tools → Switch to professional account → Business**.
3. Link Instagram to the Page: https://business.facebook.com → **Settings** → **Accounts → Instagram accounts** → **Add** → sign in to the CureMeds Instagram.

### 4b. Create the Meta app (🟦 ACCOUNT, computer)

1. Go to https://developers.facebook.com/apps and sign in with the Facebook profile that manages the Page.
2. **Create App** → use case **Manage everything on your Page** → name `CureMeds Poster` → your email → **Create app**.
3. Left menu **App settings → Basic**:
   - **App domains**: `curemeds.co.za`
   - **Privacy Policy URL**: `https://curemeds.co.za/privacy.php`
   - **Data deletion instructions URL**: `https://curemeds.co.za/privacy.php`
   - **Category**: Health & fitness → **Save changes**
   - Copy the **App ID** (digits only) and the **App secret** (press **Show**).
4. Left menu **Facebook Login for Business → Settings** → **Valid OAuth Redirect URIs** → add exactly:

   ```text
   https://curemeds.co.za/dashboards/admin/social-connect.php
   ```

   → **Save changes**.
5. **Use cases → Manage everything on your Page → Customize** → make sure these permissions are added: `pages_show_list`, `pages_manage_posts`, `pages_read_engagement`, `instagram_basic`, `instagram_content_publish`, `business_management`.

### 4c. Save and connect (🟩 WEBSITE)

1. **AI & social media** → **Facebook & Instagram** card → paste the **App ID** and **App secret** → **Save**.
2. Press **Connect with Facebook**. You move to Facebook (**🟦 ACCOUNT**):
   - Continue as yourself.
   - When it asks which Pages, tick **CureMeds**. When it asks which Instagram accounts, tick the CureMeds Instagram.
   - Allow all permissions → **Save** / **Done**.
3. You land back on the website (**🟩 WEBSITE**). The card shows **Page: CureMeds** and **Instagram: @…**. If you manage several Pages, pick CureMeds from the list.
4. Test it: **Post now** card → choose a product → tick Facebook → **Post**. Check the Page (🟦 ACCOUNT).

> While the Meta app is in **Development** mode, only people with a role on the app can see what it posts. The posts are made as your Page, so the public sees them once the app is switched to **Live** (App settings → top bar → **Live**; Meta may ask you to finish **Business verification** first).

---

## Part 5: TikTok developer app (sandbox): reels as drafts

The site sends each reel to the TikTok account's **inbox as a draft**. You open it in the TikTok app, add a sound if you like, and press Post. Sandbox apps are allowed to do this without TikTok's app review.

### 5a. Create the app (🟦 ACCOUNT, computer)

1. Go to https://developers.tiktok.com → **Log in** (use any TikTok account; the CureMeds account is best) → accept the developer terms.
2. **Manage apps** → **Connect an app** → name `CureMeds` → **Confirm**.
3. At the top of the app page, switch to **Sandbox** → **Create Sandbox** → name `curemeds-sandbox`.
4. Inside the sandbox, fill in the **Basic information**:
   - App icon: upload `assets/img/logo/curemeds-icon.png` (from the repository)
   - Category: Health & Fitness
   - Description: "Sends CureMeds product reels to our own TikTok account as drafts."
   - Terms of Service URL: `https://curemeds.co.za/terms.php`
   - Privacy Policy URL: `https://curemeds.co.za/privacy.php`
   - Platforms: tick **Web** → Website URL `https://curemeds.co.za`
5. **Add products** → add **Login Kit** and **Content Posting API**.
   - **Login Kit** → **Redirect URI** → Web → add exactly:

     ```text
     https://curemeds.co.za/dashboards/admin/tiktok-connect.php
     ```

   - **Content Posting API**: you don't need "Direct Post". Leave it off, because drafts use upload only.
6. **Scopes**: make sure `user.info.basic` and `video.upload` are added.
7. **Sandbox settings → Target users** → **Add account** → sign in with the **CureMeds TikTok account**. Only target users can connect while in sandbox.
8. Copy the sandbox **Client key** and **Client secret** (top of the sandbox page).

### 5b. Save and connect (🟩 WEBSITE)

1. **AI & social media** → **TikTok** card → paste **Client key** and **Client secret** → **Save**.
2. Press **Connect TikTok**. You move to TikTok (**🟦 ACCOUNT**). Log in as the **CureMeds TikTok account** (the target user) → **Authorize**.
3. Back on the website (**🟩 WEBSITE**), the card shows **Connected · CureMeds**.
4. Test it: **Make a reel** card → choose a product → **Make reel** → tick **TikTok (draft)** → **Post reel**. After 1–2 minutes, check the TikTok app (**🟦 ACCOUNT**): **Inbox → notifications** shows "Your video is ready to edit". Open it → edit → **Post**.

> Sandbox drafts are fine for daily use. To post straight to the feed without opening the app ("Direct Post"), TikTok must review the app later. That's a separate step you can skip for now.

---

## Part 6: Switch on automatic content (🟩 WEBSITE)

### Articles and reels

**AI & social media** → **Automatic content** card:

- ✅ **Write and publish [1] article(s) a day**: Groq writes a 500–700-word health article around 2–3 of our products, publishes it under **Articles** with "Shop the products in this article", and shares it to Facebook and Instagram.
- ✅ **Also email each new article to newsletter subscribers**: sends it as a newsletter (article + products).
- ✅ **Post [3] product reels a day**: one product reel at a time, posted to Facebook and Instagram, and sent to TikTok as a draft.
- Start with `3` reels a day. They're spread between the start and end hours (default 08:00–20:00).
- **Save**. Use **Article now** / **Reel now** to try one immediately.

### Newsletters

**Email templates** → **Automatic newsletters**:

- ✅ **Email every new article to subscribers**
- ✅ **Send a weekly product newsletter**: choose the day (default Tuesday, from 09:00). It sends four products with prices and the shop link.
- **Save**.

Both go out through the cron job, 40 emails every 5 minutes, each with an unsubscribe link.

### Email templates

**Email templates** lists the emails the site sends:

- **Enquiry: how to pay** goes automatically when a customer sends their enquiry list from the cart with an email address. It contains their items, the total, the bank account (EFT) and cash-on-delivery details.
- **Payment received**, **Order on its way** and **Blank letter**: pick one in **Write an email**, fill in the name and reference, and send it to one person, several people, or `subscribers` (the whole newsletter list).

Edit any template's wording with **Edit**. Placeholders such as `{{name}}` and `{{payment_details}}` are filled in for you.

---

## Troubleshooting

| What you see | What to do |
|---|---|
| "That App ID does not look right" | Copy the **App ID** (only digits) from Meta → App settings → Basic, not the secret. |
| Facebook: "URL blocked" / redirect error | The redirect URI in Meta must be exactly `https://curemeds.co.za/dashboards/admin/social-connect.php` (https, no trailing slash). |
| Instagram shows "not linked to this Page" | Part 4a step 3, then press **Reconnect**. |
| TikTok: `redirect_uri` error | Login Kit → Redirect URI must be exactly `https://curemeds.co.za/dashboards/admin/tiktok-connect.php`, saved inside the **sandbox**, not the production app. |
| TikTok: "unauthorized" / can't log in | The account you log in with must be added under **Sandbox → Target users**. |
| TikTok draft never appears | It can take a few minutes. Check that ImageKit is connected (Part 3). Look at the result line after **Reel now**. |
| Nothing happens automatically | The cron job isn't running. Check **Setup checklist** → cron, or cPanel → Cron Jobs → the email cPanel sends. |
| Articles say "Groq: …" | Check the Groq key (Part 2). The free plan has a daily limit, so lower **Articles per day**. |
