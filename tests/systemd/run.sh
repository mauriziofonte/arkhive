#!/usr/bin/env bash
# Runs ArkHive's own units under a real systemd (a CI runner, PID 1 systemd), as root:
# schedule --install, a backup unit that succeeds and one that fails, the unit status ArkHive
# reports (sd_notify), the journal fields and MESSAGE_ID events, OnFailure= and the catalog.
# Throw-away hosts only: it writes /etc/arkhive, /srv and /etc/systemd/system.
set -euo pipefail

phar="${1:?usage: run.sh <arkhive.phar>}"
[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 2; }
[ -d /run/systemd/system ] || { echo "systemd is not running" >&2; exit 2; }

failures=0
pass() { echo "ok    $1"; }
fail() { echo "FAIL  $1" >&2; failures=$((failures + 1)); }
expect() { # expect <description> <expected> <actual>
    if [ "$2" = "$3" ]; then pass "$1"; else fail "$1: expected '$2', got '$3'"; fi
}
property() { systemctl show --property="$2" --value "$1"; }
# the last event of a MESSAGE_ID, as JSON
event() { journalctl --no-pager --output=json --lines=1 MESSAGE_ID="$1"; }
field() { jq -r ".$1 // empty"; }

install -m 0755 "$phar" /usr/local/bin/arkhive
restic="$(command -v restic)"
mkdir -p /etc/arkhive /srv/data
printf 'one\n' > /srv/data/a.txt
printf 'two\n' > /srv/data/b.txt
cat > /etc/arkhive/config <<CONFIG
BACKUP_PATHS=/srv/data
RESTIC_REPOSITORY=/srv/restic-repo
RESTIC_PASSWORD_FILE=/etc/arkhive/password
RESTIC_BINARY=${restic}
RESTIC_CHECK_SUBSET=1/10
CONFIG
chmod 600 /etc/arkhive/config

arkhive init --config /etc/arkhive/config
arkhive schedule --install --config /etc/arkhive/config
for job in backup maintain drill; do
    expect "arkhive-${job}.timer is enabled" enabled "$(systemctl is-enabled "arkhive-${job}.timer")"
    expect "arkhive-${job}.timer is active" active "$(systemctl is-active "arkhive-${job}.timer")"
done
expect "schedule --status before any run" true "$(arkhive schedule --status --json | jq -r .healthy)"
units_before="$(sha256sum /etc/systemd/system/arkhive-*)"
second=0
reinstall="$(arkhive schedule --install --config /etc/arkhive/config 2>&1)" || second=$?
expect 'a second install succeeds' 0 "$second"
case "$reinstall" in
    *'Unchanged: /etc/systemd/system/arkhive-backup.service'*) pass 'a second install reports the units unchanged' ;;
    *) fail "a second install did not report the units unchanged: ${reinstall}" ;;
esac
expect 'a second install leaves the unit files as they were' "$units_before" "$(sha256sum /etc/systemd/system/arkhive-*)"

# a backup under the hardened unit
if systemctl start arkhive-backup.service; then pass 'the backup unit succeeds'; else fail 'the backup unit failed'; fi
expect 'Result' success "$(property arkhive-backup.service Result)"
expect 'ExecMainStatus' 0 "$(property arkhive-backup.service ExecMainStatus)"
expect 'StatusText (sd_notify)' 'arkhive backup succeeded' "$(property arkhive-backup.service StatusText)"
[ -d /var/cache/arkhive/restic ] && pass 'restic cache in CacheDirectory' || fail 'no restic cache in /var/cache/arkhive/restic'
[ -f /var/lib/arkhive/backup.json ] && pass 'run recorded in StateDirectory' || fail 'no run record in /var/lib/arkhive'
succeeded="$(event 763619ea04f7dbbf790ad973e212db7a)"
expect 'backup succeeded event: unit' arkhive-backup.service "$(field _SYSTEMD_UNIT <<<"$succeeded")"
expect 'backup succeeded event: exit status' 0 "$(field ARKHIVE_EXIT_STATUS <<<"$succeeded")"
expect 'backup succeeded event: identifier' arkhive "$(field SYSLOG_IDENTIFIER <<<"$succeeded")"
[ -n "$(field ARKHIVE_SNAPSHOT <<<"$succeeded")" ] && pass 'backup succeeded event: snapshot' || fail 'no ARKHIVE_SNAPSHOT'
run="$(field ARKHIVE_RUN_ID <<<"$succeeded")"
started="$(journalctl --no-pager --output=json ARKHIVE_RUN_ID="$run" MESSAGE_ID=038e5a6f18f6184144c8b36819606286 | wc -l)"
expect 'the run opened with a started event' 1 "$started"
saved="$(journalctl --no-pager --output=json ARKHIVE_RUN_ID="$run" ARKHIVE_LEVEL=success | jq -r 'select(.ARKHIVE_SNAPSHOT_ID != null) | .MESSAGE' | head -n 1)"
case "$saved" in Snapshot*saved*) pass 'structured snapshot event' ;; *) fail "no snapshot event with ARKHIVE_SNAPSHOT_ID in run ${run}" ;; esac
stream="$(journalctl --no-pager --output=json _SYSTEMD_UNIT=arkhive-backup.service _TRANSPORT=stdout | wc -l)"
expect 'nothing written twice through stderr' 0 "$stream"

