# php-aop-extension

Aspect-oriented programming for PHP, woven inside the Zend engine.
It brings Spring's and AspectJ's model to PHP: aspects, pointcuts, advice, the Spring AOP API and AspectJ's join points.
There are no proxies and no generated classes.
The engine decides at call time whether a call is advised.

Because nothing is proxied, the things a proxy cannot reach work too: private, protected, static and final methods, final classes, self-invocation, constructors and plain functions.

The package `floriankraemer/php-aop-extension` has two parts.
The C extension `aop` does the weaving.
The PHP package in `src/`, namespace `AOP\`, holds the model, the pointcut language and the Spring API.

## Highlights

- Advice on private, protected, static and final methods, and on final classes.
- Self-invocation is advised: a method calling its own private method goes through the advice.
- Constructors, with `initialization()` for the outermost one.
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
- Experimental: field access, exception handlers, constructor-less initialization and introductions. See [limitations](docs/limitations.md#experimental-features).

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
| Constructors | Never advised. | Advised, with `initialization()` for the outermost one. |
| Plain functions | Not supported. | Advised. |
| Internal functions and methods | Not supported. | Advised, with the limits described in [limitations](docs/limitations.md). |
| `call()` join points | Not supported. | Supported. |
| `cflow()`, `cflowbelow()`, `if()` | Not supported. | Supported. |
| `initialization()` | Not supported. | Supported for constructors. |
| Requirements | PHP only. | PHP 8.3 to 8.5, and the extension. |
| Install | Composer. | Composer and the extension (PIE or a prebuilt archive). |
| Spring AOP API and aspect attributes | Supported. | Supported. |

## Limitations

The full list is in [docs/limitations.md](docs/limitations.md).
The most relevant points:

- The JIT is disabled while the extension is loaded.
- Opcache can inline functions that only return a constant, so no advice runs for them.
- Calls compiled to dedicated opcodes, such as `strlen()` in the global namespace, are not intercepted.
- Functions that inspect their caller's frame, such as `compact()`, fail when advised.
- Functions that return by reference, closures and arrow functions are not advised.
- The `AOP\` namespace is never woven.
- Some patterns have known issues. A pattern that matches every function can crash PHP; restrict the weave with `include()`.

## Documentation

- [Docs index](docs/README.md)
- [Concepts](docs/concepts.md)
- [Pointcut expressions](docs/pointcuts.md)
- [Advice](docs/advice.md)
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
```

[docs/building.md](docs/building.md) describes the Docker matrix, the variables and the release process.

## License

MIT.
See [LICENSE](LICENSE).
