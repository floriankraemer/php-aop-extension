# Examples

One runnable script per feature.
Each script loads `vendor/autoload.php` through its own path, so run it from anywhere.
The expected output is in a comment at the end of each file.

## Running them

Install the PHP package once, then run a script with the extension loaded:

```bash
composer install
php -d extension=/path/to/php-aop-extension/ext/modules/aop.so examples/01-quickstart.php
```

Run them all:

```bash
for f in examples/*.php; do php -d extension=/path/to/aop.so "$f" >/dev/null || echo "FAILED $f"; done
```

Without the extension, a script stops at `enable()` with an `AspectDefinitionException` that names `ext-aop`.

## Scripts

| Script | Shows | Docs |
|---|---|---|
| [01-quickstart.php](01-quickstart.php) | Quick start: an aspect with before and around advice, a private method, enable and disable | [concepts](../docs/concepts.md) |
| [02-visibility.php](02-visibility.php) | Private, protected, static and final methods are advised; `execution()` with a wildcard name | [pointcuts](../docs/pointcuts.md#method-patterns) |
| [03-final-classes-and-self-invocation.php](03-final-classes-and-self-invocation.php) | Final classes and self-invocation | [concepts](../docs/concepts.md) |
| [04-constructors.php](04-constructors.php) | Constructors: `initialization()` for the outermost constructor | [pointcuts](../docs/pointcuts.md#constructors) |
| [05-functions-and-internal-functions.php](05-functions-and-internal-functions.php) | Plain functions and internal functions (`str_repeat`) | [pointcuts](../docs/pointcuts.md#function-patterns) |
| [06-call-vs-execution.php](06-call-vs-execution.php) | `call()` at the call site, `execution()` inside the callee, `withincode()` | [concepts](../docs/concepts.md#call-versus-execution) |
| [07-cflow.php](07-cflow.php) | `cflow()` and `cflowbelow()` | [pointcuts](../docs/pointcuts.md#cflow-and-cflowbelow) |
| [08-if-designator.php](08-if-designator.php) | `if()` with a runtime condition | [pointcuts](../docs/pointcuts.md#if) |
| [09-pointcut-designators.php](09-pointcut-designators.php) | `args()`, `target()`, `@annotation()`, `@within()`, bindings, `public int` patterns | [pointcuts](../docs/pointcuts.md#binding) |
| [10-advice-types.php](10-advice-types.php) | All five advice types and their order | [advice](../docs/advice.md#the-five-advice-types) |
| [11-ordering.php](11-ordering.php) | `#[Order]`, `Ordered` and `#[DeclarePrecedence]` | [advice](../docs/advice.md#order) |
| [12-declare-warning-and-error.php](12-declare-warning-and-error.php) | `#[DeclareWarning]` and `#[DeclareError]` | [advice](../docs/advice.md#declare-warning-and-error) |
| [13-perthis-pertarget.php](13-perthis-pertarget.php) | `perthis` aspects, one per object | [advice](../docs/advice.md#instantiation-models) |
| [14-percflow-pertypewithin.php](14-percflow-pertypewithin.php) | `percflow`, `percflowbelow` and `pertypewithin` | [advice](../docs/advice.md#instantiation-models) |
| [15-weaver-api.php](15-weaver-api.php) | Weaver management: add and remove aspects and advisors, include and exclude, disable and freeze | [weaver](../docs/weaver.md) |
| [16-advised-instance.php](16-advised-instance.php) | Per-object advice with `Weaver::advised()` | [weaver](../docs/weaver.md#per-object-advice) |
| [17-services.php](17-services.php) | Services: `service()` for objects and classes | [weaver](../docs/weaver.md#services) |
| [18-spring-pointcuts.php](18-spring-pointcuts.php) | Spring API pointcuts: name, regexp, composable, static and dynamic matchers | [spring-api](../docs/spring-api.md#pointcuts) |
| [19-spring-advice-types.php](19-spring-advice-types.php) | Spring API advice interfaces and `ThrowsAdviceInterface` | [spring-api](../docs/spring-api.md#advice-interfaces) |
| [20-target-sources.php](20-target-sources.php) | Target sources: hot-swappable, prototype, pooling and fiber-local | [target-sources](../docs/target-sources.md) |
| [21-configuration.php](21-configuration.php) | Configuration array for aspects without attributes | [configuration](../docs/configuration.md) |
| [22-configurable.php](22-configurable.php) | `#[Configurable]` for objects created with `new` | [configuration](../docs/configuration.md#configurable) |
| [23-aop-context.php](23-aop-context.php) | `AopContext`: the current join point and object | [concepts](../docs/concepts.md#where-advice-gets-its-values) |
| [24-advisor-adapters.php](24-advisor-adapters.php) | A custom advice type through an advisor adapter | [spring-api](../docs/spring-api.md#advice-types-of-your-own) |
| [25-advice-execution.php](25-advice-execution.php) | `adviceexecution()` | [pointcuts](../docs/pointcuts.md#adviceexecution) |
| [26-named-pointcuts.php](26-named-pointcuts.php) | Named pointcuts with `#[Pointcut]`, used across aspects, with `if()` | [pointcuts](../docs/pointcuts.md#named-pointcuts) |
