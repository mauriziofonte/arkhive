<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\Services\Process\Signals;

/**
 * Line-based prompts: questions go to stderr (stdout carries only results), answers are read a line
 * at a time from stdin, so they work in any terminal and over SSH without raw mode.
 */
final class StreamPrompter implements Prompter
{
    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private $input,
        private $output,
        private readonly Signals $signals,
        private readonly bool $colour = false,
    ) {
    }

    public function say(string $text): void
    {
        fwrite($this->output, $text . (str_ends_with($text, "\n") ? '' : "\n"));
    }

    public function ask(string $question, string $default = ''): string
    {
        $answer = $this->read($question . ($default === '' ? '' : ' [' . $default . ']') . ': ');

        return $answer === '' ? $default : $answer;
    }

    public function choose(string $question, array $options, string $default): string
    {
        $keys = array_keys($options);
        $this->say($this->bold($question));
        foreach ($keys as $i => $key) {
            $this->say(sprintf('  %s%d) %s', $key === $default ? '*' : ' ', $i + 1, $options[$key]));
        }
        $defaultNumber = (string) ((int) array_search($default, $keys, true) + 1);
        while (true) {
            $answer = $this->read("Choice [{$defaultNumber}]: ");
            if ($answer === '') {
                return $default;
            }
            if (ctype_digit($answer) && isset($keys[(int) $answer - 1])) {
                return $keys[(int) $answer - 1];
            }
            if (isset($options[$answer])) {
                return $answer;
            }
            $this->say('Please answer with a number from 1 to ' . count($keys) . '.');
        }
    }

    public function confirm(string $question, bool $default = true): bool
    {
        while (true) {
            $answer = strtolower($this->read($question . ($default ? ' [Y/n]: ' : ' [y/N]: ')));
            if ($answer === '') {
                return $default;
            }
            if (in_array($answer, ['y', 'yes'], true)) {
                return true;
            }
            if (in_array($answer, ['n', 'no'], true)) {
                return false;
            }
            $this->say('Please answer y or n.');
        }
    }

    public function typed(string $question, string $word): bool
    {
        return $this->read("{$question} Type {$word} to continue: ") === $word;
    }

    private function read(string $prompt): string
    {
        fwrite($this->output, $prompt);
        $line = fgets($this->input);
        $this->signals->check();
        if ($line === false) {
            fwrite($this->output, "\n");

            throw new UsageException('No answer: the input ended (Ctrl-D) before the restore was confirmed; nothing was restored');
        }

        return trim($line);
    }

    private function bold(string $text): string
    {
        return $this->colour ? "\033[1m{$text}\033[0m" : $text;
    }
}
