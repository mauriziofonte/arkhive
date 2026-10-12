<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Restore;

use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Process\Signals;
use Mfonte\Arkhive\Services\Restore\StreamPrompter;
use PHPUnit\Framework\TestCase;

final class StreamPrompterTest extends TestCase
{
    /** @var resource */
    private $out;

    /**
     * @param list<string> $lines
     */
    private function prompter(array $lines, bool $colour = false): StreamPrompter
    {
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($in);
        self::assertIsResource($out);
        fwrite($in, implode('', array_map(static fn (string $l): string => $l . "\n", $lines)));
        rewind($in);
        $this->out = $out;

        return new StreamPrompter($in, $out, new Signals(), $colour);
    }

    private function written(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }

    public function testAskTakesTheDefaultOnEnter(): void
    {
        $prompter = $this->prompter(['', '  ./elsewhere  ']);

        self::assertSame('./restore', $prompter->ask('Restore into', './restore'));
        self::assertSame('./elsewhere', $prompter->ask('Restore into', './restore'));
        self::assertSame('Restore into [./restore]: Restore into [./restore]: ', $this->written());
    }

    public function testChooseByNumberKeyOrDefault(): void
    {
        $options = ['everything' => 'Everything', 'paths' => 'Pick files', 'stdout' => 'One file'];
        $prompter = $this->prompter(['', '2', 'stdout', '9', 'x', '1']);

        self::assertSame('everything', $prompter->choose('What?', $options, 'everything'));
        self::assertSame('paths', $prompter->choose('What?', $options, 'everything'));
        self::assertSame('stdout', $prompter->choose('What?', $options, 'everything'));
        self::assertSame('everything', $prompter->choose('What?', $options, 'stdout'), 'wrong answers are asked again');
        $written = $this->written();
        self::assertStringContainsString("What?\n  *1) Everything\n   2) Pick files\n   3) One file\nChoice [1]: ", $written);
        self::assertStringContainsString("   1) Everything\n   2) Pick files\n  *3) One file\nChoice [3]: ", $written);
        self::assertStringContainsString('Please answer with a number from 1 to 3.', $written);
    }

    public function testConfirm(): void
    {
        $prompter = $this->prompter(['', 'n', 'YES', 'maybe', 'no', '']);

        self::assertTrue($prompter->confirm('Start?'));
        self::assertFalse($prompter->confirm('Start?'));
        self::assertTrue($prompter->confirm('Start?'));
        self::assertFalse($prompter->confirm('Start?'));
        self::assertFalse($prompter->confirm('Start?', false));
        self::assertStringContainsString('Start? [Y/n]: ', $this->written());
        self::assertStringContainsString('Start? [y/N]: ', $this->written());
        self::assertStringContainsString('Please answer y or n.', $this->written());
    }

    public function testTypedConfirmationNeedsTheExactWord(): void
    {
        $prompter = $this->prompter(['overwrite', 'yes', '', 'Overwrite']);

        self::assertTrue($prompter->typed('Target not empty.', 'overwrite'));
        self::assertFalse($prompter->typed('Target not empty.', 'overwrite'));
        self::assertFalse($prompter->typed('Target not empty.', 'overwrite'), 'Enter is not a confirmation');
        self::assertFalse($prompter->typed('Target not empty.', 'overwrite'));
        self::assertStringContainsString('Target not empty. Type overwrite to continue: ', $this->written());
    }

    public function testEndOfInputAbortsAsAUsageError(): void
    {
        $prompter = $this->prompter([]);

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('No answer: the input ended (Ctrl-D) before the restore was confirmed; nothing was restored');
        $prompter->ask('Snapshot');
    }

    public function testSayAndColour(): void
    {
        $prompter = $this->prompter(['']);
        $prompter->say('line');
        $prompter->say("already\n");
        self::assertSame("line\nalready\n", $this->written());

        $coloured = $this->prompter([''], true);
        $coloured->choose('Q', ['a' => 'A'], 'a');
        self::assertStringContainsString("\033[1mQ\033[0m", $this->written());
    }
}
