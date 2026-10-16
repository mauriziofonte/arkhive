<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

/**
 * A RESTIC_SFTP_COMMAND with the keepalives and batch mode ArkHive gives every ssh: a server that
 * stops answering fails the run within a minute instead of hanging it, and a missing key fails
 * instead of prompting. Options the command sets itself (-o Name=value, in any case) are kept; a
 * command that does not start with ssh (a wrapper script) is left as written.
 */
final class SftpCommand
{
    /** name => value, in the order they are added */
    public const OPTIONS = ['ServerAliveInterval' => '15', 'ServerAliveCountMax' => '4', 'BatchMode' => 'yes'];

    /**
     * Whether the command runs ssh itself (its first word is ssh or a path ending in /ssh).
     */
    public static function runsSsh(string $command): bool
    {
        $first = (preg_split('/\s+/', trim($command)) ?: [''])[0];

        return $first === 'ssh' || str_ends_with($first, '/ssh');
    }

    /**
     * The OPTIONS the command does not set.
     *
     * @return list<string> option names
     */
    public static function missingOptions(string $command): array
    {
        $missing = [];
        foreach (array_keys(self::OPTIONS) as $name) {
            if (preg_match('/(?:^|\s)-o\s*["\']?' . $name . '\s*[=\s]/i', $command) !== 1) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * The command with the missing OPTIONS right after `ssh`; unchanged when it does not run ssh.
     */
    public static function withKeepalives(string $command): string
    {
        if (!self::runsSsh($command)) {
            return $command;
        }
        $options = '';
        foreach (self::missingOptions($command) as $name) {
            $options .= " -o {$name}=" . self::OPTIONS[$name];
        }
        if ($options === '') {
            return $command;
        }
        $command = trim($command);
        $first = strlen((preg_split('/\s+/', $command) ?: [''])[0]);

        return substr($command, 0, $first) . $options . substr($command, $first);
    }
}
