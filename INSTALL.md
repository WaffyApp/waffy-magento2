# Waffy Escrow Payment — Installation & Setup Guide

**Extension:** Waffy Escrow Payment for Magento 2
**Composer package:** `waffy/module-payment`
**Module name:** `Waffy_Payment`
**Version:** 0.6.0
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
| Waffy credentials | Client ID, Client Secret, admin email & password — request from **support@waffyapp.com** |

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

> Pin an exact version if you prefer: `composer require waffy/module-payment:0.6.0`.
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
composer require waffy/module-payment:0.6.0
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
unzip waffy-module-payment-0.6.0.zip -d app/code/Waffy/Payment

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

Go to **Stores → Configuration → Sales → Payment Methods → Waffy Escrow
Payment** and complete the following.

### 3.1 Connect your Waffy account

1. **Environment** — set to **Sandbox** first for testing (no real money).
2. Enter the **Sandbox Client ID**, **Client Secret**, **Admin Email**, and
   **Admin Password** provided by Waffy.
3. **Merchant Phone Number** *(required)* — E.164 format, e.g. `+9665XXXXXXXX`.
   This identifies the merchant on every escrow contract.
4. *(Optional)* **Broker Phone Number** — leave empty if not used.

### 3.2 Register your webhook with Waffy

1. Copy the **Webhook URL** shown at the top of the configuration section.
   It looks like: `https://your-store.com/waffy/webhook`
2. Send that URL to the Waffy team so they register your store for order
   status updates.
3. *(Optional)* **Webhook Allowed IPs** — leave empty to allow all. To
   restrict, enter one IP or CIDR range per line (ask Waffy for their server
   IPs first).

### 3.3 Contract settings

Match these to how you sell:

| Setting | Meaning |
|---------|---------|
| **Return Policy** | Whether/what returns are allowed on the contract |
| **Return Fee Payee** | Who pays the return fee (provider / customer) |
| **Is Deliverable** | Product requires physical delivery |
| **Is Inspectable** | Buyer may inspect goods before funds are released |
| **Is Acceptable (by Customer)** | Buyer explicitly accepts/rejects before release |
| **Milestone Deadline (days)** | Days until the payment milestone expires (default 30) |
| **Contract Category** | Category shown to the buyer on the Waffy page (e.g. Services) |

### 3.4 Storefront settings

1. **Title** — what buyers see at checkout (e.g. "Pay with Waffy").
2. **Payment from Applicable Countries** — all, or a specific list.
3. **Enabled** → **Yes**.
4. Click **Save Config**, then flush the cache:

```bash
bin/magento cache:flush
```

---

## 4. Test in Sandbox

1. Place a test order on the storefront and choose **Waffy** at checkout.
2. Complete payment on the Waffy hosted payment page.
3. Confirm the order moves to **Processing** and shows Waffy status comments
   in the order's **Comments History**.

---

## 5. Go Live

1. Set **Environment → Production**.
2. Enter your **Production** Client ID, Client Secret, Admin Email & Password.
3. If your store URL changed, re-share the new **Webhook URL** with Waffy.
4. **Save Config** and flush the cache.
5. Place one small real order to confirm end to end.

---

## 6. Troubleshooting

| Symptom | Fix |
|---------|-----|
| Waffy not shown at checkout | Confirm **Enabled = Yes**, correct **countries**, and `cache:flush` was run |
| `composer require` auth error | Use Marketplace public key as username, private key as password (Access Keys) |
| Order status not updating | Confirm the **Webhook URL** was registered with Waffy and isn't blocked by the IP allowlist |
| Class/compile errors after install | Re-run `bin/magento setup:di:compile` then `cache:flush` |

## Support

Email **support@waffyapp.com** for credentials, webhook registration, or
setup help.
