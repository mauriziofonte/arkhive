<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Repository;

use Stringable;

/**
 * A restic release number ("0.14.0"), comparable.
 */
final readonly class ResticVersion implements Stringable
{
    public function __construct(
        public int $major,
        public int $minor,
        public int $patch,
    ) {
    }

    /**
     * From "0.19.1", or from the output of `restic version` ("restic 0.19.1 compiled with ...").
     */
    public static function parse(string $text): ?self
    {
        if (preg_match('/^(?:restic )?(\d+)\.(\d+)\.(\d+)/m', trim($text), $match) !== 1) {
            return null;
        }

        return new self((int) $match[1], (int) $match[2], (int) $match[3]);
    }

    public static function of(string $version): self
    {
        return self::parse($version) ?? throw new \InvalidArgumentException("Not a restic version: '{$version}'");
    }

    public function atLeast(string $version): bool
    {
        return $this->compare(self::of($version)) >= 0;
    }

    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    public function __toString(): string
    {
        return "{$this->major}.{$this->minor}.{$this->patch}";
    }
}
