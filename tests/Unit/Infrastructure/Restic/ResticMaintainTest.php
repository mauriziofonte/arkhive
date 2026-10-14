<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Restic;

use Mfonte\Arkhive\Domain\Configuration\ResticSettings;
use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Infrastructure\Restic\Restic;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeProcessRunner;
use Tests\Support\FakeRestic;

/**
 * forget --prune, check and stats as run by maintain and status.
 */
final class ResticMaintainTest extends TestCase
{
    private FakeProcessRunner $runner;
    private FakeRestic $fake;
    private Restic $restic;

    protected function setUp(): void
    {
        $this->runner = new FakeProcessRunner();
        $this->fake = (new FakeRestic())->install($this->runner);
        $this->restic = new Restic(new ResticSettings('/srv/restic-repo', '/etc/arkhive/restic-password'), '/usr/bin/restic', $this->runner);
    }

    public function testForgetAppliesThePolicyToThisHostsArkhiveSnapshotsGroupedByHost(): void
    {
        $this->fake->answers['forget'] = [0, FakeRestic::fixture('forget-0.16.json'), ''];

        $result = $this->restic->forget('web1.example.com', 'arkhive', 7, 4, 6);

        self::assertSame(['forget', '--json', '--prune', '--host', 'web1.example.com', '--tag', 'arkhive', '--group-by', 'host', '--keep-daily', '7', '--keep-weekly', '4', '--keep-monthly', '6'], $this->fake->calls[0]);
        self::assertCount(5, $result->removed);
        self::assertNull($this->runner->commands[0]->timeout, 'a prune may take hours');
    }

    public function testAFailedForgetIsAnError(): void
    {
        $this->fake->answers['forget'] = [11, '', FakeRestic::fixture('locked-0.19.stderr')];

        $this->expectException(ArkhiveException::class);
        $this->expectExceptionMessage('The restic repository is locked');
        $this->restic->forget('web1.example.com', 'arkhive', 7, 4, 6);
    }

    public function testCheckWithAndWithoutReadingData(): void
    {
        $this->fake->answers['check'] = [0, FakeRestic::fixture('check-ok-0.19.jsonl'), ''];

        self::assertTrue($this->restic->check()->ok);
        self::assertTrue($this->restic->check('5%')->ok);
        self::assertSame([['check', '--json'], ['version'], ['check', '--json', '--read-data-subset=5%']], $this->fake->calls, 'the subset is fitted to the restic version');
    }

    public function testCheckExit1IsAResultNotAnException(): void
    {
        $this->fake->answers['check'] = [1, FakeRestic::fixture('check-damaged-0.19.jsonl'), FakeRestic::fixture('check-damaged-0.19.stderr')];

        $check = $this->restic->check('100%');

        self::assertFalse($check->ok);
        self::assertCount(1, $check->brokenPacks);
    }

    public function testCheckExit1WithoutRecognisableOutputKeepsResticsWords(): void
    {
        $this->fake->answers['check'] = [1, '', ''];
        self::assertSame(['restic check failed without a message'], $this->restic->check()->errors);

        $this->fake->answers['check'] = [1, '', "{\"message_type\":\"summary\",\"num_errors\":0}\n"];
        self::assertFalse($this->restic->check()->ok);
    }

    public function testCheckOnALockedOrUnreadableRepositoryThrows(): void
    {
        $this->fake->answers['check'] = [12, '', FakeRestic::fixture('wrong-password-0.19.stderr')];

        $this->expectException(ArkhiveException::class);
        $this->expectExceptionMessage('Wrong password for the restic repository /srv/restic-repo');
        $this->restic->check();
    }

    public function testStatsReadsTheRawDataSizeWithoutALock(): void
    {
        $this->fake->answers['stats'] = [0, FakeRestic::fixture('stats-raw-data-0.19.json'), ''];

        self::assertSame(52683, $this->restic->stats()->rawBytes);
        self::assertSame(['stats', '--json', '--no-lock', '--mode', 'raw-data'], $this->fake->calls[0]);
        self::assertSame(Restic::REACH_TIMEOUT, $this->runner->commands[0]->timeout);
    }

    public function testStatsWithoutASizeIsAnError(): void
    {
        $this->fake->answers['stats'] = [0, "{}\n", ''];

        $this->expectException(ArkhiveException::class);
        $this->expectExceptionMessage('restic stats did not report a size: {}');
        $this->restic->stats();
    }
}
