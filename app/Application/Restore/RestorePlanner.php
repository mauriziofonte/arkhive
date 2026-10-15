<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Application\Restore;

use Mfonte\Arkhive\Application\Database\DatabaseImporter;
use Mfonte\Arkhive\Application\Output\TextTable;
use Mfonte\Arkhive\Application\Port\Prompter;
use Mfonte\Arkhive\Application\Port\SnapshotStore;
use Mfonte\Arkhive\Domain\Configuration\Config;
use Mfonte\Arkhive\Domain\Exclusion\ExclusionConverter;
use Mfonte\Arkhive\Domain\Repository\Node;
use Mfonte\Arkhive\Domain\Repository\Snapshot;
use Mfonte\Arkhive\Domain\Repository\Snapshots;
use Mfonte\Arkhive\Domain\Restore\DumpFile;
use Mfonte\Arkhive\Domain\Restore\OwnerMode;
use Mfonte\Arkhive\Domain\Restore\RestoreOptions;
use Mfonte\Arkhive\Domain\Restore\RestorePlan;
use Mfonte\Arkhive\Domain\Restore\Selection;
use Mfonte\Arkhive\Domain\Restore\SelectionKind;
use Mfonte\Arkhive\Domain\Shared\Bytes;
use Mfonte\Arkhive\Domain\Shared\Clock;
use Mfonte\Arkhive\Exceptions\CancelledException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\UsageException;

/**
 * Turns the flags into a RestorePlan. In a terminal (a Prompter is given) every choice not given as
 * a flag is asked, with a default taken by Enter (spec §10.1); unattended, a missing required choice
 * is exit 64 naming its flag and nothing is ever asked (spec §10.2).
 */
final class RestorePlanner
{
    public const SHOWN_SNAPSHOTS = 20;
    public const OVERWRITE_WORD = 'overwrite';

    public function __construct(
        private readonly SnapshotStore $restic,
        private readonly ?Prompter $prompter,
        private readonly Clock $clock,
        private readonly string $cwd,
        private readonly string $localHost,
        private readonly int $uid,
        private readonly ?Config $config,
    ) {
    }

    public function plan(RestoreOptions $options, Snapshots $snapshots): RestorePlan
    {
        $options->validate();
        $owner = $options->owner === null ? OwnerMode::default($this->uid) : OwnerMode::fromOption($options->owner, $this->uid);
        $wizard = $this->prompter !== null && $options->snapshot === null;

        $snapshot = match (true) {
            $options->snapshot !== null => $snapshots->resolve($options->snapshot, $options->host, $this->localHost),
            $wizard => $this->chooseSnapshot($snapshots, $options->host),
            default => throw UsageException::missingFlag('--snapshot', "The snapshot to restore (an id, or 'latest')"),
        };

        $selection = $wizard && !$options->hasSelection() ? $this->chooseSelection($snapshot) : $this->selectionFromFlags($options, $snapshot);

        $force = $options->force;
        if ($options->inPlace) {
            if (!$force) {
                if ($this->prompter === null) {
                    throw new UsageException(
                        'An in-place restore overwrites the original files and needs --force',
                        'Pass --force (a safety snapshot of the paths is taken first), or restore to a new directory with --target',
                    );
                }
                $this->prompter->say('An in-place restore overwrites the original files at ' . implode(', ', $selection->includes === [] ? $snapshot->paths : $selection->includes) . ' (a safety snapshot of them is taken first).');
                if (!$this->prompter->typed('Overwrite the original files?', self::OVERWRITE_WORD)) {
                    throw new CancelledException('In-place restore not confirmed: nothing was restored');
                }
                $force = true;
            }

            return new RestorePlan($snapshot, $selection, '/', $owner, true, $force);
        }

        $target = null;
        if ($selection->needsTarget()) {
            $target = $this->target($options, $snapshot);
            $directory = new TargetDirectory($target);
            if (!$force && !$directory->isEmpty() && is_dir($target) && $this->prompter !== null) {
                if (!$this->prompter->typed("{$target} is not empty: files with the same names will be overwritten.", self::OVERWRITE_WORD)) {
                    throw new CancelledException("The restore target {$target} is not empty and the restore was not confirmed: nothing was restored");
                }
                $force = true;
            }
        }

        return new RestorePlan($snapshot, $selection, $target, $owner, false, $force);
    }

