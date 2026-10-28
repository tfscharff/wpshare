#!/usr/bin/env bash
# Builds the plugin zip and publishes a GitHub release. Run after the release commit is pushed.
# Usage: bin/release.sh X.Y.Z "Release notes" [--prerelease]
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="$1"; NOTES="$2"; shift 2
grep -Eq "Version:[[:space:]]+${VERSION}([[:space:]]|$)" wpshare.php || { echo "wpshare.php header is not $VERSION" >&2; exit 1; }
grep -q "define( 'WPSHARE_VERSION', '${VERSION}' );" wpshare.php || { echo "WPSHARE_VERSION is not $VERSION" >&2; exit 1; }
grep -q -- "- \*\*${VERSION}\*\*" README.md || { echo "README.md has no changelog entry for $VERSION" >&2; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "Commit first: the working tree has changes" >&2; exit 1; }
[ "$(git rev-parse HEAD)" = "$(git rev-parse '@{u}')" ] || { echo "Push first" >&2; exit 1; }
rm -rf build && mkdir -p build/wpshare
cp wpshare.php README.md LICENSE build/wpshare/
if [ -d assets ]; then cp -r assets build/wpshare/; fi
(cd build && /c/Windows/System32/tar.exe -a -cf "wpshare-${VERSION}.zip" wpshare)
gh release create "v${VERSION}" "build/wpshare-${VERSION}.zip" --title "v${VERSION}" --notes "$NOTES" "$@"
cp "build/wpshare-${VERSION}.zip" "$HOME/Downloads/"
