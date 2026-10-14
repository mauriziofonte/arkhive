<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\State;

use DateTimeImmutable;
use Mfonte\Arkhive\Domain\Run\JobState;
use Mfonte\Arkhive\Domain\Run\RunRecord;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Infrastructure\State\JsonRunStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class JsonRunStateStoreTest extends TestCase
{
    use TempDir;

    private static function state(int $exit): JobState
    {
        return (new JobState())->with(new RunRecord('backup', new DateTimeImmutable('2026-10-12T03:00:00+00:00'), new DateTimeImmutable('2026-10-12T03:01:00+00:00'), $exit, 'e82340a7'));
    }

    public function testAStateIsWrittenPrivatelyAndReadBack(): void
    {
        $dir = $this->tempDir() . '/state/arkhive';
        $store = new JsonRunStateStore($dir);

        self::assertEquals(new JobState(), $store->load('backup'), 'nothing recorded yet');
        $store->save('backup', self::state(0));

        self::assertSame($dir, $store->location());
        self::assertSame(0700, fileperms($dir) & 0777);
        self::assertSame(0600, fileperms("{$dir}/backup.json") & 0777);
        self::assertEquals(self::state(0), $store->load('backup'));
        $json = json_decode((string) file_get_contents("{$dir}/backup.json"), true);
        self::assertIsArray($json);
        self::assertSame(0, $json['last']['exit_code']);
        self::assertSame(['backup.json'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])), 'no temporary file left');

        $store->save('backup', self::state(0)->with(new RunRecord('backup', new DateTimeImmutable('2026-10-13T03:00:00+00:00'), new DateTimeImmutable('2026-10-13T03:00:05+00:00'), 69)));
        self::assertSame(69, $store->load('backup')->last?->exitCode);
        self::assertSame(0, $store->load('backup')->lastSuccess?->exitCode);
    }

    public function testADamagedFileReadsAsNothingRecorded(): void
    {
        $dir = $this->tempDir();
        $store = new JsonRunStateStore($dir);
        file_put_contents("{$dir}/backup.json", '{"last": {"job": "backup"');
        file_put_contents("{$dir}/drill.json", '[]');
        mkdir("{$dir}/maintain.json");

        self::assertEquals(new JobState(), $store->load('backup'));
        self::assertEquals(new JobState(), $store->load('drill'));
        self::assertEquals(new JobState(), $store->load('maintain'));
    }

    public function testAnUnwritableDirectoryIsAnError(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/file", '');
        $store = new JsonRunStateStore("{$dir}/file/state");

        $this->expectException(ArkhiveException::class);
        $this->expectExceptionMessage("Cannot create the state directory {$dir}/file/state");
        $store->save('backup', self::state(0));
    }

    public function testAJobNameNeverEscapesTheDirectory(): void
    {
        $dir = $this->tempDir();
        $store = new JsonRunStateStore($dir);

        self::assertEquals(new JobState(), $store->load('../etc/passwd'));
        $this->expectException(ArkhiveException::class);
        $store->save('../x', self::state(0));
    }

    public function testWithoutADirectoryNothingIsStored(): void
    {
        $store = new JsonRunStateStore(null);

        self::assertNull($store->location());
        self::assertEquals(new JobState(), $store->load('backup'));
        $this->expectExceptionMessage('There is no state directory');
        $store->save('backup', self::state(0));
    }

    public function testAFailedRenameRemovesTheTemporaryFile(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/backup.json");
        mkdir("{$dir}/backup.json/occupied");
        $store = new JsonRunStateStore($dir);

        try {
            $store->save('backup', self::state(0));
            self::fail('expected a failure');
        } catch (ArkhiveException $e) {
            self::assertSame("Cannot write {$dir}/backup.json", $e->getMessage());
        }
        self::assertSame(['backup.json'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])));
    }
}
