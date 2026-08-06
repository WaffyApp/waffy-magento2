# Waffy Escrow Payment for Magento 2

[![Packagist Version](https://img.shields.io/packagist/v/waffy/module-payment.svg)](https://packagist.org/packages/waffy/module-payment)
[![License](https://img.shields.io/packagist/l/waffy/module-payment.svg)](https://github.com/WaffyApp/waffy-magento2/blob/main/composer.json)
[![PHP](https://img.shields.io/packagist/php-v/waffy/module-payment.svg)](https://www.php.net/)

Add **[Waffy](https://waffyapp.com) escrow payments** to your Magento 2 or
Adobe Commerce store. Instead of money moving straight from buyer to seller,
payments are held safely by Waffy and released once the order is confirmed —
building buyer trust and reducing payment risk for merchants.

Lightweight, native, and built on the official Waffy PHP SDK (bundled — no
extra dependencies to install).

---

## Features

- **Escrow-backed checkout** — Waffy appears as a payment method; buyers pay on
  Waffy's secure hosted payment page, so card data never touches your store.
- **Simple admin setup** — connect your account with your Waffy API
  credentials and a sandbox/production toggle, all from the Magento admin.
- **Automatic order status updates** — a built-in webhook receiver keeps the
  Magento order in sync as the payment is secured in escrow and later released.
- **Configurable contracts** — return policy, delivery/inspection/acceptance
  flags, milestone deadline, and buyer-facing category.
- **Self-contained** — the Waffy PHP SDK is bundled, so installation pulls in
  everything the extension needs.

## Compatibility

| | |
|---|---|
| Adobe Commerce (Cloud & on-premises) | 2.4.x |
| Magento Open Source | 2.4.x |
| PHP | 8.1+ |

## Installation

Install with Composer — works on Adobe Commerce and Magento Open Source, no
access keys required:

```bash
composer require waffy/module-payment
bin/magento module:enable Waffy_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

> In **production** mode also run `bin/magento setup:static-content:deploy -f`.

📖 **Full guide** (Adobe Marketplace, Cloud, and manual/offline installs):
[INSTALL.md](INSTALL.md)

## Configuration

You'll need Waffy API credentials — contact **support@waffyapp.com** to get
them.

In the admin, go to **Stores → Configuration → Sales → Payment Methods →
Waffy Escrow Payment**:

1. Set **Environment** to **Sandbox** for testing.
2. Enter your Waffy **Client ID / Secret** and **admin email & password**.
3. Set your **Merchant Phone** (E.164, e.g. `+9665XXXXXXXX`).
4. Copy the **Webhook URL** shown and send it to the Waffy team to register
   your store.
5. Adjust the contract settings, set **Enabled → Yes**, and **Save Config**.

Then place a test order and pay with Waffy to confirm the flow end to end.
See [INSTALL.md](INSTALL.md) for the detailed walkthrough and go-live steps.

## How it works

1. The buyer selects **Waffy** at checkout and is redirected to the secure
   Waffy hosted payment page.
2. Funds are held in escrow by Waffy, protecting both parties.
3. Waffy notifies your store as the payment is secured and later released, and
   the Magento order status updates automatically.

### Token warm-up

Checkout needs four OAuth tokens. All are cached (encrypted) in `waffy_token` and
reused until they near expiry, and the module keeps that cache warm so checkout
does no auth work:

- **Store tokens (app + merchant).** The `waffy_refresh_tokens` cron job runs every
  15 minutes per active store and renews either token that is within 30 minutes of
  expiring — a no-op otherwise. Also primed when the payment configuration is
  saved. **This requires Magento cron to be running**; if it is not, checkout
  still works, it just fetches the tokens itself the first time.
- **Buyer token.** Minted on `customer_login`, and on the first storefront page of
  a session for a customer who arrived already logged in. Requires a telephone on
  the customer's default billing address; guests are never signed up
  speculatively. The work is deferred until after the response is sent
  (`Model\AfterResponse`), so shoppers never wait for it.

Failures are logged (`var/log/system.log`) and never surfaced to a shopper.

### Verifying it: the call log

Every Waffy call is logged with a timestamp, its outcome and how long it took:

```bash
grep "Waffy: checkout step" var/log/system.log | tail -8
```
```
12:36:09 Waffy: checkout step=app_token        status=cached took=0.0ms
12:36:09 Waffy: checkout step=merchant_token   status=cached took=0.0ms
12:36:09 Waffy: checkout step=customer_sign_up status=cached took=0.0ms
12:36:09 Waffy: checkout step=customer_token   status=cached took=0.0ms
12:36:15 Waffy: checkout step=create_contract  status=done   took=5884.1ms
```

`status=cached` means the call **never left the server** — the token was already
in `waffy_token`. The context says which path made it: `warm-up` (cron),
`login-prefetch` (customer signed in) or `checkout`. A healthy store shows the
four auth calls under `warm-up`/`login-prefetch` and only the four contract calls
under `checkout`.

### Live progress in the checkout modal

The disclaimer modal shows a progress bar that fills as each call completes, fed
by `GET waffy/checkout/progress` — polled with a key the browser sets as a cookie
just before the order is placed. In **sandbox** the modal also lists each call
with a checkmark, a duration, and `cached` against the ones the token cache
skipped; in **production** only the bar is shown.

Two Magento-specific details make this work:

- **The progress endpoint is session-free.** It reads its key straight off the
  request cookies and looks state up in the cache — no session, cart or customer.
- **`Controller\Checkout\Start` closes the session before calling Waffy.** PHP
  holds an exclusive lock on the session for the whole request, so a checkout
  taking tens of seconds would stall every other request from that browser and
  the polls would all arrive at the end. `writeClose()` on the JSON path releases
  it before the slow work begins.

## Support

- 📧 **support@waffyapp.com**
- 🌐 [waffyapp.com](https://waffyapp.com)
- 🐛 [Issues](https://github.com/WaffyApp/waffy-magento2/issues)

## License

Licensed under the [OSL-3.0](https://opensource.org/licenses/OSL-3.0) license.
