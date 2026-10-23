<?php

declare(strict_types=1);

namespace AOP\Binding;

use AOP\Advice\AdviceDefinition;
use AOP\Advice\AdviceType;
use AOP\Exception\BindingException;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\JoinPoint\StaticPart;
use AOP\Pointcut\PointcutInterface;

/**
 * Decides, once at weave time, where each advice parameter comes from (see plan section 2.4).
 */
final class ParameterBinder
{
    /**
     * Parameters that may be bound by the pointcut (name => type name), i.e. neither join point, static part,
     * `returning` nor `throwing`. Feed this to PointcutExpressionCompiler::compile().
     *
     * @return array<string, string|null>
     */
    public static function bindingCandidates(\ReflectionMethod $adviceMethod, AdviceDefinition $advice): array
    {
        $candidates = [];
        foreach ($adviceMethod->getParameters() as $parameter) {
            if (null !== self::joinPointKind($parameter) || $parameter->getName() === $advice->returning || $parameter->getName() === $advice->throwing) {
                continue;
            }
            $type = $parameter->getType();
            $candidates[$parameter->getName()] = $type instanceof \ReflectionNamedType ? ($type->allowsNull() && 'mixed' !== $type->getName() ? '?' : '').$type->getName() : null;
        }

        return $candidates;
    }

    public function plan(\ReflectionMethod $adviceMethod, AdviceDefinition $advice, PointcutInterface $pointcut): BindingPlan
    {
        $id = $advice->aspectClass.'::'.$advice->methodName;
        $bindings = $pointcut->bindings();
        $slots = [];
        $returningType = $throwingType = null;
        $sawReturning = $sawThrowing = $sawProceeding = false;

        foreach ($adviceMethod->getParameters() as $parameter) {
            $name = $parameter->getName();
            $kind = self::joinPointKind($parameter);

            if (StaticPart::class === $kind) {
                $slots[] = ['static_part'];
            } elseif (null !== $kind) {
                $sawProceeding = $sawProceeding || ProceedingJoinPointInterface::class === $kind;
                $slots[] = ['jp'];
            } elseif (null !== $advice->returning && $name === $advice->returning) {
                $sawReturning = true;
                $returningType = $parameter->getType();
                $slots[] = ['returning'];
            } elseif (null !== $advice->throwing && $name === $advice->throwing) {
                $sawThrowing = true;
                $throwingType = $parameter->getType();
                $slots[] = ['throwing'];
            } elseif (isset($bindings[$name])) {
                $slots[] = ['bound', $name];
            } else {
                throw new BindingException(sprintf('Cannot bind parameter $%s of %s: it is not a JoinPoint or StaticPart, not the returning/throwing parameter and not bound by the pointcut expression "%s".', $name, $id, $advice->expression));
            }
        }

        if (null !== $advice->returning && !$sawReturning) {
            throw new BindingException(sprintf('%s declares returning: "%s" but has no parameter $%s.', $id, $advice->returning, $advice->returning));
        }
        if (null !== $advice->throwing && !$sawThrowing) {
            throw new BindingException(sprintf('%s declares throwing: "%s" but has no parameter $%s.', $id, $advice->throwing, $advice->throwing));
        }
        if (AdviceType::AROUND === $advice->type && !$sawProceeding) {
            throw new BindingException(sprintf('Around advice %s must declare a %s parameter.', $id, ProceedingJoinPointInterface::class));
        }
        if (AdviceType::AROUND !== $advice->type && $sawProceeding) {
            throw new BindingException(sprintf('Only Around advice may declare %s, but %s is %s advice.', ProceedingJoinPointInterface::class, $id, $advice->type->value));
        }

        return new BindingPlan($id, $slots, $returningType, $throwingType);
    }

    /**
     * @return class-string<JoinPointInterface>|class-string<StaticPart>|null
     */
    private static function joinPointKind(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        if ($type instanceof \ReflectionNamedType && in_array($type->getName(), [JoinPointInterface::class, ProceedingJoinPointInterface::class, StaticPart::class], true)) {
            return $type->getName();
        }

        return null;
    }
}
