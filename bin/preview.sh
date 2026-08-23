#!/usr/bin/env bash
# Boot a throwaway WordPress with the Cartly theme active and a seeded demo.
#   ./bin/preview.sh              # with WooCommerce
#   ./bin/preview.sh --no-woo     # theme only, offline-friendly
#   PORT=9000 ./bin/preview.sh
set -euo pipefail

THEME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-8080}"
BLUEPRINT="$THEME_DIR/bin/preview-blueprint.json"

for arg in "$@"; do
	if [ "$arg" = "--no-woo" ]; then
		BLUEPRINT="$THEME_DIR/bin/preview-blueprint-no-woo.json"
	fi
done

# Git Bash / MSYS rewrites POSIX-looking arguments: "/wordpress/..." became
# "C:\Program Files\Git\wordpress\..." and the ":" mount separator became ";".
# Disable the rewriting, convert only HOST paths ourselves, and use
# --mount-dir, which takes host and guest as two separate arguments.
IS_WIN=0
case "$(uname -s)" in
	MINGW* | MSYS* | CYGWIN*) IS_WIN=1 ;;
esac

if [ "$IS_WIN" -eq 1 ]; then
	export MSYS_NO_PATHCONV=1
	export MSYS2_ARG_CONV_EXCL='*'
	hostpath() { cygpath -w "$1"; }
else
	hostpath() { printf '%s' "$1"; }
fi

# Optional demo imagery: drop PNGs named headphones/blanket/runners/pourover/
# sweater/lamp/serum/skillet into bin/demo-img/ and the seeder attaches them.
IMG_DIR="$THEME_DIR/bin/demo-img"
mkdir -p "$IMG_DIR"

echo "Cartly theme preview"
echo "  theme     $THEME_DIR"
echo "  blueprint $(basename "$BLUEPRINT")"
echo "  port      $PORT"
[ "$IS_WIN" -eq 1 ] && echo "  platform  Windows (MSYS path conversion disabled)"
echo

ARGS=(
	server
	--port "$PORT"
	--php 8.2
	--blueprint "$(hostpath "$BLUEPRINT")"
	--mount-dir "$(hostpath "$THEME_DIR")" /wordpress/wp-content/themes/cartly
	--mount-dir "$(hostpath "$IMG_DIR")" /demo-img
)

exec npx --yes @wp-playground/cli@latest "${ARGS[@]}"
