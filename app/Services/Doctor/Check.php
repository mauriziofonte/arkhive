<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Doctor;

/**
 * One line of the doctor report: what was checked, what was found, and how to fix it.
 */
final readonly class Check
{
    public function __construct(
        public string $name,
        public CheckStatus $status,
        public string $message,
        public ?string $hint = null,
    ) {
    }

    /**
     * @return array{check: string, status: string, message: string, hint: string|null}
     */
    public function toArray(): array
    {
        return ['check' => $this->name, 'status' => $this->status->value, 'message' => $this->message, 'hint' => $this->hint];
    }
}
