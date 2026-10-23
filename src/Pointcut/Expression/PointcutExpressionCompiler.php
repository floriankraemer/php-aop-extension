<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression;

use AOP\Exception\PointcutSyntaxException;
use AOP\Pointcut\AndPointcut;
use AOP\Pointcut\AnnotationPointcut;
use AOP\Pointcut\ArgsPointcut;
use AOP\Pointcut\AtArgsPointcut;
use AOP\Pointcut\AtTargetPointcut;
use AOP\Pointcut\AtWithinPointcut;
use AOP\Pointcut\BindingKind;
use AOP\Pointcut\BindingSpec;
use AOP\Pointcut\ExecutionPointcut;
use AOP\Pointcut\Expression\Ast\AndNode;
use AOP\Pointcut\Expression\Ast\AnnotationNode;
use AOP\Pointcut\Expression\Ast\ArgsNode;
use AOP\Pointcut\Expression\Ast\AtArgsNode;
use AOP\Pointcut\Expression\Ast\AtTargetNode;
use AOP\Pointcut\Expression\Ast\AtWithinNode;
use AOP\Pointcut\Expression\Ast\BindingCandidateNode;
use AOP\Pointcut\Expression\Ast\ExecutionNode;
use AOP\Pointcut\Expression\Ast\NodeInterface;
use AOP\Pointcut\Expression\Ast\NotNode;
use AOP\Pointcut\Expression\Ast\OrNode;
use AOP\Pointcut\Expression\Ast\ParamListNode;
use AOP\Pointcut\Expression\Ast\ReferenceNode;
use AOP\Pointcut\Expression\Ast\ServiceNode;
use AOP\Pointcut\Expression\Ast\TargetNode;
use AOP\Pointcut\Expression\Ast\ThisNode;
use AOP\Pointcut\Expression\Ast\TypePatternNode;
use AOP\Pointcut\Expression\Ast\WithinNode;
use AOP\Pointcut\NotPointcut;
use AOP\Pointcut\OrPointcut;
use AOP\Pointcut\Pattern\ModifierPattern;
use AOP\Pointcut\Pattern\NamePattern;
use AOP\Pointcut\Pattern\ParameterPattern;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\ServicePointcut;
use AOP\Pointcut\TargetPointcut;
use AOP\Pointcut\ThisPointcut;
use AOP\Pointcut\WithinPointcut;

/**
 * Turns a pointcut expression into a PointcutInterface tree.
 */
final class PointcutExpressionCompiler
{
    /**
     * @param array<int|string, string|null> $adviceParameterNames Names of the advice parameters, e.g. ['id', 'audit'].
     *                                                             Optionally name => class type, e.g. ['audit' => Audit::class],
     *                                                             which becomes the binding's type restriction.
     */
    public function compile(
        string $expression,
        array $adviceParameterNames = [],
        string $declaringAspect = '',
        ?NamedPointcutResolverInterface $resolver = null,
    ): PointcutInterface {
        $names = [];
        foreach ($adviceParameterNames as $key => $value) {
            if (is_int($key)) {
                $names[(string) $value] = null;
            } else {
                $names[$key] = $value;
            }
        }

        return $this->compileExpression($expression, $names, $declaringAspect, $resolver, []);
    }

    /**
     * @param array<string, string|null> $names
     * @param list<string>               $stack named pointcuts currently being resolved
     */
    private function compileExpression(string $expression, array $names, string $aspect, ?NamedPointcutResolverInterface $resolver, array $stack): PointcutInterface
    {
        $ast = (new Parser())->parse($expression);

        return $this->build($ast, $names, $aspect, $resolver, $stack);
    }

    /**
     * @param array<string, string|null> $names
     * @param list<string>               $stack
     */
    private function build(NodeInterface $node, array $names, string $aspect, ?NamedPointcutResolverInterface $resolver, array $stack): PointcutInterface
    {
        return match (true) {
            $node instanceof AndNode => new AndPointcut($this->build($node->left, $names, $aspect, $resolver, $stack), $this->build($node->right, $names, $aspect, $resolver, $stack)),
            $node instanceof OrNode => new OrPointcut($this->build($node->left, $names, $aspect, $resolver, $stack), $this->build($node->right, $names, $aspect, $resolver, $stack)),
            $node instanceof NotNode => new NotPointcut($this->build($node->inner, $names, $aspect, $resolver, $stack)),
            $node instanceof ReferenceNode => $this->reference($node, $names, $aspect, $resolver, $stack),
            default => $this->leaf($node, $names),
        };
    }

    /**
     * Designator node class => name of the method building its pointcut from `(node, names)`.
     * Add a designator by adding an entry here and the method.
     *
     * @return array<class-string<NodeInterface>, string>
     */
    private function factories(): array
    {
        return [
            ExecutionNode::class => 'execution',
            WithinNode::class => 'within',
            ThisNode::class => 'thisPointcut',
            TargetNode::class => 'target',
            AnnotationNode::class => 'annotation',
            AtWithinNode::class => 'atWithin',
            AtTargetNode::class => 'atTarget',
            ServiceNode::class => 'service',
            ArgsNode::class => 'argsPointcut',
            AtArgsNode::class => 'atArgs',
        ];
    }

