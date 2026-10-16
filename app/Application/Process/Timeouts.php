<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Process;

/**
 * Deadlines of the child processes that should be short. A hung server or mount then fails the run
 * with exit 75 (try again later) instead of holding it until the unit's TimeoutStartSec. The long
 * ones (backup, restore, check, an import) have none: ssh's keepalives and systemd bound them. A
 * dump has DUMP_TIMEOUT and DUMP_STALL_TIMEOUT (DumpLimits).
 */
final class Timeouts
{
    /** seconds for a local binary to print its version */
    public const VERSION = 30.0;

    /** seconds for a database query on metadata: the databases, their sizes, create, drop */
    public const QUERY = 300.0;

    /** seconds for restic to open the repository or read its metadata (config, snapshots, locks, stats), or to create it */
    public const REPOSITORY = 300.0;
}
