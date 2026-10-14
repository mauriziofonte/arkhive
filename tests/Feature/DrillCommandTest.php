<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mfonte\Arkhive\Application\Port\Notifier;
use Mfonte\Arkhive\Application\Port\ProcessRunner;
use Mfonte\Arkhive\Application\System\Dependencies;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Infrastructure\Mail\SmtpNotifier;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\CapturingTransport;
use Tests\Support\FakeBinaries;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;
use Tests\Support\TempDir;
use Tests\Support\WritesConfig;
use Tests\TestCase;

/**
 * spec §10.5: drill on the sample repository (latest backup of web1.example.com: e82340a7).
 */
final class DrillCommandTest extends TestCase
{
    use TempDir;
    use WritesConfig;

    private FakeRestic $restic;
    private CapturingTransport $mail;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        $runner = new FakeProcessRunner();
        $this->restic = (new FakeRestic())->withRepository()->install($runner);
        $this->mail = new CapturingTransport();
        $this->app->instance(ProcessRunner::class, $runner);
        $this->app->instance(Dependencies::class, FakeBinaries::dependencies("{$this->dir}/bin", ['restic']));
        $this->app->instance(Notifier::class, new SmtpNotifier($this->mail->factory()));
        $this->app->instance(Randomizer::class, new Randomizer(new Mt19937(3)));
    }

    public function testAPassingDrillIsExit0WithJsonAndANotification(): void
    {
        self::assertSame(ExitCode::SUCCESS, Artisan::call('drill', ['--config' => $this->writeConfig(self::notify()), '--json' => true, '--sample' => '3']));

        $json = json_decode(Artisan::output(), true);
        self::assertIsArray($json);
        self::assertTrue($json['passed']);
        self::assertSame('e82340a77eda41788f329309f282cd62f1d4cad85933d8b22b6e4e72a0ab05b2', $json['snapshot']);
        self::assertSame(3, $json['files_tested']);
        self::assertSame(5, $json['files_available']);
        self::assertSame([], $json['failures']);
        self::assertNull($json['database']);
        self::assertSame('e82340a77eda41788f329309f282cd62f1d4cad85933d8b22b6e4e72a0ab05b2', self::last($this->restic->callsOf('restore')[0]));
        self::assertMatchesRegularExpression('/success Restore drill PASSED on snapshot e82340a7: 3 of 5 files \(\d+ B\) restored and verified in \d+\.\ds/', $this->logOutput());
        self::assertSame([], $this->restic->callsOf('unlock'));

        $mail = $this->mail->sent[0];
        self::assertSame('[ArkHive] restore drill PASSED on web1.example.com', $mail->getSubject());
        self::assertStringContainsString('Files tested:  3 of 5', (string) $mail->getTextBody());
        self::assertStringContainsString('Database:      not tested (no --db)', (string) $mail->getTextBody());
    }

    public function testAFailingDrillIsExit1AndNotified(): void
    {
        $this->restic->on['restore'] = static fn (): array => [1, '', "Fatal: failed to restore: ciphertext verification failed\n"];

        self::assertSame(ExitCode::DATA_ERROR, Artisan::call('drill', ['--config' => $this->writeConfig(self::notify('failure'))]));

        self::assertStringContainsString('error Drill: the restore failed: restic restore (drill) failed (restic exit 1): Fatal: failed to restore: ciphertext verification failed', $this->logOutput());
        self::assertStringContainsString('error Restore drill FAILED on snapshot e82340a7: 1 problem(s)', $this->logOutput());
        $mail = $this->mail->sent[0];
        self::assertSame('[ArkHive] restore drill FAILED on web1.example.com', $mail->getSubject());
        self::assertStringStartsWith("The restore drill failed:\n  the restore failed: restic restore (drill) failed", (string) $mail->getTextBody());
    }

    public function testADrillThatCannotRunIsNotified(): void
    {
        $this->restic->catConfig = [12, '', FakeRestic::fixture('wrong-password-0.19.stderr')];

        self::assertSame(ExitCode::NO_PERMISSION, Artisan::call('drill', ['--config' => $this->writeConfig(self::notify('failure'))]));

        self::assertSame('[ArkHive] restore drill FAILED on web1.example.com', $this->mail->sent[0]->getSubject());
        self::assertStringStartsWith('The restore drill could not run: Wrong password', (string) $this->mail->sent[0]->getTextBody());
    }

    public function testBareMetalWorksWithoutAConfiguration(): void
    {
        file_put_contents("{$this->dir}/pw", "placeholder\n");
        chmod("{$this->dir}/pw", 0600);

        self::assertSame(ExitCode::SUCCESS, Artisan::call('drill', ['--repo' => '/srv/restic-repo', '--password-file' => "{$this->dir}/pw", '--host' => 'web1.example.com']));
        self::assertSame([], $this->mail->sent);
    }

    public function testTheSampleMustBeAPositiveWholeNumber(): void
    {
        foreach (['0', '-1', 'ten', '1.5', '100001'] as $value) {
            self::assertSame(ExitCode::USAGE, Artisan::call('drill', ['--config' => $this->writeConfig(), '--sample' => $value]), $value);
            self::assertStringContainsString("error --sample must be a whole number from 1 to 100000, not '{$value}'", $this->logOutput());
        }
        self::assertSame([], $this->restic->calls);
    }

    public function testDbWithoutADatabaseInTheConfigurationIsAUsageError(): void
    {
        self::assertSame(ExitCode::CONFIG, Artisan::call('drill', ['--config' => $this->writeConfig(self::notify()), '--db' => true]));
        self::assertStringContainsString('error --db needs a configuration with WITH_MYSQL or WITH_PGSQL', $this->logOutput());
        self::assertSame([], $this->mail->sent, 'a usage error is not a drill result');
    }

    public function testANotificationFailureOnAPassIsExit3(): void
    {
        $this->mail->failWith = 'Connection refused';

        self::assertSame(ExitCode::WARNING, Artisan::call('drill', ['--config' => $this->writeConfig(self::notify())]));
    }

    /**
     * @param list<string> $list
     */
    private static function last(array $list): ?string
    {
        return $list[array_key_last($list)] ?? null;
    }
}
