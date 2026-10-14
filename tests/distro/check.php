<?php

declare(strict_types=1);

/*
 * spec §13.3: the built PHAR on one distribution of §12. Runs inside a fresh container after
 * setup.sh, as root, with no Composer autoloader: init, backup to a local repository, doctor,
 * restore (bytes and modes compared), drill, the unattended-mode output check (no TTY, then with
 * INVOCATION_ID), and a backup without restic that must name this distribution's install command.
 *
 * usage: php check.php <arkhive.phar> <restic binary> <expected restic install hint>
 * restic must not be in PATH or the standard directories (e.g. /opt/restic/restic): the
 * missing-dependency case relies on it.
 */

if ($argc !== 4) {
    fwrite(STDERR, "usage: php check.php <arkhive.phar> <restic binary> <expected restic install hint>\n");
    exit(2);
}
[, $phar, $restic, $hint] = $argv;

$failures = 0;

function check(bool $ok, string $what, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "ok   {$what}\n";

        return;
    }
    ++$failures;
    echo "FAIL {$what}\n";
    if ($detail !== '') {
        echo '     ' . str_replace("\n", "\n     ", rtrim($detail)) . "\n";
    }
}

/**
 * Runs a command without a terminal: stdin is /dev/null, stdout and stderr are captured.
 *
 * @param list<string> $command
 * @param array<string, string> $env added to a minimal environment
 * @return array{int, string, string}
 */
function run(array $command, array $env = []): array
{
    $environment = ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/root', ...$env];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        fwrite(STDERR, 'cannot start ' . implode(' ', $command) . "\n");
        exit(1);
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

/**
 * @param list<string> $args
 * @param array<string, string> $env
 * @return array{int, string, string}
 */
function arkhive(array $args, array $env = []): array
{
    global $phar;

    return run(['php', $phar, ...$args], $env);
}

/**
 * Relative path => sha256 and mode of every file and directory under $root.
 *
 * @return array<string, string>
 */
function state(string $root): array
{
    $state = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $path = substr($item->getPathname(), strlen($root));
        $mode = sprintf('%04o', $item->getPerms() & 07777);
        $state[$path] = $item->isFile() ? hash_file('sha256', $item->getPathname()) . " {$mode}" : "dir {$mode}";
    }
    ksort($state);

    return $state;
}

$os = parse_ini_file('/etc/os-release') ?: [];
echo 'distribution: ' . ($os['PRETTY_NAME'] ?? 'unknown') . ', PHP ' . PHP_VERSION . "\n";

$dir = sys_get_temp_dir() . '/arkhive-distro-' . bin2hex(random_bytes(4));
$tree = "{$dir}/site";
mkdir("{$tree}/data/nested", 0755, true);
file_put_contents("{$tree}/index.php", "<?php echo 'hello';\n");
file_put_contents("{$tree}/data/blob.bin", random_bytes(256 * 1024));
file_put_contents("{$tree}/data/nested/notes.txt", str_repeat("line\n", 1000));
chmod("{$tree}/data/nested/notes.txt", 0640);
file_put_contents("{$dir}/password", bin2hex(random_bytes(16)) . "\n");
chmod("{$dir}/password", 0600);

$values = [
    'BACKUP_PATHS' => $tree,
    'RESTIC_REPOSITORY' => "{$dir}/repository",
    'RESTIC_PASSWORD_FILE' => "{$dir}/password",
    'RESTIC_HOST' => 'distro.example.com',
];
$writeConfig = static function (string $path, array $values): string {
    $lines = '';
    foreach ($values as $key => $value) {
        $lines .= "{$key}='{$value}'\n";
    }
    file_put_contents($path, $lines);
    chmod($path, 0600);

    return $path;
};
$config = $writeConfig("{$dir}/config", [...$values, 'RESTIC_BINARY' => $restic]);

