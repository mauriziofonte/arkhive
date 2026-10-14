#!/bin/sh
# Installs PHP >= 8.3 with the extensions ArkHive needs, inside a fresh container of a distro of
# spec §12, from the source the README names for it. POSIX sh: Alpine has no bash.
# usage (as root, in the container): sh tests/distro/setup.sh
set -eu

. /etc/os-release
case "$ID:${VERSION_ID:-}" in
    debian:12*)
        apt-get update -q
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends ca-certificates curl
        curl -fsSLo /tmp/sury-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
        dpkg -i /tmp/sury-keyring.deb
        echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ bookworm main" > /etc/apt/sources.list.d/php.list
        apt-get update -q
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends php8.4-cli php8.4-mbstring php8.4-curl
        ;;
    debian:*)
        apt-get update -q
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends php-cli php-mbstring php-curl
        ;;
    ubuntu:22.04)
        apt-get update -q
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends software-properties-common gpg-agent
        add-apt-repository -y ppa:ondrej/php
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends php8.4-cli php8.4-mbstring php8.4-curl
        ;;
    ubuntu:*)
        apt-get update -q
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends php-cli php-mbstring php-curl
        ;;
    rocky:9*)
        # PHP 8.3 from the AppStream module stream
        dnf module reset -y -q php
        dnf module enable -y -q php:8.3
        dnf install -y -q php-cli php-mbstring php-process
        ;;
    almalinux:9* | rhel:9*)
        # PHP 8.4 from Remi (needs EPEL)
        dnf install -y -q epel-release
        dnf install -y -q https://rpms.remirepo.net/enterprise/remi-release-9.rpm
        dnf module reset -y -q php
        dnf module enable -y -q php:remi-8.4
        dnf install -y -q php-cli php-mbstring php-process
        ;;
    fedora:*)
        dnf install -y -q php-cli php-mbstring php-process
        ;;
    alpine:*)
        apk add --no-cache php83 php83-phar php83-mbstring php83-posix php83-pcntl php83-curl php83-openssl \
            php83-ctype php83-tokenizer php83-iconv php83-dom php83-xml php83-fileinfo php83-zlib
        ln -sf /usr/bin/php83 /usr/bin/php
        ;;
    arch:*)
        pacman -Sy --noconfirm --needed php
        ;;
    *)
        echo "setup.sh: no PHP recipe for $ID $VERSION_ID" >&2
        exit 1
        ;;
esac
php --version
