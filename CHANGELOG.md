# Changelog

All notable changes to ArkHive are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Exit codes follow `sysexits.h` (see "Exit codes" in the README): usage errors are 64 (were 2),
  configuration errors 78 (were 2), refused outputs such as a non-empty restore target or not
  enough space 73 (were 4), a lock held by another run 75, an unreachable repository or a missing
  tool 69, a wrong password 77, a failed `drill` or repository check 65 (were 1). 0, 1, 3, 10, 130
  and 143 keep their meaning. systemd shows the names (`status=75/TEMPFAIL`).

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
