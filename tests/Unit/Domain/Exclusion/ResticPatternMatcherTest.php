<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exclusion;

use Mfonte\Arkhive\Domain\Exclusion\ResticPatternMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResticPatternMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'name anywhere' => ['vendor', '/srv/app/vendor', true];
        yield 'below a matched dir' => ['vendor', '/srv/app/vendor/autoload.php', true];
        yield 'name is a component, not a substring' => ['vendor', '/srv/app/myvendor/x', false];
        yield 'glob on the file name' => ['*.log', '/srv/logs/app.log', true];
        yield 'glob does not match other names' => ['*.log', '/srv/logs/app.log.1', false];
        yield 'glob matching a directory' => ['*.log', '/srv/x.log/inner.txt', true];
        yield 'star does not cross a separator' => ['a*b', '/srv/a/x/b', false];
        yield 'absolute pattern' => ['/srv/www/logs/*', '/srv/www/logs/app.txt', true];
        yield 'absolute pattern below' => ['/srv/www/logs/*', '/srv/www/logs/sub/app.txt', true];
        yield 'absolute pattern elsewhere' => ['/srv/www/logs/*', '/other/srv/www/logs/app.txt', false];
        yield 'absolute pattern does not match the dir itself' => ['/srv/www/logs/*', '/srv/www/logs', false];
        yield 'relative multi-component' => ['a/b', '/x/a/b/c', true];
        yield 'relative multi-component order' => ['a/b', '/x/b/a', false];
        yield 'question mark' => ['?.txt', '/a/b.txt', true];
        yield 'question mark length' => ['?.txt', '/a/bb.txt', false];
        yield 'character class' => ['[ab].txt', '/x/b.txt', true];
        yield 'negated class' => ['[^ab].txt', '/x/b.txt', false];
        yield 'negated class match' => ['[^ab].txt', '/x/c.txt', true];
        yield 'escaped star' => ['\\*.txt', '/x/*.txt', true];
        yield 'escaped star literal' => ['\\*.txt', '/x/a.txt', false];
        yield 'double star' => ['/srv/**/cache', '/srv/a/b/cache/x', true];
        yield 'double star zero dirs' => ['/srv/**/cache', '/srv/cache', true];
        yield 'double star miss' => ['/srv/**/cache', '/var/a/cache', false];
        yield 'tilde' => ['*~', '/a/notes.txt~', true];
        yield 'dot files' => ['.git', '/a/.git/config', true];
        yield 'unicode' => ['*.ünï', '/a/f.ünï', true];
        yield 'pattern longer than path' => ['a/b/c', '/a/b', false];
        yield 'empty pattern' => ['', '/a', false];
        yield 'trailing slash on pattern' => ['vendor/', '/a/vendor/x', true];
        yield 'double slashes are cleaned' => ['/srv//www', '/srv/www/x', true];
    }

    #[DataProvider('cases')]
    public function testMatch(string $pattern, string $path, bool $expected): void
    {
        self::assertSame($expected, ResticPatternMatcher::match($pattern, $path));
    }

    public function testExcludesIsAnyPattern(): void
    {
        self::assertTrue(ResticPatternMatcher::excludes(['*.log', 'vendor'], '/a/vendor/b'));
        self::assertFalse(ResticPatternMatcher::excludes(['*.log', 'vendor'], '/a/src/b.php'));
        self::assertFalse(ResticPatternMatcher::excludes([], '/a'));
    }
}
