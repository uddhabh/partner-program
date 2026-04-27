#!/usr/bin/env bash
# Build a production-ready zip for the Partner Program plugin.
#
# Usage:
#   bin/build-release.sh                # uses version from main plugin file
#   bin/build-release.sh 1.2.3          # overrides version (also rewrites in-file)
#
# Outputs:
#   dist/partner-program.zip            # canonical name (always overwritten)
#   dist/partner-program-<version>.zip  # versioned copy
#
# The zip's TOP-LEVEL folder is always `partner-program/` (no version) so it can
# be installed as a drop-in via the WP plugin uploader and updated in place.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

PLUGIN_SLUG="partner-program"
MAIN_FILE="${PLUGIN_SLUG}.php"

if [[ ! -f "$MAIN_FILE" ]]; then
  echo "Cannot find $MAIN_FILE in $ROOT_DIR" >&2
  exit 1
fi

# Resolve version
if [[ "${1:-}" != "" ]]; then
  VERSION="$1"
  # Sync version in headers + define()
  sed -i.bak -E "s/^( \* Version:[[:space:]]+).*/\1${VERSION}/" "$MAIN_FILE"
  sed -i.bak -E "s/(define\([[:space:]]*'PARTNER_PROGRAM_VERSION'[[:space:]]*,[[:space:]]*')[^']+(' \));?/\1${VERSION}\2/" "$MAIN_FILE"
  rm -f "${MAIN_FILE}.bak"
else
  VERSION="$(grep -E "^[[:space:]]*\*[[:space:]]*Version:" "$MAIN_FILE" | head -1 | awk '{print $3}')"
fi

if [[ -z "$VERSION" ]]; then
  echo "Could not determine plugin version" >&2
  exit 1
fi

echo "Building ${PLUGIN_SLUG} v${VERSION}..."

DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT

mkdir -p "$DIST_DIR"
mkdir -p "$STAGE_DIR/$PLUGIN_SLUG"

# Use rsync to copy plugin files, excluding development artifacts.
rsync -a \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.gitignore' \
  --exclude='.gitattributes' \
  --exclude='.DS_Store' \
  --exclude='dist/' \
  --exclude='bin/' \
  --exclude='node_modules/' \
  --exclude='vendor/' \
  --exclude='tests/' \
  --exclude='.phpunit.result.cache' \
  --exclude='phpunit.xml*' \
  --exclude='phpcs.xml*' \
  --exclude='*.zip' \
  --exclude='*.log' \
  --exclude='.idea/' \
  --exclude='.vscode/' \
  --exclude='composer.lock' \
  ./ "$STAGE_DIR/$PLUGIN_SLUG/"

# Build the canonical (no-version) zip first.
CANON_ZIP="${DIST_DIR}/${PLUGIN_SLUG}.zip"
rm -f "$CANON_ZIP"
( cd "$STAGE_DIR" && zip -qr "$CANON_ZIP" "$PLUGIN_SLUG" )

# Versioned copy for archival / GitHub release asset.
VER_ZIP="${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
cp "$CANON_ZIP" "$VER_ZIP"

echo
echo "Created:"
echo "  $CANON_ZIP"
echo "  $VER_ZIP"
echo
echo "Top-level folder inside zip:"
unzip -l "$CANON_ZIP" | awk 'NR==4 {print "  " $4}'
