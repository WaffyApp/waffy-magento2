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
- **Step-by-step setup** — a three-step wizard (Stores → Waffy setup): create
  your client in the Waffy portal, upload its env file, and verify. Re-open
  any step later to change the connection.
- **Buyer consent built in** — when Waffy needs the buyer's consent, checkout
  asks for a 4-digit code sent to their phone, in the same modal.
- **Automatic order status updates** — the wizard registers your webhook with
  Waffy, and the built-in receiver keeps the Magento order in sync as the
  payment is secured in escrow and later released.
- **Self-contained** — the Waffy PHP SDK is bundled, so installation pulls in
  everything the extension needs.

## Compatibility

| | |
|---|---|
| Adobe Commerce (Cloud & on-premises) | 2.4.x |
| Magento Open Source | 2.4.x |
| PHP | 8.2+ |

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

In the admin, go to **Stores → Waffy setup** (or the link in **Stores →
Configuration → Sales → Payment Methods → Waffy Escrow Payment**):

1. **Create your Waffy client** — pick **Sandbox** or **Live** and follow the
   link to the Waffy portal (`portal-dev.waffyapp.com` / `portal.waffyapp.com`)
   to set up your organization and create a client for Magento.
2. **Connect** — upload the client's env file (or type the values in), and
   enter the client secret and admin password, which the file does not carry.
   The page shows whether the values are sandbox or live and refuses a file for
   the other environment.
3. **Verify & activate** — runs live checks against Waffy, registers your
   webhook URL with Waffy, and enables the payment method when everything
   passes. Until then, your current connection keeps taking payments.

Then place a test order and pay with Waffy to confirm the flow end to end.
See [INSTALL.md](INSTALL.md) for the detailed walkthrough and go-live steps.

## How it works

1. The buyer selects **Waffy** at checkout and is redirected to the secure
   Waffy hosted payment page.
2. Funds are held in escrow by Waffy, protecting both parties.
3. Waffy notifies your store as the payment is secured and later released, and
   the Magento order status updates automatically.

### Checkout steps

After the order is placed the checkout modal calls one endpoint per step
(`POST waffy/checkout/*`, each acting only on the session's own last order):

```
prepare ─┬─▶ startpayment ─────────────┐
begin ───┴─(consent resolved)─▶ ticket ─┴─▶ go → "Continue to Waffy"
```

- `prepare` creates the contract, milestone and parties; `begin` links the
  buyer and — only when Waffy says their consent is required — sends an OTP,
  which the modal collects and `verify` checks.
- Start-payment never waits for the buyer; the ticket waits for the contract
  and the buyer's consent.
- Cancelling at the OTP step cancels the order, restores the cart and asks
  Waffy to remove the unpaid contract. Waffy has no endpoint for that yet, so
  the order is flagged and the `waffy_discard_abandoned_contracts` cron retries.

Each step records its progress in the `waffy_checkout` table, one column per
fact, so the parallel requests never overwrite each other.

### Token warm-up

The org and client admin tokens are cached (encrypted) in `waffy_token` and
reused until they near expiry. The `waffy_refresh_tokens` cron job runs every
15 minutes per active store and renews either token within 30 minutes of
expiring — a no-op otherwise. The setup wizard primes them on activation.
**This requires Magento cron to be running**; if it is not, checkout still
works, it just fetches the tokens itself.

There is no buyer token to prefetch on Waffy ID: the payment ticket is scoped to
one contract, so it is minted at checkout.

Every Waffy call is logged with its outcome and duration:

```bash
grep "Waffy: " var/log/system.log | tail -8
```

`status=cached` means the call never left the server.

## Support

- 📧 **support@waffyapp.com**
- 🌐 [waffyapp.com](https://waffyapp.com)
- 🐛 [Issues](https://github.com/WaffyApp/waffy-magento2/issues)

## License

Licensed under the [OSL-3.0](https://opensource.org/licenses/OSL-3.0) license.
