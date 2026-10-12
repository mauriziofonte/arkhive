<?php

declare(strict_types=1);

namespace Tests\Support;

use Mfonte\Arkhive\Services\Process\Command;
use RuntimeException;

/**
 * Answers restic commands for a FakeProcessRunner with output captured from real restic
 * (tests/Fixtures/restic), and records what each backup was given while it "ran".
 */
final class FakeRestic
{
    public string $version = "restic 0.19.1 compiled with go1.26.9 on linux/amd64\n";

    /** @var array{int, string, string} */
    public array $catConfig = [0, "{\"version\": 2}\n", ''];

    /** @var array<string, array{int, string, string}> answers for other subcommands */
    public array $answers = [];

    /** @var array{int, string, string} */
    public array $backup;

    /** @var (callable(Command): void)|null */
    public $duringBackup = null;

    /** @var list<string> */
    public array $excludeFiles = [];

    /** @var list<list<string>> */
    public array $backups = [];

    public function __construct()
    {
        $this->backup = [0, self::fixture('backup-0.19-first.jsonl'), ''];
    }

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/Fixtures/restic/' . $name);
    }

    public function install(FakeProcessRunner $runner): self
    {
        $runner->on('restic', fn (Command $command): array => $this->handle($command));

        return $this;
    }

    /**
     * @return array{int, string, string}
     */
    private function handle(Command $command): array
    {
        $args = $command->argv;
        array_shift($args);
        while (($args[0] ?? '') === '--option') {
            array_splice($args, 0, 2);
        }
        $subcommand = $args[0] ?? '';
        if ($subcommand === 'version') {
            return [0, $this->version, ''];
        }
        if ($subcommand === 'cat') {
            return $this->catConfig;
        }
        if ($subcommand === 'backup') {
            $index = array_search('--exclude-file', $args, true);
            if ($index !== false) {
                $this->excludeFiles[] = (string) file_get_contents($args[$index + 1]);
            }
            $this->backups[] = $args;
            if ($this->duringBackup !== null) {
                ($this->duringBackup)($command);
            }

            return $this->backup;
        }
        if (isset($this->answers[$subcommand])) {
            return $this->answers[$subcommand];
        }

        throw new RuntimeException('FakeRestic has no answer for: ' . $command->display());
    }
}
