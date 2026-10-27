# Use cases

This page shows what aspects look like in application code.
Each section takes one problem, the pointcut and advice that solve it, and a trimmed excerpt of a runnable example.
The examples are larger than the ones in the other pages, and every excerpt is copied from the example it links to.
For the vocabulary, read [concepts](concepts.md) first.
The pointcut syntax is in [pointcuts](pointcuts.md), the advice types and the order in [advice](advice.md).

## Audit and logging

Every service method must leave an audit line: the call, the result or the failure.
Passwords must never reach the log.

The pointcut is a named pointcut that selects every public method of a class with `#[Service]`: `execution()` for the methods and `@within()` for the class attribute.
Three advice types share it, because each one fires at a different moment: `#[Before]` for the call, `#[AfterReturning]` for the result and `#[AfterThrowing]` for the exception.
None of them changes the result, so none needs `#[Around]`.
The advice reads the parameter attributes by reflection and prints `***` for a parameter marked `#[Sensitive]`.

```php
#[Aspect]
final class ServiceBoundaries
{
    // A named pointcut: every method of a class that carries #[Service].
    #[Pointcut('execution(public * *(..)) && @within(Demo\Audit\Service)')]
    public function services(): void
    {
    }
}
```

```php
    #[Before('Demo\Audit\ServiceBoundaries::services()')]
    public function entering(JoinPointInterface $jp): void
    {
        $this->log->write('-> '.$jp->getSignature()->toShortString().' '.$this->describeArguments($jp));
    }

    #[AfterThrowing(pointcut: 'Demo\Audit\ServiceBoundaries::services()', throwing: 'e')]
    public function failed(JoinPointInterface $jp, \Throwable $e): void
    {
        $this->log->write('!! '.$jp->getSignature()->toShortString().' threw '.$e::class.': '.$e->getMessage());
    }
```

Example: [`examples/31-audit-and-logging.php`](../examples/31-audit-and-logging.php).

Pitfalls:

- In `@within()` and `@annotation()`, a bare lowercase name such as `service` is a binding.
  It needs an advice parameter of that attribute type.
  Write the fully qualified attribute class, as `@within(Demo\Audit\Service)` does, when you only want to filter.
