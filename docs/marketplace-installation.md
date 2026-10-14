# Installing Waffy Escrow Payment from the Adobe Commerce Marketplace

**Extension:** [Waffy Escrow Payment](https://commercemarketplace.adobe.com/waffy-module-payment.html)
**Composer package:** `waffy/module-payment`
**Module name:** `Waffy_Payment`
**Latest version:** 0.6.0
**Price:** Free
**Works with:** Adobe Commerce (Cloud & on-premises) 2.4.x, Magento Open Source 2.4.x, PHP 8.1+

---

## Before you start

| You need | Notes |
|---|---|
| An Adobe Commerce Marketplace account | Free to create — no paid Adobe Commerce licence required |
| Command-line (SSH) access to your store | Or a developer / hosting provider who has it |
| Your Waffy API credentials | Client ID, Client Secret, admin email & password — request them from **support@waffyapp.com** |

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
composer require waffy/module-payment:0.6.0

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
  `composer require waffy/module-payment` with no `:0.6.0` suffix.

### On Adobe Commerce Cloud

Do **not** run the `bin/magento` commands on Cloud — the deploy pipeline runs
them for you.

```bash
# In your local clone of the Cloud project, on an integration branch
composer require waffy/module-payment:0.6.0

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

## Step 6 — Configure it in the Admin panel

Sign in to the Magento Admin and go to:

**Stores → Configuration → Sales → Payment Methods → Waffy Escrow Payment**

### 6.1 Connect your Waffy account

1. **Environment** — choose **Sandbox** first, so you can test without real money.
2. Enter the **Sandbox Client ID**, **Sandbox Client Secret**, **Sandbox Client
   Admin Email** and **Sandbox Client Admin Password** supplied by Waffy.
   (Switching Environment to Production reveals a separate set of production fields.)
3. **Merchant Phone Number** *(required)* — in E.164 format, e.g. `+9665XXXXXXXX`.
   This identifies you as the seller on every escrow contract.
4. **Broker Phone Number** — optional; leave empty if you do not use a broker.

Secrets and passwords are stored encrypted by Magento.

### 6.2 Set your contract rules

These describe how your escrow contracts behave. Match them to how you sell:

| Setting | What it means | Typical value |
|---|---|---|
| **Return Policy** | Whether returns are allowed on the contract | Your store's policy |
| **Return Fee Payee** | Who pays the return fee | Provider or customer |
| **Is Deliverable** | The product is physically delivered | Yes for goods |
| **Is Inspectable** | The buyer may inspect goods before funds are released | Yes for goods |
| **Is Acceptable (by Customer)** | The buyer explicitly accepts before funds are released | Yes |
| **Milestone Deadline (days)** | Days until the payment milestone expires | 30 |
| **Contract Category** | Category shown to the buyer on the Waffy page | e.g. Electronics |

### 6.3 Set how it appears at checkout

1. **Title** — the label buyers see at checkout, e.g. "Pay with Waffy".
2. **New Order Status** — the status new Waffy orders start in.
3. **Payment from Applicable Countries** — all countries, or a specific list.
4. Set **Enabled** → **Yes**.
5. Click **Save Config**.

Then flush the cache:

```bash
bin/magento cache:flush
```

---

## Step 7 — Register your webhook with Waffy

The webhook is how Waffy tells your store that a payment has been secured or
released, so orders update by themselves.

1. At the top of the Waffy configuration section, copy the **Webhook URL**.
   It looks like `https://your-store.com/waffy/webhook`.
2. Email that URL to **support@waffyapp.com** so Waffy registers your store.
3. **Webhook Allowed IPs** is optional. Leave it empty to accept calls from
   anywhere; to lock it down, ask Waffy for their server IPs and enter one IP or
   CIDR range per line.

> **Keep Magento cron running.** The extension refreshes its access tokens in the
> background every 15 minutes so checkout is fast. Without cron, checkout still
> works — it just fetches tokens on the first order itself.

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

1. In the Waffy configuration, set **Environment** → **Production**.
2. Enter your **production** Client ID, Client Secret, admin email and password.
3. If your store URL has changed since Step 7, send Waffy the new Webhook URL.
4. **Save Config**, then `bin/magento cache:flush`.
5. Place one small real order end to end to confirm everything works.

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
| Order status never updates after payment | The webhook is not registered, or is blocked. Confirm Waffy has your Webhook URL (Step 7) and that **Webhook Allowed IPs** is empty or includes Waffy's IPs. |
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
