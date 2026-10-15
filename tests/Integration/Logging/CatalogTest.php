<?php

declare(strict_types=1);

namespace Tests\Integration\Logging;

use Mfonte\Arkhive\Domain\Execution\MessageId;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

/**
 * journalctl compiles the message catalog and finds every event in it.
 */
final class CatalogTest extends TestCase
{
    use TempDir;

    public function testJournalctlCompilesTheCatalog(): void
    {
        if (!is_executable('/usr/bin/journalctl') && !is_executable('/bin/journalctl')) {
            self::markTestSkipped('journalctl is not installed');
        }
        $root = $this->tempDir();
        mkdir("{$root}/usr/lib/systemd/catalog", 0755, true);
        mkdir("{$root}/var/lib/systemd/catalog", 0755, true);
        file_put_contents("{$root}/usr/lib/systemd/catalog/arkhive.catalog", MessageId::catalog());

        [$status, $output] = self::journalctl(["--root={$root}", '--update-catalog']);
        self::assertSame(0, $status, $output);
        [$status, $output] = self::journalctl(["--root={$root}", '--list-catalog']);
        self::assertSame(0, $status, $output);
        foreach (MessageId::cases() as $id) {
            self::assertStringContainsString("{$id->value} arkhive: {$id->subject()}\n", $output);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private static function journalctl(array $args): array
    {
        $process = proc_open(['journalctl', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C']);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }
}
