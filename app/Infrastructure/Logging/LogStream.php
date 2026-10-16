<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Infrastructure\Logging;

/**
 * The stream log events are written to: stderr, or a memory stream in tests.
 */
final class LogStream
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDERR;
    }

    /**
     * @return resource
     */
    public function resource()
    {
        return $this->stream;
    }

    public static function memory(): self
    {
        $stream = fopen('php://memory', 'w+');
        assert($stream !== false);

        return new self($stream);
    }

    public function contents(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }
}
