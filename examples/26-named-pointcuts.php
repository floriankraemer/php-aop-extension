<?php

declare(strict_types=1);

namespace Demo\Named;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Pointcut;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

final class Shop
{
    public function save(string $item): string
    {
        return "saved $item";
    }

    public function load(): string
    {
        return 'loaded';
    }
}

#[Aspect]
final class Pointcuts
{
    // A named pointcut: a public method without parameters that returns void.
    #[Pointcut('execution(* Demo\Named\Shop::save(..))')]
    public function saves(): void
    {
    }

    // if() may call a public static method that takes the join point.
    public static function sampled(JoinPointInterface $jp): bool
    {
        return 'load' === $jp->getSignature()->getName();
    }
}

#[Aspect]
final class Auditing
{
    // Uses the named pointcut of another aspect, written as Class::name().
    #[Before('Demo\Named\Pointcuts::saves()')]
    public function audit(): void
    {
        echo "  audit: a save happens\n";
    }

    // Uses the named pointcut of its own aspect, and a condition with if().
    #[Before('execution(* Demo\Named\Shop::load(..)) && if(enabled())')]
    public function gated(): void
    {
        echo "  gated load\n";
    }

    #[Before('execution(* Demo\Named\Shop::load(..)) && if(Demo\Named\Pointcuts::sampled())')]
    public function gatedWithJoinPoint(): void
    {
        echo "  load with a join point check\n";
    }

    public static function enabled(): bool
    {
        return true;
    }
}

$weaver = Weaver::fromAspects([new Pointcuts(), new Auditing()]);
$weaver->enable();

$shop = new Shop();
echo $shop->save('book')."\n";
echo $shop->load()."\n";

/* Expected output:
  audit: a save happens
saved book
  gated load
  load with a join point check
loaded
*/
