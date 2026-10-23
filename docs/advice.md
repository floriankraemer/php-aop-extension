# Advice

Advice is code that runs at a join point.
An aspect is a class with `#[Aspect]` whose public methods carry advice attributes.
This page covers the five advice types, the parameters they receive, the order in which advice runs, and the instantiation models.

## The five advice types

| Attribute | Runs | Can change the result | Parameters |
|---|---|---|---|
| `#[Around(pc)]` | Replaces the call. It calls `proceed()` or does not. | Yes, the return value and the arguments. | `ProceedingJoinPointInterface` first. |
| `#[Before(pc)]` | Before the call. | No. | `JoinPointInterface` or nothing. |
| `#[AfterReturning(pc, returning: 'name')]` | After the call returned normally. | No. An exception from the advice fails the call. | `returning` parameter. |
| `#[AfterThrowing(pc, throwing: 'name')]` | After the call threw. | No. The exception is rethrown. | `throwing` parameter, typed as the exception class. |
| `#[After(pc)]` | After the call, on success and on failure. | No. | None. |

[`examples/10-advice-types.php`](../examples/10-advice-types.php) uses all five on two methods.
Its output shows the order:

```text
charge:
around: before proceed
before: charge
  charge(40) runs
after returning: charged 40
after (runs on success and on failure)
around: after proceed
charged 40
refund:
after throwing: refund of 15 rejected
after (runs on success and on failure)
caller got: refund of 15 rejected
```

Some rules that follow from the table.

- An around advice that does not call `proceed()` stops the call.
  The original method does not run, and the advice's return value is the result.
- The return value of an around advice must fit the declared return type of the advised method.
  Otherwise `AdviceReturnException` is thrown.
- `proceed()` takes an optional array of arguments.
  Pass `[$amount]` to replace the first argument.
- `AfterReturning` and `After` cannot change what the caller gets.
  Use an around advice for that.

```php
#[Around('execution(* Demo\Advice\Payments::charge(..)) && args(amount)')]
public function around(ProceedingJoinPointInterface $pjp, int $amount): mixed
{
    echo "around: before proceed\n";
    $result = $pjp->proceed([$amount]);
    echo "around: after proceed\n";

    return $result;
}
```

## Parameters

Advice methods declare the parameters they need.
The library fills each one from one of four sources.

| Parameter type or name | Source |
|---|---|
| `ProceedingJoinPointInterface` | The join point, for around advice. |
| `JoinPointInterface` | The join point. |
| `AOP\JoinPoint\StaticPart` | The static part of the join point: kind, signature and source location. |
| The `returning` name | The return value of an after-returning advice. |
| The `throwing` name | The exception of an after-throwing advice. |
| Any other name | A value bound by the pointcut, such as `args(name)`, `this(name)`, `target(name)` or `@annotation(name)`. |

A parameter that no source fills raises `AOP\Exception\BindingException` when the advised join point is first called.
The message names the parameter and the pointcut.

[`examples/09-pointcut-designators.php`](../examples/09-pointcut-designators.php) binds an attribute instance, an argument and a target.

## Order

Advice runs in order, and the order is about nesting.
A lower value runs first on the way in and last on the way out.
So the advice with the lowest value wraps the others.

### Inside one aspect

The advice type sets the order.

| Type | Position (lower is outer) |
|---|---|
| around | 0 |
| before | 1 |
| after | 2 |
| after-returning | 3 |
| after-throwing | 4 |

So an around advice wraps the before advice, and an after advice wraps the after-returning and after-throwing advice.
Within one type, the declaration order of the methods decides.

### Between aspects

Each aspect has an order.
The order comes from the first of these that is present.

1. `Ordered::getOrder()` on the aspect object.
2. `#[Order(n)]` on the aspect class.
3. `PHP_INT_MAX`, which runs last.

Aspects with equal order run in a stable sequence by class name.

```php
#[Aspect]
#[Order(1)]
final class SecurityAspect
{
    #[Around('execution(* Demo\Ordering\Handler::handle(..))')]
    public function wrap(ProceedingJoinPointInterface $pjp): mixed
    {
        return $this->named('security', $pjp);
    }

    private function named(string $name, ProceedingJoinPointInterface $pjp): mixed
    {
        echo "$name in\n";
        $result = $pjp->proceed();
        echo "$name out\n";

        return $result;
    }
}
```

The other aspect implements `Ordered` instead of carrying the attribute:

