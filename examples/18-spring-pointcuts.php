<?php

declare(strict_types=1);

namespace Demo\SpringPointcuts;

require __DIR__.'/../vendor/autoload.php';

use AOP\Advice\Api\MethodBeforeAdviceInterface;
use AOP\Advisor\Api\DefaultPointcutAdvisor;
use AOP\Advisor\Api\NameMatchMethodPointcutAdvisor;
use AOP\Advisor\Api\RegexpMethodPointcutAdvisor;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Pointcut\Api\ApiPointcut;
use AOP\Pointcut\Api\ClassFilterInterface;
use AOP\Pointcut\Api\ComposablePointcut;
use AOP\Pointcut\Api\DynamicMethodMatcherPointcut;
use AOP\Pointcut\Api\NameMatchMethodPointcut;
use AOP\Pointcut\Api\Pointcuts;
use AOP\Pointcut\Api\TrueMethodMatcher;
use AOP\Pointcut\Api\StaticMethodMatcherPointcut;
use AOP\Weaver;

final class Catalog
{
    public function find(int $id): string
    {
        return "item $id";
    }

    public function save(string $item): string
    {
        return "saved $item";
    }
}

final class Report
{
    public function find(string $key): string
    {
        return "report $key";
    }
}

final class OnlyCatalog implements ClassFilterInterface
{
    public function matches(\ReflectionClass $class): bool
    {
        return Catalog::class === $class->getName();
    }
}

// A static matcher: decided once per (class, method), without looking at the arguments.
final class SaveMethods extends StaticMethodMatcherPointcut
{
    public function __construct()
    {
        $this->setClassFilter(new OnlyCatalog());
    }

    public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
    {
        return str_starts_with($function->getName(), 'save');
    }
}

// A dynamic matcher: the static part accepts find(), the runtime part looks at the argument.
final class LargeIds extends DynamicMethodMatcherPointcut
{
    public function __construct()
    {
        $this->setClassFilter(new OnlyCatalog());
    }

    public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
    {
        return 'find' === $function->getName();
    }

    public function matchesArguments(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class, array $args): bool
    {
        return ($args[0] ?? 0) > 100;
    }
}

final class Say implements MethodBeforeAdviceInterface
{
    public function __construct(private readonly string $label)
    {
    }

    public function before(JoinPointInterface $joinPoint): void
    {
        echo "  [{$this->label}] {$joinPoint->getSignature()->toShortString()}\n";
    }
}

$weaver = Weaver::fromAspects([]);

// The pattern matches the whole "Class::method" name; it takes no delimiters.
$weaver->addAdvisor((new RegexpMethodPointcutAdvisor(['.*Catalog::save'], new Say('regexp'), 1, 'regexp'))->toAdvisor());

// Matches the method name on every class.
$weaver->addAdvisor((new NameMatchMethodPointcutAdvisor(['find'], new Say('name'), 2, 'name'))->toAdvisor());

// Composition: the name match restricted to Catalog by intersecting it with a class filter.
$onlyCatalogFind = (new ComposablePointcut(new NameMatchMethodPointcut('find')))->intersection(new OnlyCatalog());
$weaver->addAdvisor((new DefaultPointcutAdvisor($onlyCatalogFind, new Say('composed'), 3, 'composed'))->toAdvisor());

$weaver->addAdvisor((new DefaultPointcutAdvisor(new LargeIds(), new Say('large id'), 4, 'large'))->toAdvisor());
$weaver->addAdvisor((new DefaultPointcutAdvisor(new SaveMethods(), new Say('save-matcher'), 5, 'save-matcher'))->toAdvisor());

$weaver->enable();

$catalog = new Catalog();
$report = new Report();

echo "catalog->save()\n";
echo $catalog->save('book')."\n";

echo "catalog->find(7)\n";
echo $catalog->find(7)."\n";

echo "catalog->find(500)\n";
echo $catalog->find(500)."\n";

echo "report->find()\n";
echo $report->find('monthly')."\n";

echo "--- a static check, without weaving\n";
$apiPointcut = new ApiPointcut(new OnlyCatalog(), TrueMethodMatcher::INSTANCE);
var_dump(Pointcuts::matches($apiPointcut, new \ReflectionMethod(Catalog::class, 'find'), new \ReflectionClass(Catalog::class), [7]));
var_dump(Pointcuts::matches($apiPointcut, new \ReflectionMethod(Report::class, 'find'), new \ReflectionClass(Report::class), ['monthly']));

/* Expected output:
catalog->save()
  [regexp] Catalog::save(..)
  [save-matcher] Catalog::save(..)
saved book
catalog->find(7)
  [name] Catalog::find(..)
  [composed] Catalog::find(..)
item 7
catalog->find(500)
  [name] Catalog::find(..)
  [composed] Catalog::find(..)
  [large id] Catalog::find(..)
item 500
report->find()
  [name] Report::find(..)
report monthly
--- a static check, without weaving
bool(true)
bool(false)
*/