# maintenance waits for nothing and succeeds
if systemctl start arkhive-maintain.service; then pass 'the maintain unit succeeds'; else fail 'the maintain unit failed'; fi

# a failing backup: the repository is gone
mv /srv/restic-repo /srv/restic-repo.away
if systemctl start arkhive-backup.service; then fail 'the backup unit succeeded without a repository'; else pass 'the backup unit fails'; fi
expect 'Result after a failure' exit-code "$(property arkhive-backup.service Result)"
expect 'ExecMainStatus after a failure' 69 "$(property arkhive-backup.service ExecMainStatus)"
case "$(property arkhive-backup.service StatusText)" in
    'arkhive backup failed with exit 69 (UNAVAILABLE)'*) pass 'StatusText names the failure' ;;
    *) fail "StatusText: $(property arkhive-backup.service StatusText)" ;;
esac
failed="$(event 0dca6b902c5ac3d8b27d93e0ffcc4c5e)"
expect 'backup failed event: exit status' 69 "$(field ARKHIVE_EXIT_STATUS <<<"$failed")"
expect 'backup failed event: priority' 3 "$(field PRIORITY <<<"$failed")"

# OnFailure= started the failure unit; without notifications it logs and exits 3 (a success for it)
failure_unit='arkhive-failure@arkhive-backup.service.service'
for _ in $(seq 1 60); do
    [ -n "$(property "$failure_unit" ExecMainExitTimestamp)" ] && break
    sleep 1
done
expect 'the OnFailure unit ran' 3 "$(property "$failure_unit" ExecMainStatus)"
expect 'the OnFailure unit succeeded' success "$(property "$failure_unit" Result)"
journalctl --no-pager --output=cat --unit="$failure_unit" | grep -q 'arkhive-backup.service failed (exit 69 (UNAVAILABLE)), but no notifications are configured' \
    && pass 'the OnFailure unit read the failed run' || fail 'the OnFailure unit did not describe the failure'

if arkhive schedule --status --json > /tmp/arkhive-status.json; then fail 'schedule --status is 0 after a failed run'; else pass 'schedule --status is 1 after a failed run'; fi
expect 'schedule --status: last result' exit-code "$(jq -r '.timers[0].last_result' /tmp/arkhive-status.json)"
expect 'schedule --status: last exit status' 69 "$(jq -r '.timers[0].last_exit_status' /tmp/arkhive-status.json)"
mv /srv/restic-repo.away /srv/restic-repo

# the message catalog
install -D -m 0644 contrib/systemd/arkhive.catalog /usr/lib/systemd/catalog/arkhive.catalog
journalctl --update-catalog
journalctl --no-pager -x MESSAGE_ID=0dca6b902c5ac3d8b27d93e0ffcc4c5e | grep -q 'The status is a sysexits(3) code' \
    && pass 'journalctl -x explains the failed backup' || fail 'the catalog is not shown'

# syslog through /dev/log (journald serves it on systemd hosts)
arkhive internal:context --sample-log --log-target syslog > /dev/null
sleep 1
journalctl --no-pager --output=cat _TRANSPORT=syslog SYSLOG_IDENTIFIER=arkhive | grep -q 'sample warning' \
    && pass 'the syslog target reaches /dev/log' || fail 'no event from the syslog target'

if [ "$failures" -gt 0 ]; then
    echo "${failures} check(s) failed" >&2
    journalctl --no-pager --unit='arkhive-*' --lines=100 >&2 || true
    exit 1
fi
echo 'all systemd checks passed'
