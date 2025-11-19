#!/bin/bash
set -euo pipefail
IFS=$'\n\t'  # safer word splitting

# === CONFIGURATION ===
SCRIPT_NAME="$(basename "$0")"
TMP_DIR="$(mktemp -d)"
THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# === CLEANUP ON EXIT ===
cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

# === CHECK REQUIREMENTS ===
require() {
    command -v "$1" >/dev/null 2>&1 || {
        echo "⛔ Required command '$1' not found. Aborting." >&2
        exit 1
    }
}

# === RELATIVE PATH RESOLUTION ===
relative() {
    local input="$1"
    if [[ "$input" = /* ]]; then
        echo "$input"
    else
        echo "$(cd "$THIS_DIR" && cd "$(dirname "$input")" && pwd)/$(basename "$input")"
    fi
}

# require binaries
require php
require md5sum

# === LOGGING ===
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

# === MAIN LOGIC ===
main() {
    log "🚀 Running $SCRIPT_NAME"
    
    version=""
    while IFS= read -r line; do
        if [[ $line =~ \'version\'[[:space:]]*=\>[[:space:]]*\'([^\']+)\' ]]; then
            version="${BASH_REMATCH[1]}"
            break
        fi
    done < "$(relative 'config/app.php')"

    if [[ -z $version ]]; then
        log "⛔ Failed to extract version from config/app.php"
        exit 1
    fi

    log "📦 ArkHive version: $version"
    
    # build the archive
    php arkhive app:build --build-version "$version" --no-interaction arkhive.phar

    # print version string to builds/version.txt
    echo "$version" > "$(relative 'builds/version.txt')"

    # print sha256sum string to builds/arkhive.sha256sum
    sha256sum "$(relative 'builds/arkhive.phar')" | awk '{print $1}' | tr -d '\n' > "$(relative 'builds/arkhive.sha256sum')"
}

main "$@"