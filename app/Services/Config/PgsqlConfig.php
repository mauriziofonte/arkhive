<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Config;

final readonly class PgsqlConfig
{
    /**
     * @param list<string> $databases
     * @param list<string> $dumpOptions extra pg_dump options (PGSQL_DUMP_OPTIONS)
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public ?string $password,
        public array $databases,
        public array $dumpOptions,
    ) {
    }
}
