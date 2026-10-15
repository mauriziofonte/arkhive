<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Restic;

use Mfonte\Arkhive\Application\Process\Command;
use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Domain\Repository\Node;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Infrastructure\Restic\Restic;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;

/**
 * The read side of the restic client used by restore, ls and find.
 */
final class ResticReadTest extends TestCase
{
    private FakeProcessRunner $runner;
    private FakeRestic $fake;
    private Restic $restic;

    protected function setUp(): void
    {
        $this->runner = new FakeProcessRunner();
        $this->fake = (new FakeRestic())->withRepository()->install($this->runner);
        $this->restic = new Restic(new ResticSettings('/srv/restic-repo', '/etc/arkhive/restic-password'), '/usr/bin/restic', $this->runner);
    }

    public function testTheStoreExposesItsSettingsAndBinary(): void
    {
        self::assertSame('/srv/restic-repo', $this->restic->settings()->repository);
        self::assertSame('/usr/bin/restic', $this->restic->binary());
    }

    public function testMountRunsUntilResticExitsWithAnOptionalHost(): void
    {
        $this->fake->on['mount'] = static fn (): array => [0, '', ''];

        $this->restic->mount('/mnt/backup');
        $this->restic->mount('/mnt/backup', 'web1.example.com');

        self::assertSame([['mount', '--', '/mnt/backup'], ['mount', '--host', 'web1.example.com', '--', '/mnt/backup']], $this->fake->callsOf('mount'));
    }

    public function testAFailedMountIsClassified(): void
    {
        $this->fake->on['mount'] = static fn (): array => [1, '', "Fatal: wrong password or no key found\n"];

        $this->expectException(\Mfonte\Arkhive\Exceptions\PermissionException::class);
        $this->restic->mount('/mnt/backup');
    }

    public function testSnapshotsAreNewestFirstWithFilters(): void
    {
        $snapshots = $this->restic->snapshots('web1.example.com', ['/srv/www'], ['arkhive']);

        self::assertSame(['snapshots', '--json', '--host', 'web1.example.com', '--path', '/srv/www', '--tag', 'arkhive'], $this->fake->calls[0]);
        self::assertSame(['e82340a7', 'fa06bee8', '6118b3c5', '4231c203'], array_map(static fn ($s): string => $s->shortId(), $snapshots));
        self::assertSame(Restic::REACH_TIMEOUT, $this->runner->commands[0]->timeout);
    }

    public function testSnapshotsThatAreNotJsonAreAnError(): void
    {
        $this->fake->on['snapshots'] = static fn (): array => [0, "not json\n", ''];

        $this->expectException(ArkhiveException::class);
        $this->expectExceptionMessage('restic snapshots returned something that is not a JSON list: not json');
        $this->restic->snapshots();
    }

    public function testAnEmptyRepositoryHasNoSnapshots(): void
    {
        $this->fake->on['snapshots'] = static fn (): array => [0, "[]\n", ''];

        self::assertSame([], $this->restic->snapshots());
    }

    public function testLsStreamsOnlyNodes(): void
    {
        $nodes = [];
        $this->restic->ls('6118b3c5', ['/srv/www'], false, static function (Node $node) use (&$nodes): void {
            $nodes[] = $node->path;
        });

        self::assertSame(['ls', '--json', '--', '6118b3c5', '/srv/www'], $this->fake->calls[0]);
        self::assertSame(['/srv/www', '/srv/www/empty', '/srv/www/site', '/srv/www/with space'], $nodes);

        $this->restic->ls('6118b3c5', [], true, static function (): void {
        });
        self::assertSame(['ls', '--json', '--recursive', '--', '6118b3c5'], $this->fake->calls[1]);
    }

    public function testLsOfResticSixteen(): void
    {
        $this->fake->on['ls'] = static fn (): array => [0, FakeRestic::fixture('ls-0.16.jsonl'), ''];
        $count = 0;
        $this->restic->ls('6118b3c5', ['/srv/www'], false, static function () use (&$count): void {
            $count++;
        });

        self::assertSame(4, $count);
    }

