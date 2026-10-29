# Waffy Escrow Payment — Installation & Setup Guide

**Extension:** Waffy Escrow Payment for Magento 2
**Composer package:** `waffy/module-payment`
**Module name:** `Waffy_Payment`
**Version:** 0.7.2
**License:** OSL-3.0

Compatible with **Adobe Commerce (Cloud & on-premises) 2.4.x** and
**Magento Open Source 2.4.x**, on **PHP 8.1+**.

> The Waffy PHP SDK is **bundled inside this extension** (under `Sdk/`).
> There is **no external Composer dependency** to add and nothing to fetch
> from Packagist — installing the extension installs everything it needs.

---

## 1. Requirements

| Requirement | Value |
|-------------|-------|
| Magento / Adobe Commerce | 2.4.x |
| PHP | 8.1 or higher |
| PHP extensions | `ext-json`, `ext-openssl` (standard on any Magento host) |
| Access | SSH / command-line access to the store (see note below) |
| Waffy account | An organization in the Waffy portal (`portal-dev.waffyapp.com` for sandbox, `portal.waffyapp.com` for live) — the setup wizard walks you through it |

> **Why the command line?** Since Magento 2.4.0 the admin "Web Setup Wizard"
> was removed, so **all** extensions on both Adobe Commerce and Magento Open
> Source are installed from the command line. This is a one-time technical
> step (done by your developer or hosting provider). **Everything after
> installation is configured from the Admin panel** — no commands needed.

---

## 2. Installation

Pick the path that matches your store.

> **Which method should you use?**
> The extension is published on **Packagist**, so the simplest install —
> `composer require waffy/module-payment` — works today on both Adobe
> Commerce and Magento Open Source with **no access keys** (Option A). The
> Adobe Commerce Marketplace is also supported as the official channel, but
> installing from it requires Adobe access keys (Option B). For offline
> installs with no Composer repository, use the manual ZIP (Option C).

### Option A — Packagist (recommended · no access keys · live now)

Published at <https://packagist.org/packages/waffy/module-payment>. Works on
Adobe Commerce (Cloud & on-premises) and Magento Open Source — no Adobe
account, no repository configuration. From the Magento root:

```bash
composer require waffy/module-payment
bin/magento module:enable Waffy_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

> Pin an exact version if you prefer: `composer require waffy/module-payment:0.7.2`.
>
> **On Adobe Commerce Cloud:** run the `composer require` locally, then commit
> `composer.json` + `composer.lock` and `git push`. The Cloud build/deploy
> pipeline runs the `bin/magento` steps automatically — you don't run them by
> hand.

### Option B — Adobe Commerce Marketplace (official channel · requires access keys)

Use this if you obtain the extension through the Marketplace. It downloads
from Adobe's **private** Composer server (`repo.magento.com`), which requires
**access keys** — every merchant needs their own, including Magento Open
Source users and including free extensions. They are **free** to generate.

**1. Get the keys**

1. Sign in to the [Adobe Commerce Marketplace](https://commerce.adobe.com/).
   (A free account is enough — no paid Adobe Commerce licence required.)
2. Go to **Your name → My Profile → Access Keys**.
3. Click **Create A New Access Key**, give it a name (e.g. your store name).
4. You now have two values:
   - **Public Key** → used as the Composer **username**
   - **Private Key** → used as the Composer **password**

**2. Give the keys to Composer**

```bash
# Run from the Magento root. Replace with your own keys.
composer config --global http-basic.repo.magento.com <PUBLIC_KEY> <PRIVATE_KEY>
```

This writes them to `~/.composer/auth.json` (global). To keep them per-project,
drop `--global` and Composer writes `auth.json` in the project root — **do not
commit that file to Git**.

**3. Install**

Confirm the extension is listed under **My Profile → My Purchases**, then run:

```bash
composer require waffy/module-payment:0.7.2
bin/magento module:enable Waffy_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Option C — Manual install from the ZIP (offline / no Composer)

