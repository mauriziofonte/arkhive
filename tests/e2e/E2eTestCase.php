<?php

declare(strict_types=1);

namespace Tests\e2e;

use PHPUnit\Framework\TestCase;

/**
 * Base class for the end-to-end suite: everything comes from the E2E_* variables exported by run.sh.
 */
abstract class E2eTestCase extends TestCase
{
    protected static function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            self::fail("{$name} is not set: run the suite through tests/e2e/run.sh");
        }

        return $value;
    }
}
