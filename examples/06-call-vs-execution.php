<?php

declare(strict_types=1);

namespace Demo\Calls;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

final class Shelf
{
    public function stock(string $item): string
    {
        return "stocked $item";
    }
}

final class Store
{
    public function restock(Shelf $shelf): string
    {
        return $shelf->stock('apples');
    }

    public function audit(Shelf $shelf): string
    {
        return $shelf->stock('pears');
    }
}

#[Aspect]
final class ShelfAspect
{
    // Runs inside Shelf::stock(), wherever it is called from.
    #[Before('execution(* Demo\Calls\Shelf::stock(..))')]
    public function onExecution(JoinPointInterface $jp): void
    {
        echo 'execution of '.$jp->getSignature()->toShortString()."\n";
    }

    // Runs at every call site, before the call is made.
    #[Before('call(* Demo\Calls\Shelf::stock(..))')]
    public function onCall(JoinPointInterface $jp): void
    {
        echo 'call to '.$jp->getSignature()->toShortString().' from '
            .$jp->getEnclosingStaticPart()?->toShortString()."\n";
    }

    // Only the call sites inside Store::restock().
    #[Before('call(* Demo\Calls\Shelf::stock(..)) && withincode(* Demo\Calls\Store::restock(..))')]
    public function onRestock(): void
    {
        echo "the call comes from restock()\n";
    }
}

$weaver = Weaver::fromAspects([new ShelfAspect()]);
$weaver->enable();

$store = new Store();
$shelf = new Shelf();

echo "--- restock\n";
echo $store->restock($shelf)."\n";

echo "--- audit\n";
echo $store->audit($shelf)."\n";

echo "--- direct call\n";
echo $shelf->stock('plums')."\n";

/* Expected output:
--- restock
call to Shelf::stock(..) from method-execution(Store::restock(..))
the call comes from restock()
execution of Shelf::stock(..)
stocked apples
--- audit
call to Shelf::stock(..) from method-execution(Store::audit(..))
execution of Shelf::stock(..)
stocked pears
--- direct call
call to Shelf::stock(..) from 
execution of Shelf::stock(..)
stocked plums
*/
