<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Notify;

/**
 * A plain-text e-mail about one run.
 */
final readonly class Notification
{
    /**
     * @param array<string, string> $facts label => value lines of the body
     */
    public function __construct(
        public bool $success,
        public string $subject,
        public string $headline,
        public array $facts,
    ) {
    }

    public function body(): string
    {
        $width = max(array_map('strlen', array_keys($this->facts)) ?: [0]);
        $lines = [$this->headline, ''];
        foreach ($this->facts as $label => $value) {
            $lines[] = str_pad($label . ':', $width + 2) . $value;
        }

        return implode("\n", $lines) . "\n";
    }
}
