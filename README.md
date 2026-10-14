# ArkHive

**Your data's ark in the storm.**

ArkHive backs up Linux servers to a [restic](https://restic.net) repository. restic does the hard parts: content-defined deduplication (a day with a few hundred MB of changes uploads a few hundred MB, not the whole data set), compression, encryption and retention. ArkHive adds what a server needs around it: one `.env`-style configuration, plain per-database MySQL/MariaDB and PostgreSQL dumps, 1.x-compatible exclusion patterns, a lock per repository, e-mail notifications, a guided restore that never overwrites data by accident, automated restore drills, a `doctor` that explains what is wrong and how to fix it, and scheduling helpers. It is a single PHAR that works the same in a terminal and from cron or systemd.

Contents: [Requirements](#requirements) | [Install](#install) | [Quick start](#quick-start) | [Configuration](#configuration) | [Commands](#commands) | [Interactive and unattended mode](#interactive-and-unattended-mode) | [Scheduling](#scheduling) | [Exit codes](#exit-codes) | [My server is gone](#my-server-is-gone) | [Migrating from 1.x](#migrating-from-1x) | [Development](#development) | [License](#license)

## Requirements

- **PHP 8.3 or newer** (CLI) with the extensions `phar`, `json`, `mbstring`, `posix` and `pcntl`, plus `curl` or `openssl` for `self-update`. The PHAR checks this at startup and, if something is missing, prints the install command for your distribution and exits 69.
- **restic 0.11.0 or newer** (the package of Debian 11 and later), checked before every repository operation. Every release from 0.11.0 to the current one is tested (CI `restic-compat`). Older releases lack some features and ArkHive works around them: before 0.14.0 there is no compression (repository format 1, and a format 2 repository cannot be opened), before 0.15.0 restores write sparse files in full, before 0.16.0 restores show no progress. `arkhive doctor` names what the installed restic lacks.
- `ssh` for an SFTP repository; `mariadb-dump`/`mysqldump` and `mariadb`/`mysql` for MySQL dumps; `pg_dump` and `psql` for PostgreSQL dumps; `fusermount3` (FUSE) for `mount`; `openssl`, `tar` and `xz` only for `decrypt-legacy`; `gh` (optional) to verify release provenance. `arkhive doctor` reports each missing tool with the install command for your distribution.

Supported and tested on amd64 and arm64: Debian 11/12/13, Ubuntu 22.04/24.04, Rocky/Alma/RHEL 9, Fedora, Alpine 3.20+, Arch. The commands below are the ones the CI distribution matrix uses (`tests/distro/setup.sh`), run as root.

**Debian 13**, **Ubuntu 24.04** (the distribution PHP is 8.4 / 8.3):

```sh
apt-get update
apt-get install -y php-cli php-mbstring php-curl restic
```

**Debian 11, Debian 12** (ship PHP 7.4 / 8.2: PHP comes from packages.sury.org; restic from the distribution, 0.11 / 0.14):

```sh
apt-get update
apt-get install -y ca-certificates curl
curl -fsSLo /tmp/sury-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
dpkg -i /tmp/sury-keyring.deb
. /etc/os-release
echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $VERSION_CODENAME main" > /etc/apt/sources.list.d/php.list
apt-get update
apt-get install -y php8.4-cli php8.4-mbstring php8.4-curl restic
```

**Ubuntu 22.04** (ships PHP 8.1: PHP comes from `ppa:ondrej/php`; its restic package is 0.12.1):

```sh
apt-get update
apt-get install -y software-properties-common gpg-agent
add-apt-repository -y ppa:ondrej/php
apt-get install -y php8.4-cli php8.4-mbstring php8.4-curl restic
```

**Rocky, Alma, RHEL 9**: PHP 8.3 from the AppStream module, or PHP 8.4 from Remi; restic from EPEL.

```sh
# AppStream
dnf module reset -y php
dnf module enable -y php:8.3
dnf install -y php-cli php-mbstring php-process

# or Remi
dnf install -y epel-release
dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
dnf module reset -y php
dnf module enable -y php:remi-8.4
dnf install -y php-cli php-mbstring php-process

# restic
dnf install -y epel-release
dnf install -y restic
```

On RHEL itself `epel-release` is not in the base repositories: enable EPEL as its documentation describes for RHEL 9.

**Fedora**:

```sh
dnf install -y php-cli php-mbstring php-process restic
```

**Alpine 3.20+** (the PHAR runs with `php` from `PATH`, and Alpine names it `php83`):

```sh
apk add php83 php83-phar php83-mbstring php83-posix php83-pcntl php83-curl php83-openssl \
    php83-ctype php83-tokenizer php83-iconv php83-dom php83-xml php83-fileinfo php83-zlib restic
ln -sf /usr/bin/php83 /usr/bin/php
```

**Arch**:

```sh
pacman -Sy --needed php restic
```

**restic from the official release** (for compression and repository format 2 where the distribution ships restic older than 0.14, or for a distribution without a package; needs `curl` and `bzip2`). Pick the current version on the [restic releases page](https://github.com/restic/restic/releases); `a` is `amd64` or `arm64`:

```sh
v=0.19.1 a=amd64
cd /tmp
curl -fsSLO "https://github.com/restic/restic/releases/download/v$v/restic_${v}_linux_$a.bz2"
curl -fsSLO "https://github.com/restic/restic/releases/download/v$v/SHA256SUMS"
grep " restic_${v}_linux_$a.bz2\$" SHA256SUMS | sha256sum -c -
bunzip2 "restic_${v}_linux_$a.bz2"
install -m 0755 "restic_${v}_linux_$a" /usr/local/bin/restic
restic version
```

## Install

ArkHive is distributed only as a PHAR attached to each [GitHub Release](https://github.com/mauriziofonte/arkhive/releases), with its sha256 and a build provenance attestation.

```sh
curl -fsSLo /usr/local/bin/arkhive https://github.com/mauriziofonte/arkhive/releases/latest/download/arkhive.phar
curl -fsSL https://github.com/mauriziofonte/arkhive/releases/latest/download/arkhive.phar.sha256 \
    | sed 's|arkhive\.phar$|/usr/local/bin/arkhive|' | sha256sum -c - \
    && chmod +x /usr/local/bin/arkhive
arkhive --version
```

The `.sha256` asset names the file `arkhive.phar`; the `sed` points it at the installed path so that `sha256sum -c` checks the file you just downloaded. Optionally, with the [GitHub CLI](https://cli.github.com) logged in, verify where the PHAR was built:

```sh
gh attestation verify /usr/local/bin/arkhive --repo mauriziofonte/arkhive
```

### Updating

```sh
arkhive self-update                  # latest stable release
arkhive self-update --check          # report only: exit 0 when current, 10 when an update exists
arkhive self-update v2.0.1           # a specific release (a downgrade is allowed, with a warning)
arkhive self-update --pre            # consider pre-releases too
arkhive self-update --rollback       # put back the binary kept by the last update
arkhive self-update --allow-major    # needed to cross a major version (read the migration notes first)
```

`self-update` downloads `arkhive.phar` and `arkhive.phar.sha256` from the release and refuses a checksum mismatch. When `gh` is installed and logged in it also runs `gh attestation verify ... --repo mauriziofonte/arkhive` and refuses a mismatch; otherwise it says that provenance was not verified. The new PHAR must print the expected version (`php <new file> --version`) before it atomically replaces the running one, with the same file mode; the previous binary is kept next to it as `<path>.bak-<old version>` (only the most recent is kept) for `--rollback`. It refuses when the PHAR's directory is not writable (exit 77), when ArkHive runs from a source checkout (69), and when the release is a different major version without `--allow-major` (64). It honours `HTTPS_PROXY`; set `GITHUB_TOKEN` (no scopes needed) if you hit the GitHub API rate limit. `--json` prints the result as JSON.

## Quick start

1. Create an SSH key for the backups and authorise `/root/.ssh/arkhive.pub` on the SFTP server (for a storage box: one sub-account and one repository per server):

   ```sh
   ssh-keygen -t ed25519 -N '' -f /root/.ssh/arkhive
   ```

   Connect once by hand (`sftp -P 23 -i /root/.ssh/arkhive u123456@backup.example.com`) and accept the server's host key after checking its fingerprint: restic runs `ssh` without a terminal, so it cannot ask.

2. Write the configuration to `/etc/arkhive/config` and `chmod 600` it (it may hold database and SMTP passwords). Every 2.0 key, with the defaults the code applies when a key is absent or empty:

   ```sh
   # What to back up: absolute paths, space-separated ("double quotes" keep a path with spaces together)
   BACKUP_PATHS="/var/www /etc"

   # 1.x exclusion syntax, comma-separated (see "Exclusion patterns")
   EXCLUSION_PATTERNS="*/node_modules/*,*/vendor/*,*/.git/*,*/cache/*,*/tmp/*,*.log,*.swp"

   # The restic repository, and how to reach it
   RESTIC_REPOSITORY="sftp:u123456@backup.example.com:arkhive"
   RESTIC_SFTP_COMMAND="ssh -p 23 -i /root/.ssh/arkhive u123456@backup.example.com -s sftp"

   # The repository password, alone in a file only the running user can read (chmod 600).
   # Keep a second copy off this server: without it nobody can restore these backups.
   RESTIC_PASSWORD_FILE=/etc/arkhive/restic-password

   # Optional restic settings (defaults shown)
   #RESTIC_BINARY=restic
   # RESTIC_HOST defaults to the system hostname
   #RESTIC_HOST=
   #RESTIC_COMPRESSION=auto
   #RESTIC_KEEP_DAILY=7
   #RESTIC_KEEP_WEEKLY=4
   #RESTIC_KEEP_MONTHLY=6
   #RESTIC_CHECK_SUBSET=5%
   #LOCK_WAIT=300

   # Database dumps: one plain .sql file per database, staged here, included in the snapshot,
   # removed after each successful backup. A dedicated directory.
   DUMP_DIRECTORY=/var/backups/arkhive-dumps
   #DUMP_REQUIRED=false

   WITH_MYSQL=false
   MYSQL_HOST=localhost
   MYSQL_PORT=3306
   MYSQL_USER=backup
   MYSQL_PASSWORD=change-me
   MYSQL_DATABASES="*"

   WITH_PGSQL=false
   PGSQL_HOST=localhost
   PGSQL_PORT=5432
   PGSQL_USER=postgres
   #PGSQL_PASSWORD=
   PGSQL_DATABASES="database1 database2"
   #PGSQL_DUMP_OPTIONS="--no-owner --no-privileges"

   # E-mail notifications
   NOTIFY=false
   NOTIFY_ON=always
   SMTP_HOST=smtp.example.com
   SMTP_ENCRYPTION=tls
   # SMTP_PORT defaults to 465 for ssl, 587 for tls, 25 for none
   #SMTP_PORT=587
   SMTP_USER=smtp-user
   SMTP_PASSWORD=change-me
   SMTP_FROM=arkhive@example.com
   SMTP_TO=admin@example.com
   ```

   The repository ships the same example as `.arkhive-config.example`.

3. Check, create the repository, back up:

   ```sh
   arkhive doctor   # what is missing or wrong, with the fix
   arkhive init     # creates the repository; generates a random RESTIC_PASSWORD_FILE if it does not exist
   arkhive backup
   arkhive status
   ```

   `init` reminds you to copy the password off the host: do it now (a password manager or a safe). Then schedule the backups: [Scheduling](#scheduling).

### Where the configuration is searched

`--config <path>` uses that file and skips discovery. Otherwise the first readable file of this list wins (`./` is the current working directory, `~` the home directory of the running user):

1. `./.arkhive-config`
2. `./.config/arkhive-config`
3. `./.config/arkhive/config`
4. `$XDG_CONFIG_HOME/arkhive/config` (when `XDG_CONFIG_HOME` is set to an absolute path)
5. `~/.arkhive-config`
6. `~/.config/arkhive-config`
7. `~/.config/arkhive/config`
8. `/etc/arkhive-config`
9. `/etc/arkhive/config`

For scheduled runs, pass `--config` explicitly (`arkhive schedule` does).

## Configuration

Syntax: one `KEY=value` per line, optionally prefixed by `export`. `'single quotes'` keep everything literally; `"double quotes"` accept the escapes `\\ \" \$ \n \t`; an unquoted value ends at ` #`; lines starting with `#` are comments; there is no variable expansion. A line that cannot be parsed, an unterminated quote or a key set twice is an error naming the line. Unknown keys produce a warning listing them. Only `WITH_MYSQL`, `WITH_PGSQL` and `NOTIFY` are booleans (`true/false`, `yes/no`, `1/0`, `on/off`); every other value stays a string. All problems in a configuration are reported at once (exit 78).

| Key | Required | Default | Meaning |
|---|---|---|---|
| `BACKUP_PATHS` | yes | | Absolute paths to back up, space-separated; double quotes or a backslash keep spaces inside one path |
| `RESTIC_REPOSITORY` | yes | | The restic repository, e.g. `sftp:u123456@backup.example.com:arkhive` or a local path |
| `RESTIC_PASSWORD_FILE` | yes | | Absolute path of the repository password file. Refused if it is missing (except by `init`, which generates it), empty, not a regular file, readable by group or others, or not owned by the running user |
| `RESTIC_SFTP_COMMAND` | no | restic's own `ssh` call | Passed to restic as `-o sftp.command=...`, for a port or key, e.g. `ssh -p 23 -i /root/.ssh/arkhive u123456@backup.example.com -s sftp`. Without it ArkHive adds `ServerAliveInterval=15`, `ServerAliveCountMax=4` and `BatchMode=yes` to an `sftp:` repository; with it the command is used as written, so add those `-o` options yourself if you want them |
| `RESTIC_BINARY` | no | `restic` | A name searched in `PATH` and the standard directories, or a path; 0.11.0 or newer |
| `RESTIC_HOST` | no | system hostname | Host name recorded in the snapshots; retention, `status` and `drill` follow it |
| `RESTIC_COMPRESSION` | no | `auto` | `auto`, `max` or `off`; needs restic 0.14.0 or newer (`doctor` warns when it has no effect) |
| `RESTIC_KEEP_DAILY` | no | `7` | Daily snapshots kept by `maintain` |
| `RESTIC_KEEP_WEEKLY` | no | `4` | Weekly snapshots kept by `maintain` |
| `RESTIC_KEEP_MONTHLY` | no | `6` | Monthly snapshots kept by `maintain` |
| `RESTIC_CHECK_SUBSET` | no | `5%` | Data read back by `maintain`: a percentage (`5%`), a fraction (`1/10`) or a size (`500M`); with restic before 0.12 (percentage) or 0.14 (size) ArkHive reads the nearest fraction instead, a different one at each run |
| `LOCK_WAIT` | no | `300` | Seconds to wait for a repository locked by another ArkHive run or another restic client before giving up with exit 75; `0` gives up at once. See [Retries](#retries) |
| `EXCLUSION_PATTERNS` | no | none | Comma-separated 1.x patterns, see below |
| `DUMP_DIRECTORY` | when dumps are enabled | | Absolute path of a dedicated staging directory for the dumps (`mysql/<db>.sql`, `pgsql/<db>.sql`), backed up as a path of its own and emptied after each successful backup. It cannot be `/`, cannot be or contain a backup path, and must not be excluded by `EXCLUSION_PATTERNS` |
| `DUMP_REQUIRED` | no | `false` | A database dump that fails never stops the other dumps nor the backup of the files. `false`: the run ends with exit 3 and names the failed dumps; `true`: the run fails with that dump's exit code (69 server down, 77 credentials, 66 unknown database) and a failure notification |
| `WITH_MYSQL` | no | `false` | Dump MySQL/MariaDB databases |
| `MYSQL_HOST` | no | `localhost` | |
| `MYSQL_PORT` | no | `3306` | |
| `MYSQL_USER` | with `WITH_MYSQL` | | |
| `MYSQL_PASSWORD` | no | | Passed through a temporary `--defaults-extra-file` (0600), never on a command line |
| `MYSQL_DATABASES` | with `WITH_MYSQL` | | Space-separated names; `*` = every database except `information_schema`, `performance_schema`, `sys` and `mysql` (a system schema listed by name next to `*` is kept) |
| `WITH_PGSQL` | no | `false` | Dump PostgreSQL databases |
| `PGSQL_HOST` | no | `localhost` | |
| `PGSQL_PORT` | no | `5432` | |
| `PGSQL_USER` | with `WITH_PGSQL` | | |
| `PGSQL_PASSWORD` | no | | Passed through a temporary `PGPASSFILE` (0600) |
| `PGSQL_DATABASES` | with `WITH_PGSQL` | | Space-separated names (every one is dumped; `*` is not supported for PostgreSQL) |
| `PGSQL_DUMP_OPTIONS` | no | `--no-owner --no-privileges` | Extra `pg_dump` options, long form only (`--name` or `--name=value`) |
| `NOTIFY` | no | `false` | Send an e-mail after `backup`, `maintain` and `drill` |
| `NOTIFY_ON` | no | `always` | `always`, or `failure`: only when a run fails or ends with warnings |
| `SMTP_HOST` | with `NOTIFY` | | |
| `SMTP_ENCRYPTION` | no | `tls` | `ssl` (implicit TLS), `tls` (STARTTLS) or `none` |
| `SMTP_PORT` | no | `465` / `587` / `25` | Default follows `SMTP_ENCRYPTION` (`ssl` / `tls` / `none`) |
| `SMTP_USER`, `SMTP_PASSWORD` | no | | Leave both empty for an unauthenticated relay; the mechanism is negotiated automatically |
| `SMTP_FROM` | with `NOTIFY` | | A valid e-mail address |
| `SMTP_TO` | with `NOTIFY` | | One or more addresses, comma-separated |

A notification failure never turns a successful run into a failure: it is logged and the exit code becomes 3. A temporary refusal (a 4xx answer such as greylisting) or a server that cannot be reached is tried twice more first, about 15 and 45 seconds later.

### Exclusion patterns

`EXCLUSION_PATTERNS` keeps the 1.x syntax (comma-separated, `*` as wildcard). Each pattern is converted to a restic pattern anchored below **each** backup path, so it excludes exactly what 1.x excluded, and written to a temporary exclude file (0600) removed after the run:

- a leading `/` anchors the pattern to the backup path: `/logs/*` becomes `<path>/logs/*`;
- otherwise a leading `*/` and a trailing `/*` are dropped and the rest matches at any depth below the backup path: `<path>/**/<rest>`.

| 1.x pattern | Excludes | restic pattern, with `BACKUP_PATHS=/var/www` |
|---|---|---|
| `/logs/*` | the contents of `logs` directly in the backup path | `/var/www/logs/*` |
| `*.log` | files named `*.log` anywhere | `/var/www/**/*.log` |
| `node_modules/*` | any `node_modules` directory | `/var/www/**/node_modules` |
| `*/vendor/*` | any `vendor` directory | `/var/www/**/vendor` |
| `npm-debug.log` | files with that name anywhere | `/var/www/**/npm-debug.log` |

The anchoring matters: a bare restic pattern such as `tmp` matches every component of the absolute path, including directories above the backup path, so a backup path like `/var/tmp/app` would be excluded entirely. ArkHive never produces such a pattern. A consequence to keep in mind: with `BACKUP_PATHS=/var`, the pattern `*/backups/*` excludes `/var/backups/...`, so a `DUMP_DIRECTORY` there is refused at load time.

## Commands

Every command accepts:

| Option | Meaning |
|---|---|
| `-n`, `--no-interaction` | Force unattended mode |
| `--interactive` | Force interactive mode (refused without a terminal on stdin) |
| `--log-format text\|json` | `json`: one JSON object per log event |
| `--log-file <path>` | Also append the log lines to this file, rotated by size |
| `-v`, `-vv`, `-vvv` | Also show debug events |

Commands that read the configuration accept `--config <path>`. The commands that only read the repository (`restore`, `ls`, `find`, `mount`, `status`, `drill`, `doctor`) also work on a host with no configuration at all:

| Option | Meaning |
|---|---|
| `--repo <repository>` | The repository to use instead of the configuration |
| `--password-file <file>` | Its password file (required with `--repo`; same permission rules as `RESTIC_PASSWORD_FILE`) |
| `--sftp-command <cmd>` | The SSH command for an `sftp:` repository |

`--password-file` and `--sftp-command` are accepted only together with `--repo`. With `--repo`, an explicit `--config` still provides the database connections (for `restore --import-as` and `drill --db`).

Log events go to stderr; command results (tables, `--json` output, a file restored with `--to-stdout`) go to stdout.

### backup

```sh
arkhive backup [--json]
```

Preflight (restic binary and version, password file, repository reachable, free space for the dumps), the repository lock, the database dumps (a database that fails is named and skipped, the others and the files are backed up: see `DUMP_REQUIRED`; only a full dump filesystem stops the run before any snapshot), `restic backup` of `BACKUP_PATHS` and `DUMP_DIRECTORY` with tag `arkhive`, the summary (snapshot id, data added, new/changed/unmodified files, duration), the notification, then the dump directory is emptied. If restic could not read some files the snapshot is still saved, the unreadable files are listed and the exit code is 3. A second backup of the same repository while one is running waits for it, up to `LOCK_WAIT` seconds (5 minutes by default), then exits 75. `--json` prints the summary, the dumps, the failed dumps and the warnings. The 1.x options `--with-progress` and `--with-disk-space-check` are accepted and ignored with a notice: progress is automatic in a terminal and the dump space is always checked.

### restore

```sh
arkhive restore                                   # in a terminal: the guided restore
arkhive restore --snapshot latest --target /root/restore-www --include /var/www/shop --yes
arkhive restore --snapshot 1a2b3c4d --to-stdout /etc/nginx/nginx.conf > nginx.conf
arkhive restore --snapshot latest --db shop --import-as shop_restored --yes
arkhive restore --snapshot latest --include /var/www/shop --in-place --force --yes
```

**Guided restore.** In a terminal, `arkhive restore` without `--snapshot` asks every choice not given as a flag; Enter takes the default:

1. Repository check: reachable, password valid, locks. A stale lock is explained with the exact `restic unlock` command to run; ArkHive never removes a lock itself.
2. Snapshot: the host first if the repository holds several, then a table newest first (date, age, host, size, tags, paths); pick by number or id. Default: the latest backup.
3. What: everything, pick files and folders (browse and search), one database dump (when the snapshot has some), or a single file to stdout.
4. Where: default a new directory `./arkhive-restore-<host>-<snapshot id>-<YYYYmmdd-HHMMSS>`, never the original location. A non-empty directory needs the word `overwrite` typed.
5. Pre-flight: the size of the selection against the free space of the target; not enough space is refused with both numbers.
6. Summary (snapshot, selection, target, size, owners) and confirmation, then the restore with progress, verified with `restic restore --verify`.
7. Report and next step: the exact `rsync` command to put the files in place, or the command to import a dump.

**Unattended restore.** Without a terminal the wizard never starts and a missing required choice is exit 64 naming its flag:

| Option | Meaning |
|---|---|
| `--snapshot <id\|latest>` | Required. An id or a unique prefix of one; `latest` is the newest backup (safety snapshots are chosen only by id). When the repository holds several hosts, `latest` needs `--host` unless this machine's hostname is one of them |
| `--host <name>` | Only snapshots of this host |
| `--target <dir>` | Required for files: a new or empty directory. Files are restored under their original absolute paths inside it (`<dir>/var/www/...`) |
| `--include <path>` | Restore only this path (repeatable); none = everything |
| `--exclude <pattern>` | Leave out what matches (repeatable) |
| `--db <name>` | Only this database dump (`name`, or `mysql/name`, `pgsql/name` when both engines have one) |
| `--import-as <new db>` | With `--db`: import the dump into this new database instead of writing a file (1-63 letters, digits, `_` or `-`). Needs the database connection settings of a configuration; an existing database is refused unless `--force` |
| `--to-stdout <path>` | Write this single file (absolute path in the snapshot) to stdout; no `--yes` needed |
| `--in-place` | Restore over the original paths. Needs `--force`; a safety snapshot (tag `pre-restore`) of what will be overwritten is taken first and its id printed, so `arkhive restore --snapshot <safety id> --in-place --force` undoes it |
| `--force` | Restore into a non-empty target, in place, or import into an existing database |
| `--owner preserve\|current` | `preserve` (default as root) restores the recorded owners; `current` (default otherwise) leaves the files to the running user, with a warning |
| `--yes` | The final confirmation (required unattended) |
| `--json` | The result as JSON (not with `--to-stdout`) |

`--include/--exclude`, `--db` and `--to-stdout` are alternative kinds of selection; `--in-place` cannot be combined with `--target`, `--db` or `--to-stdout`. A non-empty target without `--force` and insufficient space are refused with exit 73, `--in-place` without `--force` with exit 64, and nothing is written. On a host without configuration add `--repo`, `--password-file` and `--sftp-command` ([My server is gone](#my-server-is-gone)). The 1.x positional destination is refused with a pointer to `--target`.

### ls

```sh
arkhive ls [<snapshot>] [<path>] [--host <name>] [--recursive] [--json]
arkhive ls latest /var/www
```

Lists a directory of a snapshot (mode, size, modification time). `<snapshot>` defaults to `latest`; `<path>` is absolute, as backed up, and defaults to the backed-up paths. `--json` prints one object per entry.

### find

```sh
arkhive find <pattern> [--host <name>] [--json]
arkhive find 'wp-config.php'
arkhive find '*.conf'
```

Which snapshots contain a file and how it changed: for every matching path, its versions newest first (snapshot, date, host, size, modification time, and whether it changed from the older one), to answer "when did this file last look right?". The pattern matches file names; a pattern containing `/` matches whole paths.

### mount

```sh
arkhive mount <empty directory> [--host <name>]
```

Mounts the repository read-only with FUSE (`restic mount`): snapshots appear under `<dir>/snapshots`, `ids/`, `hosts/` and `tags/`. Runs until Ctrl-C. Without FUSE (`/dev/fuse` and `fusermount3`/`fusermount`) it says so and points to `ls`, `find` and `restore --include`; in a container FUSE needs `--device /dev/fuse`.

### maintain

```sh
arkhive maintain [--json]
```

Applies the retention policy to this host's `arkhive` snapshots (`restic forget --prune` with `RESTIC_KEEP_DAILY/WEEKLY/MONTHLY`; safety snapshots are never selected), then `restic check`, then `restic check --read-data-subset RESTIC_CHECK_SUBSET`. Holds the repository lock, so it never overlaps a backup. Errors found by the check are exit 65 with the repair hint; the notification reports kept and removed snapshots, the check results and the repository size.

### status

```sh
arkhive status [--host <name>] [--max-age <hours>] [--json]
arkhive status --max-age 26     # monitoring probe for a daily backup
```

The latest backup of this host (`RESTIC_HOST`, or `--host`), its age, the snapshot count and the repository size on disk. With `--max-age`, exit 1 when there is no snapshot or the latest is older than that many hours. It also shows how the last `backup`, `maintain` and `drill` of this host ended, and when a last run failed, the last one that succeeded. `--json` fields: `repository`, `host`, `latest` (`id`, `short_id`, `time`, `age_hours`), `snapshots`, `snapshots_total`, `hosts`, `raw_size`, `uncompressed_size`, and `runs`: for each of `backup`, `maintain` and `drill`, `last` and `last_success`, each `null` or an object with `job`, `started`, `ended`, `duration_seconds`, `exit_code`, `status` (`ok`, `warning`, `failed`), `snapshot_id`, `warnings` and `error`.

Each `backup`, `maintain` and `drill` records how it ended in the state directory, one JSON file per job, replaced atomically: `$STATE_DIRECTORY` under systemd (`StateDirectory=arkhive`), otherwise `/var/lib/arkhive` as root and `$XDG_STATE_HOME/arkhive` (or `~/.local/state/arkhive`) for other users. A state directory that cannot be written is a warning, never a failure of the run.

Under systemd with `CacheDirectory=arkhive`, restic keeps its cache in `$CACHE_DIRECTORY/restic` (so a unit with `ProtectHome=` still has one); an exported `RESTIC_CACHE_DIR` wins, and elsewhere restic uses its own default (`~/.cache/restic`).

### init

```sh
arkhive init [--json]
```

Creates the repository of the configuration. If `RESTIC_PASSWORD_FILE` does not exist, it generates a random password into it (mode 0600; its directory must exist). If the repository already exists and the password opens it, there is nothing to do (exit 0); a generated password never replaces the one of an existing repository. Afterwards, copy the password file off the host.

### drill

```sh
arkhive drill [--sample <n>] [--db] [--host <name>] [--json]
```

A restore test: restores `--sample` random files (default 50, at most 100000) of the latest snapshot into a private temporary directory, verifies them against the repository, with `--db` also restores one database dump and imports it into a throw-away database, removes everything and reports PASSED (exit 0) or FAILED (exit 65). Scheduled weekly by `arkhive schedule`, so restores are proven, not assumed.

### doctor

```sh
arkhive doctor [--json]
arkhive doctor --repo <repository> --password-file <file> [--sftp-command <cmd>]
```

Checks, in order, and says how to fix each problem: PHP version and extensions, distribution and package manager, restic binary and version, the configuration used (or why none was found), removed 1.x keys, password file permissions, SSH command and key, repository reachability and locks, the latest snapshot's age (warning beyond 26 hours), the last recorded backup, maintain and drill (warning when the last one failed), free space for the dumps, clock skew against the repository, the execution context detected, SELinux. It never changes anything. Exit 0 when all is fine, 3 with warnings only, 1 when a check failed.

### schedule

```sh
arkhive schedule --systemd [--at HH:MM]
arkhive schedule --cron [--at HH:MM]
```

Prints (never installs) the systemd units or the cron lines that run `backup` daily at `--at` (default `02:30`), `maintain` on Sundays and `drill` on Wednesdays three hours later, for the current configuration, user and ArkHive path. See [Scheduling](#scheduling).

### decrypt-legacy

```sh
arkhive decrypt-legacy <file> [--to <dir>] [--password-file <file>] [--json]
```

Decrypts and extracts an ArkHive 1.x archive (`.arbk`, `.arbk.xz` or `.tar`, with `.enc` before the suffix when encrypted; gzip, xz or plain tar). See [Migrating from 1.x](#old-1x-archives).

### self-update

```sh
arkhive self-update [<release>] [--check] [--pre] [--rollback] [--allow-major] [--json]
```

See [Updating](#updating). The 1.x name `self-upgrade` still works, with a deprecation warning.

## Interactive and unattended mode

ArkHive decides once, at startup, how it is running:

| Signal | Mode |
|---|---|
| `--interactive` | interactive (refused if stdin is not a terminal; cannot be combined with `-n`) |
| `-n` / `--no-interaction` | unattended |
| `ARKHIVE_NONINTERACTIVE=1` (also `true`, `yes`, `on`) | unattended |
| `INVOCATION_ID` or `JOURNAL_STREAM` set (systemd) | unattended, journald log format |
| `CI` set | unattended |
| stdin and stdout are terminals and `TERM` is not `dumb` | interactive |
| anything else (cron, scripts, pipes) | unattended |

| Behaviour | Interactive | Unattended |
|---|---|---|
| Prompts, wizard | yes | never: a missing answer is exit 64 with `... is required in unattended mode: pass --<flag>` |
| Colours, progress | yes (no colour with `NO_COLOR` set or `TERM=dumb`) | never |
| Destructive confirmations | typed (`overwrite`) or asked | only through `--yes` / `--force` as documented per command |
| Log format | human sentences | one line per event |

The same events in each log format:

```text
# unattended (cron, scripts): ISO 8601 time, level, message
2026-10-14T02:30:05+02:00 success Snapshot 1a2b3c4d saved: 812.40 MiB added (...)
# under systemd: no time (journald adds it), syslog priority prefix
<6>Snapshot 1a2b3c4d saved: 812.40 MiB added (...)
# --log-format json, in any mode: one object per event, with structured fields
{"time":"2026-10-14T02:30:05+02:00","level":"success","message":"Snapshot 1a2b3c4d saved: ...","snapshot_id":"1a2b3c4d...","data_added":851863552,...}
```

Levels and their journald priorities: `error` `<3>`, `warning` `<4>`, `notice` `<5>`, `info` and `success` `<6>`, `debug` `<7>` (debug only with `-v`), so `journalctl -p warning -u arkhive-backup.service` shows the problems. `--log-file <path>` appends the plain format (JSON with `--log-format json`) in every mode; at 10 MiB the file is rotated to `<path>.1` ... `<path>.5`, the oldest dropped.

SIGINT and SIGTERM during a run stop restic cleanly, remove temporary files and the dump staging, release the lock and exit 130 or 143.

## Scheduling

`arkhive schedule` prints the schedule for the configuration it loads; save what it prints:

```sh
arkhive schedule --systemd --config /etc/arkhive/config
arkhive schedule --cron --at 01:00 --config /etc/arkhive/config
```

- **systemd**: six units (`arkhive-backup`, `arkhive-maintain`, `arkhive-drill`, each a `.service` and a `.timer`) with `Nice=10`, `IOSchedulingClass=idle`, `TimeoutStartSec=` and `Persistent=true` timers. Save each under `/etc/systemd/system/`, then `systemctl daemon-reload && systemctl enable --now arkhive-backup.timer arkhive-maintain.timer arkhive-drill.timer`. Logs: `journalctl -u arkhive-backup.service`; timers: `systemctl list-timers 'arkhive-*'`.
- **cron**: lines for `/etc/cron.d/arkhive` (root:root, 0644) or `crontab -e` for another user, logging with `--log-file` (`/var/log/arkhive.log` for root, `$XDG_STATE_HOME/arkhive/arkhive.log` or `~/.local/state/arkhive/arkhive.log` otherwise).

[`contrib/`](contrib/) holds what the command prints for `/usr/local/bin/arkhive` with `/etc/arkhive/config`, run as root, backup at 02:30: `contrib/systemd/*` and `contrib/cron/arkhive`. For any other path, user or time, generate your own rather than editing them.

A scheduled run that fails must not go unnoticed. Either set `NOTIFY=true` (with `NOTIFY_ON=failure` for failures and warnings only) and the SMTP keys, or point an external monitor at `arkhive status --max-age 26 --config /etc/arkhive/config`, which exits 1 when the latest backup is more than 26 hours old or missing. `schedule` warns when no notification is configured.

## Exit codes

ArkHive follows the `sysexits.h` convention, so scripts and systemd can tell a configuration mistake from a server that is down or a lock held by another run: `systemctl status arkhive-backup` shows the name (`status=75/TEMPFAIL`).

| Code | Name | Meaning |
|---|---|---|
| 0 | OK | Success |
| 1 | FAILURE | Failure not classified below (a restic or dump error without a more precise cause), `status --max-age` exceeded, `doctor` with a failed check, `find` with no match, a confirmation the operator declined |
| 3 | WARNING | Completed with warnings: unreadable files in a backup (the snapshot is saved), a notification that could not be sent, `doctor` warnings |
| 10 | UPDATE | `self-update --check`: an update is available |
| 64 | USAGE | Bad option or argument, a required answer missing in unattended mode, `--in-place` without `--force`, `self-update` across a major version without `--allow-major` |
| 65 | DATAERR | Data verified and found wrong: `maintain` check errors, a failed `drill`, a damaged 1.x archive, a download that does not match its checksum |
| 66 | NOINPUT | Something named does not exist: configuration file, password file, snapshot, path in a snapshot, dump, database, release |
| 69 | UNAVAILABLE | A service or dependency is unavailable: repository unreachable or missing, restic or a dump tool missing or too old, database server down, PHP too old or missing an extension |
| 70 | SOFTWARE | Internal error, or output of restic that ArkHive cannot understand |
| 71 | OSERR | The operating system refused to start a process |
| 73 | CANTCREAT | An output cannot be created: non-empty restore target, not enough space, an existing database for `--import-as`, an unwritable log file |
| 74 | IOERR | A write failed because a filesystem is full (repository, dump staging, extraction) |
| 75 | TEMPFAIL | Temporary, retry later: another ArkHive run or restic holds the repository lock, a timeout, the GitHub API rate limit |
| 76 | PROTOCOL | The releases API answered something that is not a valid release |
| 77 | NOPERM | Wrong repository or archive password, database credentials refused, permission denied, a password file readable by others or owned by another user |
| 78 | CONFIG | Configuration missing or invalid, including removed 1.x keys |
| 130 | SIGINT | Interrupted by SIGINT (cleanup done, lock released) |
| 143 | SIGTERM | Interrupted by SIGTERM (cleanup done, lock released) |

64, 65, 66, 77 and 78 cannot succeed by running again unchanged; the systemd units list them in `RestartPreventExitStatus`. 2.0.0-rc.1 used 2 for usage and configuration errors and 4 for refusals: they are now 64 or 78, and 73 or 64.

### Retries

Failures that may pass on their own are tried again within the same run, each retry logged as a warning with its reason and the wait; when the last try fails the exit code is that failure's own (75, 69).

- A repository locked by another ArkHive run or another restic client (another host's backup, a `prune`): tried again after 5, 10, 20 seconds, then every minute, until `LOCK_WAIT` seconds have passed (300 by default; `0` fails at once). ArkHive never removes a lock.
- A repository that cannot be reached (connection refused or lost, name resolution, a timeout): three tries, about 5 and 15 seconds apart. A refused SSH key or an unknown host key is not tried again.
- The notification e-mail, refused with a 4xx answer (greylisting, a rate limit) or not delivered for a network error: three tries, about 15 and 45 seconds apart. Its failure only turns the exit code into 3.

A wrong password, a missing repository, a configuration error or a damaged repository are never tried again. Each wait ends at once on SIGINT or SIGTERM.

## My server is gone

A fresh host, no ArkHive configuration, the repository still on the SFTP server. You need the repository password and the SSH key from your safe copy (password manager).

1. Install PHP and restic as in [Requirements](#requirements), then the PHAR as in [Install](#install).
2. Put back the SSH key and the repository password without ever typing them on a command line. Paste each from the password manager, then Enter and Ctrl-D: `umask 077; mkdir -p /root/.ssh; cat > /root/.ssh/arkhive` and `umask 077; cat > /root/restic-password`.
3. Trust the server's host key once, after checking its fingerprint: `sftp -P 23 -i /root/.ssh/arkhive u123456@backup.example.com`.
4. Set the four variables for your repository, then run the steps below.

```sh
REPO='sftp:u123456@backup.example.com:arkhive'
SSH='ssh -p 23 -i /root/.ssh/arkhive u123456@backup.example.com -s sftp'
PW=/root/restic-password
TARGET=/root/restored
```

<!-- tested: server-gone -->
```sh
arkhive doctor --repo "$REPO" --password-file "$PW" --sftp-command "$SSH"
arkhive ls --repo "$REPO" --password-file "$PW" --sftp-command "$SSH" latest
arkhive restore --repo "$REPO" --password-file "$PW" --sftp-command "$SSH" --snapshot latest --target "$TARGET" --yes
```

`doctor` must show no `FAIL` line: it names what is missing (restic, ssh, the key, the password file permissions) with the install command for this distribution. Its exit code is 3 rather than 0 when only warnings remain, typically "the latest snapshot is older than 26 hours", which is expected after a disaster. `ls` shows what the latest snapshot holds. `restore` brings it back under `$TARGET`, each file at its original absolute path (`$TARGET/var/www/...`), verified, with the original owners when run as root. If the repository holds snapshots of several hosts, add `--host <old hostname>` to `ls` and `restore`.

In a terminal you can use the guided restore instead and pick the snapshot, the folders and the target interactively:

```sh
arkhive restore --repo "$REPO" --password-file "$PW" --sftp-command "$SSH"
```

**Databases.** A full restore already contains the dumps of that backup under the restored `DUMP_DIRECTORY`, one plain file per database (`$TARGET/var/backups/arkhive-dumps/mysql/<db>.sql`, `.../pgsql/<db>.sql`). To restore a single dump: `arkhive restore --repo "$REPO" --password-file "$PW" --sftp-command "$SSH" --snapshot latest --db <db> --target /root/restored-db --yes`; the report prints the command to import it by hand. To import directly, ArkHive needs the database connection settings: once the database server runs and the configuration is back (next step), `arkhive restore --config /etc/arkhive/config --snapshot latest --db <db> --import-as <db> --yes` creates the database and imports the dump (an existing database is refused unless `--force`).

**Put the files back** after reviewing them, path by path, as the restore report suggests:

```sh
rsync -aHAX --numeric-ids "$TARGET/var/www/" /var/www/
rsync -aHAX --numeric-ids "$TARGET/etc/nginx/" /etc/nginx/
```

**Recreate the configuration** at `/etc/arkhive/config` (`/etc/arkhive/config` itself was restored under `$TARGET` if `/etc` was a backup path), with the same `RESTIC_REPOSITORY`, `RESTIC_SFTP_COMMAND` and a `RESTIC_PASSWORD_FILE` pointing at the password file. If the new server has a different hostname, set `RESTIC_HOST` to the old one so that retention, `status` and `drill` keep following the same series of snapshots. Do **not** run `arkhive init`: the repository already exists. Then `arkhive doctor`, `arkhive backup`, and `arkhive schedule --systemd` (or `--cron`) to put the backups back on a timer.

## Migrating from 1.x

### What changed and why

ArkHive 1.x built `tar | gzip/xz | openssl enc -aes-256-cbc` and streamed one full archive over SSH every day. The random salt and CBC chaining make two consecutive archives share nothing, so every run uploaded the whole data set (tens of GB a day for a few hundred MB of real change) and no deduplicating backend could help. 2.0 keeps the configuration, the dumps, the exclusions, the locking, the notifications and the interactive restore, and hands transport, deduplication, compression, encryption and retention to restic, which receives plain input (file trees and uncompressed per-database dumps) so that deduplication works.

- The **first** 2.0 backup uploads everything (a full upload); every backup after it uploads only new and changed data.
- Install **restic** 0.11 or newer ([Requirements](#requirements)). `tar`, `gzip`, `xz`, `openssl`, `scp`, `pv`, `mkfifo` and `tee` are no longer needed (`openssl`, `tar`, `xz` only for `decrypt-legacy`).
- PHP 8.3 or newer is required.
- `restore` is rewritten ([restore](#restore)): its destination is `--target`, not an argument. `backup --with-progress` and `--with-disk-space-check` are no longer needed (accepted, ignored).
- PostgreSQL: every database in `PGSQL_DATABASES` is dumped (1.x dumped only the first), in plain format. MySQL: `*` means every non-system database.
- `self-upgrade` is now `self-update`.

### Removed keys

A configuration that still contains any of these keys is refused (exit 78) with a message listing each key, its replacement and a link to this section. There is no silent fallback.

| Removed key | Replaced by |
|---|---|
| `SSH_HOST` | `RESTIC_REPOSITORY` (e.g. `sftp:user@host:/restic`), plus `RESTIC_SFTP_COMMAND` for port and key |
| `SSH_PORT` | `RESTIC_SFTP_COMMAND` (e.g. `ssh -i /root/.ssh/key -p 23 user@host -s sftp`) |
| `SSH_USER` | `RESTIC_REPOSITORY` (e.g. `sftp:user@host:/restic`) |
| `SSH_BACKUP_HOME` | `RESTIC_REPOSITORY` (the path after the host, e.g. `sftp:user@host:/restic`) |
| `WITH_CRYPT` | `RESTIC_PASSWORD_FILE` (restic always encrypts) |
| `CRYPT_PASSWORD` | `RESTIC_PASSWORD_FILE`; the 1.x password is used only by `arkhive decrypt-legacy --password-file <f>` |
| `COMPRESSION_TYPE` | `RESTIC_COMPRESSION` (`auto`, `max` or `off`) |
| `BACKUP_RETENTION_DAYS` | `RESTIC_KEEP_DAILY`, `RESTIC_KEEP_WEEKLY`, `RESTIC_KEEP_MONTHLY` (applied by `arkhive maintain`) |
| `BACKUP_DIRECTORY` | `BACKUP_PATHS` (rename the key: one or more absolute paths, space-separated) |
| `SMTP_SSL` | `SMTP_ENCRYPTION` (`ssl`, `tls` or `none`) |
| `SMTP_AUTH` | nothing: authentication is negotiated automatically when `SMTP_USER` and `SMTP_PASSWORD` are set |

### Key mapping

| 1.x | 2.0 |
|---|---|
| `SSH_USER=u123456`, `SSH_HOST=backup.example.com`, `SSH_BACKUP_HOME=/home/backups` | `RESTIC_REPOSITORY="sftp:u123456@backup.example.com:/home/arkhive-restic"` (a new directory: keep the 1.x archives apart) |
| `SSH_PORT=23` | `RESTIC_SFTP_COMMAND="ssh -p 23 -i /root/.ssh/arkhive u123456@backup.example.com -s sftp"` |
| `WITH_CRYPT=true`, `CRYPT_PASSWORD=...` | `RESTIC_PASSWORD_FILE=/etc/arkhive/restic-password` (a new password; `arkhive init` can generate it). Keep the 1.x password for `decrypt-legacy` |
| `COMPRESSION_TYPE=gzip\|xz\|none` | `RESTIC_COMPRESSION=auto\|max\|off` |
| `BACKUP_RETENTION_DAYS=10` | `RESTIC_KEEP_DAILY=10` (plus `_WEEKLY`, `_MONTHLY`) |
| `BACKUP_DIRECTORY=/home/user` | `BACKUP_PATHS=/home/user` |
| dumps written into `BACKUP_DIRECTORY` | `DUMP_DIRECTORY=/var/backups/arkhive-dumps` (required with `WITH_MYSQL`/`WITH_PGSQL`) |
| `SMTP_SSL=true` | `SMTP_ENCRYPTION=ssl` |
| `SMTP_AUTH=login` | delete the line |
| unchanged | `EXCLUSION_PATTERNS`, `WITH_MYSQL`, `MYSQL_*`, `WITH_PGSQL`, `PGSQL_HOST`, `PGSQL_USER`, `PGSQL_DATABASES`, `NOTIFY`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_FROM`, `SMTP_TO` |
| new, optional | `PGSQL_PORT`, `PGSQL_PASSWORD`, `PGSQL_DUMP_OPTIONS`, `NOTIFY_ON`, `RESTIC_BINARY`, `RESTIC_HOST`, `RESTIC_KEEP_*`, `RESTIC_CHECK_SUBSET` |

Then: `arkhive doctor` (it lists any removed key left), `arkhive init`, `arkhive backup`, and replace the 1.x cron line with `arkhive schedule`.

### Upgrading the binary

A 1.x binary cannot upgrade itself to 2.0: 1.x `self-upgrade` downloads `builds/arkhive.phar` from the `main` branch, and `builds/` no longer exists there, so it fails without changing anything. Install 2.0 by downloading the PHAR as in [Install](#install) (over the 1.x file). From 2.x on, updates use `arkhive self-update`; crossing a future major version needs `arkhive self-update --allow-major`, and `self-update --check` says when the update is a new major.

Keep a copy of the 1.x configuration before converting it: 2.0 refuses a file with 1.x keys, and 1.x does not understand 2.0 keys.

### Rolling back to 1.7.1

From 2.x:

```sh
arkhive self-update v1.7.1 --allow-major
```

This installs the 1.7.1 release (verified like any update) and keeps the 2.x binary as `/usr/local/bin/arkhive.bak-<version>`. Put the 1.x configuration back. To return to 2.x later, download the PHAR again as in [Install](#install) (1.x has no `self-update`).

### Old 1.x archives

The 1.x archives on the old SSH destination are not converted; keep them until your restic retention covers the same period. To open one, copy it to the host and extract it:

```sh
umask 077; cat > /root/arkhive-1x-password      # paste the 1.x CRYPT_PASSWORD, Enter, Ctrl-D
arkhive decrypt-legacy 2025-04-11-user-arkhive.enc.arbk --to /root/legacy-2025-04-11 --password-file /root/arkhive-1x-password
```

| Option | Meaning |
|---|---|
| `<file>` | The 1.x archive: `<date>-<user>-arkhive[.enc].arbk`, `.arbk.xz` or `.tar` (`.enc` = encrypted); gzip, xz and plain tar are detected |
| `--to <dir>` | A new or empty directory to extract into (default: `./<archive name without the suffixes>`) |
| `--password-file <file>` | File holding the 1.x `CRYPT_PASSWORD` (mode 0600, owned by you). Default: the environment variable `ARKHIVE_CRYPT_PASSWORD`. Never read from the main configuration |
| `--json` | The result as JSON |

The password reaches `openssl` through the environment, never a command line. Files are extracted with the paths they had on the 1.x host; the database dumps are `.sql` files among them.

## Development

Requires PHP 8.3+ (CI runs 8.3 and 8.4), Composer, and restic in `PATH` for the PHPUnit suite (`tests/bin/install-restic.sh /usr/local/bin/restic` installs the pinned, checksum-verified release).

```sh
composer install
vendor/bin/phpunit                                    # unit and feature tests
sudo -E tests/e2e/run.sh                              # end-to-end, Docker: SFTP, MariaDB, PostgreSQL, fake releases server
vendor/bin/php-cs-fixer check --diff                  # coding standard (PSR-12)
vendor/bin/phpstan analyse --memory-limit=1G          # static analysis, level 8
vendor/bin/phpunit --coverage-clover build/coverage/clover.xml && php tests/bin/coverage-gate.php build/coverage/clover.xml 90
vendor/bin/infection --threads=max --show-mutations=0 # mutation testing, MSI >= 80%
tests/bin/build-phar.sh build/arkhive.phar            # build the PHAR locally (box), then: php build/arkhive.phar --version
```

The e2e suite builds the PHAR, generates a throw-away SSH key and repository password, starts the services with `docker compose` and tears them down; nothing touches a real host or the real GitHub API. CI runs it as root so the ownership cases run (`E2E_FILTER` narrows it to some tests). The distribution matrix (`.github/workflows/distro.yml`) runs the built PHAR in a container of every supported distribution, set up with `tests/distro/setup.sh`.

Releases: the PHAR is never committed. When the maintainer pushes a `v*` tag, `.github/workflows/release.yml` checks that the tag equals the version in `config/app.php`, runs the full CI, builds the PHAR, checks that it prints the tag, writes `arkhive.phar.sha256`, runs the distribution matrix on amd64 and arm64, attests the build provenance and publishes the GitHub Release with the notes of that version from `CHANGELOG.md`. Tags with a `-` (e.g. `v2.0.0-rc.1`) are published as pre-releases.

## License

MIT License. See [LICENSE](LICENSE).
