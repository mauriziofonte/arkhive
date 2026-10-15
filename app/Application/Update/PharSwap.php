<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Update;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\RefusedException;

/**
 * The final rename() of an update. Replacing the PHAR that is running cannot happen mid-run: PHP
 * reads classes from the archive lazily, at the offsets of the old file, so any class loaded after
 * the rename (the console's own teardown included) would come from the new file and fail. For the
 * running PHAR the rename is therefore the last thing the process does, in a shutdown function.
 */
final class PharSwap
{
    /** @var list<array{string, string, bool}> */
    private array $pending = [];

    private bool $registered = false;

    public function __construct(private readonly bool $deferred)
    {
    }

    /**
     * Deferred when $target is the running PHAR, immediate otherwise.
     */
    public static function for(string $target, string $running): self
    {
        return new self($running !== '' && realpath($running) === realpath($target));
    }

    public function deferred(): bool
    {
        return $this->deferred;
    }

    /**
     * @param bool $discard remove $from when the rename fails (a download, not a kept binary)
     *
     * @throws ArkhiveException an immediate rename failed (a deferred one reports on stderr and exits 1)
     */
    public function rename(string $from, string $to, bool $discard): void
    {
        if (!$this->deferred) {
            if (!@rename($from, $to)) {
                if ($discard) {
                    @unlink($from);
                }

                throw new RefusedException("Cannot replace {$to}: the running version is unchanged");
            }

            return;
        }
        $this->pending[] = [$from, $to, $discard];
        if (!$this->registered) {
            $this->registered = true;
            register_shutdown_function($this->complete(...));
        }
    }

    /**
     * Runs at shutdown: only built-in functions, nothing that could autoload a class.
     */
    public function complete(): void
    {
        foreach ($this->pending as [$from, $to, $discard]) {
            if (!@rename($from, $to)) {
                if ($discard) {
                    @unlink($from);
                }
                fwrite(STDERR, "ArkHive: cannot replace {$to}: the running version is unchanged\n");
                exit(1);
            }
        }
        $this->pending = [];
    }
}
