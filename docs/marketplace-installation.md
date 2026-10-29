# Installing Waffy Escrow Payment from the Adobe Commerce Marketplace

**Extension:** [Waffy Escrow Payment](https://commercemarketplace.adobe.com/waffy-module-payment.html)
**Composer package:** `waffy/module-payment`
**Module name:** `Waffy_Payment`
**Latest version:** 0.7.1
**Price:** Free
**Works with:** Adobe Commerce (Cloud & on-premises) 2.4.x, Magento Open Source 2.4.x, PHP 8.2+

---

## Before you start

| You need | Notes |
|---|---|
| An Adobe Commerce Marketplace account | Free to create — no paid Adobe Commerce licence required |
| Command-line (SSH) access to your store | Or a developer / hosting provider who has it |
| A Waffy organization | Set up in the Waffy portal (`portal-dev.waffyapp.com` for sandbox, `portal.waffyapp.com` for live) — Step 6 walks you through it |

> **Why the command line?**
> Magento removed the admin "Web Setup Wizard" in 2.4.0, so **every** extension —
> from Adobe or anyone else — is installed with Composer from the command line.
> This is a one-time technical step. Everything after that is done in the Admin
> panel with no commands.

The whole process is: **get the extension → generate access keys → install →
configure → test**. Budget about 15 minutes.

---

## Step 1 — Get the extension from the Marketplace

1. Go to **<https://commercemarketplace.adobe.com/waffy-module-payment.html>**
2. Sign in (or create a free Adobe account).
3. Click **Add to Cart**, then check out. The extension is free, so there is
   nothing to pay — but this step is required: it links the extension to your
   account so Adobe's Composer server will serve it to you.
4. Confirm it now appears under **My Profile → My Purchases**. Note the
   **Composer package name** (`waffy/module-payment`) and the version shown.

---

## Step 2 — Generate your Adobe access keys

Marketplace extensions are downloaded from Adobe's private Composer server
(`repo.magento.com`), which requires your own **access keys**. They are free,
and every store needs its own pair.

1. In the Marketplace, go to **My Profile → Access Keys**.
2. Click **Create A New Access Key** and give it a name (e.g. your store name).
3. You now have two values — keep them somewhere safe:
   - **Public Key** → used as the Composer **username**
   - **Private Key** → used as the Composer **password**

> If you have installed any Marketplace extension on this store before, you
> already have keys configured and can skip to Step 4.

---

## Step 3 — Give the keys to Composer

Run this once on the server, from your Magento root folder:

```bash
composer config --global http-basic.repo.magento.com <PUBLIC_KEY> <PRIVATE_KEY>
```

Replace `<PUBLIC_KEY>` and `<PRIVATE_KEY>` with your own values.

This saves them to `~/.composer/auth.json`. To keep them per-project instead,
drop `--global` and Composer writes an `auth.json` in the Magento root —
**never commit that file to Git**.

---

## Step 4 — Install the extension

### On a self-hosted / on-premises store

From the Magento root folder:

```bash
# 1. Download the extension
composer require waffy/module-payment:0.7.1

# 2. Enable it and register it with Magento
bin/magento module:enable Waffy_Payment --clear-static-content
bin/magento setup:upgrade
bin/magento setup:di:compile

# 3. Publish assets (production mode only) and clear the cache
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

Notes:

- Take a **database backup** first, and consider enabling maintenance mode
  (`bin/magento maintenance:enable` … `bin/magento maintenance:disable`) on a
  live store.
- `setup:static-content:deploy -f` is only needed in **production** mode. Run
  `bin/magento deploy:mode:show` if you are unsure which mode you are in.
- To always take the newest release instead of pinning a version, use
  `composer require waffy/module-payment` with no `:0.7.1` suffix.

### On Adobe Commerce Cloud

Do **not** run the `bin/magento` commands on Cloud — the deploy pipeline runs
them for you.

```bash
# In your local clone of the Cloud project, on an integration branch
composer require waffy/module-payment:0.7.1

git add composer.json composer.lock
git commit -m "Add Waffy Escrow Payment extension"
git push origin <your-branch>
```

The build & deploy pipeline installs the module automatically. Promote the
branch to staging and production as usual.

---

## Step 5 — Check that it installed

```bash
bin/magento module:status Waffy_Payment
```

Expected output: **`Module is enabled`**.

---

## Step 6 — Set up Waffy (Admin panel)

Go to **Stores → Waffy setup**. The wizard has three steps, and you can re-open
any of them later.

1. **Create your Waffy client** — choose **Sandbox** first, follow the link to
   the Waffy portal, set up your organization, create an API client for Magento
   and download its **env file**.
2. **Connect** — upload the env file (or type the values), then enter the
   **client secret** and **admin password**, which the file does not include.
   The page shows **SANDBOX** or **LIVE** and refuses a file for the other
   environment.
3. **Verify & activate** — click **Run checks and activate**. The wizard checks
   your credentials against Waffy, registers your store's webhook URL with
   Waffy, and enables the payment method once every check passes.

The buyer-facing **Title** is under **Stores → Configuration → Sales → Payment
Methods → Waffy Escrow Payment**. Contract terms (no returns, 30-day milestone,
category "Services") are fixed.

## Step 7 — (No longer needed) Webhook registration

The wizard registers your webhook URL with Waffy in Step 6.3 — there is nothing
to send to the Waffy team.

---

## Step 8 — Place a test order in Sandbox

1. On your storefront, add a product to the cart and go to checkout.
2. Choose **Waffy** as the payment method and place the order.
3. You are redirected to Waffy's secure hosted payment page — card details never
   touch your store.
4. Complete the sandbox payment.
5. Back in the Admin, open the order under **Sales → Orders** and confirm it has
   moved to **Processing** and shows Waffy status updates in
   **Comments History**.

---

## Step 9 — Go live

1. Open **Stores → Waffy setup**, choose **Live** in step 1 and create a live
   client in `portal.waffyapp.com`.
2. Upload the live env file in step 2 and run the checks in step 3.
3. Place one small real order to confirm end to end.

---

## Updating to a newer version

```bash
composer update waffy/module-payment
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

On Adobe Commerce Cloud, run the `composer update` locally, then commit and push
`composer.json` and `composer.lock`.

---

## Uninstalling

```bash
bin/magento module:disable Waffy_Payment
composer remove waffy/module-payment
bin/magento setup:upgrade
bin/magento cache:flush
```

---

## Troubleshooting

| Symptom | What to do |
|---|---|
| `composer require` returns a 401 / authentication error | Your access keys are wrong or missing. Re-run Step 3, using the **public** key as the username and the **private** key as the password. |
| `Could not find a matching version of package waffy/module-payment` | The extension is not linked to your account yet — complete the free checkout in Step 1, then retry. |
| Waffy does not appear at checkout | Check **Enabled = Yes**, that the customer's country is allowed under **Payment from Applicable Countries**, and run `bin/magento cache:flush`. |
| Order status never updates after payment | The webhook is not registered. Re-run the checks in **Stores → Waffy setup** — the last one registers the webhook URL with Waffy. |
| Errors about missing classes after installing | Re-run `bin/magento setup:di:compile` then `bin/magento cache:flush`. |
| Checkout is slow on the first order | Magento cron is not running, so tokens are fetched during checkout. Set up cron and the warm-up job handles it. |

To see what the extension is doing, check the store log:

```bash
grep "Waffy: checkout step" var/log/system.log | tail -20
```

---

## Support

- 📧 **support@waffyapp.com** — credentials, webhook registration, setup help
- 🌐 **<https://waffyapp.com>**
- 🛒 **<https://commercemarketplace.adobe.com/waffy-module-payment.html>**
