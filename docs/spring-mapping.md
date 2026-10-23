# Spring and AspectJ mapping

This page maps the Spring AOP and AspectJ features to this library.
Use it when you port an aspect, or when you look for the equivalent of a Spring class.

The mapping is by meaning.
Names change where PHP or the design needs it.
The status column says whether the feature is supported in this build.

## Aspects and advice

| Spring / AspectJ | This library | Status |
|---|---|---|
| `@Aspect` | `#[Aspect]` (`AOP\Attribute\Aspect`) | Supported. |
| `@Before`, `@After`, `@Around`, `@AfterReturning`, `@AfterThrowing` | `#[Before]`, `#[After]`, `#[Around]`, `#[AfterReturning]`, `#[AfterThrowing]` | Supported. |
| `@Pointcut` | `#[Pointcut]`, named pointcuts called as `name()` | Supported. |
| `@Order`, `Ordered` | `#[Order]`, `AOP\Advisor\Ordered` | Supported. |
| AspectJ `declare precedence` | `#[DeclarePrecedence('A, B, *')]` | Supported. |
| AspectJ `declare warning`, `declare error` | `#[DeclareWarning]`, `#[DeclareError]` | Supported. |
| AspectJ `declare parents` (`@DeclareParents`) | `#[DeclareParents]` | Supported, compile-time: see [limitations](limitations.md#introductions). |
| `perthis`, `pertarget` | `#[Aspect(scope: AspectScope::PER_THIS)]`, `PER_TARGET` | Supported. |
| AspectJ `percflow`, `percflowbelow`, `pertypewithin` | `AspectScope::PER_CFLOW`, `PER_CFLOW_BELOW`, `PER_TYPE_WITHIN` | Supported. |
| `ThrowsAdvice` | `AOP\Advice\Api\ThrowsAdviceInterface` | Supported. |

## Pointcut language

| Spring / AspectJ | This library | Status |
|---|---|---|
| `execution`, `within`, `this`, `target`, `args` | Same names | Supported. |
| `@annotation`, `@within`, `@target`, `@args`, `@this` | Same names | Supported. |
| Spring `bean(name)` | `service(name)` | Supported. "service" replaces "bean" everywhere. |
| AspectJ `call`, `withincode`, `@withincode` | Same names | Supported. |
| AspectJ `cflow`, `cflowbelow` | Same names | Supported. |
| AspectJ `if()` | `if(C::m())` | Supported. |
| AspectJ `initialization` | `initialization()` | Supported, for constructors and for classes without a constructor. |
| AspectJ `adviceexecution()` | `adviceexecution()` | Supported. |
| AspectJ `handler`, `get`, `set` | Same names | Supported. Fields are checked on PHP 8.3; PHP 8.4 and 8.5 are in progress. See [limitations](limitations.md#fields-handlers-initialization-and-introductions). |
| AspectJ `preinitialization`, `staticinitialization` | Rejected with `UnsupportedPointcutDesignatorException` | Not applicable, see below. |
| `ControlFlowPointcut` (Spring API) | `AOP\Pointcut\Api\ControlFlowPointcut` | Supported. Execution-only and control-flow aware like `cflow()`, see [spring-api.md](spring-api.md#control-flow). |

## The Spring AOP API

| Spring | This library | Status |
|---|---|---|
| `ProxyFactory`, `AspectJProxyFactory` | `Weaver::fromAspects()`, `Weaver::fromConfig()`, `enable()` | Supported. There is no proxy. |
| `Advised` (add, remove, freeze advisors) | `Weaver::addAdvisor()`, `removeAdvisor()`, `freeze()` | Supported. |
| Per-object advice (`Advised` on one proxy) | `Weaver::advised($object)` | Supported. |
| `AopContext.currentProxy()` | `AopContext::currentObject()`, `AopContext::currentJoinPoint()` | Supported. There is no proxy to expose. |
| `MethodInterceptor`, `MethodBeforeAdvice`, `AfterReturningAdvice`, `ThrowsAdvice`, `IntroductionInterceptor` | The interfaces in `AOP\Advice\Api` | Supported. |
| `DefaultPointcutAdvisor`, `RegexpMethodPointcutAdvisor`, `NameMatchMethodPointcutAdvisor` | The classes in `AOP\Advisor\Api` | Supported. |
| `DefaultIntroductionAdvisor` | `AOP\Advisor\Api\DefaultIntroductionAdvisor` | Routes calls only; it does not add the interface. Use `#[DeclareParents]` to introduce one. |
| `Pointcut`, `ClassFilter`, `MethodMatcher`, `StaticMethodMatcherPointcut`, `DynamicMethodMatcherPointcut` | `AOP\Pointcut\Api\*` | Supported. |
| `ComposablePointcut`, `Pointcuts` | `AOP\Pointcut\Api\ComposablePointcut`, `Pointcuts` | Supported. |
| `RegexpMethodPointcut`, `NameMatchMethodPointcut`, `AttributeMatchingPointcut` | Same names in `AOP\Pointcut\Api` | Supported. A bare expression matches the whole name; a delimited PCRE (`/Catalog::save/i`) is used as written. |
| `AdvisorAdapterRegistry`, `AdvisorAdapter` | `AOP\Advisor\Api\AdvisorAdapterRegistry`, `AdvisorAdapterInterface` | Supported. |

## Target sources

| Spring | This library | Status |
|---|---|---|
| `SingletonTargetSource` | `SingletonTargetSource` | Supported. |
| `HotSwappableTargetSource` | `HotSwappableTargetSource` | Supported. |
| `PrototypeTargetSource` | `PrototypeTargetSource` | Supported. |
| `CommonsPool2TargetSource` | `PoolingTargetSource` | Supported. |
| `ThreadLocalTargetSource` | `FiberLocalTargetSource` | Supported. Fibers replace threads. |
| `AbstractBeanFactoryBasedTargetSource` | Not needed | Not applicable. Use a factory closure. |

## Configuration

| Spring | This library | Status |
|---|---|---|
| Schema-based XML (`<aop:config>`, `<aop:aspect>`, `<aop:advisor>`) | The array for `Weaver::fromConfig()` | Supported. See [configuration.md](configuration.md). |
| `@Configurable` | `#[Configurable]` with `Weaver::configurable()` | Supported for constructors. |
| Load-time weaving (LTW) | Weaving happens in the engine, at call time | Different design. There is no class file transformation. |
| `aop.xml` include and exclude | `Weaver::include()`, `Weaver::exclude()` | Supported. |
| Auto-proxy creators (`@EnableAspectJAutoProxy`) | Not needed. Register the aspects directly, or select services with `service()` | Not applicable. |

## Not applicable

| Feature | Why it does not apply |
|---|---|
| `preinitialization()`, `staticinitialization()` | PHP has no moment that matches them. Constructor arguments are evaluated before the constructor is entered, and PHP has no static initializers that run in a fixed order. The compiler rejects both with a message that suggests `execution()` or `initialization()`. |
| Proxy restrictions: final classes, private and static methods, self-invocation | They come from proxy generation. Weaving in the engine has none of them, so these are supported and not restricted. |
| `AopContext.currentProxy()` as a proxy | There is no proxy object. The current join point and object are available instead. |
| `exposeProxy` | Same reason: there is no proxy to expose. |
| Bean lifecycle, container integration | This library has no container. Use the factory callbacks in the configuration and in `configurable()`. |
| `@Transactional`, `@Cacheable` and similar annotation-driven features | These belong to Spring's framework. Write an aspect for the behaviour you need, and match it with `@annotation()`. |

## Where the behaviour differs

These are not missing features, but the result can surprise someone who knows the Spring version.

- A method pattern matches overrides through the type that declares the method, like AspectJ.
  Constructors are not overridden: `execution(* Base::__construct(..))` matches the constructor declared by `Base`, `Base+` also the subclasses'.
  Use `initialization()` for the outermost constructor.
- A bare `RegexpMethodPointcut` pattern matches the whole name; a delimited one is used as written.
- This library advises every method, including private and static ones.
  Restrict the advice with a pattern when you want only some methods.
