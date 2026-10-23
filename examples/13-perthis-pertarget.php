<?php

declare(strict_types=1);

namespace Demo\Scopes;

require __DIR__.'/../vendor/autoload.php';

use AOP\Aspect\AspectScope;
use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

final class Cart
{
    public function __construct(public readonly string $name)
    {
    }

    public function add(string $item): string
    {
        return "added $item";
    }
}

// perthis: one aspect instance per object that the join point runs on (for call kinds, the caller).
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

$weaver = Weaver::fromAspects([new PerCartCounter()]);
$weaver->enable();

$first = new Cart('first cart');
$second = new Cart('second cart');

echo $first->add('book')."\n";
echo $first->add('pen')."\n";
echo $second->add('mug')."\n";

/* Expected output:
cart first cart has had 1 add(s)
added book
cart first cart has had 2 add(s)
added pen
cart second cart has had 1 add(s)
added mug
*/
