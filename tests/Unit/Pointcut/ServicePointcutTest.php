<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\ServicePointcut;
use AOP\Pointcut\StaticMatchContext;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * service() selects the registered service (Weaver::registerService()) the join point belongs to; it was called bean()
 * in the proxy-based predecessor.
 */
final class ServicePointcutTest extends TestCase
{
    private function forService(?string $name, JoinPointKind $kind = JoinPointKind::METHOD_EXECUTION): StaticMatchContext
    {
        return new StaticMatchContext($kind, new \ReflectionClass(Callee::class), new \ReflectionMethod(Callee::class, 'find'), serviceName: $name);
    }

    #[Test]
    public function serviceMatchesTheRegisteredServiceName(): void
    {
        $pointcut = Shadow::compile('service(app.user_service)');

        self::assertInstanceOf(ServicePointcut::class, $pointcut);
        self::assertTrue(Shadow::matches($pointcut, $this->forService('app.user_service')));
        self::assertFalse(Shadow::matches($pointcut, $this->forService('app.other')));
        self::assertFalse(Shadow::matches($pointcut, $this->forService(null)), 'not a registered service');
    }

    #[Test]
    public function serviceSupportsWildcardsDotsAndDashes(): void
    {
        self::assertTrue(Shadow::matches(Shadow::compile('service(app.*)'), $this->forService('app.user-service')));
        self::assertTrue(Shadow::matches(Shadow::compile('service(*_service)'), $this->forService('user_service')));
        self::assertFalse(Shadow::matches(Shadow::compile('service(app.*)'), $this->forService('other.app.x')));
        self::assertTrue(Shadow::matches(Shadow::compile('service(App\Service\*)'), $this->forService('App\Service\Greeter')), 'autowired services are named by their FQCN');
    }

    #[Test]
    public function serviceAppliesToEveryKindOfTheService(): void
    {
        $pointcut = Shadow::compile('service(svc)');
        foreach ([JoinPointKind::METHOD_EXECUTION, JoinPointKind::METHOD_CALL, JoinPointKind::CONSTRUCTOR_EXECUTION, JoinPointKind::INITIALIZATION] as $kind) {
            self::assertTrue(Shadow::matches($pointcut, $this->forService('svc', $kind)), $kind->value);
        }
        $field = new StaticMatchContext(JoinPointKind::FIELD_GET, new \ReflectionClass(Callee::class), null, new \ReflectionProperty(Callee::class, 'count'), serviceName: 'svc');
        self::assertTrue(Shadow::matches($pointcut, $field));
    }

    #[Test]
    public function serviceIsStaticNotCallerSensitiveAndBindsNothing(): void
    {
        $pointcut = Shadow::compile('service(svc)');

        self::assertFalse($pointcut->isRuntime());
        self::assertFalse($pointcut->isCallerSensitive());
        self::assertSame([], $pointcut->bindings());
    }

    #[Test]
    public function serviceCombinesWithExecution(): void
    {
        $pointcut = Shadow::compile('service(svc) && execution(* *::find(..))');

        self::assertFalse(Shadow::matches($pointcut, $this->forService('svc')), 'the declaring type `*` has no namespace');
        self::assertTrue(Shadow::matches(Shadow::compile('service(svc) && execution(* **::find(..))'), $this->forService('svc')));
        self::assertFalse(Shadow::matches(Shadow::compile('service(svc) && execution(* **::find(..))'), $this->forService('x')));
    }
}
