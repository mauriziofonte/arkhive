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

    /** @var array<string, callable(list<string>, Command): array{int, string, string}> handlers by subcommand */
    public array $on = [];

    /** @var list<list<string>> every call, global options removed */
    public array $calls = [];

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
        $this->calls[] = $args;
        if (isset($this->on[$subcommand])) {
            return ($this->on[$subcommand])($args, $command);
        }
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

    /**
     * Answers the read commands of a restore from the fixtures of the sample repository (snapshots
     * of web1.example.com and web2.example.com with /srv/www and a dump directory /srv/dumps), and
     * simulates restic restore by writing the selected files of ls-recursive-0.19.jsonl to the target.
     */
    public function withRepository(): self
    {
        $this->on['snapshots'] = static fn (): array => [0, self::fixture('snapshots-0.19.json'), ''];
        $this->on['ls'] = static function (array $args): array {
            if (in_array('--recursive', $args, true)) {
                return [0, self::fixture('ls-recursive-0.19.jsonl'), ''];
            }
            $dirs = array_slice($args, (int) array_search('--', $args, true) + 2);
            if ($dirs !== [] && str_ends_with($dirs[0], '/mysql')) {
                return [0, self::fixture('ls-dumps-0.19.jsonl'), ''];
            }
            $lines = explode("\n", trim(self::fixture('ls-recursive-0.19.jsonl')));
            $out = [$lines[0]];
            foreach (array_slice($lines, 1) as $line) {
                $node = json_decode($line, true);
                $path = is_array($node) && is_string($node['path'] ?? null) ? $node['path'] : '';
                foreach ($dirs as $dir) {
                    if ($path === $dir || dirname($path) === $dir) {
                        $out[] = $line;
                        break;
                    }
                }
            }

            return [0, implode("\n", $out) . "\n", ''];
        };
        $this->on['find'] = static fn (): array => [0, self::fixture('find-0.19.json'), ''];
        $this->on['stats'] = static fn (): array => [0, self::fixture('stats-restore-size-0.19.json'), ''];
        $this->on['list'] = static fn (): array => [0, '', ''];
        $this->on['dump'] = static function (array $args, Command $command): array {
            $content = 'dump of ' . end($args) . "\n";
            if ($command->output !== null) {
                fwrite($command->output, $content);

                return [0, '', ''];
            }

            return [0, $content, ''];
        };
        $this->on['restore'] = static function (array $args): array {
            $target = $args[(int) array_search('--target', $args, true) + 1];
            $includes = [];
            foreach ($args as $i => $arg) {
                if ($arg === '--include') {
                    $includes[] = $args[$i + 1];
                }
            }
            $files = 0;
            $bytes = 0;
            foreach (array_slice(explode("\n", trim(self::fixture('ls-recursive-0.19.jsonl'))), 1) as $line) {
                $node = json_decode($line, true);
                if (!is_array($node) || !is_string($node['path'] ?? null)) {
                    continue;
                }
                $path = $node['path'];
                if ($includes !== [] && array_filter($includes, static fn (string $i): bool => $path === $i || str_starts_with($path, rtrim($i, '/') . '/')) === []) {
                    continue;
                }
                $dest = rtrim($target, '/') . $path;
                if ($node['type'] === 'dir') {
                    @mkdir($dest, 0755, true);
                } else {
                    @mkdir(dirname($dest), 0755, true);
                    file_put_contents($dest, str_repeat('x', (int) ($node['size'] ?? 0)));
                    $bytes += (int) ($node['size'] ?? 0);
                }
                $files++;
            }

            return [0, json_encode(['message_type' => 'summary', 'total_files' => $files, 'files_restored' => $files, 'total_bytes' => $bytes, 'bytes_restored' => $bytes]) . "\n", ''];
        };

        return $this;
    }

    /**
     * @return list<list<string>> the calls of one subcommand
     */
    public function callsOf(string $subcommand): array
    {
        return array_values(array_filter($this->calls, static fn (array $args): bool => ($args[0] ?? '') === $subcommand));
    }
}
