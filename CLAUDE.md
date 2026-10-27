# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`floriankraemer/php-aop-extension`: AOP (Spring/AspectJ model) woven inside the Zend engine, no proxies.
Two parts that must stay in sync:

- `ext/`: C extension `aop` (`aop_engine.c` decisions/dispatch, `aop_weave.c` woven backend, `aop_hook.c` hook backend, `aop_fields.c` field join points, `aop_catch.c` handlers, `aop_ast.c` compile-time work such as `#[DeclareParents]`).
- `src/`: PHP package, namespace `AOP\` (PSR-4): aspect metadata, pointcut language, advisors, runtime, Spring API.

The C side only registers `AOP\Engine` and `AOP\OriginalCall` (`ext/aop.stub.php`).
The PHP package must never declare them.

## Architecture

- The C engine asks a PHP **matcher** (`AOP\Runtime\NativeRuntime`) once per (class, function) which `Engine::ADVISE|TRACK|CALLER_SENSITIVE` flags apply, caches the answer, then calls the PHP **dispatcher** per advised call with an `OriginalCall` to run the real code.
- `Weaver` is the public entry point; `NativeRuntime` builds and caches the interceptor chain per join point (`AdvisorChainFactory`, `AdvisorOrdering`).
- Two backends, chosen by `aop.interception` (`woven` default, `hook`, `auto`): `woven` weaves a prologue at compile time and keeps the JIT but has no field/`initialization()` join points; `hook` overrides `zend_execute_ex`, supports everything, and turns the JIT off.
  Tests whose output depends on the backend pin it in `--INI--`.
- `docs/internals/contract.md` is the binding C ↔ PHP contract (matcher protocol, join point descriptor, caches).
  Change it together with both sides.
- Pointcut language: `src/Pointcut/Expression/` (Parser, PointcutExpressionCompiler) over `src/Pointcut/Pattern/`.
  Aspects come from attributes (`src/Attribute/`) or `src/Config/ConfigLoader.php` arrays.

## Commands

```bash
make ext                      # build ext/modules/aop.so (phpize/configure/make); rebuilds on source change
composer install              # or: make vendor/autoload.php
make test-ext                 # phpt suite in ext/tests, run twice (plain, then with opcache)
make test                     # phpunit unit suite, then e2e with the built aop.so
make check                    # php-cs-fixer + phpstan + phpt + phpunit
make test-examples            # run examples/NN-*.php against expected output
make mutation                 # Infection on 10 core src files (needs pcov/xdebug)
make coverage-php | coverage-c   # 85% line gate each (CI enforces)
make test-all                 # Docker: PHP 8.3/8.4/8.5 x cli/zts
```

Composer scripts: `composer test | stan | cs | cs-fix | check`.

Single tests:

```bash
vendor/bin/phpunit --testsuite unit --filter SomeTest        # unit: no extension, uses fake engine in tests/Support
php -d extension=$PWD/ext/modules/aop.so vendor/bin/phpunit --testsuite e2e --filter SomeTest
cd ext && NO_INTERACTION=1 TEST_PHP_EXECUTABLE=$(command -v php) make test TESTS=tests/007.phpt   # one phpt
```

Some phpt tests need opcache (`025`, `122`, `200`-`203`, `300`-`302`, `315`) and skip themselves without it.

## Conventions

- Docs live in `docs/` and are part of the change; update them with behavior changes (`docs/building.md` for tooling, `CHANGELOG.md` for releases).
- Release gate uses `scripts/semver-check.sh` (`make test-scripts`); see `docs/building.md`.
- `ext/` contains generated build output (`Makefile`, `configure`, `*.lo`, `aop_arginfo.h`, ...); do not hand-edit it.

## Commits and versioning

- Commit messages follow [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/): `type(scope)!: description`, `BREAKING CHANGE:` footer for breaking changes.
- Versions follow [Semantic Versioning 2.0.0](https://semver.org/).
  The commit type drives the bump: `fix` is a patch, `feat` is a minor, a breaking change is a major.
  Tags are `vMAJOR.MINOR.PATCH`, checked by `scripts/semver-check.sh`.
- PR titles use the same format.
- Contributors follow the same rules; see `CONTRIBUTING.md`.
