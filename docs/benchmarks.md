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

Measured on 2026-10-27 with the extension at commit `d0da88e` (the benchmark suite does not change `ext/`).

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

- **Code without calls does not slow down.** `NumericBench::Inline` is a JIT-compiled loop with no calls in it: with `woven` it stays within +-2% of plain PHP in every state and JIT mode, and the JIT makes it 3.7 times faster than with the JIT off (1.53 ms with `disable`, 416 us with `tracing`, 381 us with `function`).
  So the woven prologue does not disturb JIT-compiled code, and the JIT stays effective.
  Differences of this size, in either direction, are noise.
- **Every call pays a fixed price.** For `CallBench::Function` (100,000 calls of a function that adds two integers) plain PHP needs 6.7 ns per call with `tracing`.
  `woven` adds 12 ns per call when the extension is loaded and nothing is registered (`loaded`), and 57 ns per call when a matcher is registered that never matches (`miss`).
  A call that reaches a dispatcher (`pass`, the original code runs through `OriginalCall`) adds about 1.1 us.
  With the JIT off: 18 ns plain, plus 12 ns (`woven`, `loaded`), 40 ns (`miss`) and 0.64 us (`pass`).
  The `hook` backend adds 3 ns (`loaded`), 31 ns (`miss`) and 0.56 us (`pass`) on top of 18 ns.
  In percent these look large for a tight call loop, because the function does almost nothing.
- **The cost shrinks with real work.** `MixedBench::Orders` (objects, arrays, strings) costs +18% to +22% with `woven`/`loaded` and about +100% with `miss`.
  `NumericBench::CallPerPixel`, where each call does up to 100 loop iterations, costs +10% (`loaded`) and +47% (`miss`) with `tracing`.
- **A registered matcher that never matches is much dearer than no matcher.** `miss` costs about five times as much per call as `loaded` with `woven` (57 ns against 12 ns).
  The decision is cached per function, so the extra cost is the path from the woven prologue into the engine for every call.
  This is the part a cheaper guard could remove (see the follow-up below).
- **`woven` with the JIT beats `hook` without it when the code does real work.** `NumericBench::CallPerPixel` with a matcher registered that never matches: 705 us with `woven`/`tracing` and 1.62 ms with `hook`.
  For nothing but tiny calls, `hook` is cheaper per call: `CallBench::Function`/`miss` takes 6.37 ms with `woven`/`tracing` and 4.91 ms with `hook`.
  The JIT is not always faster either: `MixedBench::Orders` runs 1.56 ms with the JIT off and 2.04 ms with `tracing` in this setup, because the run is short.
- **Internal functions** (`InternalCallBench::StrlenAbs`) pay the same kind of price per call (+18% to +42% for `loaded`, about +240% to +450% for `miss`) in both backends.
  Generators cost nothing extra for the generator call itself (`Generator` stays within +5%).
  `try`/`catch` adds nothing of its own: `TryCatchNeverThrows` pays the same 11 ns per call as `CallBench`.
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

#### `opcache.jit=disable`


Time for one run of the subject (median of the rounds):

| Subject | no aop | woven / loaded | woven / miss | woven / pass | hook / loaded | hook / miss | hook / pass |
|---|---:|---:|---:|---:|---:|---:|---:|
| InternalCallBench::StrlenAbs | 2.48 ms | 2.82 ms | 8.09 ms | 124.31 ms | 2.68 ms | 7.43 ms | 108.73 ms |
| InternalCallBench::ArrayMap | 2.65 ms | 2.58 ms | 2.83 ms | 5.16 ms | 3.04 ms | 3.22 ms | 4.22 ms |
| MixedBench::Orders | 1.56 ms | 1.96 ms | 2.98 ms | 22.71 ms | 1.81 ms | 3.31 ms | 51.07 ms |
| CallBench::Function | 1.78 ms | 2.96 ms | 5.80 ms | 65.60 ms | 2.12 ms | 4.91 ms | 57.86 ms |
| CallBench::InstanceMethod | 1.94 ms | 3.09 ms | 6.40 ms | 71.75 ms | 2.29 ms | 5.68 ms | 57.12 ms |
| CallBench::StaticMethod | 1.84 ms | 3.25 ms | 7.02 ms | 66.09 ms | 2.49 ms | 5.56 ms | 58.70 ms |
| GeneratorCatchBench::Generator | 1.15 ms | 1.16 ms | 1.48 ms | 1.29 ms | 1.26 ms | 1.30 ms | 1.72 ms |
| GeneratorCatchBench::TryCatchNeverThrows | 1.06 ms | 1.80 ms | 3.27 ms | 33.39 ms | 1.16 ms | 3.13 ms | 28.97 ms |
| GeneratorCatchBench::TryCatchThrowsOften | 1.18 ms | 1.72 ms | 2.06 ms | 14.47 ms | 1.37 ms | 2.16 ms | 11.52 ms |
| NumericBench::Inline | 1.53 ms | 1.76 ms | 1.46 ms | 1.49 ms | 1.56 ms | 1.55 ms | 1.48 ms |
| NumericBench::CallPerPixel | 1.53 ms | 1.88 ms | 1.79 ms | 4.56 ms | 1.62 ms | 1.62 ms | 4.62 ms |
| NumericBench::CallPerIteration | 6.81 ms | 10.10 ms | 18.30 ms | 169.54 ms | 7.59 ms | 13.89 ms | 152.47 ms |

