# Building, testing and releasing

This page is for people who build the extension, change it, or publish a release.
Users who only install it should read the install section of the [README](../README.md).

## Local build

You need:

- PHP 8.3 or later, with its development files (`php-config`, `phpize`).
- A C toolchain: `gcc` or `clang`, `make`, `autoconf`.
- Composer, for the PHP package and the development tools.
- Docker, only for the matrix targets.

```bash
make ext                 # builds ext/modules/aop.so with phpize, configure and make
composer install         # or: make vendor/autoload.php
php -d extension=$PWD/ext/modules/aop.so -r 'var_dump(extension_loaded("aop"));'
```

`make ext` rebuilds when a C source, a header or `config.m4` changes.
It runs `phpize --clean` first, so the build directory stays consistent.

## Configuration (php.ini)

The extension has four INI settings.
The README has the same table for users.

| Setting | Default | Changeable | Meaning |
|---|---|---|---|
| `aop.enable` | `1` | php.ini or `-d` only (`PHP_INI_SYSTEM`) | `0` installs nothing: no backend, no zend_extension, no `zend_execute_ex` override, and the JIT runs. `Weaver::enable()` throws a `RuntimeException`. `phpinfo()` shows "Interception hooks => inactive (aop.enable=0)" and "Interception backend => none (aop.enable=0)". |
| `aop.interception` | `woven` | php.ini or `-d` only (`PHP_INI_SYSTEM`) | The interception backend: `woven`, `hook` or `auto` (case-insensitive). `woven` weaves a prologue into every function at compile time and keeps the opcache JIT, but has no field or `initialization()` join points. `hook` overrides `zend_execute_ex` and installs user opcode handlers: all join points, and opcache turns the JIT off. `auto` resolves to `hook`, unless the JIT is configured for the SAPI (opcache enabled, `opcache.jit` not `disable`, `opcache.jit_buffer_size` > 0), then to `woven`. An invalid value raises `Warning: aop.interception: invalid value "<value>", expected woven, hook or auto` and keeps `woven`. With `aop.enable=0` the setting has no effect. |
| `aop.disable_inlining` | `1` | php.ini or `-d` only (`PHP_INI_SYSTEM`) | With `aop.enable=1`, `1` makes opcache's optimizer leave user functions and methods alone, so opcache never inlines them and their advice runs. `0` allows inlining: a function that only returns a constant can then be inlined at compile time, and its calls may never reach the engine. |
| `aop.weave_autoload` | `0` | any time (`PHP_INI_ALL`), also `ini_set()` | `1` weaves the calls made while PHP autoloads a class. |

`phpinfo()` shows the state in these rows of the aop section:

| Row | Values |
|---|---|
| Interception hooks | `active`, or `inactive (aop.enable=0)` |
| Interception backend | `woven`, `hook`, `woven (auto: opcache JIT configured)`, `hook (auto: opcache JIT not configured)`, or `none (aop.enable=0)` |
| opcache JIT | `compatible (aop.interception=woven)`; with `hook`: `disabled: aop.interception=hook overrides zend_execute_ex() (use aop.interception=woven to keep the JIT)` when the JIT is configured, otherwise `not configured; it cannot run while aop.interception=hook overrides zend_execute_ex()`; with `aop.enable=0`: `not affected by aop (aop.enable=0)` |
| opcache function inlining | `prevented for user functions (aop.disable_inlining=1)`, `allowed (aop.disable_inlining=0): calls to functions that return a constant may not be intercepted`, or `not affected by aop (aop.enable=0)` |
| Field interception | `available` (`hook`), `not available (aop.interception=woven)`, or `not available` (`aop.enable=0`) |

With `aop.interception=hook` and a configured JIT, startup prints this notice, followed by PHP's own JIT warning:

```text
Notice: aop: the opcache JIT is disabled because aop.interception=hook overrides zend_execute_ex(); set aop.interception=woven to keep the JIT
```

