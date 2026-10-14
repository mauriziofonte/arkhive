# Changelog

All notable changes to ArkHive are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- restic 0.11.0 or newer is supported (2.0 needed 0.14.0), so the restic package of Debian 11,
  Ubuntu 22.04 and Debian 12 works as is. Where an older restic lacks a feature ArkHive falls
  back: keepalives for `sftp:` repositories go in a whole `sftp.command` before 0.16.1, `init`
  and `restore` totals are read without JSON, short snapshot ids are resolved to full ones,
  `RESTIC_CHECK_SUBSET` becomes the nearest fraction before 0.12 (percentages) and 0.14 (sizes),
  and `--compression` is left out before 0.14. `doctor` names what the installed restic lacks.
- Transient failures are tried again within the run, each retry logged with its reason: a
  repository locked by another run or another restic client is waited for up to `LOCK_WAIT`
  seconds (new optional key, default 300; `0` keeps the 2.0 behaviour of failing at once), a
  repository that cannot be reached is tried three times, and a notification refused with a 4xx
  answer (greylisting) or not delivered for a network error is tried three times. Permanent
  failures (wrong password, missing repository, refused SSH key) are never retried.
- Each `backup`, `maintain` and `drill` records how it ended (start, end, exit code, snapshot,
  warnings, error) in the state directory: `$STATE_DIRECTORY` under systemd, `/var/lib/arkhive`
  as root, `$XDG_STATE_HOME/arkhive` otherwise. `status` shows the last run of each job (and the
  last good one after a failure), `status --json` adds `runs`, and `doctor` warns when the last
  run of a job failed.
- Under systemd, restic's cache goes in the unit's `CacheDirectory=` (`$CACHE_DIRECTORY/restic`)
  unless `RESTIC_CACHE_DIR` is set, so hardened units keep a writable, persistent cache.
- A full filesystem (exit 74) is reported with the free space found on each local filesystem
  involved: the dump directory, a local repository, the restic cache, the restore target.
- Quick calls have deadlines (exit 75 when exceeded): database metadata queries, restic opening,
  creating or reading the repository's metadata (300 s each), and `--version` checks (30 s).
- Under systemd, ArkHive reports to the service manager (sd_notify protocol, in PHP):
  `systemctl status` shows the current step and its progress, then the outcome; a run that still
  makes progress gets more time (`EXTEND_TIMEOUT_USEC`) instead of being stopped by
  `TimeoutStartSec`; `WatchdogSec=` is supported. The units set `NotifyAccess=main`.
- Hardened systemd units: `StateDirectory=` and `CacheDirectory=`, `ProtectSystem=full`,
  `ProtectHome=read-only` with the dump directory and a local repository writable, and the
  kernel and privilege protections; `CPUSchedulingPolicy=batch`; timers spread over 10 minutes
  (`RandomizedDelaySec`); maintain and drill wait for a backup due at the same time. Regenerate
  and reinstall the units (`arkhive schedule --systemd`) to get them.
- `tests/Compat`: ArkHive's commands against a real restic (`ARKHIVE_TEST_RESTIC`), run in CI for
  every release from 0.11.0 to the current one, including an `sftp:` repository on a local sshd.

### Fixed

- A database dump restored for `--import-as` into a full filesystem failed as "the output
  stream was closed" (exit 1); it is now "No space left on device" (exit 74).
- `sftp:` repositories failed on restic older than 0.16.1 ("option sftp.args is not known"), and
  `maintain` failed on restic older than 0.12 with the default `RESTIC_CHECK_SUBSET=5%`.
- A repository of format 2 opened by restic older than 0.14 is reported as such (exit 69, with
  the version to install) instead of a bare restic error.

### Changed

- Exit codes follow `sysexits.h` (see "Exit codes" in the README): usage errors are 64 (were 2),
  configuration errors 78 (were 2), refused outputs such as a non-empty restore target or not
  enough space 73 (were 4), a lock held by another run 75, an unreachable repository or a missing
  tool 69, a wrong password 77, a failed `drill` or repository check 65 (were 1). 0, 1, 3, 10, 130
  and 143 keep their meaning. systemd shows the names (`status=75/TEMPFAIL`).
- A database dump that fails no longer aborts the backup: the other dumps and the files are
  backed up, the failed dumps are named in the log, the notification and `--json`
  (`dump_failures`), and the run exits 3. The new optional key `DUMP_REQUIRED=true` makes such
  a run fail with the dump's exit code instead, still after saving the rest.
- A failed database dump or import exits with its cause: server down 69, credentials refused 77,
  unknown database 66, disk full 74, timeout 75 (all were 1).

## [2.0.0-rc.1] - 2026-10-14

