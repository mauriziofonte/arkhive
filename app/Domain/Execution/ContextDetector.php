<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Execution;

use Mfonte\Arkhive\Exceptions\UsageException;

/**
 * Maps the signals of spec §11 to an ExecutionContext. Pure: every input is a parameter.
 */
final class ContextDetector
{
    /**
     * @param array<string, string> $env              environment variables
     * @param bool                  $noInteraction    --no-interaction / -n given
     * @param bool                  $forceInteractive --interactive given
     * @param string|null           $logFormat        --log-format value (text or json)
     */
    public static function detect(
        array $env,
        bool $stdinTty,
        bool $stdoutTty,
        bool $noInteraction = false,
        bool $forceInteractive = false,
        ?string $logFormat = null,
    ): ExecutionContext {
        if ($noInteraction && $forceInteractive) {
            throw new UsageException('--interactive and --no-interaction cannot be used together');
        }
        if ($forceInteractive && !$stdinTty) {
            throw new UsageException('--interactive needs a terminal on stdin');
        }

        $json = self::parseLogFormat($logFormat);
        $systemd = self::isSet($env, 'INVOCATION_ID') || self::isSet($env, 'JOURNAL_STREAM');
        $terminal = $stdinTty && $stdoutTty && ($env['TERM'] ?? '') !== 'dumb';

        [$interactive, $reason] = match (true) {
            $forceInteractive => [true, '--interactive'],
            $noInteraction => [false, '--no-interaction'],
            self::isTruthy($env['ARKHIVE_NONINTERACTIVE'] ?? '') => [false, 'ARKHIVE_NONINTERACTIVE'],
            $systemd => [false, 'systemd'],
            self::isSet($env, 'CI') => [false, 'CI'],
            $terminal => [true, 'terminal'],
            default => [false, 'no terminal'],
        };

        $format = match (true) {
            $json => LogFormat::Json,
            $interactive => LogFormat::Human,
            $systemd => LogFormat::Journald,
            default => LogFormat::Plain,
        };

        // https://no-color.org: any non-empty NO_COLOR disables colour
        $colour = $interactive && $stdoutTty && !self::isSet($env, 'NO_COLOR') && ($env['TERM'] ?? '') !== 'dumb';

        return new ExecutionContext($interactive, $systemd, $colour, $format, $reason);
    }

    private static function parseLogFormat(?string $logFormat): bool
    {
        return match ($logFormat) {
            null, '', 'text' => false,
            'json' => true,
            default => throw new UsageException("Invalid --log-format \"{$logFormat}\": use text or json"),
        };
    }

    /**
     * @param array<string, string> $env
     */
    private static function isSet(array $env, string $name): bool
    {
        return ($env[$name] ?? '') !== '';
    }

    private static function isTruthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
