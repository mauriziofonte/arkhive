<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

use Mfonte\Arkhive\Exceptions\UsageException;

/**
 * --owner: keep the owners recorded in the snapshot (needs root), or leave the files to the running user.
 */
enum OwnerMode: string
{
    case Preserve = 'preserve';
    case Current = 'current';

    public static function default(int $uid): self
    {
        return $uid === 0 ? self::Preserve : self::Current;
    }

    public static function fromOption(string $value, int $uid): self
    {
        $mode = self::tryFrom(strtolower(trim($value)));
        if ($mode === null) {
            throw new UsageException("--owner must be preserve or current, not '{$value}'");
        }
        if ($mode === self::Preserve && $uid !== 0) {
            throw new UsageException(
                "--owner preserve needs root: running as uid {$uid}, the restored files can only belong to you",
                'Run the restore as root to restore the original owners, or pass --owner current',
            );
        }

        return $mode;
    }

    public function describe(int $uid): string
    {
        return match ($this) {
            self::Preserve => 'original owners (as in the snapshot)',
            self::Current => $uid === 0 ? 'root (--owner current)' : "you (uid {$uid}); original owners are not restored",
        };
    }
}
