#!/usr/bin/env bash
# Build the EmailSquire release tarball from the committed tree (HEAD).
# Usage: scripts/build-release.sh [out-dir=dist]
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out_dir="${1:-dist}"
case "$out_dir" in
    /*) ;;
    *) out_dir="$PWD/$out_dir" ;;
esac

cd "$repo_root"
version="$(git describe --always --dirty)"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

git archive HEAD | tar -x -C "$work"

(
    cd "$work"
    composer install --no-dev --classmap-authoritative --no-interaction
    npm ci
    npm run build
    rm -rf node_modules tests docs docker .github compose.yml phpunit.xml phpstan.neon psalm.xml pint.json AGENTS.md CLAUDE.md
)

mkdir -p "$out_dir"
tarball="$out_dir/emailsquire-${version}.tar.gz"
tar -czf "$tarball" -C "$work" .
echo "Wrote $tarball"
