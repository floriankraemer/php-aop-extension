# Documentation

These pages describe the `aop` extension and the PHP package `floriankraemer/php-aop-extension` (namespace `AOP\`).
The [README](../README.md) gives the overview and the install steps.

## Reading order

Read the pages top to bottom if you are new.
Jump to a page when you know what you need.

| # | Page | Read it when |
|---|---|---|
| 1 | [Concepts](concepts.md) | You want the vocabulary, the join point kinds, and how a call becomes advised. |
| 2 | [Pointcut expressions](pointcuts.md) | You write or debug an expression. |
| 3 | [Advice](advice.md) | You write advice, need the order, or want the instantiation models. |
| 4 | [Use cases](use-cases.md) | You want realistic, larger examples of logging, transactions, caching, authorization, resilience, tracing and legacy code. |
| 5 | [Weaver](weaver.md) | You add, remove, freeze or enable aspects and advisors. |
| 6 | [Spring API](spring-api.md) | You build pointcuts, advice and advisors from objects. |
| 7 | [Target sources](target-sources.md) | You swap, pool or pool-per-Fiber the target of a call. |
| 8 | [Configuration](configuration.md) | You describe aspects in an array, or use `#[Configurable]`. |
| 9 | [Spring and AspectJ mapping](spring-mapping.md) | You port an aspect, or look for the equivalent of a Spring class. |
| 10 | [Limitations](limitations.md) | You need to know what cannot be intercepted, the limits of fields, handlers, initialization and introductions, and the known issues. |
| 11 | [Building, testing and releasing](building.md) | You build the extension, run the tests, or publish a release. |

The runnable examples are in [examples/](../examples/README.md).
Every code block in these pages is either copied from an example, or checked against one.
