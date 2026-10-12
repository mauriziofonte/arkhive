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
# builds/ is still tracked until the 2.0 release work removes it: restore the committed file
if git ls-files --error-unmatch builds/arkhive.phar >/dev/null 2>&1; then
    git checkout -- builds/arkhive.phar
fi
php "$dest" --version
