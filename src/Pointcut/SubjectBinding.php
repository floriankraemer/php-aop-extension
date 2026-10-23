<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * Shared by this/target/@annotation/@within/@target: the subject is either a type pattern
 * or a binding (then the pointcut is runtime and binds a value). A binding with a type
 * restriction additionally filters statically by that type (including subtypes).
 *
 * @internal
 */
trait SubjectBinding
{
    private ?TypePattern $subjectType = null;
    private ?BindingSpec $binding = null;

    private function initSubject(TypePattern|BindingSpec $subject): void
    {
        if ($subject instanceof TypePattern) {
            $this->subjectType = $subject;

            return;
        }
        $this->binding = $subject;
        $restriction = $subject->classRestriction();
        if (null !== $restriction) {
            $this->subjectType = new TypePattern($restriction, false, true);
        }
    }

    public function isRuntime(): bool
    {
        return null !== $this->binding;
    }

    public function bindings(): array
    {
        return null === $this->binding ? [] : [$this->binding->name => $this->binding];
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionMethod ...$reflectors
     */
    private function hasAttribute(\ReflectionClass|\ReflectionMethod ...$reflectors): bool
    {
        foreach ($reflectors as $reflector) {
            if (null !== $this->findAttribute($reflector)) {
                return true;
            }
        }

        return false;
    }

    /**
     * First attribute matching the subject type (any attribute when the subject is an untyped binding).
     *
     * @param \ReflectionClass<object>|\ReflectionMethod $reflector
     *
     * @return \ReflectionAttribute<object>|null
     */
    private function findAttribute(\ReflectionClass|\ReflectionMethod $reflector): ?\ReflectionAttribute
    {
        foreach ($reflector->getAttributes() as $attribute) {
            if (null === $this->subjectType || $this->subjectType->matchesName($attribute->getName())) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Binds the first matching attribute among the reflectors; false when there is none.
     *
     * @param \ReflectionClass<object>|\ReflectionMethod ...$reflectors
     */
    private function bindAttribute(RuntimeMatchContext $ctx, \ReflectionClass|\ReflectionMethod ...$reflectors): bool
    {
        $binding = $this->binding;
        foreach ($reflectors as $reflector) {
            $attribute = $this->findAttribute($reflector);
            if (null !== $attribute && null !== $binding) {
                $ctx->bind($binding->name, $attribute->newInstance());

                return true;
            }
        }

        return false;
    }
}
