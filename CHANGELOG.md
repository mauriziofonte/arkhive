# Changelog

All notable changes to ArkHive are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 2026-10-16

ArkHive 2 replaces the 1.x archive pipeline (`tar | gzip/xz | openssl` streamed over SSH, one full archive every day) with [restic](https://restic.net): each run uploads only what changed, and restic handles compression, encryption, deduplication and retention. It works with the tools of the host: systemd (status, progress, watchdog, failure notifications, hardened units), the journal and syslog, exit codes from `sysexits.h`, and the restic packaged by Debian 11 and later. The configuration is not compatible with 1.x: see "Migrating from 1.x" in the README.

### Added

- restic backend: `backup` runs one `restic backup` of `BACKUP_PATHS` and the database dumps, with the summary (snapshot id, data added, files new, changed and unmodified) in the log and the notification.
- `restore`: a guided wizard in a terminal (host, snapshot, what to restore, target), and an unattended mode driven by flags (`--snapshot`, `--target`, `--include`, `--exclude`, `--to-stdout`, `--db` with `--import-as`, `--in-place` with a safety snapshot taken first, `--owner`). Space is checked before writing, every restored file is verified, and a failed restore removes its partial target.
- Bare-metal recovery without a configuration file: `--repo`, `--password-file` and `--sftp-command` on `restore`, `ls`, `find`, `mount`, `status`, `drill` and `doctor`.
- `ls`, `find` and `mount` to browse snapshots.
- `maintain`: `check` and `check --read-data-subset` (`RESTIC_CHECK_SUBSET`) first, then the retention policy (`RESTIC_KEEP_DAILY`, `RESTIC_KEEP_WEEKLY`, `RESTIC_KEEP_MONTHLY`) and `prune`, only when both checks pass: a damaged repository keeps every snapshot, since an older one may hold the only intact copy of some data.
- `drill`: restores a random sample of the latest snapshot into a temporary directory and verifies it, optionally importing a database dump into a throw-away database.
- `doctor`: PHP and extensions, distribution and install hints, restic and what an older release lacks, the permissions of the configuration and of the password file, SSH options, repository, locks, latest snapshot age, last runs, dump space, clock skew, execution context and log target, each with the fix.
- `init` creates the repository and a missing password file. `status` (with `--json`, and `--max-age` for monitoring) shows the latest backup and how the last `backup`, `maintain` and `drill` ended; it exits 1 while the last `maintain` or `drill` failed, and 65 while the damage they found has not been cleared by a passing `maintain`.
- Run records: each `backup`, `maintain` and `drill` records how it ended, per host and per repository, in the state directory (`$STATE_DIRECTORY` under systemd, `/var/lib/arkhive` as root, `$XDG_STATE_HOME/arkhive` otherwise). `status` and `drill` exit 65 when the snapshot of the last successful backup is missing from the repository with no newer backup in its place (deleted on the server, or a storage rolled back to an older copy).
- Damaged repositories (missing packs, trees or blobs, failed decryption) are exit 65 in every command, with restic's repair commands in the order of its troubleshooting guide, never run automatically. A backup that follows a run that found damage ends with a warning (exit 3) until a `maintain` passes.
- Scheduling: `schedule --systemd|--cron` prints units or cron lines for the configuration. `schedule --install` (as root) writes hardened systemd units (`StateDirectory=`, `CacheDirectory=`, `ProtectSystem=full`, `ProtectHome=read-only`, timers spread with `RandomizedDelaySec`, `maintain` and `drill` after a backup due at the same time), reloads systemd and enables the timers, rewriting only what changed and never a unit it did not generate. `schedule --status [--json]` shows each timer's state, next and last run and last result. Examples in `contrib/`.
- systemd integration (sd_notify, in PHP): `systemctl status` shows the current step, its progress and the outcome; a run that still makes progress gets more time instead of being stopped by `TimeoutStartSec`; `WatchdogSec=` is supported. `arkhive notify --failed-unit <unit>` and the `arkhive-failure@.service` template started by `OnFailure=` report a scheduled run that ended without its own notification (killed by the timeout or the OOM killer, failed before reading its configuration).
- Logging to the systemd journal through its native protocol, with structured fields and `MESSAGE_ID` events (`contrib/systemd/arkhive.catalog` explains them in `journalctl -x`), or to the local syslog daemon for hosts without systemd: `--log-target auto|stderr|journal|syslog`, `LOG_TARGET`, `SYSLOG_FACILITY`. `--log-format json` and `--log-file` (rotated by size) in every mode.
- Execution-context awareness: interactive in a terminal (prompts, colour, progress), unattended under cron, scripts, CI and systemd (no prompts, plain or journald log lines; a missing answer is exit 64 naming the flag).
- Resilience: a repository locked by another run is waited for up to `LOCK_WAIT` seconds (default 300), an unreachable repository is tried three times, and a notification refused with a 4xx answer or lost to a network error is tried three times; permanent failures (wrong password, missing repository, refused SSH key) are never retried. Quick calls have deadlines (exit 75), and so do database dumps: `DUMP_TIMEOUT` for the whole dump, `DUMP_STALL_TIMEOUT` (default 300 s) for a dump that writes nothing. ArkHive adds `-o ConnectTimeout=30 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -o BatchMode=yes` to restic's `ssh` and to a `RESTIC_SFTP_COMMAND` that does not set them, so a server that stops answering fails the run instead of hanging it.
- A restic lock left by a process that died on this host is reported at once (exit 75) with what it blocks and the `restic unlock` command to run; `UNLOCK_STALE=true` removes such locks automatically. ArkHive never removes any other lock.
- restic 0.11.0 or newer, so the restic packages of Debian 11, Ubuntu 22.04 and later work as they are: ArkHive falls back where an older restic lacks a feature, and `doctor` recommends the static 0.19.x binary for anything before 0.17.0.
- `decrypt-legacy` extracts 1.x archives (`.enc.arbk`, `.arbk`, gzip or xz) with the 1.x password from `--password-file` or `ARKHIVE_CRYPT_PASSWORD`.
- `self-update` from GitHub Releases: sha256 check, build provenance check with `gh` when installed, `--check` (exit 10 when an update exists), `--pre`, a specific release as argument, a guard on major versions (`--allow-major`) and `--rollback`.
- Linux portability: distribution detection and install commands for Debian, Ubuntu, RHEL-compatible, Fedora, Alpine, Arch and openSUSE; child processes run with `LC_ALL=C`; no GNU-only flags.
- A full filesystem (exit 74) is reported with the free space of each local filesystem involved: the dump directory, a local repository, the restic cache, the restore target.
- `NOTIFY_ON=failure`, unauthenticated SMTP relays, `PGSQL_PORT`, `PGSQL_PASSWORD`, `PGSQL_DUMP_OPTIONS`, the `RESTIC_*` keys and `--config <file>`.
- Releases on GitHub with the PHAR, its sha256 and a build provenance attestation.

### Changed

- PHP 8.3 or newer and Laravel Zero 13. The PHAR refuses to start on older PHP and names where to get a newer one for the distribution.
- Exit codes follow `sysexits.h` (see "Exit codes" in the README): 64 usage, 65 damaged data or a failed check, 66 missing input, 69 unreachable repository or missing tool, 73 refused output, 74 full filesystem, 75 lock or timeout, 77 permissions, 78 configuration; 0, 1, 3 (warnings), 10, 130 and 143 as documented. systemd shows the names (`status=75/TEMPFAIL`).
- Database dumps: one plain `.sql` file per database (MySQL/MariaDB with `--single-transaction`, PostgreSQL in plain format), so each deduplicates and restores on its own. A failed dump no longer aborts the backup: the other dumps and the files are saved, the failure is named in the log, the notification and `--json`, and the run exits 3; with `DUMP_REQUIRED=true` it fails with the dump's exit code instead (server down 69, credentials refused 77, unknown database 66, disk full 74, timeout 75).
- Exclusion patterns keep their 1.x meaning and are converted to restic patterns anchored below each backup path.
- Configuration files are read by ArkHive's own parser: errors name the file and line, values are never expanded, and only boolean keys are typed. Discovery searches the working directory (1.x used the executable's directory) and `$XDG_CONFIG_HOME/arkhive/config`.
- The lock is per repository; root locks in a private `/run/arkhive`, and a stale lock is reported, never silently taken over.
- `self-upgrade` is a deprecated alias of `self-update`.

