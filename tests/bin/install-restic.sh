#!/usr/bin/env bash
# Installs the pinned official static restic release, verified against the release SHA256SUMS.
# usage: tests/bin/install-restic.sh <destination binary path> [version]
# RESTIC_ARCH=amd64|arm64 picks the binary for another architecture (default: this machine's).
set -euo pipefail

dest="${1:?usage: install-restic.sh <destination> [version]}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
version="${2:-$(tr -d '[:space:]' < "$here/../restic-version")}"

case "${RESTIC_ARCH:-$(uname -m)}" in
    x86_64 | amd64) arch=amd64 ;;
    aarch64 | arm64) arch=arm64 ;;
    *) echo "unsupported architecture: ${RESTIC_ARCH:-$(uname -m)}" >&2; exit 1 ;;
esac

asset="restic_${version}_linux_${arch}.bz2"
base="https://github.com/restic/restic/releases/download/v${version}"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

curl -fsSL --retry 3 -o "$tmp/$asset" "$base/$asset"
curl -fsSL --retry 3 -o "$tmp/SHA256SUMS" "$base/SHA256SUMS"

expected="$(awk -v a="$asset" '$2 == a || $2 == "*" a { print $1 }' "$tmp/SHA256SUMS")"
if [ -z "$expected" ]; then
    echo "$asset is not listed in SHA256SUMS" >&2
    exit 1
fi
actual="$(sha256sum "$tmp/$asset" | awk '{ print $1 }')"
if [ "$expected" != "$actual" ]; then
    echo "checksum mismatch for $asset: expected $expected, got $actual" >&2
    exit 1
fi

bunzip2 -c "$tmp/$asset" > "$tmp/restic"
install -m 0755 "$tmp/restic" "$dest"
if [ -z "${RESTIC_ARCH:-}" ]; then
    "$dest" version
fi
