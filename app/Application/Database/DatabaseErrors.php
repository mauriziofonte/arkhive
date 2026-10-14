<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Database;

use Mfonte\Arkhive\Application\Process\ProcessResult;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\IoException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\PermissionException;
use Mfonte\Arkhive\Exceptions\TemporaryException;
use Mfonte\Arkhive\Exceptions\UnavailableException;

/**
 * Turns a failed database client run (mysqldump, pg_dump, mysql, psql) into the exit code of its
 * cause: server down 69, credentials refused 77, database missing 66, disk full 74, timeout 75.
 * Anything else stays a plain failure (exit 1) with the client's own words.
 */
final class DatabaseErrors
{
    /** checked before the connection needles: PostgreSQL wraps them in "connection to server ... failed" */
    private const AUTHENTICATION = [
        'Access denied for user',
        'password authentication failed',
        'no pg_hba.conf entry',
        'peer authentication failed',
    ];

    private const MISSING_DATABASE = [
        'Unknown database',
        'Got error: 1049',
    ];

    private const UNREACHABLE = [
        "Can't connect to",
        'Unknown MySQL server host',
        'Unknown server host',
        'Lost connection to',
        'could not connect to server',
        'Connection refused',
        'could not translate host name',
        'server closed the connection unexpectedly',
        'timeout expired',
    ];

    private const DISK_FULL = [
        'No space left on device',
        'Errcode: 28',
        'errno: 28',
    ];

    public static function exception(ProcessResult $result, string $what, ?string $hint = null): ArkhiveException
    {
        $message = $result->failure($what);
        $stderr = $result->stderr;
        if ($result->timedOut) {
            return new TemporaryException($message, $hint ?? 'The database server did not answer in time: check its load, then run again');
        }
        if (self::contains($stderr, self::DISK_FULL)) {
            return new IoException($message, $hint ?? 'The filesystem is full: free space in the dump directory (DUMP_DIRECTORY) or the target, then run again');
        }
        if (self::contains($stderr, self::AUTHENTICATION) || preg_match('/role "[^"]*" does not exist/', $stderr) === 1) {
            return new PermissionException($message, $hint ?? 'Check the database user and password in the configuration, and that the user may connect from this host');
        }
        if (self::contains($stderr, self::MISSING_DATABASE) || preg_match('/database "[^"]*" does not exist/', $stderr) === 1) {
            return new NoInputException($message, $hint ?? 'Check the database names in the configuration (MYSQL_DATABASES, PGSQL_DATABASES)');
        }
        if (self::contains($stderr, self::UNREACHABLE)) {
            return new UnavailableException($message, $hint ?? 'Check that the database server is running and reachable at the configured host and port');
        }

        return new ArkhiveException($message, hint: $hint);
    }

    /**
     * @param list<string> $needles
     */
    private static function contains(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