- `execution(public * *(..))` matches every public method, including `__construct`.
  Here the `@within()` filter limits it to the service classes; see [the combined order service](#the-combined-order-service) for an attribute filter on the method.

## Transactions

A method marked `#[Transactional]` must run inside a database transaction.
A transactional method that calls other transactional methods must join the outer transaction instead of opening a new one.

The pointcut combines `execution()` with `@annotation()`, so the application code carries only the marker.
The advice is `#[Around]`, because it must decide when to begin, commit and roll back, and it must rethrow the exception.
A depth counter in the aspect makes only the outermost call own the transaction.

```php
    #[Around('execution(* *(..)) && @annotation(Demo\Tx\Transactional)')]
    public function transactional(ProceedingJoinPointInterface $pjp): mixed
    {
        // Only the outermost call owns the transaction; nested calls join it.
        $outermost = 0 === $this->depth;
        if ($outermost) {
            $this->connection->begin();
        }

        ++$this->depth;
        try {
            $result = $pjp->proceed();
        } catch (\Throwable $e) {
            if ($outermost) {
                $this->connection->rollback();
            }

            throw $e;
        } finally {
            --$this->depth;
        }

        if ($outermost) {
            $this->connection->commit();
        }

        return $result;
    }
```

Example: [`examples/32-transactions.php`](../examples/32-transactions.php).

Pitfalls:

- `@annotation(Demo\Tx\Transactional)` is fully qualified on purpose.
  `@annotation(transactional)` would be a binding and would need a parameter of the attribute type.
- The depth counter is state in the aspect object.
  One aspect object serves every call, so the counter is only right for code that runs in one thread of control.

## Caching

The result of a slow method must be cached by its arguments.
Another method must drop the cached entries when the data changes.
The slow method here is private, on a final class.

The pointcuts use `@annotation(cacheable)` as a binding: the advice declares a `Cacheable $cacheable` parameter and reads the region from it.
`#[Around]` is the right type for the cache, because on a hit it returns without calling `proceed()`.
The eviction uses `#[AfterReturning]`, so a failed refresh does not drop the cache.
A proxy-based library can neither subclass a final class nor override a private method.
Here the method body is advised itself, so the attribute works on both.

```php
    #[Around('execution(* *(..)) && @annotation(cacheable)')]
    public function cache(ProceedingJoinPointInterface $pjp, Cacheable $cacheable): mixed
    {
        $key = $pjp->getSignature()->toShortString().json_encode($pjp->getArgs());

        if (\array_key_exists($key, $this->entries[$cacheable->region] ?? [])) {
            ++$this->hits;
            echo "  HIT  $key\n";

            return $this->entries[$cacheable->region][$key];
        }

        ++$this->misses;
        echo "  MISS $key\n";

        return $this->entries[$cacheable->region][$key] = $pjp->proceed();
    }

    #[AfterReturning(pointcut: 'execution(* *(..)) && @annotation(evict)')]
    public function evict(JoinPointInterface $jp, CacheEvict $evict): void
    {
        unset($this->entries[$evict->region]);
        echo "  evicted region {$evict->region}\n";
    }
```

Example: [`examples/33-caching.php`](../examples/33-caching.php).

Pitfalls:

- The binding name (`cacheable`, `evict`) must match the advice parameter name, and the parameter type must be the attribute class.
- The cache key is built from the method and `json_encode()` of the arguments.
  Arguments that `json_encode()` cannot tell apart share a key.

## Authorization

A method may run only if the current user has a role.
The role is declared with `#[RequiresRole]` on the class, as a default for every public method, or on a method, which overrides the class.
A method without the attribute stays open.

The pointcut accepts a method that carries the attribute or whose class does: `@annotation()` `||` `@within()`, both fully qualified.
The `execution()` part names the namespace, so no other class is matched.
The advice is `#[Before]`: it only decides, and throwing from it stops the call before the method runs.
The aspect has `#[Order(1)]`, so it is the outermost aspect and a denied call reaches no other advice.

```php
#[Aspect]
final class Guarded
{
    // Methods that carry the attribute, or whose class does.
    #[Pointcut('execution(public * Demo\Authz\*::*(..)) && (@annotation(Demo\Authz\RequiresRole) || @within(Demo\Authz\RequiresRole))')]
    public function secured(): void
    {
    }
}
```

```php
// Order(1): outermost, so the check runs before any other advice and a denied call stops here.
#[Aspect]
#[Order(1)]
final class AuthorizationAspect
{
    #[Before('Demo\Authz\Guarded::secured()')]
    public function check(JoinPointInterface $jp): void
    {
        $signature = $jp->getSignature();
        \assert($signature instanceof MethodSignature);
        $method = $signature->method;
        $required = ([] !== $method->getAttributes(RequiresRole::class) ? $method : $method->getDeclaringClass())
            ->getAttributes(RequiresRole::class)[0]->newInstance()->role;

        if (!\in_array($required, $this->user->roles, true)) {
            throw new AccessDenied("{$this->user->name} needs role '$required' for {$signature->toShortString()}");
        }
    }
}
```

Example: [`examples/34-authorization.php`](../examples/34-authorization.php).

Pitfalls:

- Both attribute designators are fully qualified.
  With a bare lowercase name each would be a binding, and `||` branches must bind the same names.
- `#[Order]` sets the nesting, not the list order given to `Weaver::fromAspects()`; see [advice](advice.md#order).
  A lower number is further out.

## Retry and circuit breaker

A flaky payment gateway needs retries with a backoff.
After repeated failures a circuit breaker must stop the calls for a while.

Both are `#[Around]` advice, because both decide whether and how often `proceed()` runs.
The retry aspect selects with `execution()` and `@annotation(retry)`, so the attempts and the backoff come from the `#[Retry]` attribute.
The order matters here.
`#[Order(1)]` puts retry outside and `#[Order(2)]` puts the breaker between it and the gateway.
The breaker then sees every single attempt, and an open circuit rejects the next attempt at once.
Retry gives up immediately on `CircuitOpen`.
With the order reversed, the breaker would count one failure per exhausted call and could not stop the retries in the middle of a call.

```php
// Order(1): retry is the outer layer, the breaker sits between it and the gateway.
#[Aspect]
#[Order(1)]
final class RetryAspect
{
    #[Around('execution(* Demo\Resilience\PaymentGateway::*(..)) && @annotation(retry)')]
    public function retry(ProceedingJoinPointInterface $pjp, Retry $retry): mixed
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $pjp->proceed();
            } catch (CircuitOpen $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($attempt >= $retry->attempts) {
                    echo "  retry: giving up after $attempt attempts\n";
                    throw $e;
                }
                $backoff = $retry->backoffMs * 2 ** ($attempt - 1);
                echo "  retry: attempt $attempt failed, backing off {$backoff}ms (t={$this->clock->nowMs}ms)\n";
                $this->clock->sleep($backoff);
            }
        }
    }
}
```

```php
    #[Around('execution(* Demo\Resilience\PaymentGateway::*(..))')]
    public function guard(ProceedingJoinPointInterface $pjp): mixed
```

Example: [`examples/35-resilience.php`](../examples/35-resilience.php).

Pitfalls:

- `#[Order]` decides the nesting, and a lower number is the outer layer; see [advice](advice.md#order).
  Swapping the two numbers changes what the breaker counts.
- The example uses a fake clock, so the backoff and the cool-down are instant and the output is the same on every run.
  A real aspect would call `usleep()` and `microtime()` instead.

## Tracing and metrics

Every call in the application stack must become a span in a tree, and every method must feed a call counter and a timer.
The tree also has to show where an exception passed.

The pointcut is one `#[Around]` expression with two parts: the application classes by name pattern, and the internal function `str_repeat()`.
`#[Around]` is needed to measure around `proceed()`, and `finally` closes the span on success and on failure.
The aspect keeps its own span stack instead of `cflow()`, because it needs the parent span object to attach the new one, and `cflow()` only says whether another join point is on the stack.

```php
    #[Around('(execution(* Demo\Tracing\Article*::*(..)) && !execution(* Demo\Tracing\*::__construct(..))) || execution(* str_repeat(..))')]
    public function span(ProceedingJoinPointInterface $pjp): mixed
    {
        $span = new Span($pjp->getSignature()->toShortString(), $this->clock->now());
        if ([] === $this->open) {
            $this->roots[] = $span;
        } else {
            $this->open[array_key_last($this->open)]->children[] = $span;
        }
        $this->open[] = $span;

        try {
            return $pjp->proceed();
        } catch (\Throwable $e) {
            $span->error = $e::class.': '.$e->getMessage();

            throw $e;
        } finally {
            array_pop($this->open);
            $span->end = $this->clock->now();
            $this->metrics->record($span->name, $span->end - $span->start);
        }
    }
```

Example: [`examples/36-tracing-and-metrics.php`](../examples/36-tracing-and-metrics.php).

Pitfalls:

- Advice that calls a function it also traces recurses.
  The printer in the example indents with `str_pad()`, not `str_repeat()`, because `str_repeat()` is in the pointcut.
- A bare `*` type pattern did not exclude namespaced classes in `!execution(* *::__construct(..))`.
  Write the namespace, `Demo\Tracing\*`, as the example does.
- The constructors are excluded explicitly, because `Article*::*` also matches `__construct`.
- The fake clock advances by one millisecond per reading, so the durations in the output are fixed.

## Legacy code and architecture rules

Part A: code you cannot edit.
A final vendor class must stop sending mail while a feature flag is off, and a legacy function must return a fixed value in a test.
The pointcuts name the vendor method and the legacy function directly, and `#[Around]` returns without calling `proceed()`.
In the second one, `args(country)` binds the argument to the advice parameter of the same name.

```php
    // Feature flag: while the flag is off, the final vendor class never sends anything.
    #[Around('execution(* Demo\Legacy\VendorMailer::send(..))')]
    public function mailSwitch(ProceedingJoinPointInterface $pjp): mixed
    {
        if (!$this->mailEnabled) {
            return 'mail suppressed by feature flag';
        }

        return $pjp->proceed();
    }

    // Test seam: replace the result of a legacy function without touching it.
    #[Around('execution(* Demo\Legacy\legacy_tax_rate(..)) && args(country)')]
    public function fixedTax(ProceedingJoinPointInterface $pjp, string $country): mixed
    {
        return $this->taxOverride ?? $pjp->proceed();
    }
```

Part B: architecture rules.
Controllers must not call a repository, and must not send mail.
`call()` is the right designator, because the rule is about the caller: `within()` restricts the call site to the controllers.
`#[DeclareWarning]` and `#[DeclareError]` need no advice method.
The warning is raised and the code goes on, and the error throws a `DeclareErrorException` at the call.

```php
#[Aspect]
#[DeclareWarning(
    'call(* Demo\Legacy\OrderRepository::*(..)) && within(Demo\Legacy\*Controller)',
    'controllers must go through a service, not call a repository',
)]
#[DeclareError(
    'call(* Demo\Legacy\VendorMailer::*(..)) && within(Demo\Legacy\*Controller)',
    'controllers must not send mail directly',
)]
final class ArchitectureRules
{
}
```

Example: [`examples/37-legacy-and-architecture-rules.php`](../examples/37-legacy-and-architecture-rules.php).
See [advice](advice.md#declare-warning-and-error) for the two attributes.

Pitfalls:

- The warning arrives as a PHP warning.
  The example installs `set_error_handler()` to print it.
- The error rule stops the call at the call site, so the vendor method does not run.

## The combined order service

Authorization, audit and transactions on one service, with application code that knows none of them.

All three aspects share one pointcut string in a constant, so the selection exists once.
`execution()` limits it to the public methods of `OrderService`, and `@annotation()` with the fully qualified `RequiresRole` limits it to methods that carry the attribute.
That attribute filter also keeps it off `__construct`: without it, `execution(public * Demo\Stack\OrderService::*(..))` matches the constructor too.
The advice types follow the job: `#[Before]` to deny, `#[Before]`, `#[AfterReturning]` and `#[AfterThrowing]` to audit, `#[Around]` for the transaction.

The `#[Order]` numbers decide the nesting:

1. Authorization is outermost: a rejected call leaves no audit line and no transaction.
2. Audit is inside it: it records what was attempted, and sees the outcome after the rollback.
3. The transaction is innermost: it wraps only the method body.

```php
// Why this order:
// 1. Authorization is outermost: deny before any work, so a rejected call leaves no audit line and no transaction.
// 2. Audit sits inside it: it records what was actually attempted, and sees the outcome after the rollback.
// 3. The transaction is innermost: it wraps only the method body, so BEGIN/COMMIT/ROLLBACK stay as short as possible.
const ORDER_METHODS = 'execution(public * Demo\Stack\OrderService::*(..)) && @annotation(Demo\Stack\RequiresRole)';

#[Aspect]
#[Order(1)]
final class AuthorizationAspect
{
    #[Before(ORDER_METHODS)]
    public function check(JoinPointInterface $jp): void
```

```php
Weaver::fromAspects([
    new TransactionAspect(new FakeConnection()),
    new AuditAspect($user),
    new AuthorizationAspect($user),
])->enable();
```

Example: [`examples/38-order-service-stack.php`](../examples/38-order-service-stack.php).

Pitfalls:

- The list passed to `Weaver::fromAspects()` is in the reverse of the nesting on purpose.
  Only `#[Order]` counts; a lower number is the outer layer (see [advice](advice.md#order)).
- A pointcut like `execution(public * *(..))` without an attribute filter also matches `__construct`.
- The constant works in an attribute because it is a constant expression.
