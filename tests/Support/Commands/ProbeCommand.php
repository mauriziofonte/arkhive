<?php

declare(strict_types=1);

namespace Tests\Support\Commands;

use Closure;
use Mfonte\Arkhive\Commands\ArkhiveCommand;

/**
 * A command whose body is supplied by the test, to exercise ArkhiveCommand in-process.
 */
final class ProbeCommand extends ArkhiveCommand
{
    protected $signature = 'test:probe';
    protected $description = 'test probe';
    protected $hidden = true;

    public static ?Closure $body = null;

    protected function perform(): int
    {
        $body = self::$body;
        assert($body !== null);

        return $body($this);
    }

    public function logger(): \Mfonte\Arkhive\Services\Output\Logger
    {
        return $this->log;
    }

    public function writeResult(string $text): void
    {
        $this->result($text);
    }
}
