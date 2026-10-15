<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Restic;

use Mfonte\Arkhive\Infrastructure\Restic\ResticJson;
use Mfonte\Arkhive\Infrastructure\Restic\ResticOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRestic;

/**
 * forget, check and stats output captured from restic 0.19.1 and 0.16.4.
 */
final class ResticOutputMaintainTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function forgetOutputs(): iterable
    {
        yield '0.19' => ['forget-0.19.json', 3, 7, '880c4702'];
        yield '0.16' => ['forget-0.16.json', 3, 5, '31d04026'];
    }

    #[DataProvider('forgetOutputs')]
    public function testForgetListsKeptAndRemovedSnapshots(string $fixture, int $kept, int $removed, string $firstRemoved): void
    {
        $result = ResticOutput::forgetResult(FakeRestic::fixture($fixture));

        self::assertCount($kept, $result->kept);
        self::assertCount($removed, $result->removed);
        self::assertStringStartsWith($firstRemoved, $result->removed[0]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result->kept[0]);
    }

    public function testForgetWithNothingToRemoveOrNoJson(): void
    {
        self::assertSame([[], []], [ResticOutput::forgetResult('[]')->kept, ResticOutput::forgetResult('[]')->removed]);
        self::assertSame([], ResticOutput::forgetResult('not json')->kept);
        self::assertSame([], ResticOutput::forgetResult('[{"keep":null,"remove":[{"short_id":"x"},"y"]}, 3]')->removed);
    }

    public function testAHealthyCheckOn019(): void
    {
        $check = ResticOutput::checkResult(0, FakeRestic::fixture('check-ok-0.19.jsonl'), '');

        self::assertTrue($check->ok);
        self::assertSame([], $check->errors);
        self::assertSame([], $check->brokenPacks);
        self::assertSame('no errors were found', $check->describe());
    }

    public function testAHealthyCheckOn016PrintsOnlyProgress(): void
    {
        $check = ResticOutput::checkResult(0, FakeRestic::fixture('check-ok-0.16.txt'), '');

        self::assertTrue($check->ok);
        self::assertSame([], $check->errors);
    }

    public function testADamagedPackOn019NamesThePack(): void
    {
        $check = ResticOutput::checkResult(1, FakeRestic::fixture('check-damaged-0.19.jsonl'), FakeRestic::fixture('check-damaged-0.19.stderr'));

        self::assertFalse($check->ok);
        self::assertSame(['9c44581e683bf99da4d31da1311e76e37c381784a311c637c56a2bd887b84af7'], $check->brokenPacks);
        self::assertStringStartsWith('pack 9c44581e683bf99da4d31da1311e76e37c381784a311c637c56a2bd887b84af7 contains 2 errors', $check->errors[0]);
        self::assertStringStartsWith('pack 9c44581e683bf99da4d31da1311e76e37c381784a311c637c56a2bd887b84af7 contains 2 errors: [blob ', $check->describe(1));
        self::assertStringEndsWith('; and 3 more', $check->describe(1));
        self::assertStringNotContainsString('Fatal: repository contains errors', $check->describe(10));
    }

    public function testADamagedPackOn016ComesFromTheText(): void
    {
        $check = ResticOutput::checkResult(1, '', FakeRestic::fixture('check-damaged-0.16.txt'));

        self::assertFalse($check->ok);
        self::assertSame(['9c44581e683bf99da4d31da1311e76e37c381784a311c637c56a2bd887b84af7'], $check->brokenPacks);
        self::assertSame('Pack ID does not match, want 9c44581e683bf99da4d31da1311e76e37c381784a311c637c56a2bd887b84af7, got 830bf7b2550d5746cd46e6c92e19c3ed720eb0626b74e058969cac91fcf52009', $check->describe());
    }

    public function testErrorsCountedInTheSummaryFailEvenWithExit0(): void
    {
        $check = ResticOutput::checkResult(0, '{"message_type":"summary","num_errors":2}', '');

        self::assertFalse($check->ok);
        self::assertSame('the repository contains errors', $check->describe());
    }

    public function testStatsRawData(): void
    {
        $data = ResticJson::decode(FakeRestic::fixture('stats-raw-data-0.19.json'));
        self::assertNotNull($data);
        $stats = ResticOutput::repositoryStats($data);

        self::assertSame(52683, $stats->rawBytes);
        self::assertSame(54295, $stats->uncompressedBytes);
        self::assertSame(12, $stats->blobs);
        self::assertSame(3, $stats->snapshots);
        self::assertNull(ResticOutput::repositoryStats(['total_size' => 10])->uncompressedBytes, 'repository v1 has no compression figures');
    }
}
