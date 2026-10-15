<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

use DateTimeImmutable;

/**
 * A file, directory or other entry of a snapshot, from `restic ls --json` or `restic find --json`.
 */
final readonly class Node
{
    public function __construct(
        public string $path,
        public string $type,
        public int $size,
        public string $permissions,
        public DateTimeImmutable $mtime,
        public int $uid = 0,
        public int $gid = 0,
    ) {
    }

    public function name(): string
    {
        return basename($this->path);
    }

    public function isDirectory(): bool
    {
        return $this->type === 'dir';
    }

    public function isFile(): bool
    {
        return $this->type === 'file';
    }

    /**
     * @return array{path: string, type: string, size: int, permissions: string, mtime: string, uid: int, gid: int}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type,
            'size' => $this->size,
            'permissions' => $this->permissions,
            'mtime' => $this->mtime->format(DATE_ATOM),
            'uid' => $this->uid,
            'gid' => $this->gid,
        ];
    }
}
