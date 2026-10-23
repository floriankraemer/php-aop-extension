# Spring API

Spring's AOP API builds advice from objects instead of attributes.
This library has the same API.
A programmatic advisor is an `AOP\Advisor\Advisor`, the same type that aspect attributes produce.
You can mix both styles in one Weaver.

| You want | Use |
|---|---|
| Pointcuts from objects, names or regular expressions | The pointcut classes in `AOP\Pointcut\Api` |
| Advice as objects | The interfaces in `AOP\Advice\Api` |
| One object that joins a pointcut and advice | The advisors in `AOP\Advisor\Api` |
| Your own advice types | `AdvisorAdapterRegistry` |

Every advisor class has `toAdvisor()`.
Pass its result to `Weaver::addAdvisor()`.

## Pointcuts

### Expression pointcuts

A string is an expression, compiled once.
Any advisor accepts it where it takes a pointcut.

```php
$pointcut = 'execution(* Demo\SpringAdvice\Greeter::greet(..))';
$weaver->addAdvisor((new DefaultPointcutAdvisor($pointcut, new Timing(), 1, 'timing'))->toAdvisor());
```

`AOP\Pointcut\Api\ExpressionPointcut` is the object form of the same thing.

### Class filters and method matchers

A pointcut selects a class and a method.
A `ClassFilterInterface` selects classes, and a `MethodMatcherInterface` selects methods.

```php
final class OnlyCatalog implements ClassFilterInterface
{
    public function matches(\ReflectionClass $class): bool
    {
        return Catalog::class === $class->getName();
    }
}
```

The pointcut is built from the class filter and a method matcher:

```php
$apiPointcut = new ApiPointcut(new OnlyCatalog(), TrueMethodMatcher::INSTANCE);
```

`ApiPointcut` matches method and function executions only.
Pass a list of `JoinPointKind` values as the third argument to match other kinds.

### Static and dynamic matchers

Extend one of the matcher base classes when the decision needs code.

| Base class | Decides | Implement |
|---|---|---|
| `StaticMethodMatcherPointcut` | Once per class and method. | `matches()` |
| `DynamicMethodMatcherPointcut` | Once per class and method, then for each call. | `matches()` and `matchesArguments()` |

Call `setClassFilter()` in the constructor to restrict the class.

[`examples/18-spring-pointcuts.php`](../examples/18-spring-pointcuts.php) defines both.
Its output shows the dynamic matcher only for `find()` with an argument above 100:

```text
catalog->find(7)
  [name] Catalog::find(..)
  [composed] Catalog::find(..)
item 7
catalog->find(500)
  [name] Catalog::find(..)
  [composed] Catalog::find(..)
  [large id] Catalog::find(..)
item 500
```

### Names and regular expressions

| Class | Matches | Example |
|---|---|---|
| `NameMatchMethodPointcut` | The method name, on any class, with `*` as a wildcard. | `new NameMatchMethodPointcut(['find', 'save*'])` |
| `RegexpMethodPointcut` | The whole `Class::method` name, against a regular expression. | `new RegexpMethodPointcut(['.*Catalog::save'])` |
| `AttributeMatchingPointcut` | Classes or methods that carry an attribute. | `new AttributeMatchingPointcut(Audited::class, null)` |

`RegexpMethodPointcut` takes no delimiters.
The expression must match the entire name, so `.*Catalog::save` matches `App\Catalog::save`, and `/Catalog::save/` never matches.
A pattern with delimiters fails without an error message.

`NameMatchMethodPointcut` has no class filter.
The name `find` matches every class that has a `find()` method.
Combine it with a class filter when that is not what you want (see the composition below).

```php
$weaver->addAdvisor((new RegexpMethodPointcutAdvisor(['.*Catalog::save'], new Say('regexp'), 1, 'regexp'))->toAdvisor());

// Matches the method name on every class.
$weaver->addAdvisor((new NameMatchMethodPointcutAdvisor(['find'], new Say('name'), 2, 'name'))->toAdvisor());
```

[`examples/18-spring-pointcuts.php`](../examples/18-spring-pointcuts.php) uses both.

### Composition

`ComposablePointcut` combines pointcuts and class filters.
`Pointcuts` does the same for two pointcuts.

```php
$onlyCatalogFind = (new ComposablePointcut(new NameMatchMethodPointcut('find')))->intersection(new OnlyCatalog());
```

`union()` and `intersection()` take the same arguments on `ComposablePointcut`.
`Pointcuts::matches()` answers the question for one method, with arguments, without weaving.

### Control flow

`AOP\Pointcut\Api\ControlFlowPointcut` is the Spring form of `cflow()`.
It takes a class, an optional method name with `*` wildcards, and a flag for "below".
Two things differ from the `cflow()` designator in this build.

- The engine does not register the entry point of a `ControlFlowPointcut` as a join point of the flow.
  So it only works when other advice also advises the entry point.
  Prefer the `cflow()` designator, which needs no other advice.
- It matches call join points as well as executions, so advice runs at the call site and again inside the method.

