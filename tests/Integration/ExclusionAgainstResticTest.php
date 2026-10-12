<?php

declare(strict_types=1);

namespace Tests\Integration;

use Mfonte\Arkhive\Services\Config\ConfigParser;
use Mfonte\Arkhive\Services\Exclusion\ExclusionConverter;
use Mfonte\Arkhive\Services\Exclusion\ResticPatternMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyExclusionMatcher;
use Tests\Support\RequiresRestic;
use Tests\Support\TempDir;
use Tests\Support\TreeGenerator;

/**
 * The converted exclude file, fed to the real restic, keeps exactly the files 1.x kept.
 */
final class ExclusionAgainstResticTest extends TestCase
{
    use RequiresRestic;
    use TempDir;

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        yield 'tree 11' => [11];
        yield 'tree 12' => [12];
        yield 'tree 13' => [13];
    }

    #[DataProvider('seeds')]
    public function testResticKeepsWhat1xKept(int $seed): void
    {
        self::resticBinary();
        $dir = $this->tempDir();
        $root = "{$dir}/data";
        $files = TreeGenerator::files($seed, 150);
        foreach ($files as $file) {
            @mkdir(dirname($root . $file), 0700, true);
            file_put_contents($root . $file, $file);
        }

        $values = ConfigParser::parseFile(dirname(__DIR__, 2) . '/.arkhive-config.example');
        $patterns = array_merge(ExclusionConverter::split($values['EXCLUSION_PATTERNS']), ['/logs/*', '/docs/*', '/README.md', '*/with space/*']);
        $converted = ExclusionConverter::convert($patterns, [$root]);
        file_put_contents("{$dir}/excludes", ExclusionConverter::excludeFile($converted));
        file_put_contents("{$dir}/password", 'test');
        chmod("{$dir}/password", 0600);

        $repo = ['-r', "{$dir}/repo", '--password-file', "{$dir}/password", '--no-cache'];
        [$status, , $stderr] = self::restic(array_merge($repo, ['init']));
        self::assertSame(0, $status, $stderr);
        [$status, $stdout, $stderr] = self::restic(array_merge($repo, ['backup', '--dry-run', '--json', '-vv', '--exclude-file', "{$dir}/excludes", $root]));
        self::assertSame(0, $status, $stderr);

        $kept = [];
        foreach (explode("\n", trim($stdout)) as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && ($event['message_type'] ?? '') === 'verbose_status' && ($event['action'] ?? '') === 'new'
                && is_string($event['item']) && !str_ends_with($event['item'], '/')) {
                $kept[] = substr($event['item'], strlen($root));
            }
        }
        sort($kept);

        $expected1x = array_values(array_filter($files, static fn (string $f): bool => !LegacyExclusionMatcher::excludes($patterns, $f)));
        $expectedEmulated = array_values(array_filter($files, static fn (string $f): bool => !ResticPatternMatcher::excludes($converted, $root . $f)));
        sort($expected1x);
        sort($expectedEmulated);

        self::assertSame($expected1x, $kept, 'restic vs 1.x');
        self::assertSame($expectedEmulated, $kept, 'restic vs the emulated matcher');
    }
}