// the shebang: #!/usr/bin/env php works with this distribution's env and php
$direct = "{$dir}/arkhive";
copy($phar, $direct);
chmod($direct, 0755);
[$code, $out, $err] = run([$direct, '--version']);
check($code === 0 && str_starts_with($out, 'ArkHive '), 'the PHAR runs through its shebang', $out . $err);

[$code, $out, $err] = arkhive(['init', '--config', $config]);
check($code === 0, 'init creates the local repository', $out . $err);

[$code, $out, $err] = arkhive(['backup', '--config', $config, '--json']);
$backup = json_decode($out, true);
check($code === 0 && is_array($backup) && is_string($backup['snapshot_id'] ?? null), 'backup to a local repository', $out . $err);

[$code, $out, $err] = arkhive(['doctor', '--config', $config, '--json']);
$doctor = json_decode($out, true);
$statuses = [];
foreach (is_array($doctor) ? (array) ($doctor['checks'] ?? []) : [] as $item) {
    $statuses[(string) $item['check']] = (string) $item['status'] . ': ' . (string) ($item['message'] ?? '');
}
$failed = array_filter($statuses, static fn (string $status): bool => str_starts_with($status, 'failed'));
check(in_array($code, [0, 3], true) && $failed === [], 'doctor: no failed check (exit 0, or 3 for warnings)', "exit {$code}\n" . print_r($statuses, true) . $err);
$manager = explode(' ', $hint)[1] ?? '';
check(str_starts_with($statuses['distribution'] ?? '', 'ok') && str_contains($statuses['distribution'] ?? '', "packages via {$manager}"), "doctor detects the distribution and its package manager ({$manager})", print_r($statuses, true));

$target = "{$dir}/restored";
[$code, $out, $err] = arkhive(['restore', '--config', $config, '--snapshot', 'latest', '--target', $target, '--yes']);
check($code === 0, 'restore of the latest snapshot to a new directory', $out . $err);
check(is_dir($target . $tree) && state($tree) === state($target . $tree), 'the restored tree is identical (sha256 and modes)', is_dir($target . $tree) ? print_r(array_diff_assoc(state($tree), state($target . $tree)), true) : 'nothing restored');

[$code, $out, $err] = arkhive(['drill', '--config', $config, '--sample', '5', '--json']);
$drill = json_decode($out, true);
check($code === 0 && is_array($drill) && ($drill['passed'] ?? false) === true, 'drill passes', $out . $err);

// unattended: no terminal, then as a systemd unit; a missing answer is exit 64 naming the flag
foreach (['no terminal' => [], 'INVOCATION_ID set' => ['INVOCATION_ID' => bin2hex(random_bytes(16))]] as $context => $env) {
    [$code, $out, $err] = arkhive(['restore', '--config', $config, '--target', "{$dir}/unattended"], $env);
    check($code === 64 && str_contains($err, 'is required in unattended mode: pass --snapshot'), "unattended restore without --snapshot exits 64 naming the flag ({$context})", "exit {$code}\n{$out}{$err}");
    [$code, $out, $err] = arkhive(['backup', '--config', $config], $env);
    check($code === 0, "unattended backup ({$context})", $out . $err);
    check(!str_contains($out . $err, "\e") && !str_contains($out . $err, "\r"), "no colour, cursor movement or progress redraw ({$context})", json_encode($out . $err) ?: '');
}

// missing restic: not in PATH, not in the standard directories, no RESTIC_BINARY
$bare = $writeConfig("{$dir}/config-bare", $values);
[$code, $out, $err] = arkhive(['backup', '--config', $bare]);
check($code === 69 && str_contains($err, 'restic is needed'), 'backup without restic exits 69 (EX_UNAVAILABLE)', "exit {$code}\n{$out}{$err}");
check(str_contains($err, $hint), "the hint names this distribution's command: {$hint}", $err);

run(['rm', '-rf', $dir]);
echo $failures === 0 ? "all checks passed\n" : "{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
