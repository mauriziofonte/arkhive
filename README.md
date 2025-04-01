# ⚖️ Arkhive - Secure, Automated Backup & Disaster Recovery

**"Your Data's Ark in the Storm."**

Arkhive is a disaster recovery and backup tool built with [Laravel Zero](https://laravel-zero.com/). Designed to streamline and optionally encrypt backups, Arkhive helps ensure that critical data is safely stored offsite, and can be quickly restored in the event of data loss.

- - -

## ✨ Key Features

- **Automated Backups** – Quickly back up MySQL/PostgreSQL data, plus files/directories.
- **Encryption (AES-256-CBC)** – Keep your archives protected with strong cryptography.
- **SSH/Offsite Storage** – Push backups to a remote server over SSH for offsite safety.
- **Retention** – Automatically remove old backups after a set number of days.
- **Interactive Restores** – Select from available backup dates on the remote host.
- **Email Notifications** – Get alerts for completed or failed backups.

- - -

## 🛠️ Installation

Below are multiple ways to install **Arkhive**:

### 1) Download the PHAR File

The easiest way to get started with **Arkhive** is to download the PHAR build:

```bash
# Download using curl
curl -OL https://github.com/mauriziofonte/arkhive/raw/refs/heads/main/builds/arkhive.phar

# Or download using wget
wget https://github.com/mauriziofonte/arkhive/raw/refs/heads/main/builds/arkhive.phar

# Then move the cli tool to /usr/local/bin
sudo mv arkhive.phar /usr/local/bin/arkhive && sudo chmod +x /usr/local/bin/arkhive
```

You can now run `arkhive` from anywhere in your system.

- - -

### 2) Global Composer Package

If you use Composer, install **Arkhive** system-wide:

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

### 3) Composer Dependency (Per-Project)

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

### 4) Git Clone & Build

You can also clone the **Arkhive** source and build the PHAR locally:

```bash
git clone https://github.com/mauriziofonte/arkhive
cd arkhive
php arkhive app:build arkhive.phar
```

The build process uses [humbug/box](https://github.com/box-project/box) under the hood (see [Laravel Zero Docs](https://laravel-zero.com/docs/build-a-standalone-application) for more details).

- - -

## 🔧 Configuration

Arkhive uses a `.env-style` config file. By default, it will look for a valid config in the following locations (in order):

- `./.arkhive-config`
- `./.config/arkhive-config`
- `./.config/arkhive/config`
- `~/.arkhive-config`
- `~/.config/arkhive-config`
- `~/.config/arkhive/config`
- `/etc/arkhive-config`
- `/etc/arkhive/config`

> Please note that **./** refers to the current working directory, while **~/** refers to the user's home directory.

A sample config:

```env
BACKUP_DIRECTORY=/path/to/backup/directory
BACKUP_RETENTION_DAYS=10
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

- - -

## 🔒 Basic Usage

### Backup

```bash
arkhive backup
```

- Dumps databases (if enabled).
- Creates archive (encrypted if `WITH_CRYPT=true`).
- SCPs it to the remote server (`SSH_HOST`).
- Removes local archive/dumps.
- Prunes old backups over `BACKUP_RETENTION_DAYS`. It can also be `0` to disable retention.
- Sends email notifications (if `NOTIFY=true`).

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
crontab -e 0 2 * * * /usr/local/bin/arkhive backup
```

Runs a backup every day at 2 AM.

- - -

## 🌟 Why Arkhive?

- Minimal overhead.
- Straightforward .env-style configuration.
- Built-in encryption & retention.
- Optional email notifications for success/failure.
- Interactive restore flow.

- - -

## ⚖️ License

MIT License. See `LICENSE` for more information.

- - -

## 🔧 Contributing

Contributions are welcome! Fork the repo, open a PR, or submit an issue.

**Arkhive – Safeguarding your data, one backup at a time.**