    /**
     * The summary screen and the final confirmation; unattended, --yes is the confirmation.
     *
     * @throws UsageException unattended without --yes, or the operator said no
     */
    public function confirm(RestorePlan $plan, int $needed, ?int $free, bool $yes): void
    {
        if ($plan->selection->kind === SelectionKind::Stdout) {
            return;
        }
        if ($this->prompter === null) {
            if (!$yes) {
                throw UsageException::missingFlag('--yes', 'The confirmation of the restore');
            }

            return;
        }
        $snapshot = $plan->snapshot;
        $rows = [
            ['Snapshot', sprintf('%s of %s, %s (%s ago)', $snapshot->shortId(), $snapshot->hostname, $snapshot->time->format('Y-m-d H:i'), $snapshot->age($this->clock->now()))],
            ['Restore', $plan->selection->describe()],
            ['To', match (true) {
                $plan->inPlace => 'the original paths (in place; a safety snapshot is taken first)',
                $plan->selection->imports() => "the new database {$plan->selection->importAs}",
                default => (string) $plan->target,
            }],
            ['Size', Bytes::format($needed) . ($free === null ? '' : ' (' . Bytes::format($free) . ' free)')],
            ['Owners', $plan->owner->describe($this->uid)],
            ['Check', $plan->selection->imports() ? 'the dump is imported with ON_ERROR_STOP' : 'every restored file is verified (restic restore --verify)'],
        ];
        $this->prompter->say('');
        $this->prompter->say(TextTable::render(['', ''], $rows));
        if ($yes) {
            return;
        }
        if (!$this->prompter->confirm('Start the restore?', true)) {
            throw new CancelledException('Restore cancelled: nothing was restored');
        }
    }

    private function chooseSnapshot(Snapshots $snapshots, ?string $host): Snapshot
    {
        assert($this->prompter !== null);
        if ($snapshots->isEmpty()) {
            return $snapshots->resolve('latest');
        }
        if ($host === null && count($snapshots->hosts()) > 1) {
            $options = [];
            foreach ($snapshots->hosts() as $name) {
                $count = count($snapshots->forHost($name)->snapshots);
                $options["h:{$name}"] = sprintf('%s (%d snapshot%s)', $name, $count, $count === 1 ? '' : 's');
            }
            $options['*'] = 'all hosts';
            $choice = $this->prompter->choose('The repository holds snapshots of several hosts. Which host?', $options, isset($options["h:{$this->localHost}"]) ? "h:{$this->localHost}" : '*');
            $host = $choice === '*' ? null : substr($choice, 2);
        }
        $list = $snapshots->forHost($host);
        $pathSets = array_values(array_unique(array_map(static fn (Snapshot $s): string => implode(' ', $s->paths), $list->snapshots)));
        if (count($pathSets) > 1 && count($list->snapshots) > self::SHOWN_SNAPSHOTS) {
            $options = ['*' => 'all paths'];
            foreach ($pathSets as $i => $set) {
                $options["p{$i}"] = $set;
            }
            $choice = $this->prompter->choose('The snapshots cover different paths. Which ones?', $options, '*');
            if ($choice !== '*') {
                $set = $pathSets[(int) substr($choice, 1)];
                $list = new Snapshots(array_values(array_filter($list->snapshots, static fn (Snapshot $s): bool => implode(' ', $s->paths) === $set)));
            }
        }

        $now = $this->clock->now();
        $rows = [];
        foreach (array_slice($list->snapshots, 0, self::SHOWN_SNAPSHOTS) as $i => $s) {
            $rows[] = [
                (string) ($i + 1),
                $s->shortId(),
                $s->time->setTimezone($now->getTimezone())->format('Y-m-d H:i'),
                $s->age($now),
                $s->hostname,
                $s->size === null ? '-' : Bytes::format($s->size),
                implode(',', $s->tags),
                implode(' ', $s->paths),
            ];
        }
        $this->prompter->say('');
        $this->prompter->say(TextTable::render(['#', 'ID', 'Date', 'Age', 'Host', 'Size', 'Tags', 'Paths'], $rows, [0, 5]));
        $hidden = count($list->snapshots) - self::SHOWN_SNAPSHOTS;
        if ($hidden > 0) {
            $this->prompter->say("{$hidden} older snapshots are not shown: type an id to pick one of them.");
        }
        $default = 1;
        foreach (array_slice($list->snapshots, 0, self::SHOWN_SNAPSHOTS) as $i => $s) {
            if (!$s->hasTag(Snapshots::SAFETY_TAG)) {
                $default = $i + 1;
                break;
            }
        }
        while (true) {
            $answer = $this->prompter->ask('Snapshot (number or id)', (string) $default);
            if (ctype_digit($answer) && isset($list->snapshots[(int) $answer - 1]) && (int) $answer <= self::SHOWN_SNAPSHOTS) {
                return $list->snapshots[(int) $answer - 1];
            }
            try {
                return $list->resolve($answer, $host);
            } catch (UsageException|NoInputException $e) {
                $this->prompter->say($e->getMessage());
            }
        }
    }

