# Waffy Magento 2 Module

Waffy escrow payment integration for Magento 2 / Adobe Commerce.

Composer package: `waffy/module-payment` (TBD — confirm name with Adobe
Commerce Marketplace conventions before publishing).

## Status

**v1.0 — Not yet started.** This is Phase 1 of the project (per
`/Users/Ahmed/.claude/plans/read-these-md-files-swirling-locket.md`).

Depends on:

- [`waffy-ecom-sdk-php`](https://github.com/WaffyApp/waffy-ecom-sdk-php) — required at runtime, installed via Composer

## Roadmap

### v1.0 (thin MVP)

- Payment method registration (Magento appears at checkout)
- Admin config: paste Waffy Bearer token + webhook signing secret, sandbox toggle
- Magento Payment Gateway Adapter — `authorize`, `capture`, `cancel` wired to the SDK
- Checkout: redirect buyer to Waffy hosted payment page
- Webhook controller at `/V1/waffy/webhook` — verifies HMAC, auto-invoices on `PAYMENT_COMPLETED`
- Order ↔ Waffy contract mapping table

### v1.1 (Marketplace-ready)

- Refund flow (credit memo → SDK → Waffy)
- Dispute flow (webhook → order hold)
- Cron settlement reconciliation
- Bilingual AR + EN, RTL support
- Adobe Commerce EQP compliance
- User-guide PDF
- Submit to Adobe Commerce Marketplace

### v2.0 (post-gateway)

- Switch from Bearer-token to OAuth via `waffy-ecom-gateway`
- All traffic routes through the gateway

## License

Proprietary.
