#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# dev/switch-mode.sh — flip the magento-test store between two Waffy install modes.
#
#   ./dev/switch-mode.sh local       # develop against the LOCAL module source
#   ./dev/switch-mode.sh packagist    # run the PUBLISHED package from Packagist
#   ./dev/switch-mode.sh status       # show the current mode (no changes)
#
# local     → the module source is mounted READ-ONLY at /var/module (outside the
#             Mutagen sync root) and installed as a Composer *path* package,
#             symlinked into vendor/waffy/module-payment. Edit source on the host,
#             then `ddev exec php bin/magento cache:flush` (or di:compile for
#             DI/schema changes). The bundled Sdk/ provides the SDK.
# packagist → composer require waffy/module-payment from Packagist. Exactly what a
#             real merchant installs. No mounts.
#
# WHY this shape (learned the hard way):
#   • Bind mounts INTO /var/www/html are shadowed empty by Mutagen — so we mount
#     to /var/module (outside the sync root), like the store already does for the
#     SDK at /var/sdk, and let Composer symlink it into vendor/.
#   • The mount is READ-ONLY, so a container-side delete can never reach back and
#     damage the host module source.
#
# Safe to run repeatedly and from either mode. Only touches magento-test/
# (composer.json, .ddev/, app/code/Waffy, generated/) — never the source repos.
# ──────────────────────────────────────────────────────────────────────────────
set -euo pipefail

MODULE_VERSION="^0.5.1"          # Packagist constraint used in packagist mode
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MODULE_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"     # waffy-magento2/
TEST="$MODULE_ROOT/magento-test"                # the DDEV store
DDEV="$TEST/.ddev"
COMPOSER_JSON="$TEST/composer.json"

[ -d "$DDEV" ] || { echo "❌ magento-test/.ddev not found at $TEST"; exit 1; }

current_mode() {
  if   grep -q '/var/module' "$COMPOSER_JSON"; then echo "local";
  elif grep -q '"waffy/module-payment"' "$COMPOSER_JSON"; then echo "packagist";
  else echo "none"; fi
}

# Rewrite composer.json's waffy require + local path repo for the target mode.
# Only those keys are touched; everything else is preserved.
rewrite_composer() {
  php -r '
    $f = $argv[1]; $mode = $argv[2];
    $j = json_decode(file_get_contents($f), true);
    unset($j["require"]["waffy/module-payment"], $j["require"]["waffy/ecommerce-sdk"]);
    // drop any local path repos we manage (/var/module, /var/sdk)
    $j["repositories"] = array_values(array_filter($j["repositories"] ?? [],
      fn($r) => !in_array($r["url"] ?? "", ["/var/module", "/var/sdk"], true)));
    if ($mode === "packagist") {
      $j["require"]["waffy/module-payment"] = $argv[3];
    } else { // local: path package, symlinked from /var/module
      $j["require"]["waffy/module-payment"] = "*";
      $j["repositories"][] = ["type"=>"path","url"=>"/var/module","options"=>["symlink"=>true]];
    }
    ksort($j["require"]);
    file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
  ' "$COMPOSER_JSON" "$1" "$MODULE_VERSION"
}

# Mount the module source READ-ONLY to /var/module (outside /var/www/html).
# One bind per component dir keeps magento-test/ out of the path package, so
# Composer's classmap never descends into a nested Magento.
write_module_mount() {
  cat > "$DDEV/docker-compose.waffy.yaml" <<'YAML'
#ddev-silent-no-warn
# Module source, READ-ONLY, assembled at /var/module (outside the Mutagen sync
# root). Composer installs it as a path package symlinked into vendor/.
services:
  web:
    volumes:
      - "../../composer.json:/var/module/composer.json:ro"
      - "../../registration.php:/var/module/registration.php:ro"
      - "../../Block:/var/module/Block:ro"
      - "../../Console:/var/module/Console:ro"
      - "../../Controller:/var/module/Controller:ro"
      - "../../Model:/var/module/Model:ro"
      - "../../etc:/var/module/etc:ro"
      - "../../view:/var/module/view:ro"
      - "../../Sdk:/var/module/Sdk:ro"
YAML
}

remove_module_mount() {
  rm -f "$DDEV/docker-compose.waffy.yaml" "$DDEV/docker-compose.waffy.yaml.disabled" \
        "$DDEV/docker-compose.sdk.yaml"   "$DDEV/docker-compose.sdk.yaml.disabled"
}

restart() { echo "▶ ddev restart…"; ddev restart -y >/dev/null; }

magento_steps() {
  ddev exec php bin/magento module:enable Waffy_Payment || true
  ddev exec php bin/magento setup:upgrade
  ddev exec php bin/magento setup:di:compile
  ddev exec php bin/magento cache:flush
}

MODE="${1:-status}"
cd "$TEST"

case "$MODE" in
  status)
    echo "Current mode: $(current_mode)"
    echo "  composer: $(grep -E '"waffy/module-payment"' "$COMPOSER_JSON" | tr -d ' ' || true)"
    printf "  mounts:   "; ls "$DDEV"/docker-compose.waffy.yaml 2>/dev/null | xargs -n1 basename 2>/dev/null | tr '\n' ' '; echo
    exit 0
    ;;

  local)
    echo "▶ Switching to LOCAL dev mode…"
    rewrite_composer local
    write_module_mount
    rm -rf "$TEST/app/code/Waffy" "$TEST/generated/code/Waffy"
    restart                                   # /var/module now populated (read-only)
    ddev composer update --no-interaction     # symlinks vendor/waffy/module-payment → /var/module
    magento_steps
    echo "✅ LOCAL: module symlinked from /var/module (read-only host source)."
    ;;

  packagist)
    echo "▶ Switching to PACKAGIST mode…"
    rewrite_composer packagist
    remove_module_mount
    rm -rf "$TEST/app/code/Waffy" "$TEST/generated/code/Waffy"
    restart
    ddev composer update --no-interaction     # removes the symlink, installs from Packagist
    magento_steps
    echo "✅ PACKAGIST: waffy/module-payment $MODULE_VERSION from vendor/."
    ;;

  *)
    echo "Usage: $0 {local|packagist|status}"; exit 1 ;;
esac

echo "Now in: $(current_mode) mode."
