# Concepts

This page gives the vocabulary and explains how a call becomes advised.
Read it before the pointcut and advice pages.

## Glossary

| Term | Meaning |
|---|---|
| Aspect | A class marked with `#[Aspect]`. It holds advice methods. A plain class can be an aspect too when it is configured (see [configuration.md](configuration.md)). |
| Join point | A point in the running program where advice can run, such as a method body, a call site or a function call. |
| Pointcut | A rule that selects join points. You write it as an expression, such as `execution(* App\Service\*::*(..))`, or build it from objects (see [spring-api.md](spring-api.md)). |
| Advice | The code that runs at a selected join point. There are five types (see [advice.md](advice.md)). |
| Advisor | A pointcut plus advice plus an order. Every advice method of an aspect becomes one advisor. |
| Weaving | Attaching advice to code. Here the Zend engine does it while the program runs. There are no proxies and no generated classes. |
| Target | The object or function that a call goes to. |
| Service | A name registered for an object or a class. The `service()` designator matches on it (see [pointcuts.md](pointcuts.md)). |
| Instantiation model | How many aspect instances exist: one (`singleton`), one per object (`perthis`, `pertarget`), or one per control flow (`percflow`, `percflowbelow`, `pertypewithin`). |
| cflow | The join points that are active on the call stack of the current Fiber. |
| Static part | What is known about a join point without running it: its kind, signature and source location. |

## Join point kinds

Every join point has a kind.
`JoinPointInterface::getKind()` returns it as a `JoinPointKind` enum.

| Kind | Case | Happens when | Designator | Status |
|---|---|---|---|---|
| `method-execution` | `METHOD_EXECUTION` | The body of a method runs. | `execution()` | Stable |
| `method-call` | `METHOD_CALL` | The call site of a method calls it. | `call()` | Stable |
| `constructor-execution` | `CONSTRUCTOR_EXECUTION` | The body of a `__construct()` runs. This includes `parent::__construct()`. | `execution(... ::__construct(..))`, `initialization()` (needs `aop.interception=hook`) | Stable |
| `constructor-call` | `CONSTRUCTOR_CALL` | The `new` expression calls a constructor. | `call(... ::__construct(..))` | Stable |
| `function-execution` | `FUNCTION_EXECUTION` | A user or internal function runs. | `execution(* name(..))` | Stable |
| `function-call` | `FUNCTION_CALL` | A call site calls a function. | `call(* name(..))` | Stable |
| `advice-execution` | `ADVICE_EXECUTION` | An advice method runs. | `adviceexecution()` | Stable |
| `initialization` | `INITIALIZATION` | `new` creates an object of a class without a constructor. | `initialization()` | Implemented, needs `aop.interception=hook` |
| `field-get`, `field-set` | `FIELD_GET`, `FIELD_SET` | A property is read or written. | `get()`, `set()` | Implemented, needs `aop.interception=hook` (PHP 8.4+: hooked properties and lazy objects, see [limitations](limitations.md#fields)) |
| `exception-handler` | `EXCEPTION_HANDLER` | A `catch` clause catches an exception. | `handler()` | Implemented |

`isCall()` and `isExecution()` tell the two families apart.
The last three kinds were checked on PHP 8.3.
`initialization`, `field-get` and `field-set` exist only with `aop.interception=hook`, which turns the opcache JIT off.
The default backend, `woven`, keeps the JIT and rejects pointcuts for these kinds in `Weaver::enable()` (see [limitations](limitations.md#backends)).
Their limits are described in [limitations.md](limitations.md#fields-handlers-initialization-and-introductions).

## Call versus execution

An execution join point is inside the callee.
A call join point is at the call site, in the caller.

Use `execution()` when the advice belongs to the method itself, for example to log every run of `Shelf::stock()`.
Use `call()` when the advice depends on who calls, for example to allow calls only from one class.

[`examples/06-call-vs-execution.php`](../examples/06-call-vs-execution.php) shows both.
Its output has the call advice first, then the execution advice:

```text
--- restock
call to Shelf::stock(..) from method-execution(Store::restock(..))
the call comes from restock()
execution of Shelf::stock(..)
stocked apples
```

Inside a call join point, `getThis()` is the caller's object and `getTarget()` is the callee's object.
`getEnclosingStaticPart()` is the caller.
For top-level code it is `null`.

## Weaving flow

Weaving happens inside the engine when a call is made.
The steps are the same for every kind of call.

```text
PHP code calls a method, constructor or function
  -> the engine checks its decision cache for this callee
       on a miss it asks the matcher (PHP) whether any advice applies
  -> no advice applies: the call runs unchanged
  -> advice applies: the dispatcher (PHP) builds the chain
       1. call advice, at the call site (only for call kinds)
       2. execution advice, inside the callee (only for execution kinds)
       3. OriginalCall, which runs the method body
  -> the result, or the exception, goes back to the caller
```

Some points follow from this flow.

- Advice runs only for calls that reach the engine's hooks.
  Internal functions compiled to dedicated opcodes do not (see [limitations.md](limitations.md#compiler-specialised-internal-functions)).
- The decision is cached per callee.
  Changing the advice or the services resets the cache, so the next call asks again.
- `enable()` starts weaving and `disable()` stops it.
  Calls made while weaving is disabled run as ordinary PHP.
- Advice runs with the advised frame still on the stack.
  `debug_backtrace()` and exception traces show the advised method.

## Where advice gets its values

| Source | Available as | Notes |
|---|---|---|
| The join point | `JoinPointInterface` parameter | Gives the kind, signature, arguments and objects. |
| Static part | `StaticPart` parameter | The kind, signature and source location, without the objects. |
| Arguments | `args(name)` binding | Named by the pointcut, typed by the advice parameter. |
| Objects | `this(name)`, `target(name)` binding | Object-typed parameters. |
| Attributes | `@annotation(name)`, `@within(name)`, `@target(name)` | Attribute instances, typed by the attribute class. |
| Current join point | `AOP\Runtime\AopContext::currentJoinPoint()` | Innermost join point of the current Fiber. |

[advice.md](advice.md) explains how the parameters are bound.

## Next

- [Pointcut expressions](pointcuts.md) for the full language.
- [Advice](advice.md) for the five types and their order.
- [Weaver](weaver.md) for the management API.
