<?php

declare(strict_types=1);

namespace Demo\Pointcuts;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Audited
{
    public function __construct(public readonly string $level = 'info')
    {
    }
}

#[Audited('warn')]
final class Account
{
    private int $balance = 0;

    #[Audited]
    public function deposit(int $amount, string $note = ''): int
    {
        return $this->balance += $amount;
    }

    public function peek(): int
    {
        return $this->balance;
    }
}

#[Aspect]
final class DesignatorAspect
{
    // @annotation(name) binds the attribute instance of the method.
    #[Before('execution(* Demo\Pointcuts\Account::*(..)) && @annotation(audit)')]
    public function methodAnnotation(Audited $audit, JoinPointInterface $jp): void
    {
        echo "@annotation on {$jp->getSignature()->getName()}: level {$audit->level}\n";
    }

    // @within(name) binds the attribute of the declaring class.
    #[Before('execution(* Demo\Pointcuts\Account::peek(..)) && @within(audit)')]
    public function classAnnotation(Audited $audit): void
    {
        echo "@within: class is annotated with level {$audit->level}\n";
    }

    // args(name) binds an argument by its position in the pointcut.
    #[Before('execution(* Demo\Pointcuts\Account::deposit(..)) && args(amount, ..)')]
    public function arguments(int $amount): void
    {
        echo "args: amount is $amount\n";
    }

    // target(name) binds the object the member belongs to; the modifier and return type can be part of the pattern.
    #[Before('execution(public int Demo\Pointcuts\Account::peek(..)) && target(account)')]
    public function target(Account $account): void
    {
        echo 'target: '.$account::class."\n";
    }
}

$weaver = Weaver::fromAspects([new DesignatorAspect()]);
$weaver->enable();

$account = new Account();
echo $account->deposit(25, 'salary')."\n";
echo $account->peek()."\n";

/* Expected output:
@annotation on deposit: level info
args: amount is 25
25
@within: class is annotated with level warn
target: Demo\Pointcuts\Account
25
*/