ArkHive 2.0 replaces the 1.x archive pipeline (`tar | gzip/xz | openssl` streamed over SSH) with
[restic](https://restic.net): each run uploads only what changed, and restic handles compression,
encryption, deduplication and retention. The configuration is not compatible with 1.x: see
"Migrating from 1.x" in the README.

### Added

- restic backend: `backup` runs one `restic backup` of `BACKUP_PATHS` and the database dumps, with
  the JSON summary (snapshot id, data added, files new/changed/unmodified) in the log and the
  notification.
- `restore`: a guided wizard in a terminal (host, snapshot, what to restore, target), and an
  unattended mode driven by flags (`--snapshot`, `--target`, `--include`, `--to-stdout`, `--db`
  with `--import-as`, `--in-place` with a safety snapshot taken first, `--owner`). Space is checked
  before writing; a failed restore removes its partial target.
- Bare-metal recovery without a configuration file: `--repo`, `--password-file` and
  `--sftp-command` on `restore`, `ls`, `find`, `mount`, `status`, `drill` and `doctor`.
- `ls`, `find` and `mount` to browse snapshots.
- `maintain`: retention (`RESTIC_KEEP_DAILY`/`WEEKLY`/`MONTHLY`), prune and repository check with
  a read-data subset.
- `drill`: restores a random sample of the latest snapshot into a temporary directory and verifies
  it, optionally importing a database dump into a throw-away database.
- `doctor`: PHP and extensions, distribution and install hints, restic, configuration, password
  file, repository, lock, latest snapshot age, dump space, clock skew and execution context, each
  with the fix.
- `status` (with `--json`, and `--max-age` for monitoring), `init` (creates the repository and a
  missing password file).
- `schedule --systemd|--cron`: prints systemd units or cron lines for the current configuration;
  examples in `contrib/`.
- `decrypt-legacy` extracts 1.x archives (`.enc.arbk`, `.arbk`, gzip or xz) with the 1.x password
  from `--password-file` or `ARKHIVE_CRYPT_PASSWORD`.
- `self-update` from GitHub Releases: sha256 check, build provenance check with `gh` when installed,
  `--check` (exit 10 when an update exists), `--pre`, a specific release as argument, a guard on
  major versions (`--allow-major`) and `--rollback`.
- Execution-context awareness: interactive in a terminal (prompts, colour, progress), unattended
  under cron, scripts, CI and systemd (no prompts, plain or journald log lines; a missing answer is
  exit 2 naming the flag). `--log-format json` and `--log-file` (rotated by size) everywhere.
- Stable exit codes: 0, 1, 2, 3 (warnings), 4 (refused for safety), 10, 130 and 143.
- Linux portability: distribution detection and install commands for Debian, Ubuntu, RHEL-compatible,
  Fedora, Alpine, Arch and openSUSE; child processes run with `LC_ALL=C`; no GNU-only flags.
- `NOTIFY_ON=failure`, unauthenticated SMTP relays, `PGSQL_PORT`, `PGSQL_PASSWORD`,
  `PGSQL_DUMP_OPTIONS`, `RESTIC_*` keys and `--config <file>`.
- Release automation: `.github/workflows/release.yml` builds, checks, attests and publishes the PHAR
  for a `v*` tag.

### Changed

- PHP 8.3 or newer and Laravel Zero 13. The PHAR refuses to start on older PHP and names where to
  get a newer one for the distribution.
- Database dumps: one plain `.sql` file per database (MySQL/MariaDB with `--single-transaction`,
  PostgreSQL in plain format for every listed database), so each deduplicates and restores on its
  own. A failed dump aborts the run before anything is uploaded.
- Exclusion patterns keep their 1.x meaning and are converted to restic patterns anchored below each
  backup path.
- Configuration files are read by ArkHive's own parser: errors name the file and line, values are
  never expanded, and only boolean keys are typed.
- Configuration discovery searches the working directory (1.x used the executable's directory) and
  `$XDG_CONFIG_HOME/arkhive/config`.
- The lock is per repository and a stale lock is reported, never silently taken over.
- `self-upgrade` is a deprecated alias of `self-update`.

### Removed

- Configuration keys `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_BACKUP_HOME`, `WITH_CRYPT`,
  `CRYPT_PASSWORD`, `COMPRESSION_TYPE`, `BACKUP_RETENTION_DAYS`, `BACKUP_DIRECTORY`, `SMTP_SSL`
  and `SMTP_AUTH`: a configuration that still has one is refused with the replacement for each.
- The 1.x archive format, `--with-progress`, and the `tar`, `gzip`, `xz`, `scp`, `pv`, `mkfifo` and
  `tee` dependencies (`tar`, `xz` and `openssl` remain for `decrypt-legacy`).
- `builds/` from the repository and the Composer installation: the PHAR is distributed only through
  GitHub Releases.

### Security

- Secrets never appear on a command line: MySQL credentials through a temporary
  `--defaults-extra-file`, PostgreSQL through a temporary `PGPASSFILE`, restic through
  `RESTIC_PASSWORD_FILE`, the 1.x password through the environment.
- Password files that are group- or world-readable, or owned by another user, are refused.
- External processes are started without a shell and every exit status is checked.

## [1.7.1] - 2026-09-26

### Fixed

- Restrict the remote `chmod` to the backup directory of the day.

[Unreleased]: https://github.com/mauriziofonte/arkhive/compare/v2.0.0-rc.1...HEAD
[2.0.0-rc.1]: https://github.com/mauriziofonte/arkhive/compare/v1.7.1...v2.0.0-rc.1
[1.7.1]: https://github.com/mauriziofonte/arkhive/releases/tag/v1.7.1
