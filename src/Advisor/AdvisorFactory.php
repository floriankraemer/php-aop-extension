<?php

declare(strict_types=1);

namespace AOP\Advisor;

use AOP\Aspect\AspectMetadata;
use AOP\Aspect\IntroductionDefinition;
use AOP\Binding\ParameterBinder;
use AOP\Pointcut\Expression\NamedPointcutResolverInterface;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Pointcut\Pattern\TypePattern;

final class AdvisorFactory
{
    public function __construct(private readonly PointcutExpressionCompiler $compiler = new PointcutExpressionCompiler())
    {
    }

    /**
     * @return list<Advisor>
     */
    public function fromMetadata(AspectMetadata $meta, NamedPointcutResolverInterface $resolver): array
    {
        $advisors = [];
        foreach ($meta->advices as $advice) {
            $method = new \ReflectionMethod($advice->aspectClass, $advice->methodName);
            $pointcut = $this->compiler->compile($advice->expression, ParameterBinder::bindingCandidates($method, $advice), $meta->aspectClass, $resolver);
            $advisors[] = new Advisor($advice->aspectClass.'::'.$advice->methodName, $advice, $meta->order, $pointcut);
        }

        return $advisors;
    }

    /**
     * @return list<IntroductionAdvisor>
     */
    public function introductionsFromMetadata(AspectMetadata $meta): array
    {
        return array_map(
            static fn (IntroductionDefinition $i): IntroductionAdvisor => new IntroductionAdvisor(
                $meta->aspectClass,
                new TypePattern(rtrim($i->typePattern, '+'), false, str_ends_with($i->typePattern, '+')),
                $i->interface,
                $i->defaultImpl,
                $meta->order,
            ),
            $meta->introductions,
        );
    }
}
