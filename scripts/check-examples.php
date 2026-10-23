<?php

declare(strict_types=1);

/*
 * Runs every examples/NN-*.php with the given PHP command and compares its stdout with the
 * "Expected output:" comment at the end of the file.
 *
 * Usage: php scripts/check-examples.php [php command, default: "php -d extension=ext/modules/aop.so"]
 */

$root = dirname(__DIR__);
$php = $argv[1] ?? PHP_BINARY.' -d extension='.escapeshellarg($root.'/ext/modules/aop.so');
// Examples that use field, initialization or handler advice, which need aop.interception=hook.
$needsHook = ['04-constructors.php', '27-fields.php', '29-initialization-without-constructor.php'];
$files = glob($root.'/examples/[0-9][0-9]-*.php') ?: [];
$failures = 0;

foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    if (1 !== preg_match('~/\* Expected output:\n(.*?)\*/\s*$~s', $source, $m)) {
        echo "SKIP  ", basename($file), " (no expected-output comment)\n";
        continue;
    }
    $expected = rtrim($m[1], "\n");
    $command = $php.(in_array(basename($file), $needsHook, true) ? ' -d aop.interception=hook' : '');
    $actual = rtrim((string) shell_exec($command.' '.escapeshellarg($file).' 2>&1'), "\n");
    if ($actual === $expected) {
        echo "PASS  ", basename($file), "\n";
        continue;
    }
    ++$failures;
    echo "FAIL  ", basename($file), "\n--- expected\n", $expected, "\n--- actual\n", $actual, "\n";
}

printf("%d examples, %d failed\n", count($files), $failures);
exit($failures > 0 ? 1 : 0);
