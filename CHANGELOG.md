# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

### Added

- Native aspect-oriented programming extension skeleton (`ext/`) and PHP package (`src/`).
- Build and release tooling: `make ext|test-ext|test|check|test-all|dist`, Docker matrix
  builds for PHP 8.3/8.4/8.5 (NTS and ZTS, glibc and musl), CI and tag-driven release
  workflows with PIE-compatible Windows assets and `SHA256SUMS`.
