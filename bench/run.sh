#!/usr/bin/env bash
# Walks the benchmark matrix (backend x opcache JIT mode x aop state) and runs phpbench once per cell.
# Raw results (phpbench XML + meta.json) go to bench/results/<run>/, the Markdown tables to stdout.
#
#   bench/run.sh                    full matrix
#   CELLS="woven-tracing-pass" bench/run.sh   only the listed cells (<backend>-<jit>-<state>)
#
# Environment (all optional):
#   PHP          the PHP binary to measure (default: php)
#   EXT_SO       built extension (default: <repo>/ext/modules/aop.so)
#   CPU          CPU core to pin to with taskset (default: 2; empty disables pinning)
#   CELLS        space separated cell names to run instead of the full matrix
#   FILTER       phpbench --filter, to run a subset of the benchmarks
#   ROUNDS       interleaved rounds (default 9): every round runs all cells once, so drift of the machine (load,
#                clocks) hits every cell alike. The iterations of all rounds are pooled in the report.
#   ITERATIONS   phpbench iterations per round (default 3; the "pass" cells are slow and use PASS_ITERATIONS, default 1)
#   REVS         override runner.revs
#   JIT_BUFFER   opcache.jit_buffer_size (default 64M; 0 makes the JIT assertion abort the cell)
#   COMMIT       commit to record in meta.json (default: git HEAD of the repository; the Docker run has no .git)
#   OUT          result directory (default: bench/results/php<version>-<timestamp>)
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
EXT_SO="${EXT_SO:-$root/ext/modules/aop.so}"
CPU="${CPU-2}"
JIT_BUFFER="${JIT_BUFFER:-64M}"
export XDEBUG_MODE=off

[[ -f "$EXT_SO" ]] || { echo "extension not found: $EXT_SO (run make ext)" >&2; exit 2; }
[[ -f "$root/vendor/bin/phpbench" ]] || { echo "phpbench not installed (run composer install)" >&2; exit 2; }

php_version="$("$PHP" -r 'echo PHP_VERSION;')"
OUT="${OUT:-$root/bench/results/php${php_version}-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT"

# Opcache is a shared module on PHP 8.3/8.4 and built in on 8.5; the subprocess runs with -n, so load it explicitly.
opcache_ini=""
if ! "$PHP" -n -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'; then
    opcache_ini="\"zend_extension\": \"$("$PHP" -n -r 'echo ini_get("extension_dir");')/opcache.so\","
fi

pin=()
if [[ -n "$CPU" ]] && command -v taskset >/dev/null 2>&1; then
    pin=(taskset -c "$CPU")
fi

if [[ -n "${CELLS:-}" ]]; then
    read -r -a cells <<<"$CELLS"
else
    cells=()
    for jit in disable tracing function; do
        cells+=("none-$jit-none")
        for state in loaded miss pass; do cells+=("woven-$jit-$state"); done
    done
    # The hook backend overrides zend_execute_ex and switches the JIT off, so only jit=disable is meaningful.
    for state in loaded miss pass; do cells+=("hook-disable-$state"); done
fi

"$PHP" -n -r '
    $cpu = "unknown";
    foreach (@file("/proc/cpuinfo") ?: [] as $line) {
        if (str_starts_with($line, "model name")) { $cpu = trim(explode(":", $line, 2)[1]); break; }
    }
    echo json_encode([
        "php" => PHP_VERSION, "zts" => PHP_ZTS === 1, "os" => php_uname("s") . " " . php_uname("r"),
        "cpu" => $cpu, "cpus" => (int) shell_exec("nproc"), "date" => gmdate("Y-m-d"),
        "commit" => getenv("COMMIT") ?: trim((string) shell_exec("git -C " . escapeshellarg($argv[1]) . " rev-parse --short HEAD 2>/dev/null")),
        "debug" => PHP_DEBUG === 1,
    ], JSON_PRETTY_PRINT), "\n";
' "$root" >"$OUT/meta.json"

ROUNDS="${ROUNDS:-9}"
ITERATIONS="${ITERATIONS:-3}"
PASS_ITERATIONS="${PASS_ITERATIONS:-1}"
extra=()
[[ -n "${FILTER:-}" ]] && extra+=(--filter "$FILTER")
[[ -n "${REVS:-}" ]] && extra+=(--revs "$REVS")

run_cell() {
    local cell="$1" iterations="$2" round="$3" backend jit state jit_on ini attempt
    IFS=- read -r backend jit state <<<"$cell"
    jit_on=0
    [[ "$jit" != disable && "$backend" != hook ]] && jit_on=1
    ini="{$opcache_ini \"opcache.enable_cli\": \"1\", \"opcache.jit\": \"$jit\", \"opcache.jit_buffer_size\": \"$JIT_BUFFER\""
    if [[ "$backend" != none ]]; then
        ini+=", \"extension\": \"$EXT_SO\", \"aop.interception\": \"$backend\""
    fi
    ini+="}"

    echo "##### cell $cell round $round (jit expected on: $jit_on)" >&2
    # phpbench times with microtime(true), which can step backwards when the host clock is adjusted (seen on WSL2);
    # it then aborts with "Net time cannot be less than zero". That is the only failure worth a second attempt.
    for attempt in 1 2; do
        if AOP_BENCH_STATE="$state" AOP_BENCH_JIT_ON="$jit_on" \
            "${pin[@]}" "$PHP" "$root/vendor/bin/phpbench" run "$root/bench" --config="$root/phpbench.json" \
            --php-binary="$PHP" --php-config="$ini" --stop-on-error --progress=dots --report=aggregate \
            --iterations="$iterations" --dump-file="$OUT/$cell.r$round.xml" "${extra[@]}" >"$OUT/$cell.r$round.txt" 2>&1; then
            break
        fi
        if [[ "$attempt" == 2 ]] || ! grep -q "Net time cannot be less than zero" "$OUT/$cell.r$round.txt"; then
            cat "$OUT/$cell.r$round.txt" >&2
            echo "cell $cell failed" >&2
            exit 1
        fi
        echo "clock stepped backwards during $cell, repeating the cell" >&2
    done
    tail -n 3 "$OUT/$cell.r$round.txt" >&2
}

for ((round = 1; round <= ROUNDS; round++)); do
    for cell in "${cells[@]}"; do
        if [[ "$cell" == *-pass ]]; then iterations="$PASS_ITERATIONS"; else iterations="$ITERATIONS"; fi
        run_cell "$cell" "$iterations" "$round"
    done
done

# simplexml is a shared module in some distribution packages; the report runs with -n, so load it when it is not built in.
report_ini=()
if ! "$PHP" -n -r 'exit(extension_loaded("simplexml") ? 0 : 1);'; then
    report_ini=(-d extension=simplexml)
fi
"$PHP" -n "${report_ini[@]}" "$root/bench/report.php" "$OUT"
