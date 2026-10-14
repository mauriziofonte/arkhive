<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Commands;

use Mfonte\Arkhive\Exceptions\UsageException;
use Mfonte\Arkhive\ExitCode;
use Mfonte\Arkhive\Services\Process\ProcessRunner;
use Mfonte\Arkhive\Services\System\Dependencies;
use Mfonte\Arkhive\Services\Update\HttpClient;
use Mfonte\Arkhive\Services\Update\PharSwap;
use Mfonte\Arkhive\Services\Update\ReleaseSource;
use Mfonte\Arkhive\Services\Update\SelfUpdater;
use Mfonte\Arkhive\Services\Update\Version;
use Phar;

/**
 * spec §9: updates the PHAR from GitHub Releases, verified, with --check and --rollback. A specific
 * release is the argument (`self-update v2.0.1`): spec §9's `--version=<tag>` cannot exist, the
 * console reserves --version.
 */
class SelfUpdateCommand extends ArkhiveCommand
{
    protected const OPTIONS = '
        {release? : Install this release (e.g. v2.0.1) instead of the latest; a downgrade is allowed, with a warning}
        {--check : Only report whether an update exists (exit 10 when it does, 0 when current)}
        {--pre : Consider pre-releases}
        {--rollback : Put back the binary kept by the last update}
        {--allow-major : Allow crossing a major version (read the migration notes first)}
        {--json : Print the result as JSON on stdout}';

    protected $signature = 'self-update' . self::OPTIONS;

    protected $description = 'Update ArkHive to the latest release (verified with sha256), or roll back';

    /** the running PHAR; null: Phar::running() (tests point it at a copy) */
    public static ?string $pharPath = null;

    protected function perform(): int
    {
        $current = config('app.version');
        $current = is_string($current) ? $current : '0.0.0';
        // a positional argument: --version is the console's own "print the version" option
        $requested = self::stringOption($this->argument('release'));
        $check = (bool) $this->option('check');
        $rollback = (bool) $this->option('rollback');
        if ($rollback && ($check || $requested !== null || (bool) $this->option('pre'))) {
            throw new UsageException('--rollback cannot be combined with --check, --pre or a release');
        }
        $http = $this->laravel->make(HttpClient::class);
        $phar = self::$pharPath ?? Phar::running(false);
        $swap = PharSwap::for($phar, Phar::running(false));
        $updater = new SelfUpdater(
            ReleaseSource::fromEnvironment($http, $this->terminal->environment(), $current),
            $http,
            $this->laravel->make(ProcessRunner::class),
            $this->laravel->make(Dependencies::class),
            $this->cleanup,
            $current,
            $phar,
            PHP_BINARY,
            $swap,
        );

        if ($rollback) {
            $result = $updater->rollback($this->log);
            $this->log->success("Rolled back {$result->path} from {$result->from} to {$result->to}; {$result->from} is kept as {$result->backup}", $result->toArray());
            $this->json($result->toArray());

            return ExitCode::SUCCESS;
        }

        $release = $updater->target($requested, (bool) $this->option('pre'));
        $newer = $updater->isNewer($release);
        $same = Version::compare($release->version, $current) === 0;
        if ($check) {
            return $this->check($current, $release->tag, $newer, $updater->crossesMajor($release));
        }
        if ($same || (!$newer && $requested === null)) {
            $this->log->success("ArkHive {$current} is up to date (latest release: {$release->tag})");
            $this->json(['from' => $current, 'to' => $current, 'updated' => false]);

            return ExitCode::SUCCESS;
        }
        if (!$newer) {
            $this->log->warning("{$release->tag} is older than the running {$current}: downgrading as requested");
        }

        $result = $updater->install($release, (bool) $this->option('allow-major'), $this->log);
        $this->log->success("Updated {$result->path} from {$result->from} to {$result->to}; the previous binary is kept as {$result->backup} (undo with: arkhive self-update --rollback)", $result->toArray());
        $this->json([...$result->toArray(), 'updated' => true]);

        return ExitCode::SUCCESS;
    }

    private function check(string $current, string $latest, bool $newer, bool $major): int
    {
        $this->json(['current' => $current, 'latest' => $latest, 'update_available' => $newer, 'major' => $newer && $major]);
        if (!$newer) {
            $this->log->success("ArkHive {$current} is up to date (latest release: {$latest})");

            return ExitCode::SUCCESS;
        }
        $this->log->notice("An update is available: {$current} → {$latest}" . ($major ? ' (a new major version: read ' . SelfUpdater::MIGRATION_URL . ', then run arkhive self-update --allow-major)' : ' (run arkhive self-update)'));

        return ExitCode::UPDATE_AVAILABLE;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): void
    {
        if ((bool) $this->option('json')) {
            $this->result(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
}
