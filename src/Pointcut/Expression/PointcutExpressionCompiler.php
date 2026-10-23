<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression;

use AOP\Exception\BindingException;
use AOP\Exception\PointcutSyntaxException;
use AOP\Exception\UnsupportedPointcutDesignatorException;
use AOP\Pointcut\AdviceExecutionPointcut;
use AOP\Pointcut\AndPointcut;
use AOP\Pointcut\AnnotationPointcut;
use AOP\Pointcut\ArgsPointcut;
use AOP\Pointcut\AtArgsPointcut;
use AOP\Pointcut\AtTargetPointcut;
use AOP\Pointcut\AtThisPointcut;
use AOP\Pointcut\AtWithincodePointcut;
use AOP\Pointcut\AtWithinPointcut;
use AOP\Pointcut\BindingKind;
use AOP\Pointcut\BindingSpec;
use AOP\Pointcut\CallPointcut;
use AOP\Pointcut\CflowPointcut;
use AOP\Pointcut\ExecutionPointcut;
use AOP\Pointcut\Expression\Ast\AdviceExecutionNode;
use AOP\Pointcut\Expression\Ast\AndNode;
use AOP\Pointcut\Expression\Ast\AnnotationNode;
use AOP\Pointcut\Expression\Ast\ArgsNode;
use AOP\Pointcut\Expression\Ast\AtArgsNode;
use AOP\Pointcut\Expression\Ast\AtTargetNode;
use AOP\Pointcut\Expression\Ast\AtThisNode;
use AOP\Pointcut\Expression\Ast\AtWithincodeNode;
use AOP\Pointcut\Expression\Ast\AtWithinNode;
use AOP\Pointcut\Expression\Ast\BindingCandidateNode;
use AOP\Pointcut\Expression\Ast\CallNode;
use AOP\Pointcut\Expression\Ast\CflowNode;
use AOP\Pointcut\Expression\Ast\ExecutionNode;
use AOP\Pointcut\Expression\Ast\FieldPatternNode;
use AOP\Pointcut\Expression\Ast\GetNode;
use AOP\Pointcut\Expression\Ast\HandlerNode;
use AOP\Pointcut\Expression\Ast\IfNode;
use AOP\Pointcut\Expression\Ast\InitializationNode;
use AOP\Pointcut\Expression\Ast\NodeInterface;
use AOP\Pointcut\Expression\Ast\NotNode;
use AOP\Pointcut\Expression\Ast\OrNode;
use AOP\Pointcut\Expression\Ast\ParamListNode;
use AOP\Pointcut\Expression\Ast\ReferenceNode;
use AOP\Pointcut\Expression\Ast\ServiceNode;
use AOP\Pointcut\Expression\Ast\SetNode;
use AOP\Pointcut\Expression\Ast\TargetNode;
use AOP\Pointcut\Expression\Ast\ThisNode;
use AOP\Pointcut\Expression\Ast\TypePatternNode;
use AOP\Pointcut\Expression\Ast\WithincodeNode;
use AOP\Pointcut\Expression\Ast\WithinNode;
use AOP\Pointcut\GetPointcut;
use AOP\Pointcut\HandlerPointcut;
use AOP\Pointcut\IfPointcut;
use AOP\Pointcut\InitializationPointcut;
use AOP\Pointcut\NotPointcut;
use AOP\Pointcut\OrPointcut;
use AOP\Pointcut\Pattern\FieldPattern;
use AOP\Pointcut\Pattern\MethodPattern;
use AOP\Pointcut\Pattern\ModifierPattern;
use AOP\Pointcut\Pattern\NamePattern;
use AOP\Pointcut\Pattern\ParameterPattern;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\ServicePointcut;
use AOP\Pointcut\SetPointcut;
use AOP\Pointcut\TargetPointcut;
use AOP\Pointcut\ThisPointcut;
use AOP\Pointcut\WithincodePointcut;
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
     *
     * @throws PointcutSyntaxException
     * @throws UnsupportedPointcutDesignatorException
     * @throws BindingException                       when the branches of a `||` bind different names (whichever branch matches, every
     *                                                bound advice parameter needs a value) or a binding sits under `!`
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

        try {
            return $this->compileExpression($expression, $names, $declaringAspect, $resolver, []);
        } catch (BindingException $e) {
            // `||` branches binding different names, or a binding under `!`: say which expression it was
            throw new BindingException(sprintf('%s In pointcut "%s".', $e->getMessage(), $expression), 0, $e);
        }
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
            $node instanceof CflowNode => new CflowPointcut($this->build($node->inner, $names, $aspect, $resolver, $stack), $node->below),
            $node instanceof IfNode => $this->ifPointcut($node, $aspect),
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
            CallNode::class => 'call',
            WithincodeNode::class => 'withincode',
            AtWithincodeNode::class => 'atWithincode',
            AtThisNode::class => 'atThis',
            InitializationNode::class => 'initialization',
            AdviceExecutionNode::class => 'adviceExecution',
            HandlerNode::class => 'handler',
            GetNode::class => 'get',
            SetNode::class => 'set',
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
            new ModifierPattern($node->modifiers),
            null === $node->returnPattern ? null : TypePattern::fromNode($node->returnPattern),
            null === $node->declaringType ? null : TypePattern::fromNode($node->declaringType),
            new NamePattern($node->namePattern),
            ParameterPattern::fromNode($node->params),
        );
    }

    private function call(CallNode $node): PointcutInterface
    {
        return new CallPointcut(MethodPattern::fromNode($node->pattern));
    }

    private function withincode(WithincodeNode $node): PointcutInterface
    {
        return new WithincodePointcut(MethodPattern::fromNode($node->pattern));
    }

    private function initialization(InitializationNode $node): PointcutInterface
    {
        return new InitializationPointcut(new MethodPattern(
            new ModifierPattern($node->modifiers),
            null,
            TypePattern::fromNode($node->declaringType),
            new NamePattern('__construct'),
            ParameterPattern::fromNode($node->params),
        ));
    }

    private function adviceExecution(): PointcutInterface
    {
        return new AdviceExecutionPointcut();
    }

    private function handler(HandlerNode $node): PointcutInterface
    {
        return new HandlerPointcut(TypePattern::fromNode($node->type));
    }

    private function get(GetNode $node): PointcutInterface
    {
        return new GetPointcut($this->fieldPattern($node->field));
    }

    private function set(SetNode $node): PointcutInterface
    {
        return new SetPointcut($this->fieldPattern($node->field));
    }

    private function fieldPattern(FieldPatternNode $node): FieldPattern
    {
        return new FieldPattern(
            $node->modifiers,
            null === $node->type ? null : TypePattern::fromNode($node->type),
            TypePattern::fromNode($node->declaringType),
            new NamePattern($node->namePattern),
        );
    }

    /**
     * The short form `if(method())` calls a method of the aspect declaring the advice; in a named pointcut that is the
     * aspect declaring the named pointcut.
     */
    private function ifPointcut(IfNode $node, string $aspect): PointcutInterface
    {
        $class = $node->className ?? $aspect;
        if ('' === $class) {
            throw new PointcutSyntaxException(sprintf('if(%s()) needs the declaring aspect class to resolve the method; use if(Class::%s()) or compile it for an aspect.', $node->methodName, $node->methodName));
        }
        /* @var class-string $class */

        return new IfPointcut($class, $node->methodName);
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
    private function atWithincode(AtWithincodeNode $node, array $names): PointcutInterface
    {
        return new AtWithincodePointcut($this->subject($node->subject, BindingKind::AT_WITHINCODE, $names));
    }

    /**
     * @param array<string, string|null> $names
     */
    private function atThis(AtThisNode $node, array $names): PointcutInterface
    {
        return new AtThisPointcut($this->subject($node->subject, BindingKind::AT_THIS, $names));
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
