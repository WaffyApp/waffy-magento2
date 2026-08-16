#!/usr/bin/env bash
# Builds the manual-install / Adobe Commerce Marketplace zip from
# composer.json's version.
#
# This zip is the non-Composer install path: the merchant unpacks it into
# app/code/Waffy/Payment, so it must contain the COMPLETE module — the same
# file set Composer delivers. That equivalence is the invariant here, so the
# file list comes from git (tracked files, minus the dev-only paths that
# .gitattributes marks export-ignore) instead of a hand-written list.
#
# It used to be a hand-written list: composer.json registration.php README.md
# Block Console Controller Model etc view Sdk. When Cron/ and Observer/ were
# added nobody updated it, so the zip shipped etc/crontab.xml and events.xml
# pointing at four classes that weren't in the archive — token refresh and the
# login/config-save observers fatalled on zip installs while Composer installs
# were fine. Deriving the list removes that whole failure mode.
set -euo pipefail
cd "$(dirname "$0")"

VERSION=$(php -r 'echo json_decode(file_get_contents("composer.json"))->version;')
NAME="waffy-module-payment-${VERSION}.zip"

# Dev-only paths. Keep in sync with .gitattributes export-ignore, which does
# the same job for the Composer/Packagist zipball.
EXCLUDE='^(dev/|dist/|magento-test/|\.github/|\.vscode/|\.idea/|\.gitignore|\.gitattributes|build-package\.sh|DEMO-DEPLOY\.md)'

mkdir -p dist
rm -f "dist/${NAME}"

LIST="$(mktemp)"
trap 'rm -f "$LIST"' EXIT
git ls-files | grep -vE "$EXCLUDE" | grep -v '\.DS_Store$' > "$LIST"

zip -qX "dist/${NAME}" -@ < "$LIST"

# Guard the failure mode above: every class wired up in etc/*.xml must be in
# the archive. Cheap, and it fails the build instead of the merchant's store.
MISSING=()
while read -r CLASS; do
  [ -n "$CLASS" ] || continue
  REL="$(printf '%s' "$CLASS" | sed -e 's#^Waffy.Payment.##' -e 's#\\#/#g').php"
  grep -qxF "$REL" "$LIST" || MISSING+=("$CLASS")
done < <(grep -rhoE 'Waffy\\Payment\\[A-Za-z\\]+' etc/ | sort -u)

if [ "${#MISSING[@]}" -gt 0 ]; then
  echo "ERROR: classes referenced in etc/ are missing from the zip:" >&2
  printf '  %s\n' "${MISSING[@]}" >&2
  exit 1
fi

# Local convenience only. CI runners have no ~/Downloads, and `set -e` would
# abort the build on the failed copy.
if [ -d "$HOME/Downloads" ]; then
  cp "dist/${NAME}" "$HOME/Downloads/"
  echo "Built dist/${NAME} (copied to ~/Downloads/${NAME})"
else
  echo "Built dist/${NAME}"
fi

unzip -l "dist/${NAME}" | tail -1
