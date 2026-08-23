#!/usr/bin/env bash
# Boot a throwaway WordPress with the Cartly theme active and a seeded demo.
#
# Uses WordPress Playground (PHP + WordPress compiled to WASM), so it needs
# Node but no PHP, no MySQL and no web server.
#
#   ./bin/preview.sh                 # with WooCommerce (needs network)
#   ./bin/preview.sh --no-woo        # theme only, offline-friendly
#   PORT=9000 ./bin/preview.sh       # pick a port
#
# Then open http://127.0.0.1:$PORT — you are logged in as admin.
set -euo pipefail

THEME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-8080}"
BLUEPRINT="$THEME_DIR/bin/preview-blueprint.json"

for arg in "$@"; do
	if [ "$arg" = "--no-woo" ]; then
		BLUEPRINT="$THEME_DIR/bin/preview-blueprint-no-woo.json"
	fi
done

# Product imagery for the demo, generated locally — no external fetches.
IMG_DIR="${TMPDIR:-/tmp}/cartly-preview-img"
mkdir -p "$IMG_DIR"

echo "Cartly theme preview"
echo "  theme     $THEME_DIR"
echo "  blueprint $(basename "$BLUEPRINT")"
echo "  port      $PORT"
echo

npx --yes @wp-playground/cli@latest server \
	--port "$PORT" \
	--php 8.2 \
	--blueprint "$BLUEPRINT" \
	--mount "$THEME_DIR:/wordpress/wp-content/themes/cartly" \
	--mount "$THEME_DIR/bin/preview-seed.php:/wordpress/preview-seed.php" \
	--mount "$IMG_DIR:/demo-img"
