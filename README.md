# ⚖️ ArkHive - Secure, Automated Backup & Disaster Recovery

**"Your Data's Ark in the Storm."**

ArkHive is a disaster recovery and backup tool built with [Laravel Zero](https://laravel-zero.com/). Designed to streamline and optionally encrypt backups, ArkHive helps ensure that critical data is safely stored offsite, and can be quickly restored in the event of data loss.

- - -

## ✨ Key Features

- **Automated Backups** – Quickly back up MySQL/PostgreSQL data, plus files/directories.
- **Exclusion Patterns** – Exclude specific files or directories from the backup process.
- **Encryption (AES-256-CBC)** – Keep your archives protected with strong cryptography.
- **SSH/Offsite Storage** – Push backups to a remote server over SSH for offsite safety.
- **Retention** – Automatically remove old backups after a set number of days.
- **Interactive Restores** – Select from available backup dates on the remote host.
- **Email Notifications** – Get alerts for completed or failed backups.

- - -

## 🛠️ Installation

The easiest way to get started with **ArkHive** is to download the _PHAR build_ and place it in a valid location in your `PATH`. You can also install it via Composer, either globally or as a project dependency.

```bash
# Download using curl
curl -OL https://github.com/mauriziofonte/arkhive/raw/refs/heads/main/builds/arkhive.phar

# Or download using wget
wget https://github.com/mauriziofonte/arkhive/raw/refs/heads/main/builds/arkhive.phar

# Then move the cli tool to /usr/local/bin
sudo mv arkhive.phar /usr/local/bin/arkhive && sudo chmod +x /usr/local/bin/arkhive
```

You can now run `arkhive` from anywhere in your system.

> Looking for other installation methods? Check [Alternate Installation Methods](#alternate-installation-methods).

- - -

## 🔧 Configuration

ArkHive uses a `.env-style` config file. By default, it will look for a valid config in the following locations (in order):

- `./.arkhive-config`
- `./.config/arkhive-config`
- `./.config/arkhive/config`
- `~/.arkhive-config`
- `~/.config/arkhive-config`
- `~/.config/arkhive/config`
- `/etc/arkhive-config`
- `/etc/arkhive/config`

> Please note that **./** refers to the current working directory, while **~/** refers to the user's home directory.

### Sample Config

```env
BACKUP_DIRECTORY=/path/to/backup/directory
BACKUP_RETENTION_DAYS=10
EXCLUSION_PATTERNS="*/backups/*,*/node_modules/*,*/npm-debug.log,*/package-lock.json,*/.eslintcache,*/vendor/*,*/.git/*,*/.svn/*,*/.hg/*,*/.idea/*,*/.vscode/*,*/.history/*,*.sublime-project,*.pid,*.rar,*.7z,*.iso,*.img,*.exe,*.dll,*.so,*.bin,*.o,*.a,*.class,*.jar,*/node/*,*/go/*,*/.nvm/*,*/.rbenv/*,*/.pyenv/*,*/tmp/*,*/build/*,*/dist/*,*/out/*,*/coverage/*,*.swp,*.swo"
SSH_HOST=example.com
SSH_PORT=22
SSH_USER=example
SSH_BACKUP_HOME="~/backup"
WITH_MYSQL=true
MYSQL_HOST=localhost
MYSQL_PORT=3306
MYSQL_USER=root
MYSQL_PASSWORD=password
MYSQL_DATABASES="database1 database2"
WITH_PGSQL=false
PGSQL_HOST=localhost
PGSQL_USER=postgres
PGSQL_DATABASES="database1 database2"
WITH_CRYPT=true
CRYPT_PASSWORD=strong_encryption_password
NOTIFY=true
SMTP_HOST=smtp.example.com
SMTP_AUTH=login
SMTP_ENCRYPTION=tls
SMTP_SSL=true
SMTP_PORT=587
SMTP_USER=smtp_user
SMTP_PASSWORD=smtp_password
SMTP_FROM=user@example.com
SMTP_TO=user@example.com
```

Fill in values to match your environment.

More specifically:

- `BACKUP_DIRECTORY`: The target directory that will be backed up.
- `BACKUP_RETENTION_DAYS`: Number of days to keep backups on the remote server. Set to **0** to disable retention.
- `EXCLUSION_PATTERNS`: Comma-separated list of patterns to exclude from the backup. Use `*` as a wildcard.
- `SSH_HOST`: The remote server's hostname or IP address.
- `SSH_PORT`: The SSH port on the remote server (default is **22**).
- `SSH_USER`: The SSH user to connect to the remote server.
- `SSH_BACKUP_HOME`: The remote directory where backups will be stored. **Important**: it is suggested to specify an **absolute path** here, to avoid issues.
- `WITH_MYSQL`: Set to **true** to enable MySQL backup.
- `MYSQL_*`: MySQL connection parameters.
- `WITH_PGSQL`: Set to **true** to enable PostgreSQL backup.
- `PGSQL_*`: PostgreSQL connection parameters.
- `WITH_CRYPT`: Set to **true** to enable encryption of the backup tarball.
- `CRYPT_PASSWORD`: The password used for encryption. **Important**: this should be a strong password.
- `NOTIFY`: Set to **true** to enable email notifications.
- `SMTP_*`: SMTP parameters for sending email notifications.

- - -

## 🔒 Basic Usage

### Backup

Prerequisite: you must have a valid **ArkHive config file**. [See Sample Config](#sample-config).

```bash
arkhive backup [--with-disk-space-check] [--with-progress]
```

This command will:

- Dump MySQL/PostgreSQL, if `WITH_MYSQL` or `WITH_PGSQL` is set to `true`. Note: MySQL/PostgreSQL dumps are stored in `BACKUP_DIRECTORY`, so, these will be included in the tarball.
- Create a tarball of the specified `BACKUP_DIRECTORY`.
- Encrypt the whole tarball if `WITH_CRYPT` is set to `true`.
- Remove **remote** backups older than `BACKUP_RETENTION_DAYS`. Note: **0** is valid, and means "no retention".
- Upload the tarball to the remote server using SSH.
- Notify you via email if `NOTIFY` is set to `true`.

Optional flags:

- `--with-disk-space-check`: Check available disk space before backup. This will be a rough estimate based on gzip compression factor and overhead caused by encryption.
- `--with-progress`: Show progress during the backup process.

> **Important Note**: don't use `--with-progress` in **non-tty** contexts (e.g., CRON jobs). It will cause the command to fail, because we cannot easily show `pv` progress in this case. The command is designed to fail if it detects that you've required `--with-progress` in a non-tty context.

#### Example output

```bash
maurizio:~/libraries/arkhive (main) $ php arkhive backup --with-progress
🚀 Welcome to ArkHive 1.4.0
💡 Working in BACKUP mode...
 🔐 SSH connection test succeeded.
 💻 Retrieving list of remote backup directories...
 💻 Creating MySQL dump -> /home/maurizio/2025-04-11-mysqldump.sql ...
 🕑 Mysql Dump: 75% done. [193s elapsed, ETA 67s]. Running at 68.4MiB/s. Transferred: 20.4GiB
 💻 Enumerating directory /home/maurizio ...
 🔍 Filtering files as per exclusions patterns...
 ✅ Found 360845 files, with 2103546 exclusions.
 💻 Creating 2025-04-11 Encrypted Backup Archive and streaming to remote...
 🕑 Streaming: 5% done. [210s elapsed, ETA 4126s]. Running at 15.9MiB/s. Transferred: 3.64GiB
 💻 Fixing permissions on remote SSH server...
 ✅ Backup completed in 2543 seconds.
```

### Restore

```bash
arkhive restore
```

- Lists available backups on the remote server by date.
- Lets you pick one interactively.
- Downloads and (if necessary) decrypts it into a local directory.

### CRON Automation

Example:

```bash
su - youruser
touch ~/.arkhive-config
crontab -e
0 2 * * * /usr/local/bin/arkhive backup
```

Runs a backup every day at 2 AM, using the config file in `~/.arkhive-config` of the user `youruser`.

> **Important Note**: Make sure to set the correct permissions for the config file, especially if it contains sensitive information like passwords. You can use `chmod 600 ~/.arkhive-config` to restrict access.

- - -

## 🌟 Why ArkHive?

- Minimal overhead.
- Straightforward .env-style configuration.
- Built-in encryption & retention.
- Optional email notifications for success/failure.
- Interactive restore flow.

- - -

## Alternate Installation Methods

Apart from the PHAR build, you can also install **ArkHive** using Composer or by cloning the repository and building it locally. This is useful if you want to customize the tool or contribute to its development.

### 1) Global Composer Package

If you use Composer, install **ArkHive** system-wide:

```bash
composer global require "mfonte/arkhive=*"
```

Ensure your Composer “global bin” dir is in your `PATH`. Typically:

```bash
~/.composer/vendor/bin
```

You can verify or change the bin dir path:

```bash
composer global config bin-dir --absolute
```

After that, `arkhive` will be available at:

```bash
$(composer config -g home)/vendor/bin/arkhive
```

Consider adding this path to your `.bashrc` or equivalent:

```bash
echo 'export PATH="$(composer config -g home)/vendor/bin:$PATH"' >> ~/.bashrc
```

- - -

### 2) Composer Dependency (Per-Project)

Include `mfonte/arkhive` in your project’s composer.json:

```json
{
   "require-dev": {
      "mfonte/arkhive": "*"
   }
}
```

After installing with `composer install`, you can run:

```bash
./vendor/bin/arkhive
```

from within your project. You might alias it for convenience, for example:

```bash
alias arkhive="/usr/bin/php /path/to/project/vendor/bin/arkhive"
```

- - -

### 3) Git Clone & Build

You can also clone the **ArkHive** source and build the PHAR locally:

```bash
git clone https://github.com/mauriziofonte/arkhive
cd arkhive
composer install
```

Then, build the PHAR using either:

```bash
php arkhive app:build arkhive.phar
```

or

```bash
./build.sh
```

Either command will create a `builds/arkhive.phar` file.

> The build process uses [humbug/box](https://github.com/box-project/box) under the hood (see [Laravel Zero Docs](https://laravel-zero.com/docs/build-a-standalone-application) for more details).

- - -

## ⚖️ License

MIT License. See `LICENSE` for more information.

- - -

## 🔧 Contributing

Contributions are welcome! Fork the repo, open a PR, or submit an issue.

**ArkHive – Safeguarding your data, one backup at a time.**
