# A modern AOP Extension for PHP

[Aspect-oriented programming](https://en.wikipedia.org/wiki/Aspect-oriented_programming) for PHP, woven inside the Zend engine.
It brings [Spring's](https://docs.spring.io/spring-framework/reference/core/aop.html) and [AspectJ's](https://www.eclipse.org/aspectj/) model to PHP: aspects, pointcuts, advice, the Spring AOP API and AspectJ's join points.
There are no proxies and no generated classes.
The engine decides at call time whether a call is advised.

Because nothing is proxied, the things a proxy cannot reach work too: private, protected, static and final methods, final classes, self-invocation, constructors and plain functions.

The package `floriankraemer/php-aop-extension` has two parts.
The C extension `aop` does the weaving.
The PHP package in `src/`, namespace `AOP\`, holds the model, the pointcut language and the Spring API.

## Highlights

- Advice on private, protected, static and final methods, and on final classes.
- Self-invocation is advised: a method calling its own private method goes through the advice.
- Constructors, with `initialization()` for the outermost one (needs `aop.interception=hook`).
- Plain functions and internal functions, such as `str_repeat()`.
- `call()` and `execution()`, `withincode()` and `within()`.
- `cflow()` and `cflowbelow()` for control flow.
- `if()` for a runtime condition.
- The five advice types, binding, `Ordered`, `#[Order]` and `#[DeclarePrecedence]`.
- `#[DeclareWarning]` and `#[DeclareError]`.
- Instantiation models: singleton, `perthis`, `pertarget`, `percflow`, `percflowbelow` and `pertypewithin`.
- The Spring AOP API: programmatic pointcuts, advice interfaces, advisors, custom advice types through an adapter registry, and target sources (hot-swappable, pooling, prototype and fiber-local).
- Aspects without attributes: a configuration array in the shape of Spring's XML schema.
- `#[Configurable]` for objects created with `new`.
- Per-object advice with `Weaver::advised()`.
- Exception handlers with `handler()`.
- Field access with `get()` and `set()`, and constructor-less `initialization()`; both need `aop.interception=hook`, which turns the JIT off.
- Introductions with `#[DeclareParents]`: a class gets an interface and a default implementation, at compile time.

## Use cases

Realistic examples, each one a runnable script in `examples/`.

- Audit and logging: [a service-boundary audit that masks sensitive parameters](examples/31-audit-and-logging.php).
- Transactions: [a `#[Transactional]` attribute where nested calls join the outer transaction](examples/32-transactions.php).
- Caching: [cache and evict by attribute, on a private method of a final class](examples/33-caching.php).
- Authorization: [role checks from a class-level or method-level attribute](examples/34-authorization.php).
- Retry and circuit breaker: [two ordered around advice around a flaky gateway](examples/35-resilience.php).
- Tracing and metrics: [a span tree and call counters, including an internal function](examples/36-tracing-and-metrics.php).
- Legacy code and architecture rules: [a feature flag on a final vendor class, a test seam, and layering rules](examples/37-legacy-and-architecture-rules.php).
- The combined order service: [authorization, audit and a transaction on one service, nested by `#[Order]`](examples/38-order-service-stack.php).

The page [Use cases](docs/use-cases.md) explains the pointcut and the advice type of each one.

## Requirements

- PHP 8.3, 8.4 or 8.5, NTS or ZTS.
- For the extension, a PHP build with the development files, or a prebuilt archive for your platform.
- Composer, for the PHP package.

## Install

### With PIE

[PIE](https://github.com/php/pie) installs the extension from Composer metadata.

```bash
pie install floriankraemer/php-aop-extension
```

The Windows archives follow PIE's naming, so PIE can use them.
On other platforms PIE builds the extension from source.
See the PIE documentation for the details.

### Prebuilt archives

Each release made from a version tag has archives for Linux (glibc and musl, x86_64 and arm64), macOS (arm64 and x86_64) and Windows (x64 and x86), for PHP 8.3, 8.4 and 8.5, NTS and ZTS.
Unpack the archive for your platform, and enable the extension with the `aop.ini` it contains, or with `extension=aop` in `php.ini`.
The archive names and the checksums are described in [building.md](docs/building.md#release-process).

### From source

```bash
git clone https://github.com/floriankraemer/php-aop-extension
cd php-aop-extension
make ext                       # phpize, configure and make in ext/
composer install
```

Then enable the extension:

```ini
; php.ini
extension=/path/to/php-aop-extension/ext/modules/aop.so
```

Check that it loads:

```bash
php -m | grep aop
```

## Configuration (php.ini)

Four INI settings control the extension:

| Setting | Default | Changeable | Meaning |
|---|---|---|---|
| `aop.enable` | `1` | php.ini or `-d` only | `0` turns interception off. Nothing is installed (no backend, no hooks), the JIT runs, and `Weaver::enable()` throws a `RuntimeException`. |
| `aop.interception` | `woven` | php.ini or `-d` only | The interception backend. `woven` works with the opcache JIT but has no field or `initialization()` join points. `hook` has all join points and turns the JIT off. `auto` is `hook` unless the JIT is configured, then `woven`. An invalid value raises a warning and keeps `woven`. See [limitations](docs/limitations.md#backends). |
| `aop.disable_inlining` | `1` | php.ini or `-d` only | `1` keeps opcache from inlining calls to user functions and methods, so their advice runs. `0` lets opcache inline a function that only returns a constant, and then its advice may not run. |
| `aop.weave_autoload` | `0` | any time, also with `ini_set()` | `1` weaves class loading too. By default the autoloader and the code it runs are not woven. |

```ini
; php.ini
extension=/path/to/php-aop-extension/ext/modules/aop.so
aop.enable=1
aop.interception=woven
aop.disable_inlining=1
aop.weave_autoload=0
```

The [building page](docs/building.md#configuration-phpini) describes the same settings for developers.

## Quick start

Save this as `quickstart.php` in the project root, next to `vendor/`.
Run it with `php -d extension=/path/to/aop.so quickstart.php` if the extension is not in `php.ini`.

```php
<?php

declare(strict_types=1);

namespace Demo\Quickstart;

require __DIR__.'/vendor/autoload.php';

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

final class BankAccount
{
    private int $balance = 100;

    public function withdraw(int $amount): int
    {
        $this->assertFunds($amount);
        $this->balance -= $amount;

        return $this->balance;
    }

    private function assertFunds(int $amount): void
    {
        if ($amount > $this->balance) {
            throw new \DomainException('Insufficient funds');
        }
    }
}

#[Aspect]
final class AuditAspect
{
    #[Before('execution(* Demo\Quickstart\BankAccount::withdraw(..))')]
    public function logCall(JoinPointInterface $jp): void
    {
        echo 'before '.$jp->getSignature()->toShortString().' with '.json_encode($jp->getArgs())."\n";
    }

    #[Around('execution(* Demo\Quickstart\BankAccount::assertFunds(..))')]
    public function checkedPrivate(ProceedingJoinPointInterface $pjp): mixed
    {
        echo "around the private method assertFunds()\n";

        return $pjp->proceed();
    }
}

$weaver = Weaver::fromAspects([new AuditAspect()]);
$weaver->enable();

$account = new BankAccount();
echo 'balance: '.$account->withdraw(30)."\n";

try {
    $account->withdraw(500);
} catch (\DomainException $e) {
    echo 'refused: '.$e->getMessage()."\n";
}

$weaver->disable();
echo "disabled, no advice runs:\n";
echo 'balance: '.$account->withdraw(10)."\n";
```

Its output:

```text
before BankAccount::withdraw(..) with [30]
around the private method assertFunds()
balance: 70
before BankAccount::withdraw(..) with [500]
around the private method assertFunds()
refused: Insufficient funds
disabled, no advice runs:
balance: 60
```

The aspect advises `withdraw()`, which is public, and the private `assertFunds()` method.
`disable()` stops the weaving, and the last call runs as plain PHP.

[`examples/`](examples/README.md) has one runnable script for each feature.

## Compared with proxy-based AOP

The table compares this extension with [phauthentic/aop](https://github.com/Phauthentic/aop), which weaves with generated proxy classes.
The proxy column describes what that library documents about its proxies.

| Topic | phauthentic/aop (proxies) | This extension (engine) |
|---|---|---|
| How advice is attached | A generated subclass or interface proxy is used in place of the object. | The engine intercepts the call. There are no generated classes. |
| Private methods | Not advised. A subclass cannot override them. | Advised. |
| Protected methods | Advised with the subclass strategy, never with the interface strategy. | Advised. |
| Static methods | Not advised. | Advised. |
| Final methods | Skipped by the subclass strategy. | Advised. |
| Final classes | Need the interface strategy, so the proxy is not an instance of the class. | Advised. The object stays an instance of its class. |
| Self-invocation (`$this->method()`) | Advised with the subclass strategy, not with the interface strategy. | Advised. |
| Constructors | Never advised. | Advised, with `initialization()` for the outermost one (needs `aop.interception=hook`). |
| Plain functions | Not supported. | Advised. |
| Internal functions and methods | Not supported. | Advised, with the limits described in [limitations](docs/limitations.md). |
| `call()` join points | Not supported. | Supported. |
| `cflow()`, `cflowbelow()`, `if()` | Not supported. | Supported. |
| `initialization()` | Not supported. | Supported, for constructors and for classes without a constructor. Needs `aop.interception=hook`. |
| Requirements | PHP only. | PHP 8.3 to 8.5, and the extension. |
| Install | Composer. | Composer and the extension (PIE or a prebuilt archive). |
| Spring AOP API and aspect attributes | Supported. | Supported. |

## Limitations

The full list is in [docs/limitations.md](docs/limitations.md).
The most relevant points:

- The overhead of each backend is measured in [docs/benchmarks.md](docs/benchmarks.md): about 12 ns per call on top of plain PHP for `woven` with nothing registered, and no measurable cost for code without calls.
- The default backend (`aop.interception=woven`) runs with the JIT, but it cannot intercept field access (`get()`, `set()`) or `initialization()`; those need `aop.interception=hook`, which turns the JIT off.
- Under `woven`, advice sees arguments after parameter coercion, and advice on a generator function runs at the first resume.
- Opcache inlining of functions that only return a constant is prevented by default (`aop.disable_inlining=1`).
- Calls compiled to dedicated opcodes, such as `strlen()` in the global namespace, are not intercepted.
- Functions that inspect their caller's frame, such as `compact()`, fail when advised.
- Functions that return by reference, closures and arrow functions are not advised.
- The `AOP\` namespace is never woven.
- Class loading is not woven by default (`aop.weave_autoload=1` changes that).
- On PHP 8.4+, a hooked property access is one field join point around the whole hooked access; lazy objects created by `newLazyGhost()`/`newLazyProxy()` are not covered.

## Documentation

- [Docs index](docs/README.md)
- [Concepts](docs/concepts.md)
- [Pointcut expressions](docs/pointcuts.md)
- [Advice](docs/advice.md)
- [Use cases](docs/use-cases.md)
- [Weaver](docs/weaver.md)
- [Spring API](docs/spring-api.md)
- [Target sources](docs/target-sources.md)
- [Configuration](docs/configuration.md)
- [Spring and AspectJ mapping](docs/spring-mapping.md)
- [Limitations](docs/limitations.md)
- [Building, testing and releasing](docs/building.md)

## Development

```bash
make help        # every target
make ext         # build the extension
make test        # unit tests, then end-to-end tests with the extension
make test-ext    # the phpt suite in ext/tests
make check       # php-cs-fixer, phpstan, phpt and phpunit
make test-all    # in Docker: PHP 8.3, 8.4 and 8.5, NTS and ZTS
make dist        # in Docker: the release archives into dist/
make bench       # the benchmark matrix, see docs/benchmarks.md (make bench-docker: PHP 8.3, 8.4, 8.5)
```

[docs/building.md](docs/building.md) describes the Docker matrix, the variables and the release process.

## License

MIT.
See [LICENSE](LICENSE).
