<?php

declare(strict_types=1);

namespace Demo\Fields;

require __DIR__.'/../vendor/autoload.php';

// Needs aop.interception=hook (php.ini or -d): field and initialization advice is unavailable in woven mode.

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

final class Account
{
    public int $balance = 10;
    public ?string $note = null;

    public function deposit(int $amount): int
    {
        // A compound assignment is a read (field-get) followed by a write (field-set).
        $this->balance += $amount;

        return $this->balance;
    }
}

#[Aspect]
final class Ledger
{
    // set(): the advice runs before the write and sees the new value.
    // When it throws, the write does not happen.
    #[Before('set(int Demo\Fields\Account::$balance)')]
    public function refuseNegative(JoinPointInterface $jp): void
    {
        $value = $jp->getArgs()[0];
        echo "  set balance to $value\n";

        if ($value < 0) {
            throw new \DomainException('a balance cannot be negative');
        }
    }

    // get(): the around advice gets the value the read would return, and what it returns is what the reader sees.
    #[Around('get(?string Demo\Fields\Account::$note)')]
    public function noteOrDefault(ProceedingJoinPointInterface $pjp): mixed
    {
        return $pjp->proceed() ?? 'no note';
    }
}

$weaver = Weaver::fromAspects([new Ledger()]);
$weaver->enable();

$account = new Account();
echo 'deposit: '.$account->deposit(5)."\n";

try {
    $account->balance = -1;
} catch (\DomainException $e) {
    echo 'refused: '.$e->getMessage()."\n";
}

// Reads of balance are not advised by this aspect, so no output here.
echo 'balance: '.$account->balance."\n";
echo 'note: '.$account->note."\n";

/* Expected output:
  set balance to 15
deposit: 15
  set balance to -1
refused: a balance cannot be negative
balance: 15
note: no note
*/
