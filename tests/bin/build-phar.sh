#!/usr/bin/env bash
# Builds the PHAR with box into <destination>, without leaving changes in the working tree.
# usage: tests/bin/build-phar.sh <destination> [version]
set -euo pipefail

dest="${1:?usage: build-phar.sh <destination> [version]}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
version="${2:-$(php -r 'echo (require $argv[1])["version"];' "$root/config/app.php")}"

cd "$root"
php -d phar.readonly=0 arkhive app:build --build-version "$version" --no-interaction arkhive.phar >/dev/null
mv builds/arkhive.phar "$dest"
rmdir builds 2>/dev/null || true
php "$dest" --version
