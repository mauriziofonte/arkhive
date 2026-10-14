<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Update;

final readonly class HttpResponse
{
    public function __construct(public int $status, public string $body)
    {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
