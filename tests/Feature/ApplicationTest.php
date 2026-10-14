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

        foreach (['backup', 'restore', 'ls', 'find', 'mount', 'maintain', 'status', 'init', 'drill', 'doctor', 'decrypt-legacy', 'self-update', 'schedule'] as $command) {
            self::assertContains($command, $commands);
        }
    }
}
