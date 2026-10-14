# Scheduling examples

These files are what `arkhive schedule` prints for the PHAR installed as `/usr/local/bin/arkhive`
with the configuration `/etc/arkhive/config`, run as root, backup daily at 02:30. For any other
path, user or time, generate them for your configuration instead of editing these by hand:

```sh
arkhive schedule --systemd            # systemd service and timer units
arkhive schedule --cron --at 01:00    # lines for /etc/cron.d (root) or crontab -e (other users)
```

The command only prints; it installs nothing.

- `systemd/`: copy the six units to `/etc/systemd/system/`, then
  `systemctl daemon-reload && systemctl enable --now arkhive-backup.timer arkhive-maintain.timer arkhive-drill.timer`.
  Logs: `journalctl -u arkhive-backup.service`.
- `cron/arkhive`: copy to `/etc/cron.d/arkhive` (root:root, mode 0644). Logs: `/var/log/arkhive.log`.

Backup runs daily; `maintain` (retention, prune, check) on Sundays and `drill` (a restore test) on
Wednesdays, three hours after the backup. A failed scheduled run is reported by e-mail when
`NOTIFY=true` (with `NOTIFY_ON=failure` for failures only) is set in the configuration; `arkhive status --max-age 26` suits an external monitor.
