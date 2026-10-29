<?php

declare(strict_types=1);

/**
 * Merges the phpbench XML dumps of one bench/run.sh run into Markdown tables.
 *
 * Usage: php bench/report.php <result-dir>
 *
 * Iterations of all rounds of a cell are pooled. Cells are named <backend>-<jit>-<state>. Overhead is always relative to the "none" cell with the same JIT mode,
 * that is PHP without the extension and the same opcache settings.
 */
if (2 !== $argc || !is_dir($argv[1])) {
    fwrite(STDERR, "usage: php bench/report.php <result-dir>\n");
    exit(2);
}

$dir = rtrim($argv[1], '/');
$rawMeta = json_decode((string) file_get_contents("$dir/meta.json"), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($rawMeta)) {
    throw new RuntimeException("$dir/meta.json is not a JSON object");
}
$meta = static fn (string $key): string => is_scalar($rawMeta[$key] ?? null) ? (string) $rawMeta[$key] : '';

/** @var array<string, array<int, array<string, list<float>>>> $samples cell => round => subject => time per revolution of each iteration */
$samples = [];
foreach (glob("$dir/*.xml") ?: [] as $file) {
    preg_match('/^(.+)\.r(\d+)$/', basename($file, '.xml'), $parts);
    $xml = simplexml_load_file($file);
    if (false === $xml || 3 !== count($parts)) {
        throw new RuntimeException("Cannot parse $file");
    }
    foreach ($xml->xpath('//benchmark') ?: [] as $benchmark) {
        $class = ltrim(substr((string) $benchmark['class'], (int) strrpos((string) $benchmark['class'], '\\')), '\\');
        foreach ($benchmark->subject as $subject) {
            $name = preg_replace('/^bench/', '', (string) $subject['name']);
            foreach ($subject->variant->iteration as $iteration) {
                $samples[$parts[1]][(int) $parts[2]]["$class::$name"][] = (float) $iteration['time-avg'];
            }
        }
    }
}

/** @param list<float> $values */
function median(array $values): float
{
    if ([] === $values) {
        throw new InvalidArgumentException('Cannot take the median of no values');
    }
    sort($values);
    $count = count($values);
    $middle = intdiv($count, 2);

    return 1 === $count % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

/** @var array<string, array<string, float>> $time cell => subject => median of the per-round medians (microseconds) */
$time = [];
/** @var array<string, array<string, list<float>>> $ratios cell => subject => time / baseline time, one entry per round */
$ratios = [];
/** @var array<string, array<string, list<float>>> $perRound cell => subject => median of each round */
$perRound = [];
foreach ($samples as $cell => $rounds) {
    [$backend, $jit] = explode('-', $cell);
    foreach ($rounds as $round => $subjects) {
        foreach ($subjects as $name => $times) {
            $roundMedian = median($times);
            $baseline = $samples["none-$jit-none"][$round][$name] ?? null;
            $ratios[$cell][$name][] = null === $baseline ? 1.0 : $roundMedian / median($baseline);
            $perRound[$cell][$name][] = $roundMedian;
        }
    }
    foreach ($perRound[$cell] as $name => $medians) {
        $time[$cell][$name] = median($medians);
    }
}

$subjects = array_keys($time['none-disable-none'] ?? reset($time) ?: []);
$formatTime = static fn (float $us): string => $us >= 1000 ? sprintf('%.2f ms', $us / 1000) : sprintf('%.0f µs', $us);
$percent = static fn (float $ratio): string => sprintf('%+.0f%%', ($ratio - 1) * 100);
$cellsOf = static fn (string $jit): array => array_values(array_filter(
    array_keys($time),
    static fn (string $cell): bool => str_contains($cell, "-$jit-"),
));
$order = ['none' => 0, 'loaded' => 1, 'miss' => 2, 'pass' => 3, 'advised' => 4];
$label = static fn (string $cell): string => match (true) {
    str_starts_with($cell, 'none-') => 'no aop',
    default => preg_replace('/^(\w+)-\w+-(\w+)$/', '$1 / $2', $cell) ?? $cell,
};

echo 'Measured: ', $meta('date'), ', commit ', $meta('commit'), ', PHP ', $meta('php'), ' ', true === ($rawMeta['zts'] ?? false) ? 'ZTS' : 'NTS', ' ', true === ($rawMeta['debug'] ?? false) ? 'debug' : 'release', " build\n";
echo 'CPU: ', $meta('cpu'), ' (', $meta('cpus'), ' logical CPUs), ', $meta('os'), "\n\n";

foreach (['disable', 'tracing', 'function'] as $jit) {
    $cells = $cellsOf($jit);
    usort($cells, static fn (string $a, string $b): int => ['hook' === explode('-', $a)[0], $order[explode('-', $a)[2]]]
        <=> ['hook' === explode('-', $b)[0], $order[explode('-', $b)[2]]]);
    if ([] === $cells) {
        continue;
    }
    $header = '| Subject | '.implode(' | ', array_map($label, $cells))." |\n|---|".str_repeat('---:|', count($cells))."\n";

    echo "### opcache.jit=$jit\n\nTime for one run of the subject (median of the rounds):\n\n$header";
    foreach ($subjects as $subject) {
        echo "| $subject |";
        foreach ($cells as $cell) {
            echo ' ', isset($time[$cell][$subject]) ? $formatTime($time[$cell][$subject]) : 'n/a', ' |';
        }
        echo "\n";
    }

    echo "\nOverhead relative to no aop with the same JIT mode (median of the per-round ratios, [lowest, highest round]):\n\n";
    $overheadCells = array_values(array_filter($cells, static fn (string $cell): bool => !str_starts_with($cell, 'none-')));
    echo '| Subject | '.implode(' | ', array_map($label, $overheadCells))." |\n|---|".str_repeat('---:|', count($overheadCells))."\n";
    foreach ($subjects as $subject) {
        echo "| $subject |";
        foreach ($cells as $cell) {
            if (str_starts_with($cell, 'none-')) {
                continue;
            }
            $values = $ratios[$cell][$subject] ?? [];
            echo [] === $values ? ' n/a |' : sprintf(' %s [%s, %s] |', $percent(median($values)), $percent(min($values)), $percent(max($values)));
        }
        echo "\n";
    }
    echo "\n";
}
