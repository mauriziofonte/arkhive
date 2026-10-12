<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Config;

use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Config\ConfigParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\TempDir;

final class ConfigParserTest extends TestCase
{
    use TempDir;

    public function testQuotingCommentsAndEmptyValues(): void
    {
        $content = <<<'CONF'
            # a comment
            PLAIN=value
            SPACED="two words"
            SINGLE='no $interpolation # here'
            HASH="value # not a comment"
            TRAILING=value # a comment
            EMPTY=
            EMPTY_QUOTED=""
            export EXPORTED=yes
            UNICODE="ünïcödé ✓"
            CONF;

        self::assertSame([
            'PLAIN' => 'value',
            'SPACED' => 'two words',
            'SINGLE' => 'no $interpolation # here',
            'HASH' => 'value # not a comment',
            'TRAILING' => 'value',
            'EMPTY' => '',
            'EMPTY_QUOTED' => '',
            'EXPORTED' => 'yes',
            'UNICODE' => 'ünïcödé ✓',
        ], ConfigParser::parse($content, 'test'));
    }

    public function testCrlfAndBomAreNormalised(): void
    {
        self::assertSame(
            ['A' => '1', 'B' => 'two words'],
            ConfigParser::parse("\xEF\xBB\xBFA=1\r\nB=\"two words\"\r\n", 'test'),
        );
        self::assertSame(['A' => '1', 'B' => '2'], ConfigParser::parse("A=1\rB=2", 'test'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformed(): iterable
    {
        yield 'unterminated double quote' => ["OK=1\nA=\"unterminated\nB=2\n", 'line 2: A has an unterminated double quote'];
        yield 'unterminated single quote' => ["A='open", 'line 1: A has an unterminated single quote'];
        yield 'not an assignment' => ["\n\njust words\n", 'line 3: expected KEY=value'];
        yield 'invalid key' => ['1KEY=x', 'line 1: expected KEY=value'];
        yield 'text after quotes' => ['A="x" y', 'line 1: unexpected text after the quoted value of A'];
        yield 'duplicate key' => ["A=1\n# c\nA=2", 'line 3: A is already set on line 1'];
    }

    #[DataProvider('malformed')]
    public function testMalformedContentIsAUsageErrorNamingTheFileAndLine(string $content, string $problem): void
    {
        $this->expectExceptionObject(new UsageException("Cannot parse the configuration file /etc/arkhive/config, {$problem}"));

        ConfigParser::parse($content, '/etc/arkhive/config');
    }

    public function testValuesThatPhpdotenvSilentlyDroppedAreKept(): void
    {
        self::assertSame(['K' => 'a,é-=":Z0Z_', 'L' => '="'], ConfigParser::parse("K='a,é-=\":Z0Z_'\nL='=\"'\n", 'test'));
    }

    public function testEscapesInDoubleQuotesAndNoVariableExpansion(): void
    {
        self::assertSame(
            ['A' => "x\ny\tz\\\"\$q\\w", 'B' => '${A} $A', 'C' => 'pa#ss'],
            ConfigParser::parse("A=\"x\\ny\\tz\\\\\\\"\\\$q\\w\"\nB=\${A} \$A\nC=pa#ss", 'test'),
        );
    }

    public function testParseFile(): void
    {
        $path = $this->tempDir() . '/config';
        file_put_contents($path, "A=b\n");

        self::assertSame(['A' => 'b'], ConfigParser::parseFile($path));
    }

    public function testUnreadableFileIsAUsageError(): void
    {
        $this->expectExceptionObject(new UsageException('Cannot read the configuration file /nonexistent/config'));

        ConfigParser::parseFile('/nonexistent/config');
    }

    /**
     * Fuzzing: random values rendered with every quoting style and line ending survive a round trip.
     */
    public function testRoundTripOfGeneratedConfigs(): void
    {
        $random = new Randomizer(new Mt19937(20261012));
        $alphabet = ['a', 'Z', '0', ' ', '#', '$', '\'', '"', '\\', '=', '/', '*', ',', 'é', '✓', '-', '_', '.', ':', '@', '%'];
        for ($round = 0; $round < 300; $round++) {
            $expected = [];
            $lines = [];
            for ($k = 0; $k < $random->getInt(1, 8); $k++) {
                $key = 'KEY_' . $round . '_' . $k;
                $value = '';
                for ($c = 0; $c < $random->getInt(0, 16); $c++) {
                    $value .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
                }
                $expected[$key] = $value;
                if ($random->getInt(0, 3) === 0) {
                    $lines[] = '# comment ' . $round;
                }
                $lines[] = $key . '=' . self::quote($value, $random);
            }
            $eol = ["\n", "\r\n"][$random->getInt(0, 1)];

            self::assertSame($expected, ConfigParser::parse(implode($eol, $lines) . $eol, 'fuzz'), "round {$round}");
        }
    }

    /**
     * Fuzzing: arbitrary bytes either parse or fail with a usage error, never with anything else.
     */
    public function testArbitraryInputNeverFailsUnexpectedly(): void
    {
        $random = new Randomizer(new Mt19937(7));
        $pieces = ['A', '=', '"', '\'', '\\', "\n", "\r\n", '#', ' ', 'export ', '${A}', 'é', "\0", "\xff", 'B=1'];
        for ($round = 0; $round < 500; $round++) {
            $content = '';
            for ($i = 0; $i < $random->getInt(0, 30); $i++) {
                $content .= $pieces[$random->getInt(0, count($pieces) - 1)];
            }
            try {
                $values = ConfigParser::parse($content, 'fuzz');
                foreach ($values as $key => $value) {
                    self::assertIsString($key);
                    self::assertIsString($value);
                }
            } catch (UsageException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function quote(string $value, Randomizer $random): string
    {
        if ($value === trim($value) && preg_match('/^[^\'"#\s][^#\s]*$/', $value) === 1 && $random->getInt(0, 2) === 0) {
            return $value . ($random->getInt(0, 1) === 0 ? '' : ' # trailing comment');
        }
        // single quotes keep everything literally but cannot contain a single quote
        if (!str_contains($value, '\'') && $random->getInt(0, 1) === 0) {
            return "'{$value}'";
        }

        return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"';
    }
}
