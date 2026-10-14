<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Console;

use Mfonte\Arkhive\Application\Port\Terminal;

final class SystemTerminal implements Terminal
{
    public function stdinIsTty(): bool
    {
        return self::isTty(STDIN);
    }

    public function stdoutIsTty(): bool
    {
        return self::isTty(STDOUT);
    }

    public function environment(): array
    {
        $env = [];
        foreach (getenv() as $name => $value) {
            $env[(string) $name] = (string) $value;
        }

        return $env;
    }

    /**
     * @param resource $stream
     */
    private static function isTty($stream): bool
    {
        if (function_exists('stream_isatty')) {
            return stream_isatty($stream);
        }

        return function_exists('posix_isatty') && posix_isatty($stream);
    }
}
