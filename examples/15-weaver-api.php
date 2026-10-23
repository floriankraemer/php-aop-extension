<?php

declare(strict_types=1);

namespace Demo\Weaving;

require __DIR__.'/../vendor/autoload.php';

use AOP\Advisor\Api\DefaultPointcutAdvisor;
use AOP\Advice\Api\MethodBeforeAdviceInterface;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Exception\WeaverFrozenException;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

final class Catalog
{
    public function find(int $id): string
    {
        return "item $id";
    }
}

final class Reports
{
    public function summary(): string
    {
        return 'summary';
    }
}

#[Aspect]
final class CatalogAspect
{
    #[Before('execution(* Demo\Weaving\Catalog::find(..))')]
    public function announce(JoinPointInterface $jp): void
    {
        echo "catalog lookup {$jp->getArgs()[0]}\n";
    }
}

final class ReportLogger implements MethodBeforeAdviceInterface
{
    public function before(JoinPointInterface $joinPoint): void
    {
        echo "report requested\n";
    }
}

$weaver = Weaver::fromAspects([]);
$weaver->addAspect(new CatalogAspect());
$weaver->addAdvisor((new DefaultPointcutAdvisor('execution(* Demo\Weaving\Reports::summary(..))', new ReportLogger(), 10, 'reports'))->toAdvisor());

echo 'advisors: '.implode(', ', array_map(static fn ($a): string => $a->id, $weaver->advisors()))."\n";

$weaver->enable();
echo (new Catalog())->find(1)."\n";
echo (new Reports())->summary()."\n";

echo "--- removeAspect and removeAdvisor\n";
$weaver->removeAspect(CatalogAspect::class);
$weaver->removeAdvisor('reports');
echo (new Catalog())->find(2)."\n";
echo (new Reports())->summary()."\n";

echo "--- include and exclude\n";
$weaver->addAspect(new CatalogAspect());
$weaver->include('Demo\Weaving\*');
$weaver->exclude('Demo\Weaving\Catalog');
echo (new Catalog())->find(3)."\n";
echo "excluded types are not woven\n";

echo "--- compile an expression without weaving\n";
$expression = $weaver->compiler()->compile('execution(* Demo\Weaving\Catalog::find(..))', [], CatalogAspect::class);
echo get_class($expression)."\n";

echo "--- disable, then freeze\n";
$weaver->disable();
var_dump($weaver->isEnabled());
$weaver->freeze();
try {
    $weaver->addAspect(new CatalogAspect());
} catch (WeaverFrozenException $e) {
    echo "frozen: the configuration cannot change\n";
}

/* Expected output:
advisors: reports, Demo\Weaving\CatalogAspect::announce
catalog lookup 1
item 1
report requested
summary
--- removeAspect and removeAdvisor
item 2
summary
--- include and exclude
item 3
excluded types are not woven
--- compile an expression without weaving
AOP\Pointcut\ExecutionPointcut
--- disable, then freeze
bool(false)
frozen: the configuration cannot change
*/
