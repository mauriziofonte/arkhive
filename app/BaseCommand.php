<?php

namespace Mfonte\Arkhive;

use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Collection;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Phar;

/**
 * Class BaseCommand
 *
 * Acts as the base for all Arkhive commands.
 */
abstract class BaseCommand extends Command
{
    const ARKHIVE_VERSION = '1.0';

    /** @var string */
    protected $cwd;
    /** @var string */
    protected $commandName;
    /** @var string */
    protected $fullExecutablePath;
    /** @var int */
    protected $ttyLines = 30;
    /** @var int */
    protected $ttyCols = 120;
    /** @var bool */
    protected $isPhar = false;
    /** @var bool */
    protected $hasRootPermissions = false;
    /** @var bool */
    protected $runningAsSudo = false;
    /** @var string */
    protected $userName;
    /** @var string */
    protected $userGroup;
    /** @var string */
    protected $userHome;
    /** @var int */
    protected $userUid;
    /** @var int */
    protected $userGid;
    /** @var Collection */
    protected $config;
    /** @var string */
    protected $configFile = '';
    /** @var float */
    protected $startTime;

    public function __construct()
    {
        parent::__construct();

        if (Phar::running(false) !== '') {
            $this->isPhar = true;
        }

        $this->checkFunctions();
        $this->checkEnvironment();
        $this->setCliParams();
        $this->setRunningUser();

        $this->initConfig();

        $this->startTime = microtime(true);
    }

    /**
     * Immediately halts program execution with an error message.
     *
     * @param string $message
     * @return never
     */
    protected function criticalError(string $message)
    {
        // Clamp TTY columns to avoid weird wrapping if we got a low or zero value:
        $cols = max($this->ttyCols, 80);
        $lines = max($this->ttyLines, 24);

        if ($this->output) {
            // Use the clamped $cols to do word wrapping
            $message = wordwrap($message, $cols - 10, "\n", true);
            $textLines = explode("\n", $message);

            // If the message box would be too tall, just dump it with ->error()
            if (count($textLines) + 2 > $lines) {
                $this->error($message);
            } else {
                // Print inside asterisks
                $boxBound = str_repeat("*", $cols);
                $this->error($boxBound);
                foreach ($textLines as $line) {
                    $this->error("*" . str_pad($line, $cols - 2, " ", STR_PAD_BOTH) . "*");
                }
                $this->error($boxBound);
            }
        } else {
            // Fallback if the output isn't available
            echo "\e[41m\e[97mArkhive - Cannot execute :(\e[0m\n";
            echo "\e[41m\e[97m{$message}\e[0m\n";
        }

        exit(1);
    }

    /**
     * Verifies availability of core binaries like tar, ssh, etc.
     */
    private function checkEnvironment(): void
    {
        $binaries = [
            'tar' => 'tar',
            'ssh' => 'openssh-client',
            'scp' => 'openssh-client',
        ];

        $missingPackages = [];

        foreach ($binaries as $binary => $package) {
            if (!binary_exists($binary)) {
                $missingPackages[$package] = true;
            }
        }

        if (!empty($missingPackages)) {
            $packagesList = implode(' ', array_keys($missingPackages));
            $this->criticalError(
                "The following required packages are missing:\n" .
                $packagesList . "\n\n" .
                "Please install them by running:\n" .
                "sudo apt-get install {$packagesList}"
            );
        }
    }

    /**
     * Verifies availability of required PHP functions.
     */
    private function checkFunctions(): void
    {
        $functions = [
            'proc_open',
            'proc_get_status',
            'stream_get_contents',
            'shell_exec',
            'exec',
        ];

        foreach ($functions as $func) {
            if (!function_exists($func)) {
                $this->criticalError("Your PHP installation does not support {$func}(). Check your php.ini.");
            }
        }
    }

    /**
     * Reads and stores TTY dimensions + command invocation info.
     */
    private function setCliParams(): void
    {
        try {
            $ttyLines = wrap_exec('tput lines');
            $this->ttyLines = intval($ttyLines);

            $ttyCols = wrap_exec('tput cols');
            $this->ttyCols = intval($ttyCols);
        } catch (\Throwable $e) {
            $this->ttyLines = 30;
            $this->ttyCols = 120;
        }

        $this->cwd = dirname(realpath($_SERVER['argv'][0]));
        $this->commandName = basename(realpath($_SERVER['argv'][0]));
        $this->fullExecutablePath = realpath($_SERVER['PHP_SELF']);
    }

