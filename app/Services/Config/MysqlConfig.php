<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Config;

final readonly class MysqlConfig
{
    /**
     * @param list<string> $databases names, or ["*"] for all but the system schemas
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public ?string $password,
        public array $databases,
    ) {
    }

    public function allDatabases(): bool
    {
        return in_array('*', $this->databases, true);
    }
}
