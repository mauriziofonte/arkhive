<?php

declare(strict_types=1);

namespace Tests\e2e;

use RuntimeException;

/**
 * A separate process that records the command line of every process on the host, every millisecond,
 * to prove that no secret ever appears in the process list (spec §13.2 step 11).
 */
final class ProcessSampler
{
    /** @var resource */
    private $process;

    public function __construct(private readonly string $output)
    {
        $code = <<<'PHP'
            $out = fopen($argv[1], 'w');
            $seen = [];
            while (!file_exists($argv[1] . '.stop')) {
                foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
                    $cmdline = @file_get_contents($file);
                    if (is_string($cmdline) && $cmdline !== '' && !isset($seen[$cmdline])) {
                        $seen[$cmdline] = true;
                        fwrite($out, str_replace("\0", ' ', $cmdline) . "\n");
                        fflush($out);
                    }
                }
                usleep(1000);
            }
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, $output], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot start the process sampler');
        }
        $this->process = $process;
        for ($i = 0; $i < 500 && !file_exists($output); $i++) {
            usleep(10000);
        }
    }

    /**
     * Stops sampling and returns every distinct command line seen, one per line.
     */
    public function stop(): string
    {
        touch($this->output . '.stop');
        proc_close($this->process);

        return (string) file_get_contents($this->output);
    }
}
