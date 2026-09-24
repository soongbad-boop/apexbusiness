#!/usr/bin/env bash
set -e

# Package project into a clean production zip for direct cPanel File Manager upload
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP_NAME="apex-production-deploy.zip"
OUTPUT_PATH="${ROOT_DIR}/${ZIP_NAME}"

echo "Packaging production zip: ${OUTPUT_PATH}..."

composer install --no-dev --prefer-dist --optimize-autoloader
test -f vendor/autoload.php || { echo "Composer dependencies are missing." >&2; exit 1; }

cd "${ROOT_DIR}"
rm -f "${OUTPUT_PATH}"

zip -r "${OUTPUT_PATH}" . \
  -x "*.git*" \
  -x "*.github*" \
  -x "*node_modules*" \
  -x "*.env" \
  -x "*.zip" \
  -x "*.tar.gz" \
  -x "*.vscode*" \
  -x "*.idea*" \
  -x "storage/logs/*" \
  -x "storage/rate_limits/*"

echo "✓ Successfully created production zip: ${OUTPUT_PATH}"
echo "✓ Upload this file directly to cPanel File Manager -> Extract into public_html"
