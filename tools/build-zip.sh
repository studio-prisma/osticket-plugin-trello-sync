#!/usr/bin/env bash
# Builds the install package: dist/trello-sync-<version>.zip
#
#   ./tools/build-zip.sh            # version read from plugin.php
#   ./tools/build-zip.sh 0.5.9      # explicit version (must match plugin.php)
#
# The archive contains exactly the production files, nested in a `trello-sync/`
# folder so it can be unzipped straight into `include/plugins/`.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MANIFEST_VERSION="$(grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" plugin.php | head -1 | sed -E "s/.*'([^']+)'\$/\1/")"
VERSION="${1:-$MANIFEST_VERSION}"

if [ -z "$MANIFEST_VERSION" ]; then
  echo "Could not read 'version' from plugin.php." >&2
  exit 1
fi

if [ "$VERSION" != "$MANIFEST_VERSION" ]; then
  echo "Version mismatch: requested '$VERSION', plugin.php declares '$MANIFEST_VERSION'." >&2
  exit 1
fi

# TrelloSyncService::VERSION appears in the plugin's error messages, which is
# how a stale OPcache gets spotted. It must match the manifest.
SERVICE_VERSION="$(grep -oE "const VERSION[[:space:]]*=[[:space:]]*'[^']+'" class.trello_sync_service.php | head -1 | sed -E "s/.*'([^']+)'\$/\1/")"
if [ "$SERVICE_VERSION" != "$MANIFEST_VERSION" ]; then
  echo "Version mismatch: TrelloSyncService::VERSION is '$SERVICE_VERSION', plugin.php declares '$MANIFEST_VERSION'." >&2
  exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

PKG="$STAGE/trello-sync"
mkdir -p "$PKG/tools"

# Production files. Everything the plugin needs at runtime, nothing else:
# no tests, no docs, no .github, no CHANGELOG.
cp plugin.php config.php trello_sync.php api.trello_sync.php "$PKG/"
cp class.trello_*.php "$PKG/"
cp LICENSE "$PKG/"

# CLI tools run on the server (diagnosis, auth probe, reconciliation), so they
# ship. They are not reachable over HTTP — osTicket only routes api.*.php.
cp tools/diagnose.php tools/probe-auth.php tools/reconcile.php "$PKG/tools/"

# Refuse to ship anything that looks like a real credential. The plugin reads
# every secret from the osTicket config at runtime; a literal here is a bug.
if grep -rniE "(api[_-]?key|token|secret|password)[[:space:]]*=[[:space:]]*['\"][A-Za-z0-9_-]{16,}" "$PKG" ; then
  echo "Refusing to build: literal credential found in staged files." >&2
  exit 1
fi

# Same gate as CI: no identity leaks in a public artifact.
if grep -rniE "pfitzner|jonas|propstack|scout24" "$PKG" ; then
  echo "Refusing to build: identity leak in staged files (CONVENTIONS §1)." >&2
  exit 1
fi

mkdir -p dist
rm -f "dist/trello-sync-${VERSION}.zip"

# zip(1) fehlt in Git Bash unter Windows. Python liegt ohnehin vor
# (tools/verify_structure.py), deshalb der Fallback statt einer harten
# Abhaengigkeit — sonst laesst sich das Paket nur in der CI bauen.
if command -v zip >/dev/null 2>&1; then
  ( cd "$STAGE" && zip -qr "trello-sync-${VERSION}.zip" trello-sync )
  mv "$STAGE/trello-sync-${VERSION}.zip" "dist/"
elif command -v python3 >/dev/null 2>&1 || command -v python >/dev/null 2>&1; then
  PY="$(command -v python3 || command -v python)"
  "$PY" - "$STAGE" "$ROOT/dist/trello-sync-${VERSION}.zip" <<'PYEOF'
import os, sys, zipfile
stage, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk(os.path.join(stage, "trello-sync")):
        for f in sorted(files):
            p = os.path.join(root, f)
            z.write(p, os.path.relpath(p, stage).replace(os.sep, "/"))
PYEOF
else
  echo "Neither zip nor python available — cannot build the archive." >&2
  exit 1
fi

echo "Built dist/trello-sync-${VERSION}.zip"
if command -v unzip >/dev/null 2>&1; then
  unzip -l "dist/trello-sync-${VERSION}.zip"
else
  PY="$(command -v python3 || command -v python)"
  "$PY" -c "import zipfile,sys; [print('  '+n) for n in sorted(zipfile.ZipFile(sys.argv[1]).namelist())]" \
    "dist/trello-sync-${VERSION}.zip"
fi
