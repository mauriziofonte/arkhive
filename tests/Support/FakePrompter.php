<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Application\Port\Prompter;
use RuntimeException;

/**
 * Scripted answers: '' takes the default, like pressing Enter. Records every question asked.
 */
final class FakePrompter implements Prompter
{
    /** @var list<string> */
    public array $questions = [];

    public string $transcript = '';

    /**
     * @param list<string> $answers
     */
    public function __construct(public array $answers = [])
    {
    }

    public function say(string $text): void
    {
        $this->transcript .= $text . "\n";
    }

    public function ask(string $question, string $default = ''): string
    {
        $answer = $this->next($question);

        return $answer === '' ? $default : $answer;
    }

    public function choose(string $question, array $options, string $default): string
    {
        $this->transcript .= $question . "\n" . implode("\n", $options) . "\n";
        $answer = $this->next($question);
        if ($answer === '') {
            return $default;
        }
        $keys = array_keys($options);
        if (ctype_digit($answer) && isset($keys[(int) $answer - 1])) {
            return (string) $keys[(int) $answer - 1];
        }
        if (!isset($options[$answer])) {
            throw new RuntimeException("'{$answer}' is not an option of: {$question}");
        }

        return $answer;
    }

    public function confirm(string $question, bool $default = true): bool
    {
        $answer = strtolower($this->next($question));

        return $answer === '' ? $default : in_array($answer, ['y', 'yes'], true);
    }

    public function typed(string $question, string $word): bool
    {
        return $this->next($question) === $word;
    }

    private function next(string $question): string
    {
        $this->questions[] = $question;
        if ($this->answers === []) {
            throw new RuntimeException("No scripted answer left for: {$question}");
        }

        return array_shift($this->answers);
    }
}
