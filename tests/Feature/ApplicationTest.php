<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ApplicationTest extends TestCase
{
    public function testTheApplicationRegistersItsCommands(): void
    {
        $commands = array_keys(Artisan::all());

        self::assertContains('backup', $commands);
        self::assertContains('restore', $commands);
        self::assertContains('self-upgrade', $commands);
    }
}
