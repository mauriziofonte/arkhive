<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Port;

/**
 * A second destination for formatted log lines besides stderr (a log file).
 */
interface LogSink
{
    /**
     * @param string $line one formatted event, without the line break
     */
    public function write(string $line): void;
}
