# Google sign-in setup

This switches on **Continue with Google** on the sign-in page and at checkout.
Customers who use it get a CureMeds account with **My orders**, where they can
pay, upload proof of payment and follow each order. If someone already has an
account with the same email, Google sign-in links to it.

It takes about 10 minutes. You need a Google account. Use the business one if
you have it.

## 1. Create a Google Cloud project

1. Go to https://console.cloud.google.com and sign in.
2. Click the project picker at the top left, then **New project**.
3. Name it `CureMeds` and click **Create**. Make sure it's the selected project.

## 2. Set up the consent screen

This is the screen people see when they choose their Google account.

1. In the menu, go to **APIs & Services → OAuth consent screen**. Newer
   consoles call it **Google Auth Platform → Branding**.
2. Click **Get started**.
3. Fill in:
   - **App name:** `CureMeds`
   - **User support email:** `admin@curemeds.co.za`
   - **Audience:** **External**
   - **Contact email:** `admin@curemeds.co.za`
4. Agree to the policy and click **Create**.
5. Under **Branding**, also fill in:
   - **App logo:** upload `assets/img/logo/social/curemeds-tiktok-icon.png`. It's optional, but it looks more trustworthy.
   - **Application home page:** `https://curemeds.co.za`
   - **Privacy policy:** `https://curemeds.co.za/privacy.php`
   - **Terms of service:** `https://curemeds.co.za/terms.php`
   - **Authorised domains:** `curemeds.co.za`
6. Click **Save**.
7. Under **Audience**, click **Publish app**, then **Confirm**. Until you do
   this, only test users you add by hand can sign in.

   You don't need Google to verify the app: the site only asks for name and
   email, so it goes live straight away.

## 3. Create the Client ID

1. Go to **APIs & Services → Credentials**. Newer consoles call it
   **Google Auth Platform → Clients**.
2. Click **Create credentials → OAuth client ID**, or **Create client**.
3. **Application type:** **Web application**
4. **Name:** `CureMeds website`
5. **Authorised JavaScript origins:** add `https://curemeds.co.za`
6. **Authorised redirect URIs:** add exactly:

   ```
   https://curemeds.co.za/modules/auth/google.php
   ```

   If people also visit `https://www.curemeds.co.za`, add
   `https://www.curemeds.co.za/modules/auth/google.php` too.
7. Click **Create**.
8. Copy the **Client ID** (it ends in `.apps.googleusercontent.com`) and the
   **Client secret** (it starts with `GOCSPX-`).

## 4. Paste the keys into CureMeds

1. Sign in to the CureMeds admin and go to **Settings**.
2. Find the **Google sign-in** card and paste:
   - **Client ID**
   - **Client secret**
3. Tick **Show "Continue with Google"**.
4. Click **Save all settings**.

## 5. Test it

1. Open a private (incognito) window and go to
   `https://curemeds.co.za/modules/auth/login.php`.
2. Click **Continue with Google** and choose an account.
3. You should land on **My orders**.

### If something goes wrong

| What you see | Fix |
| --- | --- |
| `redirect_uri_mismatch` | The redirect URI in step 3.6 must match exactly: `https`, no `www` unless you added it, and no trailing slash. |
| "Access blocked: app has not completed verification" | You skipped **Publish app** in step 2.7. |
| No Google button on the sign-in page | Both keys aren't saved, or the tick box is off (Settings → Google sign-in). |
| "That Google sign-in had expired" | You took too long, or opened it in another browser. Try again. |

## How accounts work

- **Shoppers** can check out as guests or sign in with Google. A guest order
  placed with an email address joins that account as soon as they sign in
  with the same email.
- **Patients** still sign in with cellphone + PIN (the **Patient** tab).
- **Staff** sign in on the **Email** tab with email and password, or with
  Google if their Google email matches their staff account.