The backends are described in [limitations](limitations.md#backends).

## Make targets

| Target | What it does |
|---|---|
| `make help` | Lists the targets and the variables. |
| `make ext` | Builds the extension natively. |
| `make test-ext` | Runs the phpt suite in `ext/tests` against the built extension. |
| `make test` | Runs the unit suite, then the end-to-end suite with the extension. |
| `make check` | Runs php-cs-fixer, phpstan, the phpt suite and phpunit. |
| `make test-all` | In Docker: builds and tests on PHP 8.3, 8.4 and 8.5, NTS and ZTS, Debian. |
| `make dist` | In Docker: builds, tests and packages every cell into `dist/`. |
| `make bench` | Runs the [benchmark matrix](benchmarks.md) natively. Takes `PHP`, `CPU`, `CELLS`, `FILTER` and `ITERATIONS`. |
| `make bench-docker` | In Docker: runs the benchmark matrix on PHP 8.3, 8.4 and 8.5 (NTS, Debian) and writes the results to `bench/results/`. |
| `make binfmt` | Installs QEMU emulators, so `PLATFORMS=linux/arm64` works on an x86_64 host. |
| `make test-scripts` | Checks the semver gate used by the release workflow. |
| `make build`, `up`, `down`, `shell`, `docker-clean` | The development container. |
| `make clean` | Removes build output, `dist/` and caches. |

The phpunit suites are:

- `unit`, in `tests/Unit`. It runs without the extension, against the fake engine in `tests/Support`.
- `e2e`, in `tests/E2E`. It needs the extension and skips itself without it.

The phpt suite is in `ext/tests`.
`make test-ext` runs it twice: once as it is, and once with opcache enabled for the CLI (`scripts/opcache-args.sh`), because the opcache optimizer changes what reaches the engine.
The tests that need opcache (`025`, `122`, `200` to `203`, `300`, `301`, `302`, `315`) skip themselves when it is not loaded.
The phpt files `300` to `315` cover the woven backend.
Tests whose expected output depends on the backend are pinned with `aop.interception=hook` in their `--INI--` section: the field and initialization tests (`100` to `109`, `210` to `212`), `111`, `122`, `200`, `202`, `205`, and the originals of the argument, exception and fiber tests (`007`, `008`, `022`, which have woven counterparts `310`, `311`, `312`).
The [contract](internals/contract.md#woven-backend) has the details.

## Benchmarks

`make bench` runs the benchmark matrix natively and `make bench-docker` runs it in the Docker images for PHP 8.3, 8.4 and 8.5.
Each cell is one backend (`hook`, `woven` or no extension), one opcache JIT mode (`disable`, `tracing`, `function`) and one state (nothing registered, a matcher that never matches, a pass-through dispatcher).
The JIT state is asserted before every subject.
With the JIT on, `woven` adds about 12 ns per call when nothing is registered and about 57 ns when a matcher is registered that never matches; code without calls is unaffected.
[benchmarks.md](benchmarks.md) has the method, the variables and the full results.

## Variables

| Variable | Default | Meaning |
|---|---|---|
| `VERSION` | `git describe --tags --always` | Version in the package names. A leading `v` is removed. |
| `PHP_VERSIONS` | `8.3 8.4 8.5` | Versions to build. |
| `TS_MODES` | `nts zts` | Thread safety modes. `nts` uses `php:X-cli`, `zts` uses `php:X-zts`. |
| `LIBCS` | `glibc musl` | `glibc` uses `php:X-bookworm`, `musl` uses `php:X-alpine`. |
| `PLATFORMS` | the host | Docker platforms, such as `linux/amd64,linux/arm64`. Foreign platforms need `make binfmt`. |
| `DIST_DIR` | `dist` | Output directory. |
| `DRY_RUN=1` | off | Prints the Docker commands instead of running them. |
| `KEEP_IMAGES=1`, `PRUNE_IMAGES=1` | off | Control whether pulled images are removed after each cell. |
| `DEBIAN_SUITE` | `bookworm` | The Debian image suffix. |
| `DOCKER_ARGS` | empty | Extra arguments for `docker run`, such as `--network host`. |

Example:

```bash
make dist PHP_VERSIONS="8.4" TS_MODES="zts" LIBCS="glibc" VERSION=0.1.0
```

## The Docker matrix

`scripts/docker-matrix.sh dist` builds each cell in its own container.
A cell is one PHP version, one thread safety mode and one C library.
The phpt suite runs inside the container before the cell is packaged.
`make test-all` runs the same build with the phpunit suites, on glibc only.

The development container (`Dockerfile`, `docker-compose.yaml`) is PHP 8.3, for working on the code.

## Packages from `make dist`

Each cell writes one archive:

```text
dist/aop-<version>-php<X.Y>-<nts|zts>-linux-<glibc|musl>-<arch>.tar.gz
```

The archive contains:

| File | Content |
|---|---|
| `aop.so` | The extension. |
| `aop.ini` | `extension=aop`, for `php -c` or `conf.d`. |
| `LICENSE` | The license. |
| `README.md` | This package's readme, when present. |

`dist/SHA256SUMS` lists the checksums of all archives.

## Release process

Releases are made from semver tags.

1. Move the `Unreleased` entries of `CHANGELOG.md` into a section headed `## [X.Y.Z]`.
   The release notes come from that section, and the workflow falls back to generated notes when it is missing.
2. Make sure the CI workflow is green on the commit you want to release.
3. If the interception code paths changed since the last release (`ext/`, the woven prologue, the dispatch path), re-run the benchmarks (`make bench-docker`) and update the tables in [benchmarks.md](benchmarks.md).
4. Create and push the tag: `git tag vX.Y.Z` and `git push origin vX.Y.Z`.

The release workflow (`.github/workflows/release.yml`) runs on tags that look like versions.
Its first job checks the tag against the semver grammar.

| Tag | Result |
|---|---|
| `v1.2.3`, `1.2.3` | Accepted, a normal release. |
| `1.2.3-rc.1+build.5` | Accepted, a prerelease. |
| `v1.2`, `v01.2.3`, `v1.2.3.4`, `1.2.3-` | Rejected. The workflow fails before it builds anything. |

`scripts/semver-check.sh` runs the same check locally.

The build jobs produce:

| Platform | Cells | Archive names |
|---|---|---|
| Linux glibc and musl, x86_64 and arm64 | PHP 8.3, 8.4, 8.5 × NTS, ZTS | `aop-<version>-php<X.Y>-<nts\|zts>-linux-<glibc\|musl>-<arch>.tar.gz` |
| macOS arm64 (`macos-14`) and x86_64 (`macos-15-intel`) | PHP 8.3, 8.4, 8.5 × NTS, TS | `aop-<version>-php<X.Y>-<nts\|ts>-macos-<arch>.tar.gz` |
| Windows x64 and x86 | PHP 8.3, 8.4, 8.5 × NTS, TS | `php_aop-<version>-<X.Y>-<nts\|ts>-<vs16\|vs17>-<arch>.zip` |

Windows archives use the PIE naming convention.
PHP 8.3 builds use `vs16`, and PHP 8.4 and 8.5 use `vs17`.

A last job collects every archive, writes `SHA256SUMS`, and creates the GitHub Release.
A tag with a prerelease suffix is marked as a prerelease.

## Packaging for PIE

`composer.json` has `"type": "php-ext"` and the `php-ext` section:

```json
"php-ext": {
    "extension-name": "aop",
    "build-path": "ext",
    "configure-options": []
}
```

These fields tell PIE the extension name and where the C source is.

## Verifying a release locally

```bash
make test-scripts        # the semver gate
make dist DRY_RUN=1      # the commands that make dist would run
make dist PHP_VERSIONS=8.3 TS_MODES=nts LIBCS=glibc   # one real cell, needs Docker
```
