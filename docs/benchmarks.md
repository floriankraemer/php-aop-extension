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
| `ROUNDS`, `ITERATIONS`, `PASS_ITERATIONS`, `REVS` | `9`, `3`, `1`, `10` | Rounds, iterations per round (the slow `pass` cells use `PASS_ITERATIONS`), revolutions per iteration. |
| `JIT_BUFFER` | `64M` | `opcache.jit_buffer_size`. `0` makes the JIT assertion abort. |
| `OUT` | `bench/results/php<version>-<timestamp>` | Result directory. |

The suite is not part of CI, because shared runners are too noisy for it.
Re-run it when the interception code paths change, and update this page (see the [release process](building.md#release-process)).

## Results

Measured on 2026-10-27 with the extension of the decision fast path branch (issue #8, on top of commit `f4158cb`).
The "Fix to the benchmark" history predates that change; the Docker table below was measured again with it, and the "Decision fast path" section compares the native runs before and after.

| | |
|---|---|
| CPU | AMD Ryzen 7 5800X3D (8 cores, 16 threads), pinned to one core |
| OS | Linux 6.18 (WSL2) on Windows, other desktop load on the machine |
| PHP, full tables | 8.3.15 NTS release build (Debian package), `php8.3`, extension built with `make ext` |
| PHP, cross-version table | the official `php:8.3-cli`, `php:8.4-cli` and `php:8.5-cli` Debian images (8.3.36, 8.4.27, 8.5.12, NTS), `make bench-docker` |
| Suite settings | 9 rounds; 3 iterations per round (1 for `pass`); 10 runs per iteration after 2 warm-up runs |

The machine was shared with other work while this ran, so the bracketed ranges are wider than they would be on an idle host.
The `hook` columns have the widest ranges; a few of their rounds are far off, which is why only medians are quoted below.
Every cell passed the JIT assertion: `tracing` and `function` with `woven` ran with the JIT on, `disable` and `hook` with it off.

### What the numbers say

- **Code without calls does not slow down.** `NumericBench::Inline` is a JIT-compiled loop with no calls in it: with `woven` it stays within +-2% of plain PHP in every state and JIT mode, and the JIT makes it 4.1 times faster than with the JIT off (1.38 ms with `disable`, 335 us with `tracing`, 306 us with `function`).
  So the woven prologue does not disturb JIT-compiled code, and the JIT stays effective.
  Differences of this size, in either direction, are noise.
- **Every call pays a fixed price.** For `CallBench::Function` (100,000 calls of a function that adds two integers) plain PHP needs 4.3 ns per call with `tracing`.
  `woven` adds 5.4 ns per call when the extension is loaded and nothing is registered (`loaded`), and 6.9 ns per call when a matcher is registered that never matches (`miss`).
  A call that reaches a dispatcher (`pass`, the original code runs through `OriginalCall`) adds about 0.5 us.
  With the JIT off: 16.7 ns plain, plus 12 ns (`woven`, `loaded`), 13 ns (`miss`) and 0.52 us (`pass`).
  The `hook` backend adds 3.9 ns (`loaded`), 5.0 ns (`miss`) and 0.49 us (`pass`) on top of 16.7 ns.
  In percent these look large for a tight call loop, because the function does almost nothing.
- **The cost shrinks with real work.** `MixedBench::Orders` (objects, arrays, strings) costs +14% to +26% with `woven`/`loaded` and +20% to +31% with `miss`.
  `NumericBench::CallPerPixel`, where each call does up to 100 loop iterations, costs +7% (`loaded`) and +12% (`miss`) with `tracing`.
- **A registered matcher that never matches costs little more than no matcher.** `miss` adds 1.5 ns per call on top of `loaded` with `woven`/`tracing` (11.2 ns against 9.7 ns for `CallBench::Function`), and about 1 ns with the JIT off.
  The decision is cached per (function, runtime class) in a small direct-mapped cache that the guard reads before it builds a join point (see [Decision fast path](#decision-fast-path-issue-8)).
  What remains of `loaded` is the call of `AOP\Internal\intercept()` itself.
- **`woven` with the JIT beats `hook` without it.** `NumericBench::CallPerPixel` with a matcher registered that never matches: 399 us with `woven`/`tracing` and 1.50 ms with `hook`.
  Even for nothing but tiny calls, `woven` is cheaper: `CallBench::Function`/`miss` takes 1.12 ms with `woven`/`tracing` and 2.17 ms with `hook`.
  With the JIT off, `hook` is a little cheaper per call than `woven` (2.17 ms against 2.98 ms for the same cell).
- **Internal functions** (`InternalCallBench::StrlenAbs`) pay the same kind of price per call (`woven`: +16% to +32% for `loaded`, +45% to +83% for `miss`; `hook`/`miss` is +39%).
  Generators cost nothing extra for the generator call itself (`Generator` stays within +5%).
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
| InternalCallBench::StrlenAbs | 2.15 ms | 2.46 ms | 3.03 ms | 96.66 ms | 2.47 ms | 2.99 ms | 98.52 ms |
| InternalCallBench::ArrayMap | 2.48 ms | 2.48 ms | 2.46 ms | 3.65 ms | 2.72 ms | 2.84 ms | 3.86 ms |
| MixedBench::Orders | 1.42 ms | 1.75 ms | 1.81 ms | 17.86 ms | 1.66 ms | 2.09 ms | 45.48 ms |
| CallBench::Function | 1.67 ms | 2.87 ms | 2.98 ms | 53.77 ms | 2.06 ms | 2.17 ms | 52.44 ms |
| CallBench::InstanceMethod | 1.73 ms | 2.95 ms | 3.15 ms | 54.04 ms | 2.15 ms | 2.45 ms | 51.51 ms |
| CallBench::StaticMethod | 1.65 ms | 2.88 ms | 3.01 ms | 53.42 ms | 2.12 ms | 2.27 ms | 51.73 ms |
| GeneratorCatchBench::Generator | 1.10 ms | 1.12 ms | 1.12 ms | 1.21 ms | 1.18 ms | 1.21 ms | 1.28 ms |
| GeneratorCatchBench::TryCatchNeverThrows | 942 µs | 1.39 ms | 1.46 ms | 26.59 ms | 1.10 ms | 1.23 ms | 25.62 ms |
| GeneratorCatchBench::TryCatchThrowsOften | 1.07 ms | 1.22 ms | 1.52 ms | 10.59 ms | 1.20 ms | 1.59 ms | 10.33 ms |
| NumericBench::Inline | 1.38 ms | 1.37 ms | 1.39 ms | 1.41 ms | 1.39 ms | 1.37 ms | 1.42 ms |
| NumericBench::CallPerPixel | 1.48 ms | 1.58 ms | 1.59 ms | 3.66 ms | 1.52 ms | 1.50 ms | 3.65 ms |
| NumericBench::CallPerIteration | 5.76 ms | 7.90 ms | 8.37 ms | 134.75 ms | 6.61 ms | 6.70 ms | 132.31 ms |

Overhead relative to no aop with the same JIT mode (median of the per-round ratios, [lowest, highest round]):

| Subject | woven / loaded | woven / miss | woven / pass | hook / loaded | hook / miss | hook / pass |
|---|---:|---:|---:|---:|---:|---:|
| InternalCallBench::StrlenAbs | +16% [+8%, +27%] | +45% [+31%, +79%] | +4497% [+4024%, +5439%] | +15% [+9%, +72%] | +40% [+30%, +77%] | +4388% [+4210%, +5205%] |
| InternalCallBench::ArrayMap | -1% [-3%, +6%] | +0% [-3%, +12%] | +48% [+34%, +72%] | +8% [+6%, +46%] | +15% [+10%, +68%] | +55% [+44%, +66%] |
| MixedBench::Orders | +21% [+15%, +25%] | +25% [+21%, +69%] | +1170% [+1074%, +1538%] | +16% [+11%, +64%] | +44% [+40%, +130%] | +3129% [+2902%, +3380%] |
| CallBench::Function | +73% [+67%, +81%] | +78% [+73%, +114%] | +3179% [+3025%, +3602%] | +22% [+17%, +66%] | +31% [+29%, +99%] | +3055% [+2882%, +3360%] |
| CallBench::InstanceMethod | +70% [+65%, +76%] | +83% [+76%, +102%] | +3023% [+2880%, +3730%] | +23% [+20%, +80%] | +42% [+37%, +68%] | +2843% [+2712%, +3022%] |
| CallBench::StaticMethod | +75% [+68%, +79%] | +82% [+75%, +98%] | +3162% [+2918%, +3488%] | +27% [+23%, +80%] | +37% [+31%, +79%] | +3059% [+2746%, +3556%] |
| GeneratorCatchBench::Generator | +0% [-4%, +7%] | -1% [-6%, +9%] | +10% [-1%, +16%] | +8% [+2%, +10%] | +11% [+2%, +24%] | +16% [+0%, +52%] |
| GeneratorCatchBench::TryCatchNeverThrows | +47% [+32%, +56%] | +55% [+40%, +63%] | +2690% [+2396%, +2944%] | +16% [+3%, +67%] | +31% [+19%, +50%] | +2575% [+2332%, +3193%] |
| GeneratorCatchBench::TryCatchThrowsOften | +16% [+11%, +19%] | +45% [+37%, +52%] | +884% [+848%, +1224%] | +12% [+4%, +67%] | +51% [+38%, +56%] | +885% [+781%, +1080%] |
| NumericBench::Inline | -1% [-7%, +6%] | -1% [-5%, +14%] | +3% [-4%, +11%] | +0% [-5%, +35%] | +0% [-5%, +11%] | +4% [-6%, +12%] |
| NumericBench::CallPerPixel | +7% [+3%, +9%] | +9% [+3%, +12%] | +150% [+133%, +6325%] | +3% [-0%, +36%] | +2% [-2%, +17%] | +147% [+129%, +228%] |
| NumericBench::CallPerIteration | +38% [+30%, +43%] | +46% [+39%, +51%] | +2276% [+2118%, +5865%] | +14% [+9%, +48%] | +16% [+9%, +53%] | +2197% [+1992%, +2348%] |

#### opcache.jit=tracing

Time for one run of the subject (median of the rounds):

| Subject | no aop | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|---:|
| InternalCallBench::StrlenAbs | 1.07 ms | 1.42 ms | 1.93 ms | 93.11 ms |
| InternalCallBench::ArrayMap | 2.21 ms | 2.26 ms | 2.31 ms | 3.23 ms |
| MixedBench::Orders | 1.12 ms | 1.29 ms | 1.39 ms | 18.52 ms |
| CallBench::Function | 430 µs | 966 µs | 1.12 ms | 51.29 ms |
| CallBench::InstanceMethod | 493 µs | 1.12 ms | 1.23 ms | 52.99 ms |
| CallBench::StaticMethod | 708 µs | 1.39 ms | 1.44 ms | 49.10 ms |
| GeneratorCatchBench::Generator | 940 µs | 944 µs | 959 µs | 1.08 ms |
| GeneratorCatchBench::TryCatchNeverThrows | 214 µs | 530 µs | 628 µs | 24.88 ms |
| GeneratorCatchBench::TryCatchThrowsOften | 884 µs | 1.08 ms | 1.28 ms | 10.04 ms |
| NumericBench::Inline | 335 µs | 336 µs | 341 µs | 335 µs |
| NumericBench::CallPerPixel | 358 µs | 382 µs | 399 µs | 2.44 ms |
| NumericBench::CallPerIteration | 1.59 ms | 3.08 ms | 3.26 ms | 122.20 ms |

Overhead relative to no aop with the same JIT mode (median of the per-round ratios, [lowest, highest round]):

| Subject | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|
| InternalCallBench::StrlenAbs | +32% [+24%, +41%] | +83% [+43%, +101%] | +8537% [+8140%, +9526%] |
| InternalCallBench::ArrayMap | +2% [-6%, +5%] | +3% [-14%, +19%] | +48% [+15%, +86%] |
| MixedBench::Orders | +14% [+12%, +23%] | +20% [+12%, +31%] | +1557% [+1278%, +18212%] |
| CallBench::Function | +130% [+117%, +152%] | +160% [+134%, +182%] | +11800% [+10369%, +15924%] |
| CallBench::InstanceMethod | +126% [+110%, +150%] | +152% [+122%, +175%] | +10517% [+9462%, +44652%] |
| CallBench::StaticMethod | +93% [+72%, +103%] | +102% [+86%, +110%] | +6868% [+6118%, +7116%] |
| GeneratorCatchBench::Generator | +1% [-14%, +7%] | +2% [-8%, +14%] | +15% [-0%, +75%] |
| GeneratorCatchBench::TryCatchNeverThrows | +145% [+105%, +175%] | +188% [+166%, +207%] | +11503% [+11128%, +12187%] |
| GeneratorCatchBench::TryCatchThrowsOften | +21% [+5%, +31%] | +47% [+33%, +63%] | +1017% [+943%, +1564%] |
| NumericBench::Inline | +2% [+0%, +5%] | +1% [-6%, +15%] | +2% [-5%, +15%] |
| NumericBench::CallPerPixel | +7% [-3%, +12%] | +12% [-1%, +15%] | +583% [+534%, +651%] |
| NumericBench::CallPerIteration | +92% [+79%, +97%] | +102% [+88%, +124%] | +7597% [+7192%, +11010%] |

#### opcache.jit=function

Time for one run of the subject (median of the rounds):

| Subject | no aop | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|---:|
| InternalCallBench::StrlenAbs | 1.55 ms | 1.97 ms | 2.26 ms | 94.60 ms |
| InternalCallBench::ArrayMap | 2.28 ms | 2.34 ms | 2.33 ms | 3.33 ms |
| MixedBench::Orders | 1.08 ms | 1.39 ms | 1.43 ms | 17.33 ms |
| CallBench::Function | 869 µs | 1.58 ms | 1.65 ms | 51.68 ms |
| CallBench::InstanceMethod | 994 µs | 1.65 ms | 1.82 ms | 50.75 ms |
| CallBench::StaticMethod | 1.08 ms | 1.78 ms | 1.83 ms | 53.18 ms |
| GeneratorCatchBench::Generator | 931 µs | 943 µs | 925 µs | 1.03 ms |
| GeneratorCatchBench::TryCatchNeverThrows | 574 µs | 898 µs | 965 µs | 25.82 ms |
| GeneratorCatchBench::TryCatchThrowsOften | 1.01 ms | 1.13 ms | 1.39 ms | 10.33 ms |
| NumericBench::Inline | 306 µs | 310 µs | 314 µs | 340 µs |
| NumericBench::CallPerPixel | 342 µs | 371 µs | 387 µs | 2.41 ms |
| NumericBench::CallPerIteration | 2.74 ms | 4.82 ms | 5.01 ms | 136.22 ms |

Overhead relative to no aop with the same JIT mode (median of the per-round ratios, [lowest, highest round]):

| Subject | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|
| InternalCallBench::StrlenAbs | +30% [+21%, +96%] | +52% [+38%, +87%] | +6252% [+5693%, +19540%] |
| InternalCallBench::ArrayMap | +3% [-7%, +15%] | +6% [-5%, +62%] | +47% [-3%, +105%] |
| MixedBench::Orders | +26% [+5%, +47%] | +31% [+19%, +89%] | +1517% [+1113%, +1830%] |
| CallBench::Function | +80% [+61%, +92%] | +92% [+87%, +201%] | +5847% [+4739%, +29928%] |
| CallBench::InstanceMethod | +65% [+58%, +76%] | +82% [+77%, +166%] | +4951% [+4838%, +7354%] |
| CallBench::StaticMethod | +59% [+56%, +104%] | +71% [+57%, +135%] | +4748% [+4013%, +22439%] |
| GeneratorCatchBench::Generator | +5% [-4%, +9%] | +1% [-18%, +57%] | +11% [-0%, +46%] |
| GeneratorCatchBench::TryCatchNeverThrows | +58% [+10%, +124%] | +66% [+9%, +177%] | +4344% [+2929%, +5611%] |
| GeneratorCatchBench::TryCatchThrowsOften | +14% [+5%, +65%] | +37% [+12%, +143%] | +917% [+522%, +1496%] |
| NumericBench::Inline | +1% [-1%, +8%] | +1% [-9%, +19%] | +6% [-9%, +39%] |
| NumericBench::CallPerPixel | +8% [+3%, +27%] | +14% [+4%, +44%] | +615% [+525%, +873%] |
| NumericBench::CallPerIteration | +80% [+61%, +95%] | +86% [+69%, +177%] | +4708% [+4086%, +6603%] |

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