Overhead relative to no aop with the same JIT mode (median of the per-round ratios, [lowest, highest round]):

| Subject | woven / loaded | woven / miss | woven / pass | hook / loaded | hook / miss | hook / pass |
|---|---:|---:|---:|---:|---:|---:|
| InternalCallBench::StrlenAbs | +18% [+3%, +61%] | +239% [+189%, +303%] | +5103% [+4320%, +6488%] | +11% [-28%, +104%] | +213% [+108%, +472%] | +4451% [+2942%, +9395%] |
| InternalCallBench::ArrayMap | -1% [-14%, +4%] | +7% [-2%, +13%] | +55% [+40%, +106%] | +7% [-42%, +106%] | +14% [-38%, +111%] | +68% [-23%, +191%] |
| MixedBench::Orders | +22% [+14%, +30%] | +91% [+68%, +105%] | +1417% [+1196%, +1603%] | +16% [-36%, +108%] | +120% [+14%, +318%] | +3333% [+1717%, +6570%] |
| CallBench::Function | +69% [+57%, +79%] | +221% [+192%, +250%] | +3742% [+3403%, +4157%] | +22% [-31%, +130%] | +192% [+58%, +390%] | +3169% [+1775%, +5788%] |
| CallBench::InstanceMethod | +64% [+51%, +70%] | +233% [+217%, +269%] | +3634% [+3097%, +9687%] | +29% [-34%, +111%] | +210% [+68%, +436%] | +2955% [+1593%, +5737%] |
| CallBench::StaticMethod | +71% [+51%, +89%] | +240% [+200%, +309%] | +3427% [+3026%, +3807%] | +26% [-30%, +134%] | +207% [+55%, +516%] | +3108% [+1542%, +6588%] |
| GeneratorCatchBench::Generator | +1% [-7%, +8%] | +1% [-6%, +29%] | +13% [+0%, +36%] | +7% [-42%, +104%] | +8% [-39%, +86%] | +9% [+3%, +3631%] |
| GeneratorCatchBench::TryCatchNeverThrows | +64% [+40%, +82%] | +218% [+202%, +278%] | +3289% [+2824%, +4902%] | +15% [-30%, +99%] | +202% [+90%, +451%] | +2707% [+1659%, +5610%] |
| GeneratorCatchBench::TryCatchThrowsOften | +17% [+11%, +46%] | +80% [+69%, +98%] | +993% [+917%, +1212%] | +16% [-38%, +106%] | +86% [-3%, +255%] | +900% [+418%, +1837%] |
| NumericBench::Inline | -2% [-8%, +26%] | -0% [-8%, +7%] | -1% [-3%, +8%] | -1% [-25%, +44%] | -1% [-28%, +44%] | -2% [-29%, +71%] |
| NumericBench::CallPerPixel | +5% [-1%, +28%] | +16% [+10%, +23%] | +185% [+161%, +300%] | +3% [-35%, +66%] | +10% [-29%, +72%] | +182% [+73%, +354%] |
| NumericBench::CallPerIteration | +52% [+35%, +60%] | +163% [+131%, +223%] | +2389% [+2283%, +4876%] | +15% [-18%, +76%] | +128% [+49%, +266%] | +2283% [+1539%, +4577%] |

#### `opcache.jit=tracing`


Time for one run of the subject (median of the rounds):

