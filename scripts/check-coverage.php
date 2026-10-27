<?php

declare(strict_types=1);

/*
 * Fails when the line coverage in a PHPUnit Clover report is below a minimum.
 *
 * Usage: php scripts/check-coverage.php <clover.xml> [minimum percent, default: 85]
 */

$report = $argv[1] ?? null;
$minimum = (float) ($argv[2] ?? 85);
if (null === $report || !is_file($report)) {
    fwrite(STDERR, "Clover report not found: ".($report ?? '(none given)')."\n");
    exit(2);
}

$metrics = simplexml_load_file($report)->project->metrics ?? null;
$statements = (int) ($metrics['statements'] ?? 0);
if (0 === $statements) {
    fwrite(STDERR, "No statements in $report: was coverage collected?\n");
    exit(2);
}

$covered = (int) $metrics['coveredstatements'];
$percent = 100 * $covered / $statements;
printf("PHP line coverage: %.2f%% (%d/%d), minimum %.2f%%\n", $percent, $covered, $statements, $minimum);
exit($percent < $minimum ? 1 : 0);
