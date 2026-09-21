#!/usr/bin/env bash
# Build a production-like plugin tree (Composer --no-dev) and verify autoload.
# This is not a release ZIP and must not be published as an RC identity.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${1:-}"

if [[ -z "$DEST" ]]; then
	echo "Usage: $0 <destination-dir>" >&2
	exit 1
fi

rm -rf "$DEST"
mkdir -p "$DEST"

copy_items=(
	cetech-woocommerce-delivery-engine.php
	uninstall.php
	src
	database
	composer.json
	languages
	assets
	readme.txt
)

for item in "${copy_items[@]}"; do
	if [[ -e "$ROOT/$item" ]]; then
		cp -a "$ROOT/$item" "$DEST/"
	fi
done

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --working-dir="$DEST"
php "$ROOT/scripts/verify-production-package-autoload.php" "$DEST"
bash "$ROOT/scripts/ci-lint-php.sh" "$DEST"
