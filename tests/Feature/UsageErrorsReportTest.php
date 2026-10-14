<?php

declare(strict_types=1);

namespace Tests\Feature;

use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Output\UsageErrors;
use Tests\TestCase;

final class UsageErrorsReportTest extends TestCase
{
    public function testOnePlainErrorLineAndTheHint(): void
    {
        self::assertSame(ExitCode::USAGE, UsageErrors::report("Unknown command \"x\"\nsecond line", 'List the commands with: arkhive list'));

        self::assertMatchesRegularExpression('/^\S+ error Unknown command "x" \| second line\n\S+ notice List the commands with: arkhive list\n$/', $this->logOutput());
    }

    public function testWithoutAHint(): void
    {
        self::assertSame(ExitCode::USAGE, UsageErrors::report('No arguments expected'));

        self::assertMatchesRegularExpression('/^\S+ error No arguments expected\n$/', $this->logOutput());
    }
}
