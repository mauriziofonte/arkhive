<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restic;

/**
 * The outcome of `restic check`. restic >= 0.17 reports a JSON summary and JSON error lines;
 * older versions print text, so the errors then come from the plain output.
 */
final readonly class CheckResult
{
    /** the lines restic check prints as text when everything is fine; anything else is a finding */
    private const PROGRESS = '/^(using temporary cache in |create (exclusive )?lock|load(ing)? ind|check (all packs|snapshots)|read (all data|[\\d.]+% of)|no errors were found|\\[\\d+:\\d+\\]|\\d+ additional files were found|counting files|ignoring|Fatal: repository contains errors)/';

    /**
     * @param list<string> $errors      what restic reported, in order
     * @param list<string> $brokenPacks pack ids (restic >= 0.17)
     */
    public function __construct(public bool $ok, public array $errors, public array $brokenPacks)
    {
    }

    public static function fromOutput(int $exitCode, string $stdout, string $stderr): self
    {
        $errors = [];
        $broken = [];
        $summaryErrors = null;
        foreach ([...(preg_split('/\R/', $stdout) ?: []), ...(preg_split('/\R/', $stderr) ?: [])] as $line) {
            $data = ResticJson::decode($line);
            if ($data === null) {
                $line = trim($line);
                if ($line !== '' && preg_match(self::PROGRESS, $line) !== 1) {
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

        return new self($ok, $ok ? [] : array_values(array_unique($errors)), $broken);
    }

    /**
     * The first lines of the report, for a log line or a notification.
     */
    public function describe(int $max = 3): string
    {
        if ($this->ok) {
            return 'no errors were found';
        }
        $lines = [];
        foreach ($this->errors as $error) {
            $first = trim((string) strtok($error, "\n"));
            if ($first !== '' && !str_starts_with($first, 'Fatal: repository contains errors')) {
                $lines[] = $first;
            }
        }
        if ($lines === []) {
            return 'the repository contains errors';
        }
        $shown = array_slice($lines, 0, $max);
        $more = count($lines) - count($shown);

        return implode('; ', $shown) . ($more > 0 ? "; and {$more} more" : '');
    }
}
