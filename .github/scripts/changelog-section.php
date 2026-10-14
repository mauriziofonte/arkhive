<?php

declare(strict_types=1);

/*
 * Prints the CHANGELOG.md section of one version (Keep a Changelog: "## [x.y.z] - date"), without its
 * heading, for the release notes. Exit 1 when the section is missing or empty: a release without
 * notes is a mistake.
 *
 * usage: php changelog-section.php <CHANGELOG.md> <version without v>
 */

if ($argc !== 3) {
    fwrite(STDERR, "usage: php changelog-section.php <CHANGELOG.md> <version>\n");
    exit(2);
}
[, $file, $version] = $argv;

$text = @file_get_contents($file);
if (!is_string($text)) {
    fwrite(STDERR, "Cannot read {$file}\n");
    exit(1);
}

$section = null;
foreach (preg_split('/\R/', $text) ?: [] as $line) {
    if (preg_match('/^## \[([^\]]+)\]/', $line, $m) === 1) {
        if ($section !== null) {
            break;
        }
        if ($m[1] === $version) {
            $section = [];
        }

        continue;
    }
    if ($section !== null) {
        // link references at the end of the file are not part of the section
        if (preg_match('/^\[[^\]]+\]: \S+$/', $line) === 1) {
            break;
        }
        $section[] = $line;
    }
}

$notes = trim(implode("\n", $section ?? []));
if ($section === null || $notes === '') {
    fwrite(STDERR, "{$file} has no " . ($section === null ? '' : 'non-empty ') . "section \"## [{$version}]\"\n");
    exit(1);
}

echo $notes, "\n";
