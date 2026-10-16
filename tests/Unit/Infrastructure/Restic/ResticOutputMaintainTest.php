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

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function missingPacks(): iterable
    {
        yield '0.19 JSON' => ['check-missing-0.19.jsonl', 'check-missing-0.19.stderr', ['a1d3d9469ac4a5d0b4cab5907701dbbb13e06f81021c0feb0d2eac4805140d57', '381d4654a98915f1e99861f0fc60381cff66d869fb07778655c9c1471495db09', 'ca8af50008f1b04859eafd395e33790a997cd5716d6b6e54bdfba5585e9720bf']];
        yield '0.18 JSON' => ['check-missing-0.18.jsonl', 'check-missing-0.18.stderr', ['5c182a151d8b3bf14e357fc6617aa6db6c7efbb4fcd54de8d0aca12a220bd924', '94971f424cc5015378d7cee9fa221eabc53ac16f98463c573a8e9f041ed78e01', 'b2f99d3cf4d0b7b908f3882089673f4cab4a4216f90dd0470babbe798b406a0b']];
        yield '0.17 text' => ['check-missing-0.17.txt', 'check-missing-0.17.stderr', ['957cd4778bf7a864335911f9d662ccdc6e992ad3d4a19adca086e929b89986e9', 'd4534473f5d4ec24adc0c8cd4e8a115653d02b89acc0302615267402a7ce82a8', '3492b8480c2b58bec8cb3370a2dd96b8972ac068733933bc176a2210882b4977']];
    }

    /**
     * @param list<string> $packs
     */
    #[DataProvider('missingPacks')]
    public function testPackFilesDeletedOnTheServerAreNamedAsMissing(string $stdout, string $stderr, array $packs): void
    {
        $check = ResticOutput::checkResult(1, FakeRestic::fixture($stdout), FakeRestic::fixture($stderr));

        self::assertFalse($check->ok);
        self::assertSame($packs, $check->missingPacks);
        self::assertTrue($check->dataMissing);
        self::assertSame([], $check->brokenPacks, 'missing packs need repair index, not repair packs');
        self::assertSame(sprintf('3 pack file(s) listed in the index are missing from the repository (%s, %s, %s): the snapshots that use them cannot be restored in full', substr($packs[0], 0, 8), substr($packs[1], 0, 8), substr($packs[2], 0, 8)), $check->describe());
        self::assertSame(sprintf('3 pack file(s) listed in the index are missing from the repository (%s, and 2 more): the snapshots that use them cannot be restored in full', substr($packs[0], 0, 8)), $check->describe(1));
    }

    public function testAMissingTreeIsMissingDataWithoutAPackId(): void
    {
        $check = ResticOutput::checkResult(1, '', "error for tree 4b2a: tree 4b2a not found in repository\n");

        self::assertTrue($check->dataMissing);
        self::assertSame([], $check->missingPacks);
        self::assertFalse(ResticOutput::checkResult(1, FakeRestic::fixture('check-damaged-0.19.jsonl'), FakeRestic::fixture('check-damaged-0.19.stderr'))->dataMissing);
        self::assertFalse(ResticOutput::checkResult(0, FakeRestic::fixture('check-ok-0.19.jsonl'), '')->dataMissing);
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
