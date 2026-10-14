# Magento Marketplace listing assets

Source of truth for what is pasted into the Marketplace developer portal for
`waffy/module-payment`. Marketing review rejected the 0.5.0 submission on
2026-09-03; this folder holds the fixes.

## What the reviewer asked for, and where it is fixed

| Review finding | Fix |
|---|---|
| Icon text must match the extension title | `icon-240.png` — 240×240, reads "Waffy Escrow Payment" |
| Remove the heading at the top of the Long Description | `LONG_DESCRIPTION.html` opens with a paragraph, no `<h1>`/`<h2>` first |
| Add a Security / PCI section | `LONG_DESCRIPTION.html` → `<h2>Security</h2>` |

## Portal steps

1. **Extension title** → set to exactly `Waffy Escrow Payment`.
   This is the string the icon must match, and it matches the admin config
   group label in `etc/adminhtml/system.xml`.
2. **Extension icon** → upload `icon-240.png` (240×240 PNG, as the portal spec
   requires). The Waffy mark is Arabic script, so the Latin title is set in
   type underneath it — a reviewer reading the icon sees the title verbatim.
   If the title ever changes, edit `icon-source.html` and re-render:

   ```bash
   "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
     --headless --disable-gpu --hide-scrollbars --force-device-scale-factor=1 \
     --window-size=240,240 --default-background-color=FFFFFFFF \
     --screenshot=icon-240.png "file://$PWD/icon-source.html"
   ```
3. **Long Description** → paste `LONG_DESCRIPTION.html` into the portal editor's
   HTML/source view (not the WYSIWYG — pasting rendered text loses the headings).

## Before you submit: one claim to confirm

The Security section says a merchant using this extension falls under **PCI DSS
SAQ A**, which is only true if Waffy's hosted payment page is itself a **PCI DSS
validated** third party. That is a statement about the Waffy backend, not about
this module. Confirm Waffy's PCI DSS status (and get the AOC / compliance
documentation) with the backend team before submitting, and adjust the wording
if the answer is anything other than "validated".

Everything else in the Security section is verified against this repo:

- Redirect-only flow — `Controller/Checkout/Start.php` redirects to the Waffy
  payment URL; there is no other payment path.
- No card fields — `view/frontend/web/template/payment/waffy-payment.html` has a
  radio button, the logo, a redirect notice and Place Order. Nothing else.
- No cardholder data stored — `etc/db_schema.xml` defines one table,
  `waffy_token`, holding OAuth tokens encrypted via `EncryptorInterface`.
- Credentials encrypted at rest — `etc/adminhtml/system.xml`, `type="obscure"` +
  `Magento\Config\Model\Config\Backend\Encrypted`.
- HTTPS-only endpoints — `Sdk/Config/Environment.php`.
- Webhook IP allowlist → 403 — `Controller/Webhook/Index.php`,
  `Sdk/Webhook/WebhookIpAllowlist.php`.

## Version note

The rejected submission was 0.5.0; this repo is at 0.6.0. Resubmit against the
current tag so the listing and the package agree.
