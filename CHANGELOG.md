# Changelog

All notable changes to ArkHive are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.0] - 2026-10-16

The first stable release of ArkHive 2: restic as the backend (incremental, encrypted, deduplicated
backups with a guided restore), systemd units with notifications and a watchdog, the journal and
syslog, `sysexits.h` exit codes, and the restic packaged by Debian 11 and later. It includes every
change listed below under 2.0.0-rc.1 and 2.1.0-rc.1 to rc.3, none of which was published as a
release. Coming from 1.x: the configuration is not compatible, see "Migrating from 1.x" in the
README.

Changes since 2.1.0-rc.3, from its retest against a frozen storage box and a paused database:

### Added

- `DUMP_STALL_TIMEOUT` (optional, default `300`): a database dump that writes nothing for that many
  seconds is stopped and counts as a failed dump (exit 75), and the other databases of the same
  server are not attempted, since each would wait just as long. The other server's dumps and the
  files are backed up, and `DUMP_REQUIRED` decides the exit code as for any failed dump. Before, a
  dump against a paused server held the backup until the server came back (8 minutes and more in
  the test), bounded only by the unit's `TimeoutStartSec`, and under cron not at all. `0` turns it
  off.
- `DUMP_TIMEOUT` (optional, default `0`, no limit): the longest one dump may run.

### Fixed

- A storage box frozen while `ssh` was still connecting (the TCP connection accepted, no SSH
  greeting) held the run until the box came back: the keepalives only start once the session is
  up. ArkHive now adds `-o ConnectTimeout=30` along with the keepalives, so each attempt to open the
  repository fails after 30 seconds and the run ends with exit 69 after its three attempts.

### Unchanged, on purpose

