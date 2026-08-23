#!/usr/bin/env bash
#
# Keep the theme's design tokens in step with the platform repo.
#
# design/tokens.json in the platform repo is the source of truth. Its compiled
# form lives in two places that MUST stay identical:
#
#   platform:  frontend/src/tokens.css
#   theme:     assets/src/tokens.css   (this repo)
#
# Two front ends drifting apart is the failure mode this script exists to stop.
#
#   ./bin/sync-tokens.sh           pull the platform's tokens into the theme
#   ./bin/sync-tokens.sh --check   exit 1 if they differ (use in CI)
#
# Works in either layout:
#   * monorepo  — finds ../../frontend/src/tokens.css on disk
#   * standalone— fetches from the platform repo over HTTPS
#
set -euo pipefail

THEME_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCAL="$THEME_DIR/assets/src/tokens.css"

PLATFORM_REPO="${CARTLY_PLATFORM_REPO:-K-Anjan25/ecommerce-microservices}"
PLATFORM_REF="${CARTLY_PLATFORM_REF:-main}"
REMOTE_URL="https://raw.githubusercontent.com/${PLATFORM_REPO}/${PLATFORM_REF}/frontend/src/tokens.css"

CHECK_ONLY=0
[ "${1:-}" = "--check" ] && CHECK_ONLY=1

SOURCE=""
MONOREPO="$THEME_DIR/../../frontend/src/tokens.css"

if [ -f "$MONOREPO" ]; then
	SOURCE="$MONOREPO"
	echo "source: $MONOREPO (monorepo layout)"
else
	SOURCE="$(mktemp)"
	echo "source: $REMOTE_URL"
	if ! curl -fsSL "$REMOTE_URL" -o "$SOURCE"; then
		echo "error: could not fetch the platform tokens." >&2
		echo "       set CARTLY_PLATFORM_REPO / CARTLY_PLATFORM_REF, or run inside the monorepo." >&2
		exit 2
	fi
fi

if diff -q "$SOURCE" "$LOCAL" >/dev/null 2>&1; then
	echo "✓ tokens are in sync"
	exit 0
fi

if [ "$CHECK_ONLY" -eq 1 ]; then
	echo "✗ design tokens have DRIFTED from the platform repo:" >&2
	diff -u "$LOCAL" "$SOURCE" | head -40 >&2 || true
	echo >&2
	echo "  run ./bin/sync-tokens.sh and rebuild the stylesheet." >&2
	exit 1
fi

cp "$SOURCE" "$LOCAL"
echo "✓ tokens updated — now rebuild: npm run build"
