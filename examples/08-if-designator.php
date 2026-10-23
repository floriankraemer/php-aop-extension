<?php

declare(strict_types=1);

namespace Demo\Conditional;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Weaver;

final class Feature
{
    public static bool $enabled = false;

    public function run(): string
    {
        return 'ran';
    }
}

final class Flags
{
    // if() calls a public static method for every join point that matches the rest of the pointcut.
    public static function featureOn(): bool
    {
        return Feature::$enabled;
    }
}

#[Aspect]
final class RolloutAspect
{
    #[Before('execution(* Demo\Conditional\Feature::run(..)) && if(Demo\Conditional\Flags::featureOn())')]
    public function onRun(): void
    {
        echo "advice runs because the flag is on\n";
    }
}

$weaver = Weaver::fromAspects([new RolloutAspect()]);
$weaver->enable();

$feature = new Feature();

echo $feature->run()."\n";

Feature::$enabled = true;
echo $feature->run()."\n";

/* Expected output:
ran
advice runs because the flag is on
ran
*/
