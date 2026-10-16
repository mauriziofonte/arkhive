<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The direction of dependencies between the layers (docs/2.1-PLAN.md §2):
 * Domain uses only itself and the shared kernel (exceptions, exit codes); Application adds the
 * Domain and declares ports for what it needs from outside; Infrastructure implements the ports
 * and never reaches into the commands. A class that breaks a rule fails here, named with the
 * class it uses.
 */
final class LayersTest extends TestCase
{
    private const APP = __DIR__ . '/../../app';
    private const ROOT = 'Mfonte\\Arkhive\\';

    /** namespaces every layer may use */
    private const KERNEL = ['Exceptions', 'ExitCode'];

    /** what each layer may use besides itself and the kernel */
    private const ALLOWED = [
        'Domain' => [],
        'Application' => ['Domain'],
        'Infrastructure' => ['Domain', 'Application'],
    ];

    /** PHP functions that touch the outside world: never in the Domain */
    private const IMPURE = [
        'fopen', 'fwrite', 'fread', 'file_get_contents', 'file_put_contents', 'unlink', 'rename', 'mkdir', 'rmdir',
        'is_file', 'is_dir', 'file_exists', 'scandir', 'glob', 'touch', 'chmod', 'chown', 'disk_free_space',
        'proc_open', 'exec', 'shell_exec', 'system', 'passthru', 'popen', 'getenv', 'putenv', 'time', 'microtime',
        'random_bytes', 'random_int', 'mt_rand', 'rand', 'sleep', 'usleep', 'stream_socket_client', 'fsockopen',
        'curl_init', 'posix_geteuid', 'posix_getpid', 'gethostname', 'syslog', 'openlog',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function layers(): iterable
    {
        foreach (array_keys(self::ALLOWED) as $layer) {
            yield $layer => [$layer];
        }
    }

    #[DataProvider('layers')]
    public function testEachLayerDependsOnlyOnWhatItMay(string $layer): void
    {
        $allowed = [$layer, ...self::ALLOWED[$layer], ...self::KERNEL];
        $violations = [];
        foreach (self::classes($layer) as $file => $code) {
            foreach (self::references($code) as $used) {
                $top = explode('\\', $used)[0];
                if (!in_array($top, $allowed, true)) {
                    $violations[] = self::className($file) . ' uses ' . $used;
                }
            }
        }

        self::assertSame([], $violations, "{$layer} may use only " . implode(', ', $allowed));
    }

    public function testTheDomainDoesNoInputOrOutput(): void
    {
        $violations = [];
        foreach (self::classes('Domain') as $file => $code) {
            $tokens = token_get_all($code);
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]), self::IMPURE, true)) {
                    continue;
                }
                $previous = self::significant($tokens, $i, -1);
                $next = self::significant($tokens, $i, 1);
                $isCall = $next === '(' && !in_array($previous, ['->', '?->', '::', 'function', 'new', 'const'], true);
                if ($isCall) {
                    $violations[] = self::className($file) . " calls {$token[1]}()";
                }
            }
        }

        self::assertSame([], $violations, 'Domain classes are pure: the input and output go through the Application ports');
    }

    public function testPortsAreInterfaces(): void
    {
        $notInterfaces = [];
        foreach (self::classes('Application/Port') as $file => $code) {
            if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(class|enum|trait)\s/m', $code) === 1 && !str_contains($code, '@port-value')) {
                $notInterfaces[] = self::className($file);
            }
        }

        self::assertSame([], $notInterfaces, 'Application\Port holds interfaces and the values they exchange (marked @port-value)');
    }

    public function testTheLayersAreTheOnlyCodeOutsideCommandsAndProviders(): void
    {
        $top = array_values(array_diff(scandir(self::APP) ?: [], ['.', '..']));
        sort($top);

        self::assertSame(['Application', 'Commands', 'Domain', 'Exceptions', 'ExitCode.php', 'Infrastructure', 'Providers'], $top);
    }

    /**
     * @return iterable<string, string> file => source
     */
    private static function classes(string $directory): iterable
    {
        $base = self::APP . '/' . $directory;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));
        $list = [];
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $list[] = $file->getPathname();
            }
        }
        sort($list);
        self::assertNotSame([], $list, "no class in {$directory}");
        foreach ($list as $path) {
            yield $path => (string) file_get_contents($path);
        }
    }

    /**
     * Every Mfonte\Arkhive class named in the code (imports and fully qualified names), relative
     * to the root namespace.
     *
     * @return list<string>
     */
    private static function references(string $code): array
    {
        preg_match_all('/\\\\?Mfonte\\\\Arkhive\\\\([A-Za-z0-9_\\\\]+)/', $code, $matches);
        $own = preg_match('/^namespace Mfonte\\\\Arkhive\\\\([^;]+);/m', $code, $ns) === 1 ? $ns[1] : '';
        $used = [];
        foreach ($matches[1] as $name) {
            if ($name !== $own) {
                $used[$name] = true;
            }
        }

        return array_keys($used);
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private static function significant(array $tokens, int $from, int $step): string
    {
        for ($i = $from + $step; isset($tokens[$i]); $i += $step) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_NS_SEPARATOR], true)) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return '';
    }

    private static function className(string $file): string
    {
        return str_replace([realpath(self::APP) . '/', self::APP . '/', '.php', '/'], ['', '', '', '\\'], (string) realpath($file));
    }
}