| Subject | no aop | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|---:|
| InternalCallBench::StrlenAbs | 1.21 ms | 2.70 ms | 11.12 ms | 205.47 ms |
| InternalCallBench::ArrayMap | 4.39 ms | 4.18 ms | 4.60 ms | 6.63 ms |
| MixedBench::Orders | 2.04 ms | 2.42 ms | 4.31 ms | 37.85 ms |
| CallBench::Function | 671 µs | 1.84 ms | 6.37 ms | 111.72 ms |
| CallBench::InstanceMethod | 859 µs | 1.97 ms | 7.52 ms | 110.11 ms |
| CallBench::StaticMethod | 1.24 ms | 2.25 ms | 7.48 ms | 102.36 ms |
| GeneratorCatchBench::Generator | 1.66 ms | 1.72 ms | 1.76 ms | 2.11 ms |
| GeneratorCatchBench::TryCatchNeverThrows | 400 µs | 964 µs | 3.63 ms | 52.84 ms |
| GeneratorCatchBench::TryCatchThrowsOften | 1.71 ms | 2.13 ms | 3.54 ms | 21.54 ms |
| NumericBench::Inline | 416 µs | 420 µs | 414 µs | 420 µs |
| NumericBench::CallPerPixel | 471 µs | 520 µs | 705 µs | 4.68 ms |
| NumericBench::CallPerIteration | 2.56 ms | 5.26 ms | 17.42 ms | 268.46 ms |

Overhead relative to no aop with the same JIT mode (median of the per-round ratios, [lowest, highest round]):

| Subject | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|
| InternalCallBench::StrlenAbs | +42% [+26%, +162%] | +452% [+377%, +963%] | +10986% [+9024%, +19101%] |
| InternalCallBench::ArrayMap | -2% [-8%, +11%] | -2% [-26%, +8%] | +51% [+31%, +137%] |
| MixedBench::Orders | +18% [+5%, +36%] | +101% [+76%, +130%] | +1668% [+1436%, +9735%] |
| CallBench::Function | +157% [+133%, +193%] | +860% [+655%, +930%] | +15464% [+11923%, +40658%] |
| CallBench::InstanceMethod | +118% [+87%, +170%] | +687% [+633%, +880%] | +11931% [+10306%, +19996%] |
| CallBench::StaticMethod | +86% [+76%, +103%] | +490% [+445%, +561%] | +7972% [+7235%, +20021%] |
| GeneratorCatchBench::Generator | +5% [-10%, +13%] | +5% [-12%, +10%] | +15% [+5%, +102%] |
| GeneratorCatchBench::TryCatchNeverThrows | +149% [+128%, +162%] | +838% [+743%, +948%] | +12938% [+11898%, +25944%] |
| GeneratorCatchBench::TryCatchThrowsOften | +23% [+17%, +33%] | +105% [+84%, +264%] | +1147% [+1018%, +2435%] |
| NumericBench::Inline | +2% [-3%, +11%] | +1% [-6%, +12%] | +1% [-8%, +27%] |
| NumericBench::CallPerPixel | +10% [+5%, +15%] | +47% [+32%, +103%] | +861% [+553%, +1233%] |
| NumericBench::CallPerIteration | +98% [+67%, +108%] | +558% [+400%, +817%] | +10059% [+8016%, +15904%] |

#### `opcache.jit=function`


Time for one run of the subject (median of the rounds):

| Subject | no aop | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|---:|
| InternalCallBench::StrlenAbs | 2.68 ms | 4.00 ms | 12.89 ms | 209.30 ms |
| InternalCallBench::ArrayMap | 4.51 ms | 4.80 ms | 4.74 ms | 5.42 ms |
| MixedBench::Orders | 2.15 ms | 2.67 ms | 4.43 ms | 27.32 ms |
| CallBench::Function | 1.55 ms | 2.84 ms | 7.33 ms | 67.02 ms |
| CallBench::InstanceMethod | 1.83 ms | 2.87 ms | 8.72 ms | 62.62 ms |
| CallBench::StaticMethod | 1.78 ms | 3.51 ms | 9.19 ms | 105.38 ms |
| GeneratorCatchBench::Generator | 1.76 ms | 1.79 ms | 1.80 ms | 1.26 ms |
| GeneratorCatchBench::TryCatchNeverThrows | 1.06 ms | 1.59 ms | 4.54 ms | 39.25 ms |
| GeneratorCatchBench::TryCatchThrowsOften | 2.06 ms | 2.03 ms | 3.42 ms | 13.96 ms |
| NumericBench::Inline | 381 µs | 359 µs | 379 µs | 379 µs |
| NumericBench::CallPerPixel | 444 µs | 514 µs | 727 µs | 3.70 ms |
| NumericBench::CallPerIteration | 4.51 ms | 8.10 ms | 20.27 ms | 168.29 ms |

