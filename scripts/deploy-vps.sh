#!/usr/bin/env bash
# ==============================================================================
#  gickers.sh — Production Deployment Script
#  Delegates directly to the unified CLI installer: `bin/console install`
# ==============================================================================
set -euo pipefail

if [ -n "${BASH_SOURCE[0]:-}" ]; then
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
else
    SCRIPT_DIR="$PWD"
fi
cd "$SCRIPT_DIR"

if [ -f "$SCRIPT_DIR/bin/console" ]; then
    exec php "$SCRIPT_DIR/bin/console" install "$@"
elif [ -f "$SCRIPT_DIR/bin/soft" ]; then
    exec "$SCRIPT_DIR/bin/soft" install "$@"
else
    echo "[ERROR] bin/console introuvable dans $SCRIPT_DIR" >&2
    exit 1
fi
