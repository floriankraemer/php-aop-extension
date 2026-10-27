# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Coverage quality gate: `make coverage-php` and `make coverage-c`, and a CI job that fails when line coverage of `src/` or `ext/*.c` drops below 85%.
- Native engine (`ext/`) that weaves advice into the Zend engine, without proxies or generated classes.
  Advice reaches private, protected, static and final methods, final classes, self-invocation, constructors, functions and internal functions.
- Pointcut language: `execution()`, `call()`, `within()`, `withincode()`, `this()`, `target()`, `args()`, the `@`
  designators, `cflow()`, `cflowbelow()`, `if()`, `initialization()`, `adviceexecution()`, `service()` and named pointcuts (`#[Pointcut]`).
- Advice: before, after, after-returning, after-throwing and around advice, with `#[Order]`, `Ordered`,
  `#[DeclarePrecedence]`, `#[DeclareWarning]` and `#[DeclareError]`.
- Weaver: aspects and advisors added and removed at run time, include and exclude, per-object advice, services,
  `freeze()`, and the instantiation models singleton, `perthis`, `pertarget`, `percflow`, `percflowbelow` and `pertypewithin`.
- Spring AOP API: programmatic pointcuts, advice interfaces, advisors, advisor adapters, and target sources
  (hot-swappable, pooling, prototype and fiber-local).
- Configuration: aspects and advisors described as an array in the shape of Spring's XML schema, and `#[Configurable]`
  for objects created with `new`.
- Fields: `get()` and `set()` pointcuts on property reads and writes, including PHP 8.4 property hooks and lazy objects (needs `aop.interception=hook`).
- Exception handlers: `handler()` pointcuts on `catch` clauses.
- Constructor-less classes: `initialization()` matches their creation, and `#[Configurable]` configures them (needs `aop.interception=hook`).
- Introductions: `#[DeclareParents]` and the `declare-parents` configuration key make a class implement an interface,
  with a default implementation, at compile time; `Weaver::declareParents()` is the programmatic form.
- php.ini setting `aop.interception` (`woven`, `hook` or `auto`; php.ini only): the interception backend.
  The new `woven` backend weaves a prologue into every named function and method at compile time and a call into every `catch` block.
  It installs no `zend_execute_ex` override and no user opcode handlers, so the opcache JIT stays on.
  `phpinfo()` shows the resolved backend in the row "Interception backend".
- php.ini settings `aop.enable`, `aop.disable_inlining` (keeps opcache from inlining advisable functions) and
  `aop.weave_autoload` (calls made while a class is autoloaded are not woven by default).
- PHP 8.3, 8.4 and 8.5, NTS and ZTS.
- Benchmark suite (`bench/`, phpbench): `make bench` and `make bench-docker` measure the overhead of the `hook` and `woven` backends
  with the opcache JIT off, `tracing` and `function`; the method and the measured results are in `docs/benchmarks.md`.
- Build and release tooling: `make ext|test-ext|test|check|test-all|dist`, Docker matrix builds for PHP 8.3/8.4/8.5
  (NTS and ZTS, glibc and musl), CI and tag-driven release workflows with PIE-compatible Windows assets and `SHA256SUMS`.
- Examples 31 to 38 (audit and logging, transactions, caching, authorization, retry and circuit breaker, tracing and metrics, legacy code and architecture rules, a combined order service) and the page [docs/use-cases.md](docs/use-cases.md).

### Changed

- **Breaking:** the default interception backend is `woven` instead of the JIT-disabling hook.
  The opcache JIT now runs with the extension.
  The woven backend has no field join points and no `initialization()`: `get()`, `set()` and every `initialization()` pointcut are rejected by `Weaver::enable()` with an `AspectDefinitionException` that names the advisor and `aop.interception`.
  Migration: set `aop.interception=hook` in php.ini to keep field and `initialization()` advice (the JIT stays off then), or `aop.interception=auto` to get `hook` only where the JIT is not configured.
- Under `woven`, advice sees arguments after parameter coercion, advice on a generator function runs at the first resume, and backtraces contain `AOP\Internal\intercept` and `AOP\Internal\caught` frames.
  See [docs/limitations.md](docs/limitations.md#woven-differences).
- With `aop.interception=hook`, the startup notice for a configured JIT now reads "aop: the opcache JIT is disabled because aop.interception=hook overrides zend_execute_ex(); set aop.interception=woven to keep the JIT".
- `Weaver::enable()` rejects `@annotation(Attr)` when `Attr` can only target properties.
  `true`, `within()`, `this()`, `target()` and `configurable()` are accepted under `woven`; a constructor-less `#[Configurable]` class raises an `AspectDefinitionException` when the engine first asks about one of its methods.
  Without the extension or with `aop.enable=0`, `enable()` keeps reporting that instead of the hook hint.
- The examples 04, 27 and 29 need `aop.interception=hook`.

### Performance

- Decision fast path: the guard of the woven prologue and of the hook backend reads a per-request, direct-mapped cache of matcher flags before it builds a join point.
  With a matcher registered that never matches, `CallBench::Function` drops from 3.33 ms to 1.12 ms per run with `woven` and `opcache.jit=tracing` (3.5 times to 1.2 times the cost of `loaded`), and from 5.08 ms to 2.98 ms with the JIT off.
  See [docs/benchmarks.md](docs/benchmarks.md#decision-fast-path-issue-8).
  (#8)

### Fixed

- Spring `ControlFlowPointcut` advises method and function executions only (advice no longer runs twice per call) and
  is control-flow aware like `cflow()`, so it works without another advisor on the entry method.
- `execution()`, `call()`, `withincode()` and `initialization()` on `__construct` match the constructor declared by the
  named type (`Base+` for subclasses), not the own constructors of its subclasses. Other methods are unchanged.
- Method patterns accept `public`, `protected`, `private`, `static`, `final`, `abstract` and `!` negation; field
  patterns accept `!`. A plain function counts as public, non-static and non-final (`execution(public * f(..))` now matches it).
  Unknown modifiers raise a clear syntax error.
- `RegexpMethodPointcut` accepts fully delimited PCRE (`/Catalog::save/i`) next to bare whole-name patterns, and throws
  `\InvalidArgumentException` (was `PointcutSyntaxException`) for a pattern that does not compile.
- `BindingException` for `||` branches binding different names now names the expression.
- `AdvisorAdapterInterface::getInterceptor()` may return a Spring-style `MethodInterceptorInterface` (wrapped in
  `InterceptorBridge`); a wrong return raises a `LogicException` naming the adapter.
- `ConfigurableInterceptor` uses the join point's `isOutermost()` instead of counting running constructors.

