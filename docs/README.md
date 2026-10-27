# Documentation

These pages describe the `aop` extension and the PHP package `floriankraemer/php-aop-extension` (namespace `AOP\`).
The [README](../README.md) gives the overview.
The [getting started](getting-started.md) page has the requirements, the install steps and a quick start.

## Reading order

Read the pages top to bottom if you are new.
Jump to a page when you know what you need.

| # | Page | Read it when |
|---|---|---|
| 1 | [Getting started](getting-started.md) | You install the extension, or want a first run. |
| 2 | [php.ini settings](php-ini.md) | You choose the backend, inlining or autoload weaving. |
| 3 | [Concepts](concepts.md) | You want the vocabulary, the join point kinds, and how a call becomes advised. |
| 4 | [Pointcut expressions](pointcuts.md) | You write or debug an expression. |
| 5 | [Advice](advice.md) | You write advice, need the order, or want the instantiation models. |
| 6 | [Use cases](use-cases.md) | You want realistic, larger examples of logging, transactions, caching, authorization, resilience, tracing and legacy code. |
| 7 | [Weaver](weaver.md) | You add, remove, freeze or enable aspects and advisors. |
| 8 | [Spring API](spring-api.md) | You build pointcuts, advice and advisors from objects. |
| 9 | [Target sources](target-sources.md) | You swap, pool or pool-per-Fiber the target of a call. |
| 10 | [Configuration](configuration.md) | You describe aspects in an array, or use `#[Configurable]`. |
| 11 | [Spring and AspectJ mapping](spring-mapping.md) | You port an aspect, or look for the equivalent of a Spring class. |
| 12 | [Limitations](limitations.md) | You need to know what cannot be intercepted, the limits of fields, handlers, initialization and introductions, and the known issues. |
| 13 | [Building, testing and releasing](building.md) | You build the extension, run the tests, or publish a release. |
| 14 | [Benchmarks](benchmarks.md) | You want the overhead of the `hook` and `woven` backends, or you re-run the benchmark suite. |

The runnable examples are in [examples/](../examples/README.md).
Every code block in these pages is either copied from an example, or checked against one.