### Removed

- Configuration keys `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_BACKUP_HOME`, `WITH_CRYPT`, `CRYPT_PASSWORD`, `COMPRESSION_TYPE`, `BACKUP_RETENTION_DAYS`, `BACKUP_DIRECTORY`, `SMTP_SSL` and `SMTP_AUTH`: a configuration that still has one is refused with the replacement for each.
- The 1.x archive format, `--with-progress`, and the `tar`, `gzip`, `xz`, `scp`, `pv`, `mkfifo` and `tee` dependencies (`tar`, `xz` and `openssl` remain for `decrypt-legacy`).
- `builds/` from the repository and the Composer installation: the PHAR is distributed only through GitHub Releases.

### Security

- Secrets never appear on a command line: MySQL credentials through a temporary `--defaults-extra-file`, PostgreSQL through a temporary `PGPASSFILE`, restic through `RESTIC_PASSWORD_FILE`, the 1.x password through the environment.
- A configuration file that someone besides the running user and root can change (writable by group or others, or owned by another user) is refused with exit 77. One that holds `MYSQL_PASSWORD`, `PGSQL_PASSWORD` or `SMTP_PASSWORD` and is readable by group or others is a warning in 2.0 and will be refused with exit 77 in a later minor version: run `chmod 600` on it.
- The repository password file is refused when group or others have any permission on it, when it is a symbolic link or owned by another user, and when it sits in a directory that belongs to another user or that others can write without the sticky bit.
- Database dumps are created 0600 in 0700 directories; a `DUMP_DIRECTORY` that belongs to another user or that others can write is refused with exit 77.
- External processes are started without a shell and every exit status is checked.

### Known limitations

- The first backup after the repository lost data, before any check, ends with exit 0: restic trusts its index. Run `drill` daily and `maintain` weekly (docs/OPERATIONS.md).

[Unreleased]: https://github.com/mauriziofonte/arkhive/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/mauriziofonte/arkhive/releases/tag/v2.0.0
