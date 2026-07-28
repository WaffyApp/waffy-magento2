# Vendored copy — DO NOT EDIT BY HAND

This directory is a **verbatim, generated mirror** of the `src/` tree in the
canonical PHP SDK. It is bundled into this module instead of pulled in as a
Composer dependency (`waffy-ecom-sdk-php` isn't published on Packagist).

**Source of truth is the SDK repo. Never edit files under `Sdk/` directly.**
Make the change in `waffy-ecom-sdk-php`, commit it there, then run
`dev/sync-sdk.sh` to regenerate this folder.

- Source: https://github.com/WaffyApp/waffy-ecom-sdk-php
- Synced from: `v0.1.1-2-g72c7ff7` (commit `72c7ff7-dirty`)
- Synced on: 2026-07-28
- Namespace: `Waffy\Ecommerce\` (unchanged — mapped via this module's
  `composer.json` psr-4 autoload, so all `use` statements work unmodified)
- Transform: none. This is a byte-for-byte copy of the SDK `src/`. If Magento
  ever requires a source-level change (e.g. dropping `final` for the
  Marketplace Code Sniffer), make it in the SDK so the mirror stays verbatim.

Regenerate with: `dev/sync-sdk.sh`
