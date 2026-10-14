<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exclusion;

use Mfonte\Arkhive\Application\Configuration\ConfigParser;
use Mfonte\Arkhive\Domain\Exclusion\ExclusionConverter;
use Mfonte\Arkhive\Domain\Exclusion\ResticPatternMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExclusionConverterTest extends TestCase
{
    /**
     * The spec §5 table.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function specTable(): iterable
    {
        yield 'anchored' => ['/logs/*', ['/srv/www/logs/*', '/etc/logs/*']];
        yield 'file name anywhere' => ['*.log', ['/srv/www/**/*.log', '/etc/**/*.log']];
        yield 'directory anywhere' => ['node_modules/*', ['/srv/www/**/node_modules', '/etc/**/node_modules']];
        yield 'directory below the root' => ['*/vendor/*', ['/srv/www/**/vendor', '/etc/**/vendor']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('specTable')]
    public function testSpecTable(string $pattern, array $expected): void
    {
        self::assertSame($expected, ExclusionConverter::convert([$pattern], ['/srv/www', '/etc']));
    }

    /**
     * Every pattern shipped in .arkhive-config.example, with its expected restic pattern.
     *
     * @return array<string, string>
     */
    private static function examplePatterns(): array
    {
        return [
            '*/backups/*' => 'backups', '*/node_modules/*' => 'node_modules', 'npm-debug.log' => 'npm-debug.log',
            'yarn-error.log' => 'yarn-error.log', 'package-lock.json' => 'package-lock.json', 'yarn.lock' => 'yarn.lock',
            'pnpm-lock.yaml' => 'pnpm-lock.yaml', '.eslintcache' => '.eslintcache', '.stylelintcache' => '.stylelintcache',
            '*/vendor/*' => 'vendor', '*/composer.lock' => 'composer.lock', '*/.git/*' => '.git', '*/.svn/*' => '.svn',
            '*/.hg/*' => '.hg', '*/.idea/*' => '.idea', '*/.vscode/*' => '.vscode', '*/.history/*' => '.history',
            '*/.DS_Store' => '.DS_Store', 'Thumbs.db' => 'Thumbs.db', 'desktop.ini' => 'desktop.ini',
            '*.sublime-project' => '*.sublime-project', '*.sublime-workspace' => '*.sublime-workspace', '*.pid' => '*.pid',
            '*.sock' => '*.sock', '*.log' => '*.log', '*.rar' => '*.rar', '*.7z' => '*.7z', '*.zip' => '*.zip',
            '*.tar' => '*.tar', '*.tar.gz' => '*.tar.gz', '*.tar.xz' => '*.tar.xz', '*.iso' => '*.iso', '*.img' => '*.img',
            '*.dmg' => '*.dmg', '*.exe' => '*.exe', '*.dll' => '*.dll', '*.so' => '*.so', '*.dylib' => '*.dylib',
            '*.bin' => '*.bin', '*.o' => '*.o', '*.a' => '*.a', '*.class' => '*.class', '*.jar' => '*.jar', '*.war' => '*.war',
            '*.pyc' => '*.pyc', '*.pyo' => '*.pyo', '__pycache__/*' => '__pycache__', '*/node/*' => 'node', '*/go/*' => 'go',
            '*/.nvm/*' => '.nvm', '*/.rbenv/*' => '.rbenv', '*/.pyenv/*' => '.pyenv', '*/.cargo/*' => '.cargo',
            '*/.rustup/*' => '.rustup', '*/tmp/*' => 'tmp', '*/temp/*' => 'temp', '*/cache/*' => 'cache', '*/build/*' => 'build',
            '*/dist/*' => 'dist', '*/out/*' => 'out', '*/target/*' => 'target', '*/coverage/*' => 'coverage',
            '*/.pytest_cache/*' => '.pytest_cache', '*/.tox/*' => '.tox', '*.swp' => '*.swp', '*.swo' => '*.swo',
            '*.swn' => '*.swn', '*~' => '*~', '.env.local' => '.env.local', '.env.*.local' => '.env.*.local',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examplePatternCases(): iterable
    {
        foreach (self::examplePatterns() as $pattern => $restic) {
            yield $pattern => [$pattern, $restic];
        }
    }

    #[DataProvider('examplePatternCases')]
    public function testEveryPatternOfTheExampleConfig(string $pattern, string $restic): void
    {
        self::assertSame(['/srv/**/' . $restic], ExclusionConverter::convert([$pattern], ['/srv']));
    }

    public function testTheExampleConfigShipsExactlyTheTestedPatterns(): void
    {
        $values = ConfigParser::parseFile(dirname(__DIR__, 4) . '/.arkhive-config.example');

        self::assertSame(array_keys(self::examplePatterns()), ExclusionConverter::split($values['EXCLUSION_PATTERNS']));
    }

    public function testLiteralPatternsMatchOnlyTheirPathAndRoundTrip(): void
    {
        $names = ['/srv/www/plain.txt', '/srv/a[1].txt', '/srv/st*r?', '/srv/back\\slash', '/srv/]odd[', '/srv/ünï cödé'];
        foreach ($names as $name) {
            $pattern = ExclusionConverter::literal($name);
            self::assertTrue(ResticPatternMatcher::excludes([$pattern], $name), $name);
            self::assertSame($name, ExclusionConverter::unliteral($pattern), $name);
        }
        self::assertSame('/srv/a\\[1\\].txt', ExclusionConverter::literal('/srv/a[1].txt'));
        self::assertFalse(ResticPatternMatcher::excludes([ExclusionConverter::literal('/srv/a[1].txt')], '/srv/a1.txt'));
    }

    public function testUnliteralRejectsWildcards(): void
    {
        foreach (['*.php', '/srv/a?', '/srv/[ab]', '/srv/trailing\\', '/srv/x]'] as $pattern) {
            self::assertNull(ExclusionConverter::unliteral($pattern), $pattern);
        }
    }

    public function testSplitTrimsAndDropsEmptyEntries(): void
    {
        self::assertSame(['*.log', '/tmp/*', 'a b'], ExclusionConverter::split(' *.log, ,/tmp/* ,,a b,'));
        self::assertSame([], ExclusionConverter::split(''));
    }

    public function testAnchoredPatternsAreAnchoredToEveryBackupPath(): void
    {
        self::assertSame(
            ['/var/www/cache/*', '/home/a b/cache/*', '/cache/*', '/var/www/private.key', '/home/a b/private.key', '/private.key'],
            ExclusionConverter::convert(['/cache/*', '/private.key'], ['/var/www/', '/home/a b', '/']),
        );
    }

    public function testDirectoriesAboveTheBackupRootNeverMatch(): void
    {
        // restic matches bare patterns against every path component: "tmp" would exclude all of /var/tmp/app
        $converted = ExclusionConverter::convert(['*/tmp/*', '*.log', 'cache/*'], ['/var/tmp/app', '/srv/cache.log/site']);

        self::assertFalse(ResticPatternMatcher::excludes($converted, '/var/tmp/app/index.php'));
        self::assertFalse(ResticPatternMatcher::excludes($converted, '/srv/cache.log/site/index.php'));
        self::assertTrue(ResticPatternMatcher::excludes($converted, '/var/tmp/app/tmp/x'));
        self::assertTrue(ResticPatternMatcher::excludes($converted, '/srv/cache.log/site/a/cache/x'));
    }

    public function testDegeneratePatternsAreDropped(): void
    {
        self::assertSame([], ExclusionConverter::convert(['', '  ', '/', '*/', '//'], ['/srv']));
    }

    public function testRepeatedWildcardSegmentsAreStrippedAndDuplicatesRemoved(): void
    {
        self::assertSame(['/srv/**/vendor', '/srv/**/a/b'], ExclusionConverter::convert(['*/*/vendor/*/*', 'vendor/*', '*/a/b/*'], ['/srv']));
    }

    public function testBackupPathsAreEscapedAsLiteralPrefixes(): void
    {
        self::assertSame(
            ['/srv/a\\[1\\]\\*/**/*.log', '/**/*.log', '/srv/a\\[1\\]\\*/logs/*', '/logs/*'],
            ExclusionConverter::convert(['*.log', '/logs/*'], ['/srv/a[1]*', '/']),
        );
    }

    public function testExcludeFileEscapesRestricMetacharacters(): void
    {
        self::assertSame(
            "*.log\n\\#notes\n\\!keep\nprice\$\$list\nvendor\n",
            ExclusionConverter::excludeFile(['*.log', '#notes', '!keep', 'price$list', 'vendor']),
        );
        self::assertSame('', ExclusionConverter::excludeFile([]));
    }
}
