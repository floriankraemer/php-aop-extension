<?php

declare(strict_types=1);

namespace Demo\Intro;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\DeclareParents;
use AOP\Weaver;

interface Greeting
{
    public function greet(string $name): string;
}

// The default implementation. The woven class delegates greet() to one instance of it per object.
final class DefaultGreeting implements Greeting
{
    public function greet(string $name): string
    {
        return "hello $name";
    }
}

// The rule: every class whose name matches the type pattern gets the interface, and its default implementation.
#[Aspect]
#[DeclareParents(typePattern: 'Demo\Intro\Report', interface: Greeting::class, defaultImpl: DefaultGreeting::class)]
final class Introduce
{
    // The introduced method is an ordinary method, so an execution pointcut sees it.
    #[Before('execution(* Demo\Intro\Report::greet(..)) && args(name)')]
    public function beforeGreet(string $name): void
    {
        echo "  greet() called with $name\n";
    }
}

$weaver = Weaver::fromAspects([new Introduce()]);
$weaver->enable();

// The rules apply to classes compiled after enable(), so the target is loaded only now.
require __DIR__.'/30-introductions/Report.php';

$report = new Report();
var_dump($report instanceof Greeting);
echo $report->greet('Ada')."\n";
echo $report->render()."\n";

/* Expected output:
bool(true)
  greet() called with Ada
hello Ada
report: untitled
*/
