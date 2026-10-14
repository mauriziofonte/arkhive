<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Restic;

use DateTimeImmutable;
use DateTimeZone;
use Mfonte\Arkhive\Domain\Repository\BackupStatus;
use Mfonte\Arkhive\Domain\Repository\BackupSummary;
use Mfonte\Arkhive\Domain\Repository\CheckResult;
use Mfonte\Arkhive\Domain\Repository\ForgetResult;
use Mfonte\Arkhive\Domain\Repository\Node;
use Mfonte\Arkhive\Domain\Repository\RepositoryLock;
use Mfonte\Arkhive\Domain\Repository\RepositoryStats;
use Mfonte\Arkhive\Domain\Repository\RestoreProgress;
use Mfonte\Arkhive\Domain\Repository\Snapshot;

/**
 * Translates what restic prints (JSON lines, and the text of older versions) into the repository
 * model of the Domain. Nothing outside the restic adapter knows restic's output format.
 */
final class ResticOutput
{
    /** the lines restic check prints as text when everything is fine; anything else is a finding */
    private const CHECK_PROGRESS = '/^(using temporary cache in |create (exclusive )?lock|load(ing)? ind|check (all packs|snapshots)|read (all data|[\\d.]+% of)|no errors were found|\\[\\d+:\\d+\\]|\\d+ additional files were found|counting files|ignoring|Fatal: repository contains errors)/';

    /**
     * @param array<string, mixed> $data
     */
    public static function snapshot(array $data): Snapshot
    {
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : null;

        return new Snapshot(
            ResticJson::string($data, 'id'),
            self::time(ResticJson::string($data, 'time')),
            ResticJson::string($data, 'hostname'),
            self::strings($data['paths'] ?? []),
            self::strings($data['tags'] ?? []),
            $summary === null ? null : ResticJson::int($summary, 'total_bytes_processed'),
            ResticJson::string($data, 'username'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function node(array $data): Node
    {
        return new Node(
            ResticJson::string($data, 'path'),
            ResticJson::string($data, 'type'),
            ResticJson::int($data, 'size'),
            ResticJson::string($data, 'permissions'),
            self::time(ResticJson::string($data, 'mtime')),
            ResticJson::int($data, 'uid'),
            ResticJson::int($data, 'gid'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function backupSummary(array $data): BackupSummary
    {
        return new BackupSummary(
            ResticJson::string($data, 'snapshot_id'),
            ResticJson::int($data, 'files_new'),
            ResticJson::int($data, 'files_changed'),
            ResticJson::int($data, 'files_unmodified'),
            ResticJson::int($data, 'dirs_new'),
            ResticJson::int($data, 'dirs_changed'),
            ResticJson::int($data, 'dirs_unmodified'),
            ResticJson::int($data, 'data_added'),
            array_key_exists('data_added_packed', $data) ? ResticJson::int($data, 'data_added_packed') : null,
            ResticJson::int($data, 'total_files_processed'),
            ResticJson::int($data, 'total_bytes_processed'),
            ResticJson::float($data, 'total_duration'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function backupStatus(array $data): BackupStatus
    {
        return new BackupStatus(
            ResticJson::float($data, 'percent_done'),
            ResticJson::int($data, 'files_done'),
            ResticJson::int($data, 'total_files'),
            ResticJson::int($data, 'bytes_done'),
            ResticJson::int($data, 'total_bytes'),
            array_key_exists('seconds_remaining', $data) ? ResticJson::int($data, 'seconds_remaining') : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function restoreProgress(array $data): RestoreProgress
    {
        $total = ResticJson::int($data, 'total_bytes');
        $restored = ResticJson::int($data, 'bytes_restored');
        $percent = array_key_exists('percent_done', $data)
            ? ResticJson::float($data, 'percent_done')
            : ($total > 0 ? $restored / $total : 1.0);

        return new RestoreProgress(
            $percent,
            ResticJson::int($data, 'total_files'),
            ResticJson::int($data, 'files_restored'),
            $total,
            $restored,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function repositoryStats(array $data): RepositoryStats
    {
        return new RepositoryStats(
            ResticJson::int($data, 'total_size'),
            array_key_exists('total_uncompressed_size', $data) ? ResticJson::int($data, 'total_uncompressed_size') : null,
            ResticJson::int($data, 'total_blob_count'),
            ResticJson::int($data, 'snapshots_count'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function repositoryLock(string $id, array $data): RepositoryLock
    {
        return new RepositoryLock(
            $id,
            self::time(ResticJson::string($data, 'time')),
            ($data['exclusive'] ?? false) === true,
            ResticJson::string($data, 'hostname'),
            ResticJson::string($data, 'username'),
            ResticJson::int($data, 'pid'),
        );
    }

    /**
     * restic before 0.17 prints prune's text after the JSON line, on the same stdout.
     */
    public static function forgetResult(string $output): ForgetResult
    {
        $json = trim($output);
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '[')) {
                $json = $line;
                break;
            }
        }
        $data = json_decode($json, true);
        $kept = [];
        $removed = [];
        foreach (is_array($data) ? $data : [] as $group) {
            if (!is_array($group)) {
                continue;
            }
            array_push($kept, ...self::ids($group['keep'] ?? null));
            array_push($removed, ...self::ids($group['remove'] ?? null));
        }

        return new ForgetResult($kept, $removed);
    }
    public static function checkResult(int $exitCode, string $stdout, string $stderr): CheckResult
    {
        $errors = [];
        $broken = [];
        $summaryErrors = null;
        foreach ([...(preg_split('/\R/', $stdout) ?: []), ...(preg_split('/\R/', $stderr) ?: [])] as $line) {
            $data = ResticJson::decode($line);
            if ($data === null) {
                $line = trim($line);
                if ($line !== '' && preg_match(self::CHECK_PROGRESS, $line) !== 1) {
                    $errors[] = $line;
                }
                continue;
            }
            $type = ResticJson::string($data, 'message_type');
            if ($type === 'summary') {
                $summaryErrors = ResticJson::int($data, 'num_errors');
                foreach (is_array($data['broken_packs'] ?? null) ? $data['broken_packs'] : [] as $pack) {
                    if (is_string($pack)) {
                        $broken[] = $pack;
                    }
                }
            } elseif ($type === 'error' || $type === 'exit_error') {
                $message = trim(ResticJson::string($data, 'message'));
                if ($message !== '') {
                    $errors[] = $message;
                }
            }
        }
        $ok = $exitCode === 0 && ($summaryErrors ?? 0) === 0;

        return new CheckResult($ok, $ok ? [] : array_values(array_unique($errors)), $broken);
    }

    /**
     * restic prints times with nanoseconds, which PHP cannot parse: cut to microseconds.
     */
    public static function time(string $value): DateTimeImmutable
    {
        $value = (string) preg_replace('/(\.\d{6})\d+/', '$1', $value);
        $time = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $value);
        if ($time === false) {
            return new DateTimeImmutable('@0', new DateTimeZone('UTC'));
        }

        return $time;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @return list<string>
     */
    private static function ids(mixed $snapshots): array
    {
        $ids = [];
        foreach (is_array($snapshots) ? $snapshots : [] as $snapshot) {
            if (is_array($snapshot) && is_string($snapshot['id'] ?? null)) {
                $ids[] = $snapshot['id'];
            }
        }

        return $ids;
    }
}
