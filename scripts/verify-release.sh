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
tar -tzf "$tarball" | sed -e 's#^\./##' -e '/^\.\{0,1\}\/\{0,1\}$/d' > "$listing"

# Names that are legitimate top-level entries of the app (or of a bad build).
# A single shared first path component outside this list is a wrapper directory
# (for example release-x/) and is stripped before any check.
known_roots="app bootstrap config database docker docs public resources routes scripts storage tests vendor node_modules private .superpowers .github"
roots="$(cut -d/ -f1 "$listing" | sort -u)"
if [ "$(printf '%s\n' "$roots" | grep -c .)" -eq 1 ] \
    && grep -q -F -e "$roots/" "$listing" \
    && ! printf '%s\n' $known_roots | grep -q -x -F -e "$roots"; then
    echo "note: stripping wrapper directory $roots/"
    sed -i.bak -e "s#^$roots/##" -e '/^$/d' "$listing" && rm -f "$listing.bak"
fi

status=0

for forbidden in tests/ docker/ node_modules/ private/ .superpowers/ vendor/pestphp/ vendor/larastan/ vendor/vimeo/ vendor/phpstan/phpstan/ vendor/psalm/; do
    if awk -v p="$forbidden" 'index($0, p) == 1 { found = 1 } END { exit !found }' "$listing"; then
        echo "FAIL: $forbidden must not be in the release" >&2
        status=1
    fi
done

# Exact top-level files: .env itself (not .env.example), AGENTS.md, CLAUDE.md and the e2e/test config.
for forbidden in .env .env.e2e AGENTS.md CLAUDE.md playwright.config.ts vitest.config.ts; do
    if grep -q -x -F -e "$forbidden" "$listing"; then
        echo "FAIL: $forbidden must not be in the release" >&2
        status=1
    fi
done

for required in vendor/autoload.php public/build/manifest.json scripts/root-artisan; do
    if ! grep -q -x -F "$required" "$listing"; then
        echo "FAIL: $required is missing from the release" >&2
        status=1
    fi
done

if [ "$status" -eq 0 ]; then
    echo "OK: $tarball"
fi
exit "$status"
