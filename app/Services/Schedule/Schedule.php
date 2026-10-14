<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Schedule;

use Mfonte\Arkhive\Exceptions\UsageException;

/**
 * What `arkhive schedule` renders: how to start ArkHive, which configuration, as which user, and the
 * time of the daily backup.
 */
final readonly class Schedule
{
    public const DEFAULT_TIME = '02:30';

    /**
     * @param list<string> $invocation the ArkHive executable, e.g. ['/usr/local/bin/arkhive'] or [php, phar]
     * @param string       $user       the account the jobs run as
     */
    public function __construct(
        public array $invocation,
        public string $config,
        public string $user,
        public int $hour,
        public int $minute,
        public string $logFile,
    ) {
        foreach ([...$invocation, $config, $user, $logFile] as $value) {
            if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new UsageException('Cannot schedule ' . json_encode($value) . ': a path or name with a control character (or an empty one) cannot be written into a unit or crontab');
            }
        }
    }

    /**
     * @return array{int, int} hour and minute
     *
     * @throws UsageException
     */
    public static function parseTime(string $time): array
    {
        if (preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new UsageException("--at must be a time of day as HH:MM (24 h), not '{$time}'", 'For example: arkhive schedule --systemd --at ' . self::DEFAULT_TIME);
        }

        [$hour, $minute] = explode(':', $time);

        return [(int) $hour, (int) $minute];
    }

    public function root(): bool
    {
        return $this->user === 'root';
    }

    /**
     * @return array{int, int} hour and minute of $job
     */
    public function timeOf(Job $job): array
    {
        return [($this->hour + $job->hourOffset()) % 24, $this->minute];
    }

    /**
     * The arguments after the executable.
     *
     * @return list<string>
     */
    public function arguments(Job $job): array
    {
        return [$job->value, '--config', $this->config];
    }
}
