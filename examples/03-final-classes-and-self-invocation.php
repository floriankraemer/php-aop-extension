<?php

declare(strict_types=1);

namespace Demo\FinalClasses;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

final class Money
{
    public function __construct(private readonly int $cents)
    {
    }

    public function add(Money $other): Money
    {
        return new self($this->cents + $other->cents);
    }

    public function format(): string
    {
        return $this->render();
    }

    private function render(): string
    {
        return sprintf('%d.%02d EUR', intdiv($this->cents, 100), $this->cents % 100);
    }
}

#[Aspect]
final class MoneyAspect
{
    #[Before('execution(* Demo\FinalClasses\Money::render(..))')]
    public function onRender(JoinPointInterface $jp): void
    {
        echo 'render() called on '.$jp->getThis()::class." (self-invocation)\n";
    }
}

$weaver = Weaver::fromAspects([new MoneyAspect()]);
$weaver->enable();

$total = (new Money(1250))->add(new Money(275));
echo $total->format()."\n";

/* Expected output:
render() called on Demo\FinalClasses\Money (self-invocation)
15.25 EUR
*/
