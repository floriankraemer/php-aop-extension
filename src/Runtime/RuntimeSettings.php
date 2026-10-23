<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Advisor\Advisor;
use AOP\Advisor\IntroductionAdvisor;
use AOP\Pointcut\Pattern\TypePattern;

/**
 * The configuration of a NativeRuntime, rebuilt by the Weaver after every change.
 */
final readonly class RuntimeSettings
{
    /**
     * @param list<Advisor>             $advisors      in no particular order
     * @param list<string>              $precedence    `declare precedence` lists
     * @param list<DeclareRule>         $declareRules
     * @param list<CflowEntry>          $flowEntries
     * @param list<IntroductionAdvisor> $introductions
     * @param list<TypePattern>         $includes      when not empty, only matching types are woven
     * @param list<TypePattern>         $excludes
     * @param array<string, string>     $classServices class name => service name
     */
    public function __construct(
        public array $advisors = [],
        public array $precedence = [],
        public array $declareRules = [],
        public array $flowEntries = [],
        public array $introductions = [],
        public array $includes = [],
        public array $excludes = [],
        public array $classServices = [],
    ) {
    }
}
