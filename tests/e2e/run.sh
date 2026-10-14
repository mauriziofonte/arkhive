#!/usr/bin/env bash
# End-to-end suite (spec §13.2): throw-away services, a generated SSH key and repository
# password, the built PHAR, and the PHPUnit "e2e" test suite.
#
# Environment:
#   E2E_PHAR       use this PHAR instead of building one
#   E2E_RESTIC     use this restic binary instead of installing the pinned release
#   E2E_EXTERNAL=1 do not start docker compose: the services are already running and
#                  described by the E2E_* variables below (used for local runs without Docker)
#   E2E_SSH_KEY_SOURCE  with E2E_EXTERNAL=1: an SSH key the external SFTP server already trusts
#   E2E_FILTER     PHPUnit --filter expression
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
e2e="$root/tests/e2e"
work="$e2e/work"
compose=(docker compose -f "$e2e/docker-compose.yml")

log() { printf '[e2e] %s\n' "$*"; }

cleanup() {
    status=$?
    if [ "${E2E_EXTERNAL:-0}" != "1" ] && [ "${E2E_KEEP:-0}" != "1" ]; then
        log "teardown"
        "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    fi
    exit "$status"
}
trap cleanup EXIT

rm -rf "$work"
mkdir -p "$work/ssh" "$work/releases" "$work/bin"
chmod 0700 "$work/ssh"

log "generating a throw-away SSH key and repository password"
if [ -n "${E2E_SSH_KEY_SOURCE:-}" ]; then
    # external services already trust this key
    install -m 0600 "$E2E_SSH_KEY_SOURCE" "$work/ssh/id_ed25519"
    install -m 0644 "$E2E_SSH_KEY_SOURCE.pub" "$work/ssh/id_ed25519.pub"
else
    ssh-keygen -q -t ed25519 -N '' -C arkhive-e2e -f "$work/ssh/id_ed25519"
fi
chmod 0644 "$work/ssh/id_ed25519.pub"
head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$work/repo-password"
chmod 0600 "$work/repo-password"

if [ -n "${E2E_RESTIC:-}" ]; then
    install -m 0755 "$E2E_RESTIC" "$work/bin/restic"
else
    log "installing the pinned restic release"
    "$root/tests/bin/install-restic.sh" "$work/bin/restic"
fi

if [ -n "${E2E_PHAR:-}" ]; then
    cp "$E2E_PHAR" "$work/arkhive.phar"
else
    log "building the PHAR"
    "$root/tests/bin/build-phar.sh" "$work/arkhive.phar"
fi
chmod 0755 "$work/arkhive.phar"

export E2E_WORK="$work"
export E2E_PHAR="$work/arkhive.phar"
export E2E_RESTIC="$work/bin/restic"
export E2E_SSH_KEY="$work/ssh/id_ed25519"
export E2E_PASSWORD_FILE="$work/repo-password"
export E2E_RELEASES_DIR="$work/releases"
export E2E_SFTP_HOST="${E2E_SFTP_HOST:-127.0.0.1}"
export E2E_SFTP_PORT="${E2E_SFTP_PORT:-2222}"
export E2E_SFTP_USER="${E2E_SFTP_USER:-e2e}"
export E2E_SFTP_PATH="${E2E_SFTP_PATH:-/repo}"
export E2E_MYSQL_HOST="${E2E_MYSQL_HOST:-127.0.0.1}"
export E2E_MYSQL_PORT="${E2E_MYSQL_PORT:-13306}"
export E2E_MYSQL_USER="${E2E_MYSQL_USER:-root}"
export E2E_MYSQL_PASSWORD="${E2E_MYSQL_PASSWORD:-e2e-root-placeholder}"
export E2E_PGSQL_HOST="${E2E_PGSQL_HOST:-127.0.0.1}"
export E2E_PGSQL_PORT="${E2E_PGSQL_PORT:-15432}"
export E2E_PGSQL_USER="${E2E_PGSQL_USER:-e2e}"
export E2E_PGSQL_PASSWORD="${E2E_PGSQL_PASSWORD:-e2e-pg-placeholder}"
export E2E_RELEASES_URL="${E2E_RELEASES_URL:-http://127.0.0.1:18080}"

if [ "${E2E_EXTERNAL:-0}" != "1" ]; then
    export E2E_SFTP_PAUSE="${compose[*]} pause sftp"
    export E2E_SFTP_RESUME="${compose[*]} unpause sftp"
    export E2E_MYSQL_STOP="${compose[*]} stop mariadb"
    export E2E_MYSQL_START="${compose[*]} start mariadb"
    log "starting services"
    "${compose[@]}" up -d --wait --wait-timeout 180 mariadb postgres
    "${compose[@]}" up -d sftp releases
fi

log "waiting for the SFTP server"
for _ in $(seq 1 60); do
    if ssh -q -i "$E2E_SSH_KEY" -p "$E2E_SFTP_PORT" -o BatchMode=yes -o StrictHostKeyChecking=no \
        -o UserKnownHostsFile=/dev/null "$E2E_SFTP_USER@$E2E_SFTP_HOST" -s sftp </dev/null >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

args=(--testsuite e2e --log-junit "$work/junit.xml" --testdox-text "$work/e2e-summary.txt")
if [ -n "${E2E_FILTER:-}" ]; then
    args+=(--filter "$E2E_FILTER")
fi

log "running the e2e suite"
set +e
"$root/vendor/bin/phpunit" "${args[@]}"
status=$?
set -e
log "summary written to $work/e2e-summary.txt"
exit "$status"
