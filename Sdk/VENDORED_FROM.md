# Vendored copy

This directory is a bundled copy of the `waffy/ecommerce-sdk` PHP SDK,
vendored directly into this module instead of being pulled in as a
Composer dependency (`waffy-ecom-sdk-php` isn't published on Packagist).

- Source: https://github.com/WaffyApp/waffy-ecom-sdk-php
- Commit: e38dfc9f6398cf186b422318495b16b345e3d5b1 (2026-07-08)
- Namespace: `Waffy\Ecommerce\` (unchanged — mapped via this module's
  `composer.json` psr-4 autoload to keep all existing `use` statements
  working without modification)

To pick up SDK changes, re-copy `src/` from the SDK repo into this
directory and update the commit hash above. If the SDK is ever published
to Packagist or Adobe Commerce Marketplace as a Shared Package, prefer
switching back to a real Composer dependency instead of this vendored
copy.
