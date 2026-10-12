<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Exclusion;

use Mfonte\Arkhive\Services\Config\ConfigParser;
use Mfonte\Arkhive\Services\Exclusion\ExclusionConverter;
use Mfonte\Arkhive\Services\Exclusion\ResticPatternMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyExclusionMatcher;
use Tests\Support\TreeGenerator;

/**
 * A converted pattern excludes, under restic's matching rules, exactly what the 1.x matcher excluded
 * on generated trees (spec §13.1).
 */
final class ExclusionPropertyTest extends TestCase
{
    private const ROOT = '/srv/data';

    /**
     * @return list<string>
     */
    private static function patterns(): array
    {
        $values = ConfigParser::parseFile(dirname(__DIR__, 4) . '/.arkhive-config.example');

        return array_merge(
            ExclusionConverter::split($values['EXCLUSION_PATTERNS']),
            ['/logs/*', '/src/cache/*', '/README.md', '/docs/*', '/a/b/*', '*/with space/*', 'ünïcödé/*'],
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        for ($seed = 1; $seed <= 40; $seed++) {
            yield "tree {$seed}" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testConvertedPatternsExcludeExactlyWhat1xExcluded(int $seed): void
    {
        $patterns = self::patterns();
        $converted = ExclusionConverter::convert($patterns, [self::ROOT]);
        $files = TreeGenerator::files($seed);

        $legacyExcluded = 0;
        foreach ($files as $file) {
            $legacy = LegacyExclusionMatcher::excludes($patterns, $file);
            $restic = ResticPatternMatcher::excludes($converted, self::ROOT . $file);
            $legacyExcluded += $legacy ? 1 : 0;
            self::assertSame($legacy, $restic, "{$file}: 1.x " . ($legacy ? 'excluded' : 'kept') . ', restic ' . ($restic ? 'excluded' : 'kept'));
        }

        // the generated trees must exercise both outcomes
        self::assertGreaterThan(0, $legacyExcluded);
        self::assertLessThan(count($files), $legacyExcluded);
    }

    /**
     * Each pattern alone, so a wrong conversion cannot hide behind another pattern.
     *
     * @return iterable<string, array{string}>
     */
    public static function singlePatterns(): iterable
    {
        foreach (self::patterns() as $pattern) {
            yield $pattern => [$pattern];
        }
    }

    #[DataProvider('singlePatterns')]
    public function testEachPatternAlone(string $pattern): void
    {
        $converted = ExclusionConverter::convert([$pattern], [self::ROOT]);
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $seed) {
            foreach (TreeGenerator::files($seed) as $file) {
                self::assertSame(
                    LegacyExclusionMatcher::excludes([$pattern], $file),
                    ResticPatternMatcher::excludes($converted, self::ROOT . $file),
                    "{$pattern} on {$file}",
                );
            }
        }
    }
}
