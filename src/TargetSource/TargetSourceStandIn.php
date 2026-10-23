<?php

declare(strict_types=1);

namespace AOP\TargetSource;

use AOP\Advisor\Advisor;

/**
 * Brings Spring's TargetSource to a proxy-less weaver.
 *
 * There is no proxy object to swap targets behind, so the caller holds a *stand-in*: an instance of the class,
 * created without running its constructor (final classes work), whose methods never run their own code. The
 * returned advisor reroutes every instance method execution on exactly that stand-in to `$source->getTarget()`
 * and releases the target afterwards. Attach it to the stand-in only:
 *
 *     [$standIn, $advisor] = TargetSourceStandIn::create(Service::class, $source);
 *     $weaver->advised($standIn)->addAdvisor($advisor);   // per-instance advisors, contract section 5
 *     $standIn->work();                                   // runs Service::work() on $source->getTarget()
 *
 * The advisor sorts after every other advisor of the same order (its id starts with "~~"), so other advice wraps the
 * rerouting. Static methods, constructors and destructors are not rerouted. A target returning itself yields the
 * stand-in, so fluent APIs keep working.
 */
final class TargetSourceStandIn
{
    private static int $sequence = 0;

    private function __construct()
    {
    }

    /**
     * @param class-string $class the class of the stand-in: the target class or one of its supertypes
     *
     * @return array{0: object, 1: Advisor}
     *
     * @throws TargetSourceException when the class cannot be instantiated, or the source's targets are not of that class
     */
    public static function create(string $class, TargetSourceInterface $source): array
    {
        if (!class_exists($class)) {
            throw new TargetSourceException(sprintf('Cannot create a stand-in for "%s": it is not a class (interfaces and enums have no instances).', $class));
        }
        $reflection = new \ReflectionClass($class);
        if ($reflection->isAbstract()) {
            throw new TargetSourceException(sprintf('Cannot create a stand-in for abstract class "%s".', $class));
        }
        if (!is_a($source->getTargetClass(), $class, true)) {
            throw new TargetSourceException(sprintf('The target source provides %s, which is not a %s.', $source->getTargetClass(), $class));
        }
        try {
            $standIn = $reflection->newInstanceWithoutConstructor();
        } catch (\ReflectionException $e) {
            throw new TargetSourceException(sprintf('Cannot create a stand-in for "%s": %s', $class, $e->getMessage()), 0, $e);
        }

        $advisor = new Advisor(
            sprintf('~~target-source:%s#%d', $class, ++self::$sequence),
            null,
            PHP_INT_MAX,
            new StandInPointcut($standIn),
            new TargetSourceInterceptor($source),
        );

        return [$standIn, $advisor];
    }
}