- A configuration file that holds passwords and is readable by group or others is still a warning
  (in every run's log and a failure in `doctor`), not a refusal: refusing it in this release would
  stop the scheduled backups of every host upgraded from 2.0 with a 0644 configuration. A later
  minor version refuses it with exit 77; run `chmod 600` on the configuration now.
- The first backup after the repository lost data, before any check, still ends with exit 0: restic
  trusts its index. Run `drill` daily and `maintain` weekly (docs/OPERATIONS.md).

## [2.1.0-rc.3] - 2026-10-16

Fixes for the gaps found by the retest of 2.1.0-rc.2 against a simulated Hetzner Storage Box
(damaged repository, frozen server, quota, scale, unusual environments).

### Changed

- `maintain` runs `check` and `check --read-data-subset` first and applies the retention policy only
  when both pass: a damaged repository keeps every snapshot (an older one may hold the only intact
  copy of some data). `forget` and `prune` run apart, so a `prune` refused on missing data still
  reports the snapshots `forget` removed. Before, `forget --prune` ran first: on a damaged
  repository it removed snapshots (6 to 2 in the test), then prune refused and the report said
  0 removed. `maintain --json` gains `retention_applied`.
- `status` exits 1 while the last `maintain` or `drill` failed (until it runs again and passes;
  exit 3 counts as done), and 65 while damage they found has not been cleared by a passing
  `maintain`. Before it exited 0, so a monitor had to read `status --json` or each run's exit code.
  `status --json` gains `failed_jobs`.
- `doctor` recommends the static restic 0.19.x binary when the installed restic is older than
  0.17.0 (information, the exit code does not change): restic 0.14.0 spent minutes in retries in
  `maintain` and `drill` on a damaged repository.

### Fixed

- A `RESTIC_SFTP_COMMAND` lost the keepalives and batch mode ArkHive gives restic's own `ssh`, so
  the README form (port and key) hung a backup for as long as the server stayed frozen. ArkHive now
  adds `-o ServerAliveInterval=15 -o ServerAliveCountMax=4 -o BatchMode=yes` right after `ssh` for
  each option the command does not set; a command that does not start with `ssh` is used as written
  and `doctor` warns that it gets no keepalives.
- A stale restic lock left by a run that was killed or lost its server says why it is there and
  what it blocks (every `maintain` for a backup's lock, everything for an exclusive one). ArkHive
  still removes it only with `UNLOCK_STALE=true`.
- A password file in a directory that belongs to another user (even at mode 0755) is refused: its
  owner could replace the file or remove it. The directory must belong to the running user or root.
- With neither `HOME` nor `XDG_CACHE_HOME` set (`env -i`), restic had no cache directory and every
  command failed with exit 1 and no hint. ArkHive now gives restic `.cache/restic` under the home
  directory of the running user's account, and reports exit 78 with the fix when there is none.

## [2.1.0-rc.2] - 2026-10-15

Fixes for weak points found by an isolated test against a simulated Hetzner Storage Box
(chrooted SFTP on port 23, quota, synthetic data): repository damage, file permissions, the run
lock, run records, exit codes and messages.

### Added

- `UNLOCK_STALE` (optional, default `false`): with `true`, wherever ArkHive would wait for a lock
  (opening the repository, `backup`, `maintain`, `restore`) it removes a restic lock left by a
  process that died on this host with `restic unlock` (never `--remove-all`) and tries the
  operation again at once, once, logged as a warning. Only when the
  lock's PID no longer exists (restic keeps a zombie's lock) and no other lock made on this host in
  the last 30 minutes belongs to a running process, since `restic unlock` tests those PIDs with
  SIGHUP. By default ArkHive still never removes a lock. See docs/OPERATIONS.md.

### Fixed

- A repository whose pack files were deleted on the server is reported as damaged (exit 65) by
  `maintain`, `restore`, `ls`, `find` and `drill`, with restic's repair commands in the order of its
  troubleshooting guide (`repair index`, a new backup, `repair snapshots --forget`, a check), never
  run automatically. `maintain` exited 1 with raw restic JSON when `forget --prune` refused to run on
  missing packs; it now still runs the check, names the missing packs and exits 65. `restore` exited
  1 ("Measuring snapshot failed"). A backup that follows a `maintain` or `drill` that found damage,
  with no passing `maintain` since, ends with a warning (exit 3), since restic cannot detect the
  damage while backing up. See docs/OPERATIONS.md.
- Any local user could block root's backups by creating root's lock file in the world-writable
  `/run/lock` first; root then failed with exit 73 and a misleading hint. Root now locks in
  `/run/arkhive` (0700 root:root, checked before use), the systemd units and root cron lines set
  `ARKHIVE_LOCK_DIR=/run/arkhive` (units: `RuntimeDirectory=arkhive`, mode 0700, preserved between
  runs), and a lock file of another user or a symbolic link in a shared directory is exit 77 naming
  its owner. Reinstall the units with `arkhive schedule --install`.
- The configuration file's permissions are checked before every run. A configuration that someone
  besides the running user and root can change (writable by group or others, or owned by another
  user) is refused with exit 77, since whoever changes it chooses the commands ArkHive runs. A
  configuration that holds `MYSQL_PASSWORD`, `PGSQL_PASSWORD` or `SMTP_PASSWORD` and is readable by
  group or others is a warning in 2.1 ("Run: chmod 600 <path>"), so existing installs keep running;
  a later minor version will refuse it with exit 77 (`ConfigFileAccess::ENFORCE_PRIVATE`). `doctor`
  has a new `config file` check: failed for both cases, a warning for a configuration without
  passwords that others can read.
- The repository password file is checked more strictly: any permission for group or others
  (0620, 0602, not only read bits) is refused, a symbolic link is refused (it is no longer followed:
  point `RESTIC_PASSWORD_FILE` or `--password-file` at the file itself), and so is a file in a
  directory that group or others can write without the sticky bit, where someone could replace it
  with a password they know before restic reads it. `init` checks the directory before it generates
  a password there.
- SQL dumps could be readable by every local user: `mariadb-dump`, `mysqldump` and `pg_dump` create
  their file with the process umask (0644 under the usual 022), and `mysql`/`pgsql` directories
  created by hand or by an older version kept their mode. Each dump file is now created 0600 before
  the dump tool writes into it and is 0600 after the rename, the engine subdirectories are made 0700
  on every run, and a `DUMP_DIRECTORY` that belongs to another user or that group or others can
  write is refused with exit 77.
- Snapshots deleted on the server, or a storage rolled back to an older copy, went unnoticed:
  restic sees a healthy repository with fewer snapshots. Each run now records the repository
  and the host with its full snapshot id, the run state is kept per repository
  (`<state>/<key>/<job>.json`, the key of the lock file; the host-wide `<job>.json` stays), and
  `status` and `drill` exit 65 when the snapshot of the last successful backup recorded for the
  repository is gone with no newer backup of the host in its place ("snapshot <id> recorded as the
  last successful backup at <time> is missing from the repository (deleted or rolled back)"). A drill
  then restores nothing, since an older snapshot would pass. `status --json` has a new
  `continuity` object, and run records have `repository` and `host`. The records of 2.1.0-rc.1 are
  taken over by the first repository that reads them, and are not checked (they may be of another
  repository).
- restic killed by a signal ArkHive did not send (the kernel's out-of-memory killer, an
  administrator) ended with exit 1 and no message; it is now exit 75, "restic was killed by signal 9
  (SIGKILL: out of memory?)", and is not tried again within the run. restic stopped by a SIGTERM or
  SIGINT it caught ("signal terminated received", exit 1 before 0.18, 130 after) is the same. Error
  messages read restic's JSON records (`--json`, restic 0.18 and later) as their text, without
  terminal escape sequences and with repeated lines once: "repository is locked" now names the
  holder and the lock time again with restic 0.18 and later, where the regular expressions met the
  raw JSON. Missing or damaged data (`tree ... not found`, `blob ... not found`, `LoadRaw`,
  `ciphertext verification failed`, packs missing from the index) is exit 65 everywhere.
- A restic lock left by a process that died on this host (`kill -9`, the out-of-memory killer, a
  crash) is never released, since restic does not skip stale locks; ArkHive waited `LOCK_WAIT` on it
  at every run and then reported an ordinary lock. When the lock was made on this host, its PID no
  longer exists or is a zombie (`/proc/<pid>/stat`), and no other ArkHive run holds the repository,
  the run now fails at once with exit 75, "a stale lock from a dead process on this host (PID N no
  longer exists)", and the `restic unlock` command. `doctor` and the guided restore no longer call
  a lock stale when its process runs as another user (`kill(pid, 0)` failing with EPERM).

## [2.1.0-rc.1] - 2026-10-14

ArkHive 2.1 works with the tools of the host: systemd (status, progress, watchdog, failure
notifications, hardened units installed by `schedule --install`), the journal and syslog
(structured fields, message ids), exit codes from `sysexits.h`, and the restic packaged by
Debian 11 and later. Transient failures are tried again within the run, and a failed database
dump no longer costs the backup of the files.

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
- `arkhive notify --failed-unit <unit>` and the `arkhive-failure@.service` template the units
  start with `OnFailure=`: a scheduled run that ends without sending its own notification
  (killed by the timeout or the OOM killer, failed before reading its configuration) is reported
  with its outcome and its last journal lines. Run records gain `invocation` and `notified`.
- `arkhive schedule --install` (as root) writes the systemd units, reloads systemd and enables the
  timers; it rewrites only what changed and never overwrites a unit it did not generate.
  `arkhive schedule --status [--json]` shows each timer's state, next and last run and last
  result, exit 1 when a timer is missing, disabled or its last run failed.
- Logging to the systemd journal through its native protocol, with structured fields
  (`ARKHIVE_COMMAND`, `ARKHIVE_RUN_ID`, `ARKHIVE_PHASE`, `ARKHIVE_LEVEL`, the event's data as
  `ARKHIVE_<KEY>`), chosen automatically when stderr is connected to the journal; or to the local
  syslog daemon (`/dev/log`, facility `SYSLOG_FACILITY`) for hosts without systemd. New option
  `--log-target auto|stderr|journal|syslog` and keys `LOG_TARGET`, `SYSLOG_FACILITY`; stderr is
  the fallback when neither can be reached, and `doctor` reports the target.
- `backup`, `maintain`, `drill` and `restore` open and close with journal events that carry a
  `MESSAGE_ID` (`journalctl MESSAGE_ID=...` lists every failed backup) and `ARKHIVE_EXIT_STATUS`;
  `contrib/systemd/arkhive.catalog` explains them in `journalctl -x`.
- `tests/Compat`: ArkHive's commands against a real restic (`ARKHIVE_TEST_RESTIC`), run in CI for
  every release from 0.11.0 to the current one, including an `sftp:` repository on a local sshd.

### Fixed

- A database dump restored for `--import-as` into a full filesystem failed as "the output
  stream was closed" (exit 1); it is now "No space left on device" (exit 74).
- `sftp:` repositories failed on restic older than 0.16.1 ("option sftp.args is not known"), and
  `maintain` failed on restic older than 0.12 with the default `RESTIC_CHECK_SUBSET=5%`.
- A repository of format 2 opened by restic older than 0.14 is reported as such (exit 69, with
  the version to install) instead of a bare restic error.
- A run whose stderr reader went away (`arkhive ... 2>&1 | grep -q`, a pipe closed early) exited 1
  at its next log line although the work succeeded; the lost lines are now ignored.

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

[Unreleased]: https://github.com/mauriziofonte/arkhive/compare/v2.1.0...HEAD
[2.1.0]: https://github.com/mauriziofonte/arkhive/compare/v1.7.1...v2.1.0
[2.1.0-rc.3]: https://github.com/mauriziofonte/arkhive/compare/v2.1.0-rc.2...v2.1.0-rc.3
[2.1.0-rc.2]: https://github.com/mauriziofonte/arkhive/compare/v2.1.0-rc.1...v2.1.0-rc.2
[2.1.0-rc.1]: https://github.com/mauriziofonte/arkhive/compare/v2.0.0-rc.1...v2.1.0-rc.1
[2.0.0-rc.1]: https://github.com/mauriziofonte/arkhive/compare/v1.7.1...v2.0.0-rc.1
[1.7.1]: https://github.com/mauriziofonte/arkhive/releases/tag/v1.7.1