Both are listed in [limitations.md](limitations.md#known-issues).

## Advice interfaces

Advice objects implement one of these interfaces.
The library adapts each to the engine.

| Interface | Method | Runs | Spring name |
|---|---|---|---|
| `MethodInterceptorInterface` | `invoke(ProceedingJoinPointInterface $invocation): mixed` | Around the call. | `MethodInterceptor` |
| `MethodBeforeAdviceInterface` | `before(JoinPointInterface $joinPoint): void` | Before the call. | `MethodBeforeAdvice` |
| `AfterReturningAdviceInterface` | `afterReturning(mixed $returnValue, JoinPointInterface $joinPoint): void` | After a normal return. | `AfterReturningAdvice` |
| `ThrowsAdviceInterface` | `afterThrowing*()` methods, one per exception type | After a throw. | `ThrowsAdvice` |
| `AfterAdviceInterface` | Marker interface for after advice. | - | `AfterAdvice` |

An around interceptor works like this:

```php
final class Timing implements MethodInterceptorInterface
{
    public function invoke(ProceedingJoinPointInterface $invocation): mixed
    {
        echo "around: enter {$invocation->getSignature()->getName()}\n";
        $result = $invocation->proceed();
        echo "around: leave\n";

        return $result;
    }
}
```

### Throws advice

`ThrowsAdviceInterface` is a marker.
Its handlers are public methods whose names start with `afterThrowing`.
Each takes one exception parameter, or a join point and an exception.
When the call throws, the handler for the most specific exception type runs.
Then the exception is rethrown.

```php
final class FailureReport implements ThrowsAdviceInterface
{
    public function afterThrowing(\Throwable $e): void
    {
        echo 'afterThrowing (any): '.$e->getMessage()."\n";
    }

    public function afterThrowingInvalid(\InvalidArgumentException $e): void
    {
        echo 'afterThrowing (invalid argument): '.$e->getMessage()."\n";
    }
}
```

[`examples/19-spring-advice-types.php`](../examples/19-spring-advice-types.php) shows all four interfaces.
Its output shows the most specific handler, and the rethrown exception reaching the caller:

```text
--- empty name
around: enter greet
before: argument is 
afterThrowing (invalid argument): a name is required
caller got: a name is required
```

### Introductions

`IntroductionInterceptorInterface` and `DelegatingIntroductionInterceptor` implement introductions, which add an interface to existing classes.
`DefaultIntroductionAdvisor` attaches one.
Introductions are experimental, and this build does not apply them (see [limitations.md](limitations.md#experimental-features)).

## Advisors

An advisor joins a pointcut, advice and an order.

| Class | Constructor | Use |
|---|---|---|
| `DefaultPointcutAdvisor` | `(PointcutInterface\|string $pointcut, object $advice, int $order = PHP_INT_MAX, ?string $id = null, AdvisorAdapterRegistry $adapters)` | Any pointcut. |
| `RegexpMethodPointcutAdvisor` | `(string\|array $patterns, object $advice, int $order = PHP_INT_MAX, ?string $id = null, string\|array $excludedPatterns = [], AdvisorAdapterRegistry $adapters)` | Patterns against `Class::method`. |
| `NameMatchMethodPointcutAdvisor` | `(string\|array $mappedNames, object $advice, int $order = PHP_INT_MAX, ?string $id = null, AdvisorAdapterRegistry $adapters)` | Method names, on any class. |
| `DefaultIntroductionAdvisor` | `(IntroductionInterceptorInterface $interceptor, array $interfaces, ...)` | Introductions (experimental). |

- The id defaults to the advice class and a counter, such as `App\Audit#3`.
  Give an id when you want to remove the advisor later.
- The order is the same as for aspects: lower values are outer.
- Use `toAdvisor()` to get the `Advisor` for the Weaver.

```php
$weaver->addAdvisor((new DefaultPointcutAdvisor('execution(* Demo\Weaving\Reports::summary(..))', new ReportLogger(), 10, 'reports'))->toAdvisor());
```

## Advice types of your own

`AdvisorAdapterRegistry` turns an advice object into an engine interceptor.
The registry starts with an adapter for each interface above.
Register an adapter for a type of your own.

An adapter has two methods.

- `supportsAdvice(object $advice): bool` says whether the adapter handles the object.
- `getInterceptor(object $advice): AOP\Interceptor\MethodInterceptorInterface` builds the interceptor.
  Wrap a Spring-style interceptor in `InterceptorBridge`.

[`examples/24-advisor-adapters.php`](../examples/24-advisor-adapters.php) adds a `Retry` advice type.
Its output shows two failed attempts before the success:

```text
attempt 1 failed: timeout on https://example.test
attempt 2 failed: timeout on https://example.test
200 OK from https://example.test
```

Pass the registry to the advisor as its last argument.
An adapter that implements `TypedAdvisorAdapterInterface` also says which `AdviceType` the advice has, which sets its position in the order.

Without a matching adapter, `UnknownAdviceTypeException` is thrown.

## Errors

| Exception | When |
|---|---|
| `AspectDefinitionException` | An advisor has neither or both of advice and interceptor, or an id is taken. |
| `UnknownAdviceTypeException` | No adapter supports an advice object. |
| `PointcutSyntaxException` | An expression or a regular expression is invalid. |
