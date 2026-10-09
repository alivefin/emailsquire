#!/usr/bin/env bash
# Check that a release tarball has what it needs and nothing it must not ship.
# Usage: scripts/verify-release.sh <tarball>
set -euo pipefail

if [ "$#" -ne 1 ]; then
    echo "usage: $0 <tarball>" >&2
    exit 2
fi

tarball="$1"
listing="$(mktemp)"
trap 'rm -f "$listing"' EXIT
tar -tzf "$tarball" | sed -e 's#^\./##' > "$listing"

status=0

for forbidden in tests/ docker/ node_modules/ vendor/pestphp/ vendor/larastan/ vendor/vimeo/; do
    if awk -v p="$forbidden" 'index($0, p) == 1 { found = 1 } END { exit !found }' "$listing"; then
        echo "FAIL: $forbidden must not be in the release" >&2
        status=1
    fi
done

# .env itself, but not .env.example.
if grep -q -x -F -e .env "$listing"; then
    echo "FAIL: .env must not be in the release" >&2
    status=1
fi

for required in vendor/autoload.php public/build/manifest.json; do
    if ! grep -q -x -F "$required" "$listing"; then
        echo "FAIL: $required is missing from the release" >&2
        status=1
    fi
done

if [ "$status" -eq 0 ]; then
    echo "OK: $tarball"
fi
exit "$status"
