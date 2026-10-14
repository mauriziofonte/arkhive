<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Commands\MountCommand;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Process\ProcessRunner;
use Mfonte\Arkhive\Services\System\Dependencies;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\TempDir;
use Tests\TestCase;

/**
 * spec §10.3: ls, find and mount.
 */
final class BrowseCommandsTest extends TestCase
{
    use TempDir;

    private FakeRestic $restic;
    private string $dir;
    /** @var array<string, string> */
    private array $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        $runner = new FakeProcessRunner();
        $this->restic = (new FakeRestic())->withRepository()->install($runner);
        $this->app->instance(ProcessRunner::class, $runner);
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic']));
        file_put_contents("{$this->dir}/password", "placeholder\n");
        chmod("{$this->dir}/password", 0600);
        $this->repo = ['--repo' => '/srv/restic-repo', '--password-file' => "{$this->dir}/password"];
    }

    protected function tearDown(): void
    {
        MountCommand::$fuseDevice = '/dev/fuse';
        parent::tearDown();
    }

    public function testLsListsADirectoryOfTheSnapshot(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('ls', ['snapshot' => '6118', 'path' => '/srv/www/site/', ...$this->repo]));

        self::assertSame(['ls', '--json', '--', '6118b3c5b2b1ffc9e12d6022dfcab6275e9621122cb2b8d5b66d11a67fdc5c59', '/srv/www/site'], $this->restic->callsOf('ls')[0]);
        $lines = explode("\n", rtrim(Artisan::output()));
        self::assertSame('Mode        Size  Modified          Path', $lines[0]);
        self::assertSame('drwxr-xr-x     -  2026-10-12 14:14  /srv/www/site/', $lines[1]);
        self::assertMatchesRegularExpression('#^-rw-r--r--  22 B  2026-10-12 14:14  /srv/www/site/index\.php$#', $lines[3]);
        self::assertStringContainsString('info Snapshot 6118b3c5 of web1.example.com', $this->logOutput());
    }

    public function testLsDefaultsToLatestAndTheBackedUpPaths(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('ls', ['--host' => 'web1.example.com', '--recursive' => true, '--json' => true, ...$this->repo]));

        self::assertSame(['ls', '--json', '--recursive', '--', 'e82340a77eda41788f329309f282cd62f1d4cad85933d8b22b6e4e72a0ab05b2', '/srv/big'], $this->restic->callsOf('ls')[0]);
    }

    public function testLsJson(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('ls', ['snapshot' => '6118', 'path' => '/srv/www', '--json' => true, ...$this->repo]));

        $lines = explode("\n", trim(Artisan::output()));
        self::assertCount(4, $lines);
        $first = json_decode($lines[0], true);
        self::assertIsArray($first);
        self::assertSame(['path' => '/srv/www', 'type' => 'dir', 'size' => 0, 'permissions' => 'drwxr-xr-x', 'mtime' => '2026-10-12T14:14:34+00:00', 'uid' => 0, 'gid' => 0], $first);
    }

    public function testLsErrors(): void
    {
        self::assertSame(ExitCode::USAGE, Artisan::call('ls', ['snapshot' => '6118', 'path' => 'srv/www', ...$this->repo]));
        self::assertStringContainsString("The path must be absolute, as it was backed up: 'srv/www'", $this->logOutput());

        self::assertSame(ExitCode::FAILURE, Artisan::call('ls', ['snapshot' => '6118', 'path' => '/srv/nothing', ...$this->repo]));
        self::assertStringContainsString('error /srv/nothing is not in snapshot 6118b3c5', $this->logOutput());
        self::assertStringContainsString("notice Find it with: arkhive find 'nothing'", $this->logOutput());
    }

    public function testFindShowsTheVersionsOfEachPath(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('find', ['pattern' => 'index.php', ...$this->repo]));

        self::assertSame([
            '/srv/www/site/index.php',
            '  Snapshot  Date              Host              Size  Modified',
            '  fa06bee8  2026-10-12 14:14  web2.example.com  22 B  2026-10-12 14:14:37',
            '  6118b3c5  2026-10-12 14:14  web1.example.com  22 B  2026-10-12 14:14:37  changed',
            '  4231c203  2026-10-12 14:14  web1.example.com  14 B  2026-10-12 14:14:34  oldest',
        ], explode("\n", rtrim(Artisan::output())));
        self::assertStringContainsString('Restore one version with: arkhive restore --snapshot <id> --include <path> --target <new directory>', $this->logOutput());
    }

    public function testFindJsonAndHost(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('find', ['pattern' => 'index.php', '--host' => 'web1.example.com', '--json' => true, ...$this->repo]));

        self::assertSame(['find', '--json', '--host', 'web1.example.com', '--', 'index.php'], $this->restic->callsOf('find')[0]);
        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertSame([false, true, true], array_column($json, 'changed'), 'newest first; the oldest counts as a change');
        self::assertSame('/srv/www/site/index.php', $json[0]['path']);
        self::assertSame(22, $json[0]['size']);
    }

    public function testFindWithoutMatchesIsAnError(): void
    {
        $this->restic->on['find'] = static fn (): array => [0, "[]\n", ''];

        self::assertSame(ExitCode::FAILURE, Artisan::call('find', ['pattern' => 'nginx.conf', ...$this->repo]));
        self::assertStringContainsString("error Nothing matching 'nginx.conf' is in any snapshot", $this->logOutput());
        self::assertStringContainsString("arkhive find '*nginx.conf*'", $this->logOutput());
    }

    public function testMountWithoutFusePointsToLsAndRestore(): void
    {
        MountCommand::$fuseDevice = "{$this->dir}/no-fuse";
        mkdir("{$this->dir}/mnt");

        self::assertSame(ExitCode::FAILURE, Artisan::call('mount', ['directory' => "{$this->dir}/mnt", ...$this->repo]));
        self::assertStringContainsString("error FUSE is not available on this host ({$this->dir}/no-fuse does not exist), so the repository cannot be mounted", $this->logOutput());
        self::assertStringContainsString('notice Browse without FUSE: arkhive ls latest /path, arkhive find <name>, then arkhive restore --include /path --target <new directory>', $this->logOutput());
        self::assertSame([], $this->restic->calls, 'restic is not even started');
    }

    public function testMountWithoutFusermountNamesThePackage(): void
    {
        touch("{$this->dir}/fuse");
        MountCommand::$fuseDevice = "{$this->dir}/fuse";
        mkdir("{$this->dir}/mnt");

        self::assertSame(ExitCode::FAILURE, Artisan::call('mount', ['directory' => "{$this->dir}/mnt", ...$this->repo]));
        self::assertStringContainsString('(fusermount is not installed)', $this->logOutput());
        self::assertStringContainsString('To enable mounting: Run: apt install fuse3', $this->logOutput());
    }

    public function testMountRunsResticMountUntilInterrupted(): void
    {
        touch("{$this->dir}/fuse");
        MountCommand::$fuseDevice = "{$this->dir}/fuse";
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic', 'fusermount3']));
        mkdir("{$this->dir}/mnt");
        $this->restic->on['mount'] = static fn (): array => [0, '', ''];

        self::assertSame(ExitCode::SUCCESS, Artisan::call('mount', ['directory' => "{$this->dir}/mnt", '--host' => 'web1.example.com', ...$this->repo]));
        self::assertSame(['mount', '--host', 'web1.example.com', '--', "{$this->dir}/mnt"], $this->restic->callsOf('mount')[0]);
        self::assertStringContainsString("Mounting the repository read-only on {$this->dir}/mnt", $this->logOutput());
    }

    public function testMountPointMustBeAnEmptyDirectory(): void
    {
        touch("{$this->dir}/fuse");
        MountCommand::$fuseDevice = "{$this->dir}/fuse";
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic', 'fusermount3']));

        self::assertSame(ExitCode::USAGE, Artisan::call('mount', ['directory' => "{$this->dir}/missing", ...$this->repo]));
        self::assertStringContainsString('is not a directory', $this->logOutput());
        mkdir("{$this->dir}/full");
        touch("{$this->dir}/full/x");
        self::assertSame(ExitCode::USAGE, Artisan::call('mount', ['directory' => "{$this->dir}/full", ...$this->repo]));
        self::assertStringContainsString('is not empty', $this->logOutput());
    }
}
