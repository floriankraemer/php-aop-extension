<?php

declare(strict_types=1);

/**
 * Fails when statement coverage in a PHPUnit clover report is below a threshold.
 *
 * Usage: php tools/coverage-check.php <clover.xml> <min-percent>
 */
if ($argc !== 3 || !is_numeric($argv[2])) {
    fwrite(STDERR, "Usage: php tools/coverage-check.php <clover.xml> <min-percent>\n");
    exit(2);
}

$xml = @simplexml_load_file($argv[1]);
if ($xml === false) {
    fwrite(STDERR, sprintf("Cannot read clover report: %s\n", $argv[1]));
    exit(2);
}

$statements = 0;
$covered = 0;
foreach ($xml->xpath('//file/metrics') ?: [] as $metrics) {
    $statements += (int) $metrics['statements'];
    $covered += (int) $metrics['coveredstatements'];
}

$percent = $statements > 0 ? 100 * $covered / $statements : 0.0;
$min = (float) $argv[2];
printf("Statement coverage: %.2f%% (%d/%d), required: %.2f%%\n", $percent, $covered, $statements, $min);

exit($percent >= $min ? 0 : 1);