Use this for offline or air-gapped installs, or on any store where you'd rather
not run Composer. It needs no Composer repository and no access keys.

Download the latest `waffy-module-payment-<version>.zip` from the releases page:

**<https://github.com/WaffyApp/waffy-magento2/releases/latest>**

> Take the `waffy-module-payment-<version>.zip` asset. **Ignore** GitHub's
> automatic **Source code (zip)** and **(tar.gz)** entries — they wrap
> everything in a `waffy-magento2-<version>/` folder, so unzipping one into
> `app/code/Waffy/Payment` leaves Magento unable to find the module.

Every release also carries a version-less `waffy-module-payment.zip` with
identical contents, for scripted installs that should always fetch the current
version:

```
https://github.com/WaffyApp/waffy-magento2/releases/latest/download/waffy-module-payment.zip
```

```bash
# From the Magento root
mkdir -p app/code/Waffy/Payment
unzip waffy-module-payment-0.7.2.zip -d app/code/Waffy/Payment

bin/magento module:enable Waffy_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

> **Note on `-f` and static content:** `setup:static-content:deploy -f` is
> required in **production** mode. In **developer** mode you can skip that
> line. Run `bin/magento deploy:mode:show` if you're unsure which mode the
> store is in.

### Verify the module is active

```bash
bin/magento module:status Waffy_Payment
```

Expected output: `Module is enabled`.

---

## 3. Configuration (Admin panel — no commands)

Go to **Stores → Waffy setup**. The wizard has three steps; you can re-open any
of them later to change the connection.

### 3.1 Create your Waffy client

Choose **Sandbox** first (no real money), then follow the link to the Waffy
portal: set up your organization if you have not already, create an API client
for Magento, and download its **env file**. Keep the client secret at hand —
the file does not include it.

### 3.2 Connect

Upload the env file, or type the values in (client ID, admin username — a mobile or an email,
organization account ID). Enter the **client secret** and **admin password**.
The page shows whether the values are **SANDBOX** or **LIVE** and refuses a
file that does not match the environment you chose.

Saving stores the connection as a draft: your current connection (if any)
keeps taking payments until the next step succeeds.

### 3.3 Verify & activate

**Run checks and activate** tests the client and admin credentials against
Waffy, reads your client's settings, and **registers your store's webhook URL
with Waffy** (`https://your-store.com/waffy/webhook`) — nothing to send to the
Waffy team. When every check passes, the connection goes live and the payment
method is enabled.

The contract terms (no returns, 30-day milestone, category "Services") are
fixed for every store. The title buyers see at checkout is under **Stores →
Configuration → Sales → Payment Methods → Waffy Escrow Payment**.

---

## 4. Test in Sandbox

1. Place a test order on the storefront and choose **Waffy** at checkout.
2. Complete payment on the Waffy hosted payment page.
3. Confirm the order moves to **Processing** and shows Waffy status comments
   in the order's **Comments History**.

---

## 5. Go Live

1. Open **Stores → Waffy setup**, choose **Live** in step 1, and create a live
   client in `portal.waffyapp.com`.
2. Upload the live env file in step 2 and run the checks in step 3.
3. Place one small real order to confirm end to end.

---

## 6. Troubleshooting

| Symptom | Fix |
|---------|-----|
| Waffy not shown at checkout | Run the checks in **Stores → Waffy setup**, confirm **Enabled = Yes**, and run `cache:flush` |
| `composer require` auth error | Use Marketplace public key as username, private key as password (Access Keys) |
| Order status not updating | Re-run the checks in **Stores → Waffy setup** — the last one registers the webhook URL with Waffy |
| Class/compile errors after install | Re-run `bin/magento setup:di:compile` then `cache:flush` |

## Support

Email **support@waffyapp.com** for credentials, webhook registration, or
setup help.