```php
#[Aspect]
final class MetricsAspect implements Ordered
{
    public function getOrder(): int
    {
        return 5;
    }

    #[Around('execution(* Demo\Ordering\Handler::handle(..))')]
    public function wrap(ProceedingJoinPointInterface $pjp): mixed
    {
        echo "metrics in\n";
        $result = $pjp->proceed();
        echo "metrics out\n";

        return $result;
    }
}
```


[`examples/11-ordering.php`](../examples/11-ordering.php) shows both forms.

### `#[DeclarePrecedence]`

`#[DeclarePrecedence('A, B, *')]` puts aspect `A` before aspect `B`, and `B` before the others.
The `*` stands for all other aspects, in their normal order.
A declaration overrides `#[Order]` and `Ordered` between the aspects it names.
Names may end in `+` to include subtypes.
A contradiction between declarations throws `AspectDefinitionException`.

```php
#[Aspect]
#[DeclarePrecedence('Demo\Ordering\TracingAspect, Demo\Ordering\SecurityAspect')]
final class TracingAspect
{
    #[Around('execution(* Demo\Ordering\Handler::handle(..))')]
    public function wrap(ProceedingJoinPointInterface $pjp): mixed
    {
        echo "tracing in\n";
        $result = $pjp->proceed();
        echo "tracing out\n";

        return $result;
    }
}
```

A name that is not registered with the Weaver is ignored.

## Declare warning and error

AspectJ's `declare warning` and `declare error` check the code when a join point is first matched.
They are class attributes on an aspect.

```php
#[Aspect]
#[DeclareWarning('execution(* Demo\Declare\LegacyApi::*(..))', 'LegacyApi is deprecated, use the new API')]
#[DeclareError('execution(* Demo\Declare\Gateway::unsafe*(..))', 'unsafe methods must not be called')]
final class PolicyAspect
{
}
```

- A warning is raised with `trigger_error(E_USER_WARNING)`, once per rule and join point.
  An error handler can catch it, and the call goes on.
- An error throws `AOP\Exception\DeclareErrorException` on every call.

The check runs when the join point is first dispatched.
[`examples/12-declare-warning-and-error.php`](../examples/12-declare-warning-and-error.php) shows both.

## Instantiation models

By default, an aspect is a singleton: one object, shared by all calls.
`#[Aspect(scope: ..., pointcut: ...)]` changes that.

| Scope | Instances | Pointcut |
|---|---|---|
| `AspectScope::SINGLETON` (default) | One. | Not allowed. |
| `AspectScope::PER_THIS` (`perthis`) | One per object that the join point runs on. For call kinds, the caller. | Optional. Restricts the join points. |
| `AspectScope::PER_TARGET` (`pertarget`) | One per target object. | Optional. Restricts the join points. |
| `AspectScope::PER_CFLOW` (`percflow`) | One per entry into a flow matching the pointcut. | Required. |
| `AspectScope::PER_CFLOW_BELOW` (`percflowbelow`) | As `percflow`, but the advice does not run at the entry point itself. | Required. |
| `AspectScope::PER_TYPE_WITHIN` (`pertypewithin`) | One per type the join point belongs to. | Required, a type pattern. |

Static members and functions have no object, so `perthis` and `pertarget` use the singleton for them.

```php
#[Aspect(scope: AspectScope::PER_THIS, pointcut: 'execution(* Demo\Scopes\Cart::add(..))')]
final class PerCartCounter
{
    private int $adds = 0;

    #[Around('execution(* Demo\Scopes\Cart::add(..))')]
    public function count(ProceedingJoinPointInterface $pjp): mixed
    {
        ++$this->adds;
        echo "cart {$pjp->getThis()->name} has had {$this->adds} add(s)\n";

        return $pjp->proceed();
    }
}
```

[`examples/13-perthis-pertarget.php`](../examples/13-perthis-pertarget.php) counts per cart.
[`examples/14-percflow-pertypewithin.php`](../examples/14-percflow-pertypewithin.php) shows `percflow`, `percflowbelow` and `pertypewithin`.

## Ordered and the aspect object

The object passed to `Weaver::fromAspects()` is the singleton, and the prototype for the other instances.
Its constructor runs once.
Other instances are made by cloning it, so they keep its state at the time of the clone.

## The advice API

Advice written with the Spring API uses other interfaces: `MethodInterceptorInterface`, `MethodBeforeAdviceInterface` and the rest.
See [spring-api.md](spring-api.md).
