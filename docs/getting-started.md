# Getting started

This page covers the requirements, the install and a first run.
The settings in `php.ini` are described in [php.ini settings](php-ini.md).

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
The archive names and the checksums are described in [building.md](building.md#release-process).

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

[`examples/`](../examples/README.md) has one runnable script for each feature.

Next, read [Concepts](concepts.md) for the vocabulary.
