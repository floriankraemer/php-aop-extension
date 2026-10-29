# Benchmarks

This page describes how the overhead of the two interception backends is measured, how to reproduce it, and what the published numbers say.
The suite lives in `bench/` and uses [PHPBench](https://phpbench.readthedocs.io/).

## What is measured

Each benchmark subject is a method that runs a workload in a loop with local variables, so the JIT sees typed locals and not the globals of a top-level script.

| Class | Subjects | What it stresses |
|---|---|---|
| `CallBench` | `Function`, `InstanceMethod`, `StaticMethod` | 100,000 calls of a tiny typed `add(int, int): int`. The worst case: the prologue cost is not hidden by any other work. |
| `MixedBench` | `Orders` | Builds 300 orders of 20 lines (objects, arrays, string building). Call overhead is a smaller share. |
| `NumericBench` | `Inline`, `CallPerPixel`, `CallPerIteration` | A 64 x 64 Mandelbrot set. `Inline` has no calls in the hot loop, `CallPerPixel` calls a function per pixel (up to 100 iterations of work per call), `CallPerIteration` calls a function twice per iteration. |
| `GeneratorCatchBench` | `Generator`, `TryCatchNeverThrows`, `TryCatchThrowsOften` | A generator with 50,000 yields, and calls inside `try`/`catch` (the woven backend also weaves `catch` blocks). |
| `InternalCallBench` | `StrlenAbs`, `ArrayMap` | Calls of internal functions (`zend_execute_internal`). |

The workload code is in `bench/Workload/` and is identical in every configuration.
It is required by `bench/bootstrap.php` and not autoloaded, because code that the autoloader loads is not woven unless `aop.weave_autoload=1`.

Every subject runs in these states:

| State | Meaning |
|---|---|
| `no aop` | The extension is not loaded. This is the baseline. |
| `loaded` | The extension is loaded, nothing is registered. |
| `miss` | `Engine::register()` was called with a matcher that never matches (`return 0`). The matcher answer is cached per function. |
| `pass` | The matcher returns `Engine::ADVISE` for everything and the dispatcher calls the original code (`$original($args)`). This is the cost of the advice machinery itself, without any aspect logic. |
| `advised` | The real runtime: `Weaver::fromAspects()` with one aspect whose before advice matches every function and method (`execution(* *(..)) \|\| execution(* *::*(..))`). This is what an application pays per advised call. Not in the default matrix (slow); run it with `CELLS`. |

The configuration matrix is the backend (`woven` or `hook`) times the opcache JIT mode (`disable`, `tracing`, `function`), always with opcache enabled for the CLI and `opcache.jit_buffer_size=64M`.
The `hook` backend overrides `zend_execute_ex` and makes opcache switch the JIT off, so it only runs with `opcache.jit=disable`.

## How it is measured

- PHPBench runs every iteration in a fresh PHP process with `-n`.
  The extension and the opcache settings are passed as `-d` options, so `aop.interception` (a php.ini-only setting) is set at startup.
  The PHPBench host process never loads aop.
- Each iteration runs the subject 10 times after 2 warm-up runs; the JIT has compiled the hot code by then.
  The value of an iteration is the time of one run.
- The driver `bench/run.sh` pins the process to one CPU (`taskset`).
- Every cell runs in several **rounds**.
  A round runs all cells once, in a fixed order, so slow drift of the machine (other load, clocks, thermal state) hits all cells alike.
  The report takes the median of the iterations of a round, divides it by the median of the baseline in the same round, and shows the median of these ratios with the lowest and highest round in brackets.
  The times are the median of the per-round medians.
- Before each subject, `bench/Environment.php` asserts the configuration: `opcache_get_status()['jit']['on']` must match what the cell expects (on for `tracing` and `function` with `woven`, off otherwise), the extension must be loaded exactly when the state needs it, and in state `pass` a probe call must reach the dispatcher.
  A mismatch aborts the run.
  For example, `JIT_BUFFER=0 CELLS=woven-tracing-loaded bench/run.sh` stops with `JIT is off but this cell expects it on.`

Caveats:

- PHPBench measures with `microtime(true)`, a wall clock.
  When the host clock steps backwards (this happens on WSL2), PHPBench aborts with `Net time cannot be less than zero`.
  The driver repeats a cell once in that case.
- Whole-process timing (startup, compile time) is not measured.
- A shared or busy machine adds noise.
  Run on an idle machine; the bracketed range in the overhead tables shows how stable a number was.
  Differences of about 10% or less, in either direction, are noise.

## Running the suite

```bash
composer install
make bench                          # the full matrix with the PHP found as `php`
make bench PHP=php8.4 CPU=4         # another PHP binary, another core
make bench CELLS="woven-tracing-miss none-tracing-none" FILTER=benchFunction ROUNDS=3
make bench CELLS="none-tracing-none woven-tracing-advised" FILTER="CallBench|MixedBench"   # the advised state
make bench-docker                   # PHP 8.3, 8.4 and 8.5 (NTS, Debian) in Docker, results in bench/results/
make bench-docker PHP_VERSIONS=8.5 CPU=2
```

`make bench` builds the extension first and needs the PHP development files (the same as `make ext`).
The raw PHPBench XML files and `meta.json` (PHP, CPU, commit, date) go to `bench/results/<run>/`; the Markdown tables of this page are printed at the end and can be regenerated with `php bench/report.php bench/results/<run>`.

| Variable | Default | Meaning |
|---|---|---|
| `PHP` | `php` | The PHP binary to measure. |
| `EXT_SO` | `ext/modules/aop.so` | The extension to load. |
| `CPU` | `2` | The core to pin to with `taskset`. Empty disables pinning. Pick a core that is otherwise idle. |
| `CELLS` | the full matrix | Space separated cells, named `<backend>-<jit>-<state>`, such as `woven-tracing-miss` or `none-disable-none`. Include the matching `none-<jit>-none` cell to get overhead numbers. |
| `FILTER` | all subjects | PHPBench `--filter` regular expression on the subject, such as `benchFunction`. |
| `ROUNDS`, `ITERATIONS`, `PASS_ITERATIONS`, `REVS` | `9`, `3`, `1`, `10` | Rounds, iterations per round (the slow `pass` and `advised` cells use `PASS_ITERATIONS`), revolutions per iteration. |
| `JIT_BUFFER` | `64M` | `opcache.jit_buffer_size`. `0` makes the JIT assertion abort. |
| `OUT` | `bench/results/php<version>-<timestamp>` | Result directory. |

The suite is not part of CI, because shared runners are too noisy for it.
Re-run it when the interception code paths change, and update this page (see the [release process](building.md#release-process)).

## Results

Measured on 2026-10-29 with the dispatch fast path branch (on top of commit `b12fd25`); the "Dispatch fast path" section below says what changed.
The "Fix to the benchmark" history and the cross-version Docker table predate that change (the Docker table was measured with the decision fast path of issue #8, which the "Decision fast path" section compares before and after).

| | |
|---|---|
| CPU | AMD Ryzen 7 5800X3D (8 cores, 16 threads), pinned to one core |
| OS | Linux 6.18 (WSL2) on Windows, other desktop load on the machine |
| PHP, full tables | 8.3.15 NTS release build (Debian package), `php8.3`, extension built with `make ext` |
| PHP, cross-version table | the official `php:8.3-cli`, `php:8.4-cli` and `php:8.5-cli` Debian images (8.3.36, 8.4.27, 8.5.12, NTS), `make bench-docker` |
| Suite settings | 9 rounds; 3 iterations per round (1 for `pass` and `advised`); 10 runs per iteration after 2 warm-up runs |

The machine was shared with other work while this ran, so the bracketed ranges are wider than they would be on an idle host.
The `hook` columns have the widest ranges; a few of their rounds are far off, which is why only medians are quoted below.
Every cell passed the JIT assertion: `tracing` and `function` with `woven` ran with the JIT on, `disable` and `hook` with it off.

### What the numbers say

- **Code without calls does not slow down.** `NumericBench::Inline` is a JIT-compiled loop with no calls in it: with `woven` it stays within +-3% of plain PHP in every state and JIT mode, and the JIT makes it 4.2 times faster than with the JIT off (1.46 ms with `disable`, 346 us with `tracing`, 324 us with `function`).
  So the woven prologue does not disturb JIT-compiled code, and the JIT stays effective.
  Differences of this size, in either direction, are noise.
- **Every call pays a fixed price.** For `CallBench::Function` (100,000 calls of a function that adds two integers) plain PHP needs 4.5 ns per call with `tracing`.
  `woven` adds 4.2 ns per call when the extension is loaded and nothing is registered (`loaded`), and 6.8 ns per call when a matcher is registered that never matches (`miss`).
  A call that reaches a dispatcher (`pass`, the original code runs through `OriginalCall`) adds about 0.33 us.
  With the JIT off: 17.3 ns plain, plus 10 ns (`woven`, `loaded`), 14 ns (`miss`) and 0.35 us (`pass`).
  The `hook` backend adds 3.2 ns (`loaded`), 4.9 ns (`miss`) and 0.32 us (`pass`) on top of 17.3 ns.
  In percent these look large for a tight call loop, because the function does almost nothing.
- **The cost shrinks with real work.** `MixedBench::Orders` (objects, arrays, strings) costs +13% to +19% with `woven`/`loaded` and +21% to +29% with `miss`.
  `NumericBench::CallPerPixel`, where each call does up to 100 loop iterations, costs +5% (`loaded`) and +7% (`miss`) with `tracing`.
- **A registered matcher that never matches costs little more than no matcher.** `miss` adds about 2.7 ns per call on top of `loaded` with `woven`/`tracing` (11.3 ns against 8.6 ns for `CallBench::Function`), and about 3.6 ns with the JIT off.
  The decision is cached per (function, runtime class) in a small direct-mapped cache that the guard reads before it builds a join point (see [Decision fast path](#decision-fast-path-issue-8)).
  What remains of `loaded` is the call of `AOP\Internal\intercept()` itself, answered inside `zend_execute_internal` (see [Dispatch fast path](#dispatch-fast-path)).
- **`woven` with the JIT beats `hook` without it.** `NumericBench::CallPerPixel` with a matcher registered that never matches: 402 us with `woven`/`tracing` and 1.72 ms with `hook`.
  Even for nothing but tiny calls, `woven` is cheaper: `CallBench::Function`/`miss` takes 1.13 ms with `woven`/`tracing` and 2.22 ms with `hook`.
  With the JIT off, `hook` is a little cheaper per call than `woven` (2.22 ms against 3.12 ms for the same cell).
- **Internal functions** (`InternalCallBench::StrlenAbs`) pay the same kind of price per call (`woven`: +14% to +28% for `loaded`, +49% to +58% for `miss`; `hook`/`miss` is +46%).
  Generators cost nothing extra for the generator call itself (`Generator` stays within +8%).
  `try`/`catch` adds nothing of its own: `TryCatchNeverThrows` pays a per-call price of the same size as `CallBench`.
- **The three PHP versions agree** (table below).
  The percentages are relative to plain PHP with the same JIT mode, so compare absolute times, not the percentages, when you compare the backends.

### Fix to the benchmark before publishing

The first ad-hoc numbers in issue #3 showed `woven` faster than plain PHP and a JIT that hardly changed anything.
That came from the method (a top-level loop over global variables, one run, no warm-up, no pinning), and this suite removes those causes: subjects are methods with local variables, every run warms up, cells are paired per round against the baseline, and the JIT state is asserted.
The first version of the driver ran every cell once, one after the other.
Cells that ran minutes apart differed by up to 2x in absolute time on this machine, and call-free subjects came out 5% to 35% faster than plain PHP in some cells.
That drift is why the cells are now interleaved in rounds, and why the tables show the median of the per-round ratios and their range.
After that change the call-free subjects stay within +-2%.

### Full tables, PHP 8.3.15, native

#### opcache.jit=disable

Time for one run of the subject (median of the rounds):

| Subject | no aop | woven / loaded | woven / miss | woven / pass | hook / loaded | hook / miss | hook / pass |
|---|---:|---:|---:|---:|---:|---:|---:|
| InternalCallBench::StrlenAbs | 2.21 ms | 2.50 ms | 3.34 ms | 62.17 ms | 2.46 ms | 3.14 ms | 64.34 ms |
| InternalCallBench::ArrayMap | 2.59 ms | 2.59 ms | 2.62 ms | 3.34 ms | 2.77 ms | 2.94 ms | 3.61 ms |
| MixedBench::Orders | 1.56 ms | 1.74 ms | 1.88 ms | 13.25 ms | 1.71 ms | 2.20 ms | 33.18 ms |
| CallBench::Function | 1.73 ms | 2.76 ms | 3.12 ms | 36.62 ms | 2.05 ms | 2.22 ms | 33.54 ms |
| CallBench::InstanceMethod | 1.85 ms | 2.86 ms | 3.20 ms | 36.57 ms | 2.20 ms | 2.58 ms | 34.49 ms |
| CallBench::StaticMethod | 1.75 ms | 2.79 ms | 3.15 ms | 37.66 ms | 2.16 ms | 2.35 ms | 34.27 ms |
| GeneratorCatchBench::Generator | 1.17 ms | 1.16 ms | 1.16 ms | 1.24 ms | 1.23 ms | 1.25 ms | 1.26 ms |
| GeneratorCatchBench::TryCatchNeverThrows | 993 µs | 1.38 ms | 1.59 ms | 18.23 ms | 1.15 ms | 1.30 ms | 16.58 ms |
| GeneratorCatchBench::TryCatchThrowsOften | 1.12 ms | 1.28 ms | 1.52 ms | 8.07 ms | 1.25 ms | 1.55 ms | 7.66 ms |
| NumericBench::Inline | 1.46 ms | 1.47 ms | 1.46 ms | 1.49 ms | 1.46 ms | 1.46 ms | 1.46 ms |
| NumericBench::CallPerPixel | 1.51 ms | 1.54 ms | 1.59 ms | 3.01 ms | 1.70 ms | 1.72 ms | 2.99 ms |
| NumericBench::CallPerIteration | 6.15 ms | 7.99 ms | 8.92 ms | 96.62 ms | 6.79 ms | 7.01 ms | 87.90 ms |

Overhead relative to no aop with the same JIT mode (median of the per-round ratios, [lowest, highest round]):

| Subject | woven / loaded | woven / miss | woven / pass | hook / loaded | hook / miss | hook / pass |
|---|---:|---:|---:|---:|---:|---:|
| InternalCallBench::StrlenAbs | +14% [+8%, +17%] | +53% [+39%, +67%] | +2761% [+2536%, +3244%] | +12% [+4%, +20%] | +46% [+29%, +55%] | +2806% [+2687%, +3307%] |
| InternalCallBench::ArrayMap | -1% [-3%, +3%] | +1% [-9%, +7%] | +26% [+15%, +41%] | +7% [-7%, +16%] | +12% [+2%, +23%] | +39% [+27%, +67%] |
| MixedBench::Orders | +13% [+7%, +18%] | +21% [+18%, +25%] | +737% [+721%, +965%] | +9% [+3%, +17%] | +38% [+33%, +50%] | +2014% [+1907%, +2261%] |
| CallBench::Function | +58% [+47%, +62%] | +76% [+64%, +90%] | +1972% [+1874%, +2196%] | +19% [+8%, +25%] | +28% [+18%, +32%] | +1834% [+1677%, +1971%] |
| CallBench::InstanceMethod | +55% [+47%, +63%] | +77% [+68%, +80%] | +1870% [+1808%, +2099%] | +19% [+17%, +28%] | +41% [+29%, +49%] | +1766% [+1668%, +1875%] |
| CallBench::StaticMethod | +57% [+51%, +65%] | +77% [+71%, +87%] | +2014% [+1891%, +2210%] | +23% [+21%, +25%] | +35% [+30%, +48%] | +1826% [+1737%, +2010%] |
| GeneratorCatchBench::Generator | +0% [-8%, +3%] | -2% [-6%, +4%] | +7% [-2%, +15%] | +7% [-0%, +15%] | +8% [+1%, +17%] | +9% [-1%, +14%] |
| GeneratorCatchBench::TryCatchNeverThrows | +39% [+35%, +47%] | +60% [+50%, +70%] | +1770% [+1613%, +1890%] | +16% [+9%, +25%] | +31% [+25%, +33%] | +1594% [+1456%, +1778%] |
| GeneratorCatchBench::TryCatchThrowsOften | +14% [+6%, +27%] | +35% [+27%, +49%] | +608% [+583%, +681%] | +13% [+6%, +19%] | +40% [+26%, +49%] | +574% [+542%, +604%] |
| NumericBench::Inline | +2% [-2%, +6%] | +1% [-3%, +5%] | +1% [-1%, +10%] | +1% [-5%, +7%] | -0% [-7%, +4%] | +0% [-5%, +7%] |
| NumericBench::CallPerPixel | +1% [-6%, +10%] | +5% [-2%, +21%] | +99% [+91%, +115%] | +14% [+4%, +19%] | +14% [+3%, +19%] | +103% [+74%, +157%] |
| NumericBench::CallPerIteration | +30% [+27%, +38%] | +47% [+38%, +50%] | +1447% [+1382%, +2879%] | +11% [+6%, +35%] | +15% [+8%, +24%] | +1314% [+1231%, +1373%] |

#### opcache.jit=tracing

Time for one run of the subject (median of the rounds):

| Subject | no aop | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|---:|
| InternalCallBench::StrlenAbs | 1.11 ms | 1.43 ms | 1.75 ms | 57.64 ms |
| InternalCallBench::ArrayMap | 2.39 ms | 2.39 ms | 2.41 ms | 3.15 ms |
| MixedBench::Orders | 1.10 ms | 1.32 ms | 1.43 ms | 12.13 ms |
| CallBench::Function | 446 µs | 864 µs | 1.13 ms | 33.44 ms |
| CallBench::InstanceMethod | 519 µs | 912 µs | 1.25 ms | 34.35 ms |
| CallBench::StaticMethod | 728 µs | 1.19 ms | 1.50 ms | 34.26 ms |
| GeneratorCatchBench::Generator | 1.00 ms | 999 µs | 994 µs | 1.07 ms |
| GeneratorCatchBench::TryCatchNeverThrows | 230 µs | 434 µs | 625 µs | 17.63 ms |
| GeneratorCatchBench::TryCatchThrowsOften | 936 µs | 1.05 ms | 1.30 ms | 7.68 ms |
| NumericBench::Inline | 346 µs | 354 µs | 348 µs | 350 µs |
| NumericBench::CallPerPixel | 373 µs | 397 µs | 402 µs | 2.13 ms |
| NumericBench::CallPerIteration | 2.13 ms | 2.68 ms | 3.31 ms | 85.24 ms |

Overhead relative to no aop with the same JIT mode (median of the per-round ratios, [lowest, highest round]):

| Subject | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|
| InternalCallBench::StrlenAbs | +28% [+7%, +45%] | +58% [+36%, +68%] | +5148% [+4390%, +5536%] |
| InternalCallBench::ArrayMap | +1% [-10%, +3%] | +1% [-5%, +6%] | +31% [+22%, +48%] |
| MixedBench::Orders | +14% [+10%, +28%] | +29% [+17%, +34%] | +1006% [+918%, +1157%] |
| CallBench::Function | +97% [+87%, +109%] | +149% [+131%, +184%] | +7528% [+7041%, +8144%] |
| CallBench::InstanceMethod | +77% [+60%, +86%] | +144% [+116%, +168%] | +6525% [+5826%, +6618%] |
| CallBench::StaticMethod | +63% [+43%, +71%] | +102% [+88%, +119%] | +4634% [+4225%, +4702%] |
| GeneratorCatchBench::Generator | +0% [-6%, +8%] | -1% [-8%, +8%] | +8% [+1%, +20%] |
| GeneratorCatchBench::TryCatchNeverThrows | +88% [+86%, +116%] | +172% [+157%, +184%] | +7513% [+7244%, +8456%] |
| GeneratorCatchBench::TryCatchThrowsOften | +12% [+9%, +20%] | +39% [+31%, +46%] | +712% [+691%, +765%] |
| NumericBench::Inline | +3% [-3%, +18%] | +1% [-8%, +3%] | -0% [-2%, +18%] |
| NumericBench::CallPerPixel | +5% [-5%, +11%] | +7% [-2%, +21%] | +470% [+419%, +583%] |
| NumericBench::CallPerIteration | +25% [+18%, +30%] | +53% [+47%, +71%] | +3889% [+3668%, +3985%] |

#### opcache.jit=function

Time for one run of the subject (median of the rounds):

| Subject | no aop | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|---:|
| InternalCallBench::StrlenAbs | 1.52 ms | 1.89 ms | 2.27 ms | 59.70 ms |
| InternalCallBench::ArrayMap | 2.39 ms | 2.37 ms | 2.33 ms | 3.00 ms |
| MixedBench::Orders | 1.17 ms | 1.37 ms | 1.47 ms | 12.24 ms |
| CallBench::Function | 940 µs | 1.36 ms | 1.68 ms | 33.69 ms |
| CallBench::InstanceMethod | 999 µs | 1.49 ms | 1.84 ms | 34.43 ms |
| CallBench::StaticMethod | 1.09 ms | 1.52 ms | 1.85 ms | 35.01 ms |
| GeneratorCatchBench::Generator | 976 µs | 982 µs | 982 µs | 1.12 ms |
| GeneratorCatchBench::TryCatchNeverThrows | 575 µs | 816 µs | 983 µs | 17.64 ms |
| GeneratorCatchBench::TryCatchThrowsOften | 1.05 ms | 1.13 ms | 1.37 ms | 7.63 ms |
| NumericBench::Inline | 324 µs | 328 µs | 324 µs | 327 µs |
| NumericBench::CallPerPixel | 362 µs | 381 µs | 384 µs | 1.73 ms |
| NumericBench::CallPerIteration | 2.91 ms | 4.10 ms | 4.98 ms | 89.74 ms |

Overhead relative to no aop with the same JIT mode (median of the per-round ratios, [lowest, highest round]):

| Subject | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|
| InternalCallBench::StrlenAbs | +24% [+19%, +29%] | +49% [+44%, +62%] | +3813% [+3689%, +4222%] |
| InternalCallBench::ArrayMap | -1% [-4%, +4%] | -2% [-8%, +3%] | +26% [+24%, +33%] |
| MixedBench::Orders | +19% [+13%, +22%] | +25% [+22%, +32%] | +958% [+892%, +980%] |
| CallBench::Function | +46% [+22%, +70%] | +80% [+64%, +89%] | +3510% [+3038%, +3806%] |
| CallBench::InstanceMethod | +53% [+40%, +57%] | +84% [+72%, +101%] | +3379% [+3149%, +3572%] |
| CallBench::StaticMethod | +40% [+28%, +42%] | +71% [+54%, +83%] | +3093% [+2872%, +3316%] |
| GeneratorCatchBench::Generator | +1% [-6%, +8%] | +0% [-6%, +10%] | +12% [+8%, +21%] |
| GeneratorCatchBench::TryCatchNeverThrows | +42% [+28%, +54%] | +71% [+58%, +78%] | +2988% [+2625%, +3061%] |
| GeneratorCatchBench::TryCatchThrowsOften | +11% [+4%, +17%] | +31% [+27%, +39%] | +633% [+608%, +703%] |
| NumericBench::Inline | +2% [-9%, +5%] | +0% [-5%, +9%] | +3% [-8%, +18%] |
| NumericBench::CallPerPixel | +3% [-5%, +11%] | +5% [+0%, +14%] | +376% [+337%, +474%] |
| NumericBench::CallPerIteration | +40% [+39%, +51%] | +72% [+58%, +93%] | +3009% [+2761%, +3185%] |

#### State advised

The real runtime: one before advice through the Weaver on every function and method, measured on the same machine with 5 rounds (`CELLS="none-tracing-none woven-tracing-pass woven-tracing-advised ..."`).
`pass` is listed again for the same run, so the two columns compare directly: the difference is the PHP runtime (`NativeRuntime::dispatch()`, the interceptor chain, the advice call).

| Subject | JIT | no aop | woven / pass | woven / advised | hook / advised |
|---|---|---:|---:|---:|---:|
| CallBench::Function | tracing | 444 µs | 34.76 ms | 319.27 ms | |
| CallBench::InstanceMethod | tracing | 522 µs | 35.81 ms | 308.51 ms | |
| CallBench::StaticMethod | tracing | 740 µs | 34.36 ms | 309.57 ms | |
| MixedBench::Orders | tracing | 1.13 ms | 12.15 ms | 101.06 ms | |
| NumericBench::CallPerPixel | tracing | 388 µs | 2.13 ms | 13.27 ms | |
| InternalCallBench::StrlenAbs | tracing | 1.15 ms | 59.40 ms | 635.84 ms | |
| InternalCallBench::ArrayMap | tracing | 2.38 ms | 3.11 ms | 9.33 ms | |
| CallBench::Function | disable | 1.81 ms | | 368.42 ms | 400.93 ms |
| MixedBench::Orders | disable | 1.59 ms | | 120.61 ms | 129.17 ms |
| NumericBench::CallPerPixel | disable | 1.50 ms | | 16.91 ms | 17.77 ms |

An advised call of `add(int, int)` costs about 3.2 µs with `woven`/`tracing` (0.33 µs of it in the engine), against about 4.6 µs before the dispatch fast path.
Advice is meant for a few hundred to a few thousand calls per request, not for the inner loop; the `miss` columns above are what every other call pays.

### Overhead by PHP version

Median overhead of the Docker runs (PHP 8.3.36, 8.4.27, 8.5.12), relative to plain PHP with the same JIT mode.
The `hook` columns are relative to plain PHP with the JIT off, the `woven` columns to plain PHP with `opcache.jit=tracing`.

| Subject | PHP | woven + JIT tracing, loaded | woven + JIT tracing, miss | hook, loaded | hook, miss |
|---|---|---:|---:|---:|---:|
| `CallBench::Function` | 8.3 | +120% | +154% | +19% | +22% |
| `CallBench::Function` | 8.4 | +84% | +113% | +15% | +24% |
| `CallBench::Function` | 8.5 | +83% | +105% | +19% | +28% |
| `MixedBench::Orders` | 8.3 | +14% | +26% | +11% | +38% |
| `MixedBench::Orders` | 8.4 | +21% | +26% | +18% | +50% |
| `MixedBench::Orders` | 8.5 | +22% | +29% | +11% | +39% |
| `NumericBench::Inline` | 8.3 | -0% | -3% | -2% | -2% |
| `NumericBench::Inline` | 8.4 | -2% | -3% | +7% | -1% |
| `NumericBench::Inline` | 8.5 | -0% | -0% | +2% | +1% |
| `NumericBench::CallPerPixel` | 8.3 | +7% | +7% | -1% | +1% |
| `NumericBench::CallPerPixel` | 8.4 | +3% | +6% | +6% | +5% |
| `NumericBench::CallPerPixel` | 8.5 | +9% | +12% | -0% | +1% |

## Dispatch fast path

Where the time of an advised call went before this change, measured with callgrind on a 2 million call micro benchmark (PHP 8.3, `woven`, JIT off):

- `pass` (every call reaches a trivial dispatcher, 465 ns per call with the tracing JIT): about 40% of the instructions built the two descriptor arrays.
  Every `add_assoc_*_ex()` hashed its key, allocated a key string and freed it again when the dispatcher returned.
  The engine now interns the twelve keys and the three kind values once in MINIT and inserts with `zend_hash_add_new()`.
- `loaded` (nothing registered): every woven call ran about 85 instructions of extension code across `aop_execute_internal()`, `execute_internal()` and `intercept()` before it could say "not intercepted".
  `aop_execute_internal()` now answers the prologue with `false` as soon as it sees that no matcher is registered, runs other internal functions directly in that case, and calls the handler without the `execute_internal()` layer.
- A real aspect (a before advice through the Weaver, 5.7 µs per call): 5% of all instructions were `strncasecmp()` for the `AOP\` prefix, because every internal function the PHP runtime itself calls passes the join point checks, and the rest was the PHP runtime.
  The prefix test is an inline byte comparison now.
  In `NativeRuntime::dispatch()` the argument normalizer is skipped for a complete positional argument list, the plan cache key is built by concatenation, the `WeakMap` lookups for instance services and per-object advice wait until something was registered, the call-chain closure is created only when a call join point applies, and `AopContext::push()`/`pop()` modify the main stack in place instead of copying it on every call.

Micro benchmark, same machine and settings as the tables, PHP 8.3.15, `woven`, median of two runs (ns per call):

| Subject | JIT | State | Before | After |
|---|---|---|---:|---:|
| `add(int, int)` | tracing | plain PHP | 4.3 | 4.3 |
| `add(int, int)` | tracing | loaded | 10.1 | 8.9 |
| `add(int, int)` | tracing | miss | 11.0 | 10.9 |
| `add(int, int)` | tracing | pass | 465 | 293 |
| `abs(int)` (internal) | tracing | loaded | 8.1 | 7.0 |
| `abs(int)` (internal) | tracing | miss | 9.6 | 9.0 |
| `abs(int)` (internal) | tracing | pass | 418 | 262 |
| `Calc::add()`, one before advice | tracing | advised | 5726 | 3648 |
| `Calc::add()`, one around advice | tracing | advised | 5906 | 3800 |
| `Calc::add()`, before and around | tracing | advised | 6141 | 3939 |

Of the 3.6 µs that remain for a before advice, the engine and the call itself take about a quarter; the rest is the chain in PHP: `JoinPointData`, `MethodInvocation` and its copy per interceptor, the frame bookkeeping and the advice interceptor.

## Decision fast path (issue #8)

The `miss` price was 27.5 ns per call against 10.1 ns for `loaded` (`CallBench::Function`, `woven`, `tracing`, 2 million calls in a micro benchmark).
The difference of about 17 ns was the work the guard did before it looked at the cached answer: `aop_join_point()` (about 5 ns) and the lookup in the `decisions` hashtable (about 12 ns).
Since this change the guard first reads a per-request, direct-mapped cache with 256 slots, keyed by (function, runtime class), whose value is the matcher's flags.
A hit without `ADVISE`, `TRACK` or `CALLER_SENSITIVE` returns "pass" at once.
Closures never use it, and only pairs that already passed `aop_join_point()` fill a slot, so the matcher is still asked once per (class, function).
The cache is cleared with the decisions on `register()`, `unregister()` and `reset()` and at request start.
After the change the micro benchmark shows 11.3 ns (`miss`) against 10.2 ns (`loaded`).

A flag inline in the woven prologue, checked before `intercept()`, was not used.
Opcache keeps op arrays in read-only shared memory, so per-function state cannot be written there.
The decision also depends on the runtime class (and on the caller for `CALLER_SENSITIVE` and `cflow()`), not on the function alone.
The prologue is unchanged, so the `loaded` price (the call of `intercept()`) is unchanged too.

Both runs below use the same machine and settings as the tables above (PHP 8.3.15, 9 rounds, CPU 2, full matrix), measured back to back: before is `master` at `f4158cb`, after is this branch.
Every cell passed the JIT assertion.
Median time of one run, `miss` state:

| Subject | JIT | Backend | Before | After |
|---|---|---|---:|---:|
| CallBench::Function | tracing | woven | 3.33 ms | 1.12 ms |
| CallBench::InstanceMethod | tracing | woven | 3.88 ms | 1.23 ms |
| CallBench::StaticMethod | tracing | woven | 3.93 ms | 1.44 ms |
| InternalCallBench::StrlenAbs | tracing | woven | 5.50 ms | 1.93 ms |
| MixedBench::Orders | tracing | woven | 2.24 ms | 1.39 ms |
| NumericBench::CallPerPixel | tracing | woven | 478 µs | 399 µs |
| NumericBench::CallPerIteration | tracing | woven | 8.55 ms | 3.26 ms |
| CallBench::Function | disable | woven | 5.08 ms | 2.98 ms |
| CallBench::InstanceMethod | disable | woven | 5.58 ms | 3.15 ms |
| CallBench::StaticMethod | disable | woven | 5.84 ms | 3.01 ms |
| InternalCallBench::StrlenAbs | disable | woven | 6.79 ms | 3.03 ms |
| MixedBench::Orders | disable | woven | 2.72 ms | 1.81 ms |
| NumericBench::CallPerPixel | disable | woven | 1.69 ms | 1.59 ms |
| NumericBench::CallPerIteration | disable | woven | 14.07 ms | 8.37 ms |
| CallBench::Function | disable | hook | 4.57 ms | 2.17 ms |
| CallBench::InstanceMethod | disable | hook | 5.23 ms | 2.45 ms |
| CallBench::StaticMethod | disable | hook | 5.08 ms | 2.27 ms |
| InternalCallBench::StrlenAbs | disable | hook | 6.71 ms | 2.99 ms |
| MixedBench::Orders | disable | hook | 3.03 ms | 2.09 ms |
| NumericBench::CallPerPixel | disable | hook | 1.61 ms | 1.50 ms |
| NumericBench::CallPerIteration | disable | hook | 12.71 ms | 6.70 ms |

Median time of one run, `loaded` state (the guard is unchanged; the differences are noise):

| Subject | JIT | Backend | Before | After |
|---|---|---|---:|---:|
| CallBench::Function | tracing | woven | 955 µs | 966 µs |
| CallBench::InstanceMethod | tracing | woven | 1.09 ms | 1.12 ms |
| CallBench::StaticMethod | tracing | woven | 1.32 ms | 1.39 ms |
| InternalCallBench::StrlenAbs | tracing | woven | 1.40 ms | 1.42 ms |
| MixedBench::Orders | tracing | woven | 1.30 ms | 1.29 ms |
| NumericBench::CallPerPixel | tracing | woven | 381 µs | 382 µs |
| NumericBench::CallPerIteration | tracing | woven | 3.02 ms | 3.08 ms |
| CallBench::Function | disable | woven | 2.87 ms | 2.87 ms |
| CallBench::InstanceMethod | disable | woven | 2.95 ms | 2.95 ms |
| CallBench::StaticMethod | disable | woven | 2.97 ms | 2.88 ms |
| InternalCallBench::StrlenAbs | disable | woven | 2.50 ms | 2.46 ms |
| MixedBench::Orders | disable | woven | 1.73 ms | 1.75 ms |
| NumericBench::CallPerPixel | disable | woven | 1.66 ms | 1.58 ms |
| NumericBench::CallPerIteration | disable | woven | 8.12 ms | 7.90 ms |
| CallBench::Function | disable | hook | 1.95 ms | 2.06 ms |
| CallBench::InstanceMethod | disable | hook | 2.15 ms | 2.15 ms |
| CallBench::StaticMethod | disable | hook | 2.16 ms | 2.12 ms |
| InternalCallBench::StrlenAbs | disable | hook | 2.55 ms | 2.47 ms |
| MixedBench::Orders | disable | hook | 1.67 ms | 1.66 ms |
| NumericBench::CallPerPixel | disable | hook | 1.50 ms | 1.52 ms |
| NumericBench::CallPerIteration | disable | hook | 6.62 ms | 6.61 ms |
