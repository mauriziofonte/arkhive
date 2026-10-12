<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

use Mfonte\Arkhive\Services\Restic\Node;
use Mfonte\Arkhive\Services\Restic\Restic;
use Mfonte\Arkhive\Services\Restic\Snapshot;
use Mfonte\Arkhive\Support\Bytes;

/**
 * The wizard's file picker: browse the snapshot folder by folder, search it by name, and select any
 * number of files and folders. Returns the selected paths (restic include patterns).
 */
final class SnapshotBrowser
{
    public const MAX_LISTED = 200;

    /** @var array<string, true> */
    private array $selected = [];

    public function __construct(
        private readonly Restic $restic,
        private readonly Prompter $prompter,
        private readonly Snapshot $snapshot,
    ) {
    }

    /**
     * @return list<string>
     */
    public function pick(): array
    {
        $directory = null;
        while (true) {
            $entries = $this->entries($directory);
            $this->show($directory, $entries);
            $answer = $this->prompter->ask('Number to open a folder or select a file, +N to select a folder, /text to search, .. to go up, Enter when done');
            if ($answer === '') {
                if ($this->selected !== []) {
                    return array_keys($this->selected);
                }
                $this->prompter->say('Nothing is selected yet.');
                continue;
            }
            if ($answer === '..') {
                $directory = $directory === null || in_array($directory, $this->snapshot->paths, true) ? null : dirname($directory);
                continue;
            }
            if (str_starts_with($answer, '/') && strlen($answer) > 1 && !str_contains(substr($answer, 1), '/')) {
                $this->search(substr($answer, 1));
                continue;
            }
            $toggle = str_starts_with($answer, '+');
            $number = ltrim($answer, '+');
            if (!ctype_digit($number) || !isset($entries[(int) $number - 1])) {
                $this->prompter->say("'{$answer}' is not one of the choices.");
                continue;
            }
            $entry = $entries[(int) $number - 1];
            if ($entry->isDirectory() && !$toggle) {
                $directory = $entry->path;
                continue;
            }
            $this->toggle($entry->path);
        }
    }

    /**
     * @return list<Node>
     */
    private function entries(?string $directory): array
    {
        if ($directory === null) {
            return array_map(fn (string $path): Node => new Node($path, 'dir', 0, 'drwxr-xr-x', $this->snapshot->time), $this->snapshot->paths);
        }
        $entries = [];
        $this->restic->ls($this->snapshot->id, [$directory], false, static function (Node $node) use (&$entries, $directory): void {
            if ($node->path !== $directory) {
                $entries[] = $node;
            }
        });
        usort($entries, static fn (Node $a, Node $b): int => [!$a->isDirectory(), $a->name()] <=> [!$b->isDirectory(), $b->name()]);

        return $entries;
    }

    /**
     * @param list<Node> $entries
     */
    private function show(?string $directory, array $entries): void
    {
        $this->prompter->say('');
        $this->prompter->say($directory === null ? "Snapshot {$this->snapshot->shortId()}:" : "{$directory}:");
        foreach (array_slice($entries, 0, self::MAX_LISTED) as $i => $node) {
            $this->prompter->say(sprintf(
                '  %s %3d) %s%s%s',
                isset($this->selected[$node->path]) ? '[x]' : '[ ]',
                $i + 1,
                $directory === null ? $node->path : $node->name(),
                $node->isDirectory() ? '/' : '',
                $node->isDirectory() ? '' : '  ' . Bytes::format($node->size) . '  ' . $node->mtime->format('Y-m-d H:i'),
            ));
        }
        if (count($entries) > self::MAX_LISTED) {
            $this->prompter->say(sprintf('  ... %d more: search with /text', count($entries) - self::MAX_LISTED));
        }
        if ($this->selected !== []) {
            $this->prompter->say('Selected: ' . implode(', ', array_keys($this->selected)));
        }
    }

    private function search(string $text): void
    {
        $found = $this->restic->find("*{$text}*", null, [$this->snapshot->id]);
        if ($found === []) {
            $this->prompter->say("Nothing in snapshot {$this->snapshot->shortId()} has '{$text}' in its name.");

            return;
        }
        $this->prompter->say(sprintf("%d match(es) for '%s':", count($found), $text));
        $nodes = array_slice(array_map(static fn (array $hit): Node => $hit[1], $found), 0, self::MAX_LISTED);
        foreach ($nodes as $i => $node) {
            $this->prompter->say(sprintf('  %s %3d) %s%s', isset($this->selected[$node->path]) ? '[x]' : '[ ]', $i + 1, $node->path, $node->isDirectory() ? '/' : '  ' . Bytes::format($node->size)));
        }
        $answer = $this->prompter->ask('Numbers to select (e.g. 1,3), Enter to go back');
        foreach (preg_split('/[\s,]+/', $answer) ?: [] as $number) {
            if (ctype_digit($number) && isset($nodes[(int) $number - 1])) {
                $this->toggle($nodes[(int) $number - 1]->path);
            }
        }
    }

    private function toggle(string $path): void
    {
        if (isset($this->selected[$path])) {
            unset($this->selected[$path]);

            return;
        }
        $this->selected[$path] = true;
    }
}
