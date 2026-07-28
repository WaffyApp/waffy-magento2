#!/usr/bin/env bash
#
# sync-sdk.sh — Rebuild waffy-magento2/Sdk/ from the canonical SDK repo.
#
# Source of truth is ALWAYS waffy-ecom-sdk-php. Never hand-edit Sdk/ here;
# make the change in the SDK repo, commit it there, then run this script.
#
# What it does:
#   1. Locates the SDK repo (sibling ../waffy-ecom-sdk-php by default).
#   2. Mirrors every *.php under the SDK's src/ into this module's Sdk/,
#      VERBATIM (same namespace Waffy\Ecommerce\, no transforms). Files that
#      were renamed/removed upstream are pruned from Sdk/.
#   3. Regenerates Sdk/VENDORED_FROM.md stamped with the SDK's commit + tag.
#
# Usage:
#   dev/sync-sdk.sh                 # use ../waffy-ecom-sdk-php
#   dev/sync-sdk.sh /path/to/sdk    # explicit SDK repo path
#   WAFFY_SDK_PATH=/path dev/sync-sdk.sh
#
set -euo pipefail

# ── Resolve paths (works from any CWD) ───────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_ROOT="$(dirname "$SCRIPT_DIR")"                    # waffy-magento2/
DEST="$MODULE_ROOT/Sdk"

SDK_REPO="${1:-${WAFFY_SDK_PATH:-$(dirname "$MODULE_ROOT")/waffy-ecom-sdk-php}}"
SDK_SRC="$SDK_REPO/src"

# ── Preflight ────────────────────────────────────────────────────────────────
if [[ ! -d "$SDK_SRC" ]]; then
  echo "ERROR: SDK src/ not found at: $SDK_SRC" >&2
  echo "       Pass the SDK repo path as an argument or set WAFFY_SDK_PATH." >&2
  exit 1
fi

# Provenance: commit hash + human-readable tag description. Mark -dirty if the
# SDK working tree has uncommitted changes, so a stamped hash never lies about
# what was copied.
if git -C "$SDK_REPO" rev-parse --git-dir >/dev/null 2>&1; then
  SDK_HASH="$(git -C "$SDK_REPO" rev-parse --short HEAD)"
  SDK_DESC="$(git -C "$SDK_REPO" describe --tags --always 2>/dev/null || echo "$SDK_HASH")"
  if [[ -n "$(git -C "$SDK_REPO" status --porcelain)" ]]; then
    SDK_HASH="${SDK_HASH}-dirty"
    echo "WARNING: SDK working tree is dirty — copying uncommitted state." >&2
    echo "         Commit in the SDK repo first for a clean provenance stamp." >&2
  fi
else
  SDK_HASH="unknown (not a git repo)"
  SDK_DESC="$SDK_HASH"
fi

TODAY="$(date +%Y-%m-%d)"

echo "Syncing SDK → module"
echo "  from : $SDK_SRC   ($SDK_DESC)"
echo "  into : $DEST"

# ── Mirror *.php verbatim, prune stale files, drop empty dirs ────────────────
# --filter rules: recurse into dirs, copy .php, exclude everything else.
# --delete removes .php present in Sdk/ but no longer in src/ (handles renames).
# VENDORED_FROM.md is excluded on the sender, so --delete leaves it alone; we
# regenerate it below regardless.
rsync -a --delete --prune-empty-dirs \
  --filter='+ */' \
  --filter='+ *.php' \
  --filter='- *' \
  "$SDK_SRC/" "$DEST/"

# ── Regenerate provenance note ───────────────────────────────────────────────
cat > "$DEST/VENDORED_FROM.md" <<EOF
# Vendored copy — DO NOT EDIT BY HAND

This directory is a **verbatim, generated mirror** of the \`src/\` tree in the
canonical PHP SDK. It is bundled into this module instead of pulled in as a
Composer dependency (\`waffy-ecom-sdk-php\` isn't published on Packagist).

**Source of truth is the SDK repo. Never edit files under \`Sdk/\` directly.**
Make the change in \`waffy-ecom-sdk-php\`, commit it there, then run
\`dev/sync-sdk.sh\` to regenerate this folder.

- Source: https://github.com/WaffyApp/waffy-ecom-sdk-php
- Synced from: \`$SDK_DESC\` (commit \`$SDK_HASH\`)
- Synced on: $TODAY
- Namespace: \`Waffy\\Ecommerce\\\` (unchanged — mapped via this module's
  \`composer.json\` psr-4 autoload, so all \`use\` statements work unmodified)
- Transform: none. This is a byte-for-byte copy of the SDK \`src/\`. If Magento
  ever requires a source-level change (e.g. dropping \`final\` for the
  Marketplace Code Sniffer), make it in the SDK so the mirror stays verbatim.

Regenerate with: \`dev/sync-sdk.sh\`
EOF

echo "  stamped: Sdk/VENDORED_FROM.md ($SDK_DESC)"
echo "Done. Review with 'git -C \"$MODULE_ROOT\" status' and commit."