    private function chooseSelection(Snapshot $snapshot): Selection
    {
        assert($this->prompter !== null);
        $dumps = DumpFinder::find($this->restic, $snapshot);
        $options = [
            'everything' => 'Everything in the snapshot (' . implode(' ', $snapshot->paths) . ')',
            'paths' => 'Pick files and folders (browse and search)',
        ];
        if ($dumps !== []) {
            $options['database'] = sprintf('One database dump (%d in this snapshot)', count($dumps));
        }
        $options['stdout'] = 'A single file to standard output';
        $choice = $this->prompter->choose('What do you want to restore?', $options, 'everything');

        return match ($choice) {
            // picked entries are paths, not patterns: a name like "a[1].txt" must match only itself
            'paths' => Selection::paths(array_map(ExclusionConverter::literal(...), (new SnapshotBrowser($this->restic, $this->prompter, $snapshot))->pick())),
            'database' => $this->chooseDump($dumps),
            'stdout' => Selection::stdout($this->chooseFile($snapshot)),
            default => Selection::everything(),
        };
    }

    /**
     * @param list<DumpFile> $dumps
     */
    private function chooseDump(array $dumps): Selection
    {
        assert($this->prompter !== null);
        $options = [];
        foreach ($dumps as $i => $dump) {
            $options["d{$i}"] = sprintf('%s %s (%s)', $dump->engineLabel(), $dump->database, Bytes::format($dump->size));
        }
        $dump = $dumps[(int) substr($this->prompter->choose('Which database?', $options, 'd0'), 1)];
        $connected = $dump->engine === 'mysql' ? $this->config?->mysql !== null : $this->config?->pgsql !== null;
        if (!$connected) {
            $this->prompter->say('The configuration has no connection for this server: the dump file is restored to a directory, to import by hand.');

            return Selection::database($dump);
        }
        while (true) {
            $name = $this->prompter->ask('Import it into a NEW database named (Enter: just restore the .sql file)');
            if ($name === '') {
                return Selection::database($dump);
            }
            try {
                DatabaseImporter::assertValidName($name);

                return Selection::database($dump, $name);
            } catch (UsageException $e) {
                $this->prompter->say($e->getMessage());
            }
        }
    }

    private function chooseFile(Snapshot $snapshot): string
    {
        assert($this->prompter !== null);
        while (true) {
            $answer = $this->prompter->ask('Absolute path of the file in the snapshot, or a name to search for');
            if ($answer === '') {
                continue;
            }
            if (str_starts_with($answer, '/')) {
                return $answer;
            }
            $files = array_values(array_filter(
                array_map(static fn (array $hit): Node => $hit[1], $this->restic->find("*{$answer}*", null, [$snapshot->id])),
                static fn (Node $n): bool => $n->isFile(),
            ));
            if ($files === []) {
                $this->prompter->say("No file in snapshot {$snapshot->shortId()} has '{$answer}' in its name.");
                continue;
            }
            $options = [];
            foreach (array_slice($files, 0, SnapshotBrowser::MAX_LISTED) as $i => $file) {
                $options["f{$i}"] = $file->path . '  ' . Bytes::format($file->size);
            }

            return $files[(int) substr($this->prompter->choose('Which file?', $options, 'f0'), 1)]->path;
        }
    }

    private function target(RestoreOptions $options, Snapshot $snapshot): string
    {
        if ($options->target !== null) {
            return TargetDirectory::absolute($options->target, $this->cwd);
        }
        if ($this->prompter === null) {
            throw UsageException::missingFlag('--target', 'The directory to restore into (new or empty)');
        }
        $default = './' . TargetDirectory::defaultName($snapshot->hostname, $snapshot->shortId(), $this->clock->now());

        return TargetDirectory::absolute($this->prompter->ask('Restore into (a new directory; never the original location)', $default), $this->cwd);
    }

    private function selectionFromFlags(RestoreOptions $options, Snapshot $snapshot): Selection
    {
        if ($options->toStdout !== null) {
            return Selection::stdout($options->toStdout);
        }
        if ($options->db !== null) {
            if ($options->importAs !== null) {
                DatabaseImporter::assertValidName($options->importAs);
            }

            return Selection::database(DumpFinder::select(DumpFinder::find($this->restic, $snapshot), $options->db), $options->importAs);
        }

        return Selection::paths($options->includes, $options->excludes);
    }
}