    /**
     * Determines user context (root or sudo).
     */
    private function setRunningUser(): void
    {
        $uname = $_SERVER['USER'] ?? '';

        if (empty($uname)) {
            $this->criticalError("Failed to get the current user name. Make sure to run from a terminal.");
        }

        if ($uname === 'root' && !array_key_exists('SUDO_USER', $_SERVER)) {
            $this->hasRootPermissions = true;
        } elseif ($uname === 'root' && array_key_exists('SUDO_USER', $_SERVER)) {
            $this->hasRootPermissions = true;
            $this->runningAsSudo = true;
            $uname = $_SERVER['SUDO_USER'];
        }

        $userInfo = posix_getpwnam($uname);
        if (empty($userInfo)) {
            $this->criticalError("User {$uname} does not exist.");
        }

        $this->userName  = $userInfo['name'];
        $this->userGroup = posix_getgrgid($userInfo['gid'])['name'];
        $this->userHome  = $userInfo['dir'];
        $this->userUid   = $userInfo['uid'];
        $this->userGid   = $userInfo['gid'];
    }

    /**
     * Initializes the config Collection with defaults and loads from .env.
     */
    protected function initConfig(): void
    {
        $this->config = collect([
            'BACKUP_DIRECTORY'      => null,
            'BACKUP_FILE'           => null,
            'BACKUP_RETENTION_DAYS' => null,
            'SSH_HOST'              => null,
            'SSH_USER'              => null,
            'SSH_PORT'              => 22,
            'SSH_BACKUP_HOME'       => null,
            'WITH_MYSQL'            => false,
            'MYSQL_HOST'            => 'localhost',
            'MYSQL_USER'            => null,
            'MYSQL_PASSWORD'        => null,
            'MYSQL_DATABASES'       => null,
            'WITH_PGSQL'            => false,
            'PGSQL_HOST'            => 'localhost',
            'PGSQL_USER'            => null,
            'PGSQL_DATABASES'       => null,
            'WITH_CRYPT'            => false,
            'CRYPT_PASSWORD'        => null,
            'NOTIFY'                => false,
            'SMTP_HOST'             => null,
            'SMTP_PORT'             => 25,
            'SMTP_AUTH'             => 'default',
            'SMTP_ENCRYPTION'       => 'tls',
            'SMTP_USER'             => null,
            'SMTP_PASSWORD'         => null,
            'SMTP_FROM'             => null,
            'SMTP_TO'               => null,
        ]);

        $this->loadConfig();
    }

    /**
     * Loads the .env-like config data from an actual config file.
     */
    private function loadConfig(): void
    {
        $configFiles = [
            "{$this->cwd}/.arkhive-config",
            "{$this->cwd}/.config/arkhive-config",
            "{$this->cwd}/.config/arkhive/config",
            "{$this->userHome}/.arkhive-config",
            "{$this->userHome}/.config/arkhive-config",
            "{$this->userHome}/.config/arkhive/config",
            "/etc/arkhive-config",
            "/etc/arkhive/config",
        ];

        $foundFile = '';
        foreach ($configFiles as $file) {
            if (file_exists($file) && is_readable($file)) {
                $foundFile = $file;
                break;
            }
        }

        if (empty($foundFile)) {
            $this->criticalError(
                "Failed to find the Arkhive configuration file. Create any of:\n" .
                implode("\n", $configFiles) . "\n" .
                "Please refer to stub config file at https://github.com/mauriziofonte/arkhive/blob/main/.arkhive-config.example"
            );
        }

        try {
            $dotenv = Dotenv::parse(file_get_contents($foundFile));
            $this->validateDotenv($dotenv, $foundFile);
            $this->hydrateConfig($dotenv);
            $this->configFile = $foundFile;
        } catch (InvalidFileException $e) {
            $this->criticalError("Invalid .env file format: {$e->getMessage()}");
        } catch (\Throwable $e) {
            $this->criticalError($e->getMessage());
        }
    }