    public function testFindGroupsMatchesBySnapshot(): void
    {
        $found = $this->restic->find('index.php', 'web1.example.com', ['6118b3c5']);

        self::assertSame(['find', '--json', '--host', 'web1.example.com', '--snapshot', '6118b3c5', '--', 'index.php'], $this->fake->calls[0]);
        self::assertCount(3, $found);
        self::assertSame('fa06bee805dda81ed2a395009d857a7187a02f260c95e52e923cd5440847965a', $found[0][0]);
        self::assertSame('/srv/www/site/index.php', $found[0][1]->path);
        self::assertSame([22, 22, 14], array_map(static fn (array $f): int => $f[1]->size, $found));
    }

    public function testFindWithoutMatches(): void
    {
        $this->fake->on['find'] = static fn (): array => [0, "[]\n", ''];
        self::assertSame([], $this->restic->find('nothing'));
        $this->fake->on['find'] = static fn (): array => [0, '', ''];
        self::assertSame([], $this->restic->find('nothing'));
        $this->fake->on['find'] = static fn (): array => [0, '"text"', ''];
        $this->expectException(ArkhiveException::class);
        $this->restic->find('nothing');
    }

    public function testRestoreSize(): void
    {
        self::assertSame(82, $this->restic->restoreSize('6118b3c5'));
        self::assertSame(['stats', '--json', '--mode', 'restore-size', '--', '6118b3c5'], $this->fake->calls[0]);

        $this->fake->on['stats'] = static fn (): array => [0, "{}\n", ''];
        $this->expectException(ArkhiveException::class);
        $this->expectExceptionMessage('restic stats did not report a size');
        $this->restic->restoreSize('6118b3c5');
    }

    public function testLocksAreReadWithoutLocking(): void
    {
        $id = str_repeat('c', 64);
        $gone = str_repeat('d', 64);
        $this->fake->on['list'] = static fn (): array => [0, "{$id}\n{$gone}\nnoise\n", ''];
        $this->fake->on['cat'] = static fn (array $args): array => end($args) === $id ? [0, FakeRestic::fixture('lock-0.19.json'), ''] : [1, '', "Fatal: lock not found\n"];

        $locks = $this->restic->locks();

        self::assertCount(1, $locks, 'a lock removed between list and cat is simply gone');
        self::assertSame(30066, $locks[0]->pid);
        self::assertSame(['list', 'locks', '--no-lock'], $this->fake->calls[0]);
        self::assertSame(['cat', 'lock', '--no-lock', '--', $id], $this->fake->calls[1]);
    }

    public function testDumpWritesToTheStream(): void
    {
        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        $this->restic->dump('6118b3c5', '/srv/www/site/index.php', $out);
        rewind($out);

        self::assertSame("dump of /srv/www/site/index.php\n", stream_get_contents($out));
        self::assertSame(['dump', '--', '6118b3c5', '/srv/www/site/index.php'], $this->fake->calls[0]);
        self::assertSame($out, $this->runner->commands[0]->output);
    }

    public function testADumpOfAMissingPathIsExplained(): void
    {
        $this->fake->on['dump'] = static fn (): array => [1, '', FakeRestic::fixture('dump-missing-0.19.stderr')];
        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($out);

        $this->expectException(ArkhiveException::class);
        $this->expectExceptionMessage('Reading /srv/www/nope from snapshot 6118b3c5 failed (restic exit 1): Fatal: cannot dump file: path "/srv/www/nope" not found in snapshot');
        $this->restic->dump('6118b3c5', '/srv/www/nope', $out);
    }

    public function testEveryReadCarriesTheRepositoryInTheEnvironmentOnly(): void
    {
        $this->restic->snapshots();
        $this->restic->find('x');
        foreach ($this->runner->commands as $command) {
            self::assertInstanceOf(Command::class, $command);
            self::assertSame('/srv/restic-repo', $command->env['RESTIC_REPOSITORY']);
            self::assertStringNotContainsString('restic-password', implode(' ', $command->argv));
        }
    }
}
