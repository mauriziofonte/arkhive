<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Execution;

/**
 * The journal MESSAGE_ID of the events that open and close a run, so that `journalctl
 * MESSAGE_ID=...` finds every failed backup; explained in contrib/systemd/arkhive.catalog.
 */
enum MessageId: string
{
    case BackupStarted = '038e5a6f18f6184144c8b36819606286';
    case BackupSucceeded = '763619ea04f7dbbf790ad973e212db7a';
    case BackupFailed = '0dca6b902c5ac3d8b27d93e0ffcc4c5e';
    case MaintainStarted = 'bedaa88476b29d5823188e7b4166a770';
    case MaintainSucceeded = '6dc1eccd2541e9f90d03624658f290b5';
    case MaintainFailed = '4d903cbc18aebe5d3f5104e8ffafb680';
    case DrillStarted = '4ab9cf2c02c5ec5cec9909a01e690f8d';
    case DrillSucceeded = '9a5fe499bf6fa3dd1568b001e32b99bd';
    case DrillFailed = 'f7cdf89ae28a1c3f76aa4e8eb6dafb68';
    case RestoreStarted = '7c93417303d1095eb82437b0d94fb351';
    case RestoreSucceeded = '45d7795db9c8033e2dad8ea399e8952b';
    case RestoreFailed = '0786ecc48c256aa9399d4030f793119c';

    public const DOCUMENTATION = 'https://github.com/mauriziofonte/arkhive#logging';

    private const WHAT = [
        'backup' => ['ArkHive backup', 'The backup of the configured paths and database dumps to the restic repository'],
        'maintain' => ['ArkHive maintenance', 'The maintenance of the restic repository (retention, prune, structure check and data read back)'],
        'drill' => ['ArkHive restore drill', 'The restore drill (a sample of the latest snapshot restored and verified)'],
        'restore' => ['ArkHive restore', 'A restore from the restic repository'],
    ];

    /**
     * The event of a command's start (exit null) or end; null for commands that are not runs.
     */
    public static function forRun(string $command, ?int $exit): ?self
    {
        if (!isset(self::WHAT[$command])) {
            return null;
        }
        $outcome = match (true) {
            $exit === null => 'Started',
            $exit === 0 || $exit === 3 => 'Succeeded',
            default => 'Failed',
        };

        $name = ucfirst($command) . $outcome;
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }

    public function command(): string
    {
        return strtolower((string) preg_replace('/(Started|Succeeded|Failed)$/', '', $this->name));
    }

    public function subject(): string
    {
        $label = self::WHAT[$this->command()][0];

        return match (true) {
            str_ends_with($this->name, 'Started') => "{$label} started",
            str_ends_with($this->name, 'Succeeded') => "{$label} succeeded",
            default => "{$label} failed",
        };
    }

    public function explanation(): string
    {
        $what = self::WHAT[$this->command()][1];
        $command = $this->command();

        return match (true) {
            str_ends_with($this->name, 'Started') => "{$what} started (arkhive {$command}, run @ARKHIVE_RUN_ID@).\n"
                . "Every event of this run carries ARKHIVE_RUN_ID=@ARKHIVE_RUN_ID@; the run ends with a\n"
                . 'succeeded or a failed event.',
            str_ends_with($this->name, 'Succeeded') => "{$what} ended with exit status @ARKHIVE_EXIT_STATUS@:\n"
                . '0 is a success, 3 a success with warnings (logged before this event).',
            default => "{$what} failed with exit status @ARKHIVE_EXIT_STATUS@.\n\n"
                . "The status is a sysexits(3) code: 64 usage, 65 data error (a check or a drill\n"
                . "found damage), 66 missing input, 69 unavailable (repository, server or tool\n"
                . "unreachable), 73 output refused, 74 I/O error (disk full), 75 temporary (lock\n"
                . "held, deadline passed: the next run may succeed), 77 permission denied,\n"
                . "78 configuration error, 130 or 143 interrupted, 1 any other failure.\n\n"
                . "The error and its hint are the events before this one with the same\n"
                . "ARKHIVE_RUN_ID=@ARKHIVE_RUN_ID@: journalctl ARKHIVE_RUN_ID=@ARKHIVE_RUN_ID@.\n"
                . '`arkhive doctor` checks the whole setup.',
        };
    }

    /**
     * The message catalog (journalctl --update-catalog format) of every event.
     */
    public static function catalog(): string
    {
        $text = "# ArkHive message catalog: install as /usr/lib/systemd/catalog/arkhive.catalog,\n"
            . "# then run journalctl --update-catalog. journalctl -x shows these explanations.\n";
        foreach (self::cases() as $id) {
            $text .= "\n-- {$id->value}\n"
                . "Subject: {$id->subject()}\n"
                . "Defined-By: arkhive\n"
                . 'Documentation: ' . self::DOCUMENTATION . "\n\n"
                . $id->explanation() . "\n";
        }

        return $text;
    }
}
