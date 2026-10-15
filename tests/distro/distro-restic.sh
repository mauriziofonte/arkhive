#!/bin/sh
# Installs the distribution's own restic package and moves the binary to <destination>, out of
# PATH and the standard directories (check.php's missing-restic case needs restic absent there).
# usage (as root, in the container): sh tests/distro/distro-restic.sh <destination>
set -eu

dest="${1:?usage: distro-restic.sh <destination>}"
apt-get update -q
DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends restic
binary="$(command -v restic)"
mkdir -p "$(dirname "$dest")"
mv "$binary" "$dest"
"$dest" version