Overhead relative to no aop with the same JIT mode (median of the per-round ratios, [lowest, highest round]):

| Subject | woven / loaded | woven / miss | woven / pass |
|---|---:|---:|---:|
| InternalCallBench::StrlenAbs | +42% [+29%, +56%] | +360% [+134%, +432%] | +7706% [+3757%, +16711%] |
| InternalCallBench::ArrayMap | +6% [-7%, +27%] | +10% [-49%, +33%] | +44% [-26%, +70%] |
| MixedBench::Orders | +21% [+9%, +42%] | +105% [+13%, +123%] | +1543% [+901%, +1837%] |
| CallBench::Function | +84% [+74%, +103%] | +366% [+175%, +454%] | +6512% [+3349%, +7900%] |
| CallBench::InstanceMethod | +64% [-3%, +78%] | +354% [+142%, +435%] | +5611% [+3255%, +5927%] |
| CallBench::StaticMethod | +110% [+26%, +120%] | +377% [+170%, +448%] | +5512% [+2973%, +20442%] |
| GeneratorCatchBench::Generator | +2% [-49%, +6%] | +3% [-50%, +9%] | +13% [-42%, +29%] |
| GeneratorCatchBench::TryCatchNeverThrows | +62% [-11%, +75%] | +309% [+108%, +350%] | +4649% [+2591%, +22283%] |
| GeneratorCatchBench::TryCatchThrowsOften | +10% [-39%, +21%] | +76% [-11%, +87%] | +914% [+467%, +1289%] |
| NumericBench::Inline | +1% [-17%, +8%] | -1% [-13%, +2%] | +1% [-15%, +39%] |
| NumericBench::CallPerPixel | +14% [-12%, +24%] | +50% [+2%, +64%] | +659% [+505%, +1057%] |
| NumericBench::CallPerIteration | +79% [+14%, +93%] | +345% [+141%, +360%] | +5266% [+3120%, +6835%] |


### Overhead by PHP version

Median overhead of the Docker runs (PHP 8.3.36, 8.4.27, 8.5.12), relative to plain PHP with the same JIT mode.
The `hook` columns are relative to plain PHP with the JIT off, the `woven` columns to plain PHP with `opcache.jit=tracing`.

| Subject | PHP | woven + JIT tracing, loaded | woven + JIT tracing, miss | hook, loaded | hook, miss |
|---|---|---:|---:|---:|---:|
| `CallBench::Function` | 8.3 | +132% | +651% | +19% | +162% |
| `CallBench::Function` | 8.4 | +105% | +625% | +33% | +187% |
| `CallBench::Function` | 8.5 | +120% | +650% | +35% | +166% |
| `MixedBench::Orders` | 8.3 | +21% | +110% | +12% | +93% |
| `MixedBench::Orders` | 8.4 | +23% | +126% | +15% | +107% |
| `MixedBench::Orders` | 8.5 | +28% | +143% | +6% | +108% |
| `NumericBench::Inline` | 8.3 | +1% | +1% | -1% | +1% |
| `NumericBench::Inline` | 8.4 | -2% | +1% | +3% | +1% |
| `NumericBench::Inline` | 8.5 | -2% | -2% | -6% | -9% |
| `NumericBench::CallPerPixel` | 8.3 | +7% | +33% | -6% | +5% |
| `NumericBench::CallPerPixel` | 8.4 | +10% | +43% | +13% | +26% |
| `NumericBench::CallPerPixel` | 8.5 | +9% | +44% | -5% | +2% |

## Follow-up

The `miss` price (about 57 ns per call with `woven`, against 12 ns without a registered matcher) is the first candidate for a cheaper guard, for example a flag that the prologue checks before it calls `AOP\Internal\intercept()`.
Measure such a change with this suite: `make bench CELLS="none-tracing-none woven-tracing-loaded woven-tracing-miss" FILTER=CallBench`.
