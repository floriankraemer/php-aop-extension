<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Advisor;

use AOP\Advice\AdviceType;
use AOP\Advisor\AdvisorFactory;
use AOP\Advisor\MetadataNamedPointcutResolver;
use AOP\Aspect\AspectMetadata;
use AOP\Aspect\AspectMetadataReader;
use AOP\Aspect\IntroductionDefinition;
use AOP\Exception\PointcutSyntaxException;
use AOP\Tests\Fixtures\Aspect\CrossReferenceAspect;
use AOP\Tests\Fixtures\Aspect\IntroductionOnlyAspect;
use AOP\Tests\Fixtures\Aspect\NamedPointcutAspect;
use AOP\Tests\Fixtures\Aspect\RecordingAspectA;
use AOP\Tests\Fixtures\Introduction\Auditable;
use AOP\Tests\Fixtures\Introduction\AuditTrail;
use AOP\Tests\Fixtures\Introduction\BareTarget;
use AOP\Tests\Fixtures\Introduction\FinalWorkTarget;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Pointcut\Reflect;
use AOP\Tests\Fixtures\Service\Greeter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdvisorFactoryTest extends TestCase
{
    #[Test]
    public function buildsIntroductionAdvisorsWithTypePatternAndOrder(): void
    {
        $meta = new AspectMetadata(IntroductionOnlyAspect::class, 7, [], [], [
            new IntroductionDefinition('AOP\Tests\Fixtures\Introduction\WorkInterface+', Auditable::class, AuditTrail::class),
        ]);

        $introductions = (new AdvisorFactory())->introductionsFromMetadata($meta);

        self::assertCount(1, $introductions);
        self::assertSame(IntroductionOnlyAspect::class, $introductions[0]->aspectClass);
        self::assertSame(7, $introductions[0]->order);
        self::assertSame(Auditable::class, $introductions[0]->interface);
        self::assertSame(AuditTrail::class, $introductions[0]->defaultImpl);
        self::assertTrue($introductions[0]->typePattern->matchesType(Reflect::cls(FinalWorkTarget::class)), 'a trailing + includes subtypes');
        self::assertFalse($introductions[0]->typePattern->matchesType(Reflect::cls(BareTarget::class)));
    }

    #[Test]
    public function buildsOneAdvisorPerAdviceWithIdOrderAndPointcut(): void
    {
        $reader = new AspectMetadataReader();
        $meta = $reader->read(RecordingAspectA::class);

        $advisors = (new AdvisorFactory())->fromMetadata($meta, new MetadataNamedPointcutResolver([$meta]));

        self::assertCount(5, $advisors);
        self::assertSame(RecordingAspectA::class.'::around', $advisors[0]->id);
        self::assertSame(AdviceType::AROUND, $advisors[0]->advice?->type);
        self::assertSame(1, $advisors[0]->order);

        $class = Reflect::cls(Greeter::class);
        $ctx = Jp::ctx(Greeter::class, 'greet');
        self::assertTrue($advisors[0]->pointcut->matchesClass($class, $ctx));
        self::assertTrue($advisors[0]->pointcut->matchesMethod($class->getMethod('greet'), $ctx));
    }

    #[Test]
    public function resolvesLocalNamedPointcutAndKeepsBindingsRuntime(): void
    {
        $meta = (new AspectMetadataReader())->read(NamedPointcutAspect::class);
        $advisors = (new AdvisorFactory())->fromMetadata($meta, new MetadataNamedPointcutResolver([$meta]));

        self::assertCount(2, $advisors);
        self::assertFalse($advisors[0]->pointcut->isRuntime());
        self::assertTrue($advisors[1]->pointcut->isRuntime());
        self::assertArrayHasKey('name', $advisors[1]->pointcut->bindings());
    }

    #[Test]
    public function resolvesReferenceToOtherAspectReadingItOnDemand(): void
    {
        $meta = (new AspectMetadataReader())->read(CrossReferenceAspect::class);
        $resolver = new MetadataNamedPointcutResolver([$meta]);

        self::assertSame(
            'execution(* **::greet(..))',
            $resolver->resolve(NamedPointcutAspect::class, 'greets', CrossReferenceAspect::class),
        );
    }

    #[Test]
    public function usesRegisteredMetadataForClassReference(): void
    {
        $reader = new AspectMetadataReader();
        $resolver = new MetadataNamedPointcutResolver([$reader->read(NamedPointcutAspect::class)]);

        self::assertSame('execution(* **::greet(..))', $resolver->resolve(null, 'greets', NamedPointcutAspect::class));
    }

    #[Test]
    public function unknownNamedPointcutThrows(): void
    {
        $meta = (new AspectMetadataReader())->read(CrossReferenceAspect::class);

        $this->expectException(PointcutSyntaxException::class);
        (new AdvisorFactory())->fromMetadata($meta, new MetadataNamedPointcutResolver([$meta]));
    }

    #[Test]
    public function unknownClassReferenceThrows(): void
    {
        $this->expectException(PointcutSyntaxException::class);
        (new MetadataNamedPointcutResolver([]))->resolve('Not\\An\\Aspect', 'x', 'Any');
    }

    #[Test]
    public function unknownLocalReferenceThrows(): void
    {
        $this->expectException(PointcutSyntaxException::class);
        (new MetadataNamedPointcutResolver([]))->resolve(null, 'x', 'Any');
    }
}
