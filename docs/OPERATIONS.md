# ArkHive: operating notes

What an operator should know to run ArkHive safely, beyond the command reference in the README.

## Repository damage: what detects it and when

A backup cannot tell that the repository lost data. restic trusts its index (and its local cache of
it) and deduplicates against the blobs the index lists, so after pack files disappear on the server
(deleted by hand, a failing disk, a rollback of the storage) `arkhive backup` still ends with exit 0
and a new snapshot that may reference the missing data. Only two commands look at the data itself:

| Command | What it reads | Detects |
|---|---|---|
| `arkhive drill` | a random sample of the latest snapshot, restored and verified | missing or damaged data in the files sampled |
| `arkhive maintain` | `restic check` (every index, pack list, snapshot and tree) and `--read-data-subset` | missing pack files, missing trees, damaged packs in the subset read |

Both exit 65 (`DATAERR`) on damage, with a hint that lists restic's repair commands in the order of
its [troubleshooting guide](https://restic.readthedocs.io/en/stable/077_troubleshooting.html):
`restic repair packs <ids>` for damaged packs, `restic repair index` for missing ones, then a new
`arkhive backup` (it uploads again whatever is still on the host, which can heal older snapshots),
then `restic repair snapshots --forget`, then `arkhive maintain` to confirm. ArkHive never runs any of
them on its own. When `forget --prune` refuses to run because packs are missing, `maintain` still
runs the check so the report names everything wrong, and exits 65.

`restore` (and `ls`, `find`, `stats`) on missing data also exits 65 with the same hint, instead of 1.

Between a failed check and the next `maintain` that passes, every `backup` ends with exit 3
(`WARNING`) and says that the repository was found damaged, so that the damage stays visible in the
notifications and the journal instead of being followed by "Last backup OK".

Recommended:

- run `drill` daily and `maintain` weekly. `arkhive schedule` installs `maintain` on Sunday and
  `drill` on Wednesday; to run the drill every day, add a drop-in that `schedule --install` leaves
  alone:

  ```sh
  systemctl edit arkhive-drill.timer
  # [Timer]
  # OnCalendar=
  # OnCalendar=*-*-* 06:30:00
  ```

  A drill restores 50 files by default, so a daily one costs little bandwidth;
- alert on any non-zero exit of `backup`, `maintain` and `drill`: `NOTIFY=true` with
  `NOTIFY_ON=failure` (exit 3 counts as a warning and is reported), or the `OnFailure=` unit the
  schedule installs, or an external monitor reading `arkhive status --json`.

## Deleted snapshots and rollbacks

Whoever can write to the repository server can delete snapshots (`restic forget`, or removing files
under `snapshots/`), and a storage restored from an older copy (a ZFS or VM snapshot rolled back)
loses the newest backups. In both cases restic finds a healthy repository with fewer snapshots:
`check` passes, and a new backup succeeds.

Every successful `backup` records its full snapshot id for its repository in the state directory.
`arkhive status` and `arkhive drill` look for it in the repository:

| The recorded snapshot | Result |
|---|---|
| is there | fine (`continuity.status` `ok`) |
| is gone, a newer backup of the host is there | fine (`superseded`): retention may remove the recorded one once a newer exists |
| is gone, nothing newer of the host | exit 65 (`missing`): deleted or rolled back |

The message names the snapshot, when it was recorded, and the newest backup of the host left
(`continuity.newest_older` in `status --json`). A drill then restores nothing: an older snapshot
would pass. Find out who can delete snapshots on the server and whether the storage was restored;
then run `arkhive maintain` (restic's local cache can still list data the server lost, and a backup
would deduplicate against it) and `arkhive backup`, which records a new last snapshot. After a drill
that found the snapshot missing, backups end with a warning until a `maintain` passes (see above).

Records are kept per repository (`<state>/<key>/<job>.json`, the key as in the lock file name), so
two configurations of one host with different repositories never compare each other's snapshots.
Records written by 2.1.0-rc.1 name no repository and are not checked.

## The run lock

ArkHive takes one lock file per repository (`arkhive-<hash>.lock`, the hash of the repository URL)
so that two of its runs never work on the same repository at once. The directory must be the same
for cron, systemd and a login shell, or two runs started from different places would not see each
other:

| Who | Directory |
|---|---|
| `ARKHIVE_LOCK_DIR` set (absolute, writable) | that directory |
| root | `/run/arkhive`, created `0700 root:root` when the lock is first taken |
| any other user | the first writable of `/run/lock`, `$XDG_RUNTIME_DIR`, `/run/user/<uid>`, the temp directory |

Root used `/run/lock` until 2.1.0-rc.1. That directory is world-writable (mode 1777), so any local
user could create root's lock file first and block every backup (with `fs.protected_regular=1`
root cannot even open it). Root's directory is now private: ArkHive refuses it (exit 77) when it is
a symbolic link or belongs to another user, and makes it 0700 when it is open to others. The
systemd units installed by `arkhive schedule` as root name it as well (`RuntimeDirectory=arkhive`,
`RuntimeDirectoryMode=0700`, `RuntimeDirectoryPreserve=yes`, `Environment=ARKHIVE_LOCK_DIR=/run/arkhive`;
kept between runs, so that a unit that stops never removes a lock file another run holds), and so
do the root cron lines. Reinstall the units (`arkhive schedule --install`) to get these settings.

In a shared directory, a lock file that is a symbolic link or belongs to another user is refused
with exit 77 and names the owner; the file opened must be the one found at the path. Set
`ARKHIVE_LOCK_DIR` to a directory only the backup user can write when other users share the host.

## The configuration file

Whoever can change the configuration chooses the commands ArkHive runs (`RESTIC_BINARY`,
`RESTIC_SFTP_COMMAND`, the dump tools) as the user that runs it, usually root; whoever can read it
reads `MYSQL_PASSWORD`, `PGSQL_PASSWORD` and `SMTP_PASSWORD`. Every command that loads it checks
its mode and owner first, with the rule of sshd's `StrictModes`:

| The file | Result |
|---|---|
| writable by group or others | refused, exit 77 |
| owned by another user than the running one or root | refused, exit 77 |
| holds a password and readable by group or others | warning in 2.1, exit 77 in a later version |
| readable by others, no password in it | `doctor` warns; runs are not affected |

The fix is in the message: `chmod 600 <path>` (and `chown` when the owner is wrong). `arkhive
doctor` reports the same in its `config file` check, where readable passwords already fail.

The repository password is never in the configuration: `RESTIC_PASSWORD_FILE` names a file that
must be private as well, with no grace period (exit 78, an invalid configuration; 77 for
`--password-file`). It must be a regular file (not a symbolic link) owned by the running user, with
no permission for group or others (`chmod 600`), in a directory that group or others cannot write
(a sticky directory such as `/tmp` is accepted: only the owner can replace a file there). Whoever
can replace the file between the check and restic reading it chooses the password that
`arkhive init` gives a new repository.

## Database dumps

The dumps hold every row of every table, so they are as private as the password file. ArkHive
creates each one as an empty 0600 file before `mariadb-dump`, `mysqldump` or `pg_dump` writes into
it (the tools keep the mode of an existing file, whatever the umask of the process), sets 0600
again after renaming `<db>.sql.partial` to `<db>.sql`, and makes the `mysql` and `pgsql`
subdirectories of `DUMP_DIRECTORY` 0700 on every run. `DUMP_DIRECTORY` itself may be readable by
others (it can be a directory of the operator's), but it must belong to the running user and must
not be writable by group or others, sticky or not: such a directory is refused with exit 77.
