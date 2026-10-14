<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

/**
 * Questions for the interactive wizard. Every question has a default taken by pressing Enter.
 * Used only in interactive mode: unattended runs never reach a prompt (spec §11).
 */
interface Prompter
{
    /**
     * Writes text for the operator (not a log event).
     */
    public function say(string $text): void;

    /**
     * Free text; Enter returns $default.
     */
    public function ask(string $question, string $default = ''): string;

    /**
     * One of $options (key => label), by number or key; Enter returns $default.
     *
     * @param array<string, string> $options
     */
    public function choose(string $question, array $options, string $default): string;

    public function confirm(string $question, bool $default = true): bool;

    /**
     * A destructive step: true only if the operator types $word exactly.
     */
    public function typed(string $question, string $word): bool;
}
