<?php

declare(strict_types=1);

// usage: php tests/bin/coverage-gate.php <clover.xml> <minimum line coverage percent>

if ($argc !== 3) {
    fwrite(STDERR, "usage: coverage-gate.php <clover.xml> <minimum percent>\n");
    exit(2);
}

$xml = @simplexml_load_file($argv[1]);
if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(STDERR, "cannot read clover report {$argv[1]}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements === 0 ? 0.0 : 100 * $covered / $statements;
$minimum = (float) $argv[2];

printf("Line coverage of app/: %.2f%% (%d/%d lines), minimum %.2f%%\n", $percent, $covered, $statements, $minimum);

exit($percent + 1e-9 >= $minimum ? 0 : 1);
