#!/usr/bin/env bash
# Builds the Adobe Commerce Marketplace release zip from composer.json's
# version — only the actual module contents, never magento-test/ or dev/.
set -euo pipefail
cd "$(dirname "$0")"

VERSION=$(php -r 'echo json_decode(file_get_contents("composer.json"))->version;')
NAME="waffy-module-payment-${VERSION}.zip"

mkdir -p dist
rm -f "dist/${NAME}"

zip -rq "dist/${NAME}" \
  composer.json registration.php README.md \
  Block Console Controller Model etc view Sdk \
  -x "*.DS_Store"

cp "dist/${NAME}" ~/Downloads/

echo "Built dist/${NAME} (copied to ~/Downloads/${NAME})"
unzip -l "dist/${NAME}" | tail -1