    /**
     * @param array<string, string|null> $names
     */
    private function leaf(NodeInterface $node, array $names): PointcutInterface
    {
        $method = $this->factories()[$node::class] ?? throw new PointcutSyntaxException(sprintf('Unsupported pointcut node %s.', $node::class));
        /** @var PointcutInterface $pointcut */
        $pointcut = $this->{$method}($node, $names);

        return $pointcut;
    }

    private function execution(ExecutionNode $node): PointcutInterface
    {
        return new ExecutionPointcut(
            new ModifierPattern($node->modifier),
            null === $node->returnPattern ? null : TypePattern::fromNode($node->returnPattern),
            null === $node->declaringType ? null : TypePattern::fromNode($node->declaringType),
            new NamePattern($node->namePattern),
            ParameterPattern::fromNode($node->params),
        );
    }

    private function within(WithinNode $node): PointcutInterface
    {
        return new WithinPointcut(TypePattern::fromNode($node->type));
    }

    private function service(ServiceNode $node): PointcutInterface
    {
        return new ServicePointcut(new NamePattern($node->pattern));
    }

    /**
     * @param array<string, string|null> $names
     */
    private function thisPointcut(ThisNode $node, array $names): PointcutInterface
    {
        return new ThisPointcut($this->subject($node->subject, BindingKind::THIS, $names));
    }

    /**
     * @param array<string, string|null> $names
     */
    private function target(TargetNode $node, array $names): PointcutInterface
    {
        return new TargetPointcut($this->subject($node->subject, BindingKind::TARGET, $names));
    }

    /**
     * @param array<string, string|null> $names
     */
    private function annotation(AnnotationNode $node, array $names): PointcutInterface
    {
        return new AnnotationPointcut($this->subject($node->subject, BindingKind::ANNOTATION, $names));
    }

    /**
     * @param array<string, string|null> $names
     */
    private function atWithin(AtWithinNode $node, array $names): PointcutInterface
    {
        return new AtWithinPointcut($this->subject($node->subject, BindingKind::WITHIN, $names));
    }

    /**
     * @param array<string, string|null> $names
     */
    private function atTarget(AtTargetNode $node, array $names): PointcutInterface
    {
        return new AtTargetPointcut($this->subject($node->subject, BindingKind::AT_TARGET, $names));
    }

    /**
     * @param array<string, string|null> $names
     */
    private function argsPointcut(ArgsNode $node, array $names): PointcutInterface
    {
        return $this->args($node->params, $names, false);
    }

    /**
     * @param array<string, string|null> $names
     */
    private function atArgs(AtArgsNode $node, array $names): PointcutInterface
    {
        return $this->args($node->params, $names, true);
    }

    /**
     * @param array<string, string|null> $names
     * @param list<string>               $stack
     */
    private function reference(ReferenceNode $node, array $names, string $aspect, ?NamedPointcutResolverInterface $resolver, array $stack): PointcutInterface
    {
        $class = $node->className ?? $aspect;
        $key = $class.'::'.$node->methodName;

        if (in_array($key, $stack, true)) {
            throw new PointcutSyntaxException(sprintf('Cyclic named pointcut reference: %s.', implode(' -> ', [...$stack, $key])));
        }
        if (null === $resolver) {
            throw new PointcutSyntaxException(sprintf('Cannot resolve named pointcut "%s()": no resolver given.', $key));
        }

        $expression = $resolver->resolve($node->className, $node->methodName, $aspect);

        return $this->compileExpression($expression, $names, $class, $resolver, [...$stack, $key]);
    }

    /**
     * @param array<string, string|null> $names
     */
    private function subject(TypePatternNode|BindingCandidateNode $subject, BindingKind $kind, array $names): TypePattern|BindingSpec
    {
        if ($subject instanceof TypePatternNode) {
            return TypePattern::fromNode($subject);
        }
        if (array_key_exists($subject->name, $names)) {
            return new BindingSpec($subject->name, $kind, $names[$subject->name]);
        }

        return new TypePattern($subject->name);
    }

    /**
     * @param array<string, string|null> $names
     */
    private function args(ParamListNode $list, array $names, bool $atArgs): PointcutInterface
    {
        $params = [];
        $bindings = [];
        foreach ($list->params as $param) {
            if ($param instanceof BindingCandidateNode) {
                if (array_key_exists($param->name, $names)) {
                    $bindings[$param->name] = new BindingSpec($param->name, $atArgs ? BindingKind::AT_ARGS : BindingKind::ARGS, $names[$param->name]);
                } else {
                    $param = new TypePatternNode($param->name);
                }
            }
            $params[] = $param;
        }
        $list = new ParamListNode($params, $list->trailingEllipsis);

        return $atArgs ? new AtArgsPointcut($list, $bindings) : new ArgsPointcut($list, $bindings);
    }
}
