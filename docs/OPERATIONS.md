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
