<?php

declare(strict_types=1);

namespace Demo\Quickstart;

require __DIR__.'/../vendor/autoload.php';

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

/* Expected output:
before BankAccount::withdraw(..) with [30]
around the private method assertFunds()
balance: 70
before BankAccount::withdraw(..) with [500]
around the private method assertFunds()
refused: Insufficient funds
disabled, no advice runs:
balance: 60
*/
