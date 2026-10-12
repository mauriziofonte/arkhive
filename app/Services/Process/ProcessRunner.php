<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Process;

use Mfonte\Arkhive\Exceptions\InterruptedException;

interface ProcessRunner
{
    /**
     * Runs the command to completion. With $onStdoutLine, stdout is streamed line by line to the
     * callback instead of being collected; $onStderrLine sees stderr lines as they arrive (stderr is
     * collected either way).
     *
     * @param (callable(string): void)|null $onStdoutLine
     * @param (callable(string): void)|null $onStderrLine
     *
     * @throws InterruptedException when SIGINT/SIGTERM arrived: the signal is forwarded to the child
     *                              and this is thrown once the child has exited
     */
    public function run(Command $command, ?callable $onStdoutLine = null, ?callable $onStderrLine = null): ProcessResult;
}
