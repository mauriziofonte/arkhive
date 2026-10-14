<?php

declare(strict_types=1);

namespace Mfonte\Arkhive;

/**
 * Stable, documented exit codes (docs/2.1-PLAN.md §1): the sysexits.h codes for every failure
 * class, plus 3 (completed with warnings, as restic), 10 (self-update --check) and 128 + signal.
 * systemd shows the sysexits ones by name: status=75/TEMPFAIL.
 */
final class ExitCode
{
    public const SUCCESS = 0;
    /** unclassified runtime failure, or an operation the operator declined */
    public const FAILURE = 1;
    /** completed with warnings: snapshot saved but some files unreadable, notification failed */
    public const WARNING = 3;
    /** self-update --check: an update is available */
    public const UPDATE_AVAILABLE = 10;
    /** EX_USAGE: bad option or argument, required answer missing in unattended mode */
    public const USAGE = 64;
    /** EX_DATAERR: data verified and found wrong (check errors, drill mismatch, corrupt archive) */
    public const DATA_ERROR = 65;
    /** EX_NOINPUT: an input does not exist (file, snapshot, path in a snapshot, release) */
    public const NO_INPUT = 66;
    /** EX_UNAVAILABLE: a service or dependency is unavailable (repository, binary, database server) */
    public const UNAVAILABLE = 69;
    /** EX_SOFTWARE: internal error, or output of another program ArkHive cannot understand */
    public const SOFTWARE = 70;
    /** EX_OSERR: the operating system refused something ArkHive needs (starting a process) */
    public const OS_ERROR = 71;
    /** EX_CANTCREAT: an output cannot be created: target not empty, not enough space */
    public const CANNOT_CREATE = 73;
    /** EX_IOERR: a local read or write failed (filesystem full) */
    public const IO_ERROR = 74;
    /** EX_TEMPFAIL: temporary failure, retrying later may succeed (lock held, timeout) */
    public const TEMPORARY = 75;
    /** EX_PROTOCOL: a remote service answered something that does not follow its protocol */
    public const PROTOCOL = 76;
    /** EX_NOPERM: permission denied, wrong password, secret readable by others */
    public const NO_PERMISSION = 77;
    /** EX_CONFIG: configuration missing or invalid, removed 1.x keys */
    public const CONFIG = 78;
    public const SIGINT = 130;
    public const SIGTERM = 143;

    /** name and meaning of every code, for the documentation and --help */
    public const TABLE = [
        self::SUCCESS => ['OK', 'success'],
        self::FAILURE => ['FAILURE', 'failure not classified below, or an operation the operator declined'],
        self::WARNING => ['WARNING', 'completed with warnings (snapshot saved but some files unreadable, notification failed)'],
        self::UPDATE_AVAILABLE => ['UPDATE', 'self-update --check: an update is available'],
        self::USAGE => ['USAGE', 'bad option or argument, or a required answer missing in unattended mode'],
        self::DATA_ERROR => ['DATAERR', 'data verified and found wrong: repository check, drill, damaged archive or download'],
        self::NO_INPUT => ['NOINPUT', 'an input does not exist: file, snapshot, path in a snapshot, release'],
        self::UNAVAILABLE => ['UNAVAILABLE', 'a service or dependency is unavailable: repository, restic, dump tools, database server'],
        self::SOFTWARE => ['SOFTWARE', 'internal error, or output of restic ArkHive cannot understand'],
        self::OS_ERROR => ['OSERR', 'the operating system refused to start a process'],
        self::CANNOT_CREATE => ['CANTCREAT', 'an output cannot be created: target not empty, not enough space'],
        self::IO_ERROR => ['IOERR', 'a local read or write failed (filesystem full)'],
        self::TEMPORARY => ['TEMPFAIL', 'temporary failure, retry later: repository or ArkHive lock held, timeout'],
        self::PROTOCOL => ['PROTOCOL', 'a remote service answered something that does not follow its protocol'],
        self::NO_PERMISSION => ['NOPERM', 'permission denied, wrong password, or a secret readable by others'],
        self::CONFIG => ['CONFIG', 'configuration missing or invalid, including removed 1.x keys'],
        self::SIGINT => ['SIGINT', 'interrupted by SIGINT: cleanup done, lock released'],
        self::SIGTERM => ['SIGTERM', 'interrupted by SIGTERM: cleanup done, lock released'],
    ];

    /** codes after which retrying the same run cannot succeed (systemd RestartPreventExitStatus) */
    public const NOT_RETRYABLE = [self::USAGE, self::DATA_ERROR, self::NO_INPUT, self::NO_PERMISSION, self::CONFIG];

    private function __construct()
    {
    }
}
