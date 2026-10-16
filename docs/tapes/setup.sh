#!/usr/bin/env bash
# Prepares the host the README demos record on (docs/tapes/*.tape): a site to back up and a
# configuration with a local repository. Throw-away hosts only (the Demos workflow runner): it
# replaces <root>/www, <root>/backups and the user's arkhive configuration directory.
#
# usage: docs/tapes/setup.sh fresh     the site and the configuration, no repository yet
#        docs/tapes/setup.sh history   the same, then a repository with two backups (the second
#                                      after a change), and a file deleted since the last one
# ARKHIVE_DEMO_ROOT (default /srv) holds www/ and backups/; arkhive and restic must be in PATH.
set -euo pipefail

mode="${1-}"
case "$mode" in
    fresh | history) ;;
    *) echo "usage: setup.sh fresh|history" >&2; exit 2 ;;
esac
root="${ARKHIVE_DEMO_ROOT:-/srv}"
config_dir="${XDG_CONFIG_HOME:-$HOME/.config}/arkhive"
site="$root/www/shop"

rm -rf "$root/www" "$root/backups" "$config_dir"
mkdir -p "$site/public/assets" "$site/storage/uploads" "$site/storage/cache" "$site/storage/logs" "$root/backups"
mkdir -p -m 0700 "$config_dir"

printf '<?php\nrequire __DIR__ . "/../bootstrap.php";\n' > "$site/public/index.php"
printf '<?php\nreturn ["name" => "shop", "url" => "https://shop.example.com"];\n' > "$site/bootstrap.php"
printf 'body { font-family: sans-serif; }\n' > "$site/public/assets/app.css"
for i in 1 2 3 4 5 6; do
    head -c $((1536 * 1024)) /dev/urandom > "$site/storage/uploads/product-$i.jpg"
done
head -c $((4096 * 1024)) /dev/urandom > "$site/storage/cache/views.bin"
printf 'GET / 200\n' > "$site/storage/logs/access.log"

cat > "$config_dir/config" <<CONFIG
BACKUP_PATHS="$root/www"
EXCLUSION_PATTERNS="*/storage/cache/*,*.log"
RESTIC_REPOSITORY=$root/backups/arkhive
RESTIC_PASSWORD_FILE=$config_dir/restic-password
RESTIC_HOST=web01.example.com
CONFIG
chmod 0600 "$config_dir/config"

if [ "$mode" = fresh ]; then
    exit 0
fi

arkhive init > /dev/null 2>&1
arkhive backup > /dev/null 2>&1
printf 'body { font-family: sans-serif; color: #222; }\n' > "$site/public/assets/app.css"
head -c $((1536 * 1024)) /dev/urandom > "$site/storage/uploads/product-7.jpg"
arkhive backup > /dev/null 2>&1
rm "$site/storage/uploads/product-3.jpg"
