<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restic;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Services\Config\ResticSettings;
use Mfonte\Arkhive\Services\Process\Command;
use Mfonte\Arkhive\Services\Process\ProcessResult;
use Mfonte\Arkhive\Services\Process\ProcessRunner;

/**
 * The restic client. The repository and the password file go in the environment; inherited
 * password variables are removed so that only RESTIC_PASSWORD_FILE is ever used.
 */
final class Restic
{
    /** spec §4: repository v2 and compression */
    public const MINIMUM_VERSION = '0.14.0';

    /** keepalives so that a server that stops answering fails the run instead of hanging it */
    public const SFTP_ARGS = '-o ServerAliveInterval=15 -o ServerAliveCountMax=4 -o BatchMode=yes';

    /** seconds allowed for the reachability check */
    public const REACH_TIMEOUT = 300.0;

    public function __construct(
        public readonly ResticSettings $settings,
        public readonly string $binary,
        private readonly ProcessRunner $runner,
    ) {
    }

    /**
     * @param list<string>               $args
     * @param array<string, string|null> $env
     */
    public function command(array $args, array $env = []): Command
    {
        $options = [];
        if ($this->settings->sftpCommand !== null) {
            $options = ['--option', 'sftp.command=' . $this->settings->sftpCommand];
        } elseif (str_starts_with($this->settings->repository, 'sftp:')) {
            $options = ['--option', 'sftp.args=' . self::SFTP_ARGS];
        }

        return new Command([$this->binary, ...$options, ...$args], [
            'RESTIC_REPOSITORY' => $this->settings->repository,
            'RESTIC_PASSWORD_FILE' => $this->settings->passwordFile,
            'RESTIC_PASSWORD' => null,
            'RESTIC_PASSWORD_COMMAND' => null,
            'RESTIC_REPOSITORY_FILE' => null,
            ...$env,
        ]);
    }

    /**
     * Runs restic and throws the classified error when it fails.
     *
     * @param list<string>                  $args
     * @param array<string, string|null>    $env
     * @param (callable(string): void)|null $onStdoutLine
     * @param list<int>                     $acceptedExitCodes
     */
    public function run(array $args, string $what, array $env = [], ?callable $onStdoutLine = null, array $acceptedExitCodes = [0], ?float $timeout = null, ?callable $onStderrLine = null): ProcessResult
    {
        $command = $this->command($args, $env);
        if ($timeout !== null) {
            $command = $command->withTimeout($timeout);
        }
        $result = $this->runner->run($command, $onStdoutLine, $onStderrLine);
        if (!in_array($result->exitCode, $acceptedExitCodes, true) || $result->timedOut) {
            throw ResticErrors::exception($result, $what, $this->settings);
        }

        return $result;
    }

    /**
     * "0.19.1" from `restic version`.
     */
    public function version(): string
    {
        $result = $this->runner->run(new Command([$this->binary, 'version']));
        $version = self::parseVersion($result->stdout);
        if (!$result->succeeded() || $version === null) {
            throw new ArkhiveException($result->failure("Running {$this->binary} version"));
        }

        return $version;
    }

    public static function parseVersion(string $output): ?string
    {
        return preg_match('/^restic (\d+\.\d+\.\d+)/m', $output, $match) === 1 ? $match[1] : null;
    }

    /**
     * @throws ArkhiveException when restic is older than 0.14
     */
    public function requireSupportedVersion(): string
    {
        $version = $this->version();
        if (version_compare($version, self::MINIMUM_VERSION, '<')) {
            throw new ArkhiveException(
                "restic {$version} is too old: ArkHive needs restic " . self::MINIMUM_VERSION . ' or newer (repository format 2, compression)',
                hint: 'Install a newer restic from your distribution, or the static binary from https://github.com/restic/restic/releases (then set RESTIC_BINARY)',
            );
        }

        return $version;
    }

    /**
     * Repository reachable and password valid (`restic cat config` reads and decrypts the config).
     */
    public function checkReachable(): void
    {
        $this->run(['cat', 'config'], 'Opening the restic repository', timeout: self::REACH_TIMEOUT);
    }

    /**
     * @return string the repository id
     */
    public function init(): string
    {
        $result = $this->run(['init', '--json'], 'Creating the restic repository');
        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            $data = ResticJson::decode($line);
            if ($data !== null && ResticJson::string($data, 'id') !== '') {
                return ResticJson::string($data, 'id');
            }
        }

        return '';
    }
}