    /**
     * Ensures required keys exist, checks bin availability, etc.
     */
    private function validateDotenv(array $dotenv, string $configFile): void
    {
        $requiredKeys = ['BACKUP_DIRECTORY', 'BACKUP_FILE', 'BACKUP_RETENTION_DAYS', 'SSH_HOST', 'SSH_USER', 'SSH_BACKUP_HOME'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $dotenv)) {
                $this->criticalError("Missing config key: {$key} in {$configFile}.");
            }
        }

        // MySQL
        if ($this->isTruthy($dotenv['WITH_MYSQL'])) {
            $mysqlKeys = ['MYSQL_HOST', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_DATABASES'];
            foreach ($mysqlKeys as $key) {
                if (!array_key_exists($key, $dotenv)) {
                    $this->criticalError("Missing MySQL key: {$key} in {$configFile}.");
                }
            }
            if (!binary_exists('mysqldump') && !binary_exists('mariadb-dump')) {
                $this->criticalError("Binary mysqldump/mariadb-dump not found.");
            }
        }

        // PGSQL
        if ($this->isTruthy($dotenv['WITH_PGSQL'])) {
            $pgsqlKeys = ['PGSQL_HOST', 'PGSQL_USER', 'PGSQL_DATABASES'];
            foreach ($pgsqlKeys as $key) {
                if (!array_key_exists($key, $dotenv)) {
                    $this->criticalError("Missing PGSQL key: {$key} in {$configFile}.");
                }
            }
            if (!binary_exists('pg_dump')) {
                $this->criticalError("Binary pg_dump not found.");
            }
        }

        // Crypt
        if ($this->isTruthy($dotenv['WITH_CRYPT'])) {
            if (empty($dotenv['CRYPT_PASSWORD'])) {
                $this->criticalError("Missing CRYPT_PASSWORD in {$configFile}.");
            }
            if (!binary_exists('openssl')) {
                $this->criticalError("Binary openssl not found.");
            }
        }

        // Notify
        if ($this->isTruthy($dotenv['NOTIFY'])) {
            $notifyKeys = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASSWORD', 'SMTP_FROM', 'SMTP_TO'];
            foreach ($notifyKeys as $key) {
                if (!array_key_exists($key, $dotenv)) {
                    $this->criticalError("Missing NOTIFY key: {$key} in {$configFile}.");
                }
            }
        }
    }

    /**
     * Checks if a value is truthy.
     *
     * @param mixed $value
     * @return bool
     */
    private function isTruthy($value): bool
    {
        if (is_null($value)) {
            return false;
        }

        return in_array(strtolower($value), ['1', 'yes', 'true'], true);
    }

    /**
     * Converts boolean-ish strings and populates config collection.
     */
    private function hydrateConfig(array $dotenv): void
    {
        foreach ($dotenv as $key => $value) {
            if (in_array(strtolower($value), ['1', 'yes', 'true', '0', 'no', 'false'], true)) {
                $dotenv[$key] = $this->isTruthy($value);
            } elseif ($value === '') {
                $dotenv[$key] = null;
            }
        }

        // Store in $this->config
        foreach ($dotenv as $key => $value) {
            if (in_array($key, ['BACKUP_RETENTION_DAYS', 'SSH_PORT', 'SMTP_PORT'], true)) {
                $this->config->put($key, (int) $value);
            } elseif (in_array($key, ['BACKUP_DIRECTORY', 'SSH_BACKUP_HOME'], true) && is_string($value)) {
                $this->config->put($key, rtrim($value, DIRECTORY_SEPARATOR));
            } else {
                $this->config->put($key, $value);
            }
        }
    }

    /**
     * Creates the SMTP transport for notifications.
     */
    private function createSmtpTransport() : TransportInterface
    {
        switch ($this->config->get('SMTP_ENCRYPTION')) {
            case 'ssl':
                $encryption = 'ssl';
                $port       = $this->config->get('SMTP_PORT', 465);
                break;
            case 'tls':
                $encryption = 'tls';
                $port       = $this->config->get('SMTP_PORT', 587);
                break;
            default:
                $encryption = null;
                $port       = $this->config->get('SMTP_PORT', 25);
                break;
        }

        $smtpUser = $this->config->get('SMTP_USER', '');
        $smtpPass = $this->config->get('SMTP_PASSWORD', '');

        if ($smtpUser && $smtpPass) {
            $dsn = "smtp://$smtpUser:$smtpPass@{$this->config->get('SMTP_HOST')}:$port";
        } elseif ($smtpUser) {
            $dsn = "smtp://$smtpUser@{$this->config->get('SMTP_HOST')}:$port";
        } else {
            $dsn = "smtp://{$this->config->get('SMTP_HOST')}:$port";
        }

        if ($encryption) {
            $dsn .= "?encryption=$encryption";
        }

        return Transport::fromDsn($dsn);
    }

    /**
     * Sends an email notification if NOTIFY=true.
     *
     * @param string $subject
     * @param string $message
     */
    protected function sendEmailNotification(string $subject, string $message): void
    {
        if (!$this->config->get('NOTIFY')) {
            return;
        }

        try {
            $endTime = microtime(true);
            $elapsedMinutes = round(($endTime - $this->startTime) / 60, 2);

            $body = sprintf(
                "<p><strong>Arkhive Notification</strong></p>\n\n
                <p>Hostname: <strong>%s</strong></p>\n
                <p>Elapsed Time: <strong>%s minutes</strong></p>\n
                <p>Config File: <strong>%s</strong></p>\n
                <p>Output:</p>\n
                <p><pre>%s</pre></p>
                <p>&nbsp;</p>
                <p><small>This is an automated notification from <a href='https://github.com/mauriziofonte/arkhive'>Arkhive</a>.</small></p>",
                system_hostname(),
                $elapsedMinutes,
                $this->configFile,
                $message
            );

            $mailer = new Mailer($this->createSmtpTransport());
            $email = (new Email())
                ->from($this->config->get('SMTP_FROM'))
                ->to($this->config->get('SMTP_TO'))
                ->subject($subject)
                ->html($body)
                ->text(strip_tags($body));

            $mailer->send($email);
        } catch (\Throwable $e) {
            $this->criticalError("Failed to send email: {$e->getMessage()}");
        }
    }
}
