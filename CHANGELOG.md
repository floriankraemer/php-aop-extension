# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
- Fields: `get()` and `set()` pointcuts on property reads and writes. Checked on PHP 8.3; PHP 8.4 and 8.5 support is in progress.
- Exception handlers: `handler()` pointcuts on `catch` clauses.
- Constructor-less classes: `initialization()` matches their creation, and `#[Configurable]` configures them.
- Introductions: `#[DeclareParents]` and the `declare-parents` configuration key make a class implement an interface,
  with a default implementation, at compile time.
- PHP 8.3, 8.4 and 8.5, NTS and ZTS.
- Build and release tooling: `make ext|test-ext|test|check|test-all|dist`, Docker matrix builds for PHP 8.3/8.4/8.5
  (NTS and ZTS, glibc and musl), CI and tag-driven release workflows with PIE-compatible Windows assets and `SHA256SUMS`.

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

