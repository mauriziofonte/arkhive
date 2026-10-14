<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restic;

/**
 * What `restic forget --json` decided, summed over its snapshot groups.
 */
final readonly class ForgetResult
{
    /**
     * @param list<string> $kept    snapshot ids
     * @param list<string> $removed snapshot ids
     */
    public function __construct(public array $kept, public array $removed)
    {
    }

    /**
     * restic before 0.17 prints prune's text after the JSON line, on the same stdout.
     */
    public static function fromJson(string $output): self
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

        return new self($kept, $removed);
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
