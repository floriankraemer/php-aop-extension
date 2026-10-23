# Weaver

`AOP\Weaver` is the entry point of the library.
It collects aspects, advisors and services, and it turns weaving on and off.
Everything else in the library is reached through it.

## Lifecycle

```php
$weaver = Weaver::fromAspects([new AuditAspect()]);
$weaver->enable();
```

- `fromAspects()` and `fromConfig()` create a Weaver, with its aspects registered.
  Nothing is woven yet.
- `enable()` starts weaving.
  Changes made after that take effect at once.
- `disable()` stops weaving.
  `isEnabled()` tells you the state.
- `freeze()` makes the advice configuration read-only.
  `isFrozen()` tells you whether it is.

A Weaver created without the extension loaded still works as a configuration object.
`enable()` then throws `AspectDefinitionException` and names `ext-aop`.

## Creating a Weaver

| Method | Purpose |
|---|---|
| `Weaver::fromAspects(array $aspects, ?EngineBridgeInterface $engine = null)` | Objects of `#[Aspect]` classes, in any order. |
| `Weaver::fromConfig(array $config, ?callable $aspectFactory = null, ?EngineBridgeInterface $engine = null)` | Aspects and advisors described as an array. See [configuration.md](configuration.md). |
| `new Weaver()` | An empty Weaver. Add everything afterwards. |

The `$engine` argument exists for tests.
Leave it out in application code.

## Aspects

| Method | Behaviour |
|---|---|
| `addAspect(object $aspect)` | Registers one aspect object. Throws `AspectDefinitionException` if the class is not an aspect or is registered already. |
| `removeAspect(string $aspectClass)` | Removes the aspect and its advice. Unknown classes are ignored. |
| `addConfig(ConfigResult $result)` | Registers the aspects and advisors of a configuration. |

```php
$weaver->addAspect(new CatalogAspect());
$weaver->addAdvisor((new DefaultPointcutAdvisor('execution(* Demo\Weaving\Reports::summary(..))', new ReportLogger(), 10, 'reports'))->toAdvisor());

echo 'advisors: '.implode(', ', array_map(static fn ($a): string => $a->id, $weaver->advisors()))."\n";

$weaver->enable();
echo (new Catalog())->find(1)."\n";
echo (new Reports())->summary()."\n";

echo "--- removeAspect and removeAdvisor\n";
$weaver->removeAspect(CatalogAspect::class);
```

[`examples/15-weaver-api.php`](../examples/15-weaver-api.php) shows these calls.

## Advisors

An advisor is a pointcut, the advice, and an order.
Aspect attributes produce advisors.
You can also add advisors yourself, with the Spring API (see [spring-api.md](spring-api.md)).

| Method | Behaviour |
|---|---|
| `addAdvisor(Advisor $advisor)` | Adds a programmatic advisor. Its id must be unique. |
| `removeAdvisor(string $id)` | Removes a programmatic advisor, or one advice of an aspect (its id is `AspectClass::method`). Unknown ids are ignored. |
| `advisors()` | All advisors, outermost first. |

```php
$weaver->addAdvisor((new DefaultPointcutAdvisor('execution(* Demo\Weaving\Reports::summary(..))', new ReportLogger(), 10, 'reports'))->toAdvisor());
```

`advisors()` lists them in the order they run, outermost first.
See [advice.md](advice.md#order) for how that order is set.

## Per-object advice

`advised(object $instance)` returns an `AOP\Runtime\AdvisedInstance`.
Advice added there applies to that one object only.

| Method | Behaviour |
|---|---|
| `addAdvisor(Advisor $advisor)` | Adds advice for this object. |
| `removeAdvisor(string $id)` | Removes it. |
| `advisors()` | The advisors of this object. |
| `instance()` | The object. |
| `freeze()`, `isFrozen()` | Makes the object's advice read-only. |

```php
$advised = $weaver->advised($alice);
$advised->addAdvisor((new DefaultPointcutAdvisor('execution(* Demo\PerInstance\Mailbox::deliver(..))', new Announce('watch'), 0, 'watch-alice'))->toAdvisor());
```

Other objects of the same class are not affected.
[`examples/16-advised-instance.php`](../examples/16-advised-instance.php) shows this.

## Services

A service is a name for an object or a class.
The `service()` designator matches on the names.

| Method | Behaviour |
|---|---|
| `registerService(object $instance, string $serviceName)` | The name applies to this object. |
| `registerServiceClass(string $class, string $serviceName)` | The name applies to every object of the class, and its subclasses. |

```php
$archive = new OrderRepository();
$weaver->registerService($archive, 'orders.repository');
$weaver->registerServiceClass(Mailer::class, 'mailer');
```

[`examples/17-services.php`](../examples/17-services.php) matches both.

## Include and exclude

Include and exclude rules restrict weaving to some types or functions.

| Method | Behaviour |
|---|---|
| `include(string $typePattern)` | Only matching types are woven. Without any include rule, every type is woven. |
| `exclude(string $typePattern)` | Matching types are never woven. An exclude rule wins over an include rule. |

Type patterns follow the rules in [pointcuts.md](pointcuts.md#type-patterns).
A trailing `+` includes subtypes.
Functions are matched by their qualified name.

```php
$weaver->include('Demo\Weaving\*');
$weaver->exclude('Demo\Weaving\Catalog');
```

An include rule also keeps a broad pattern away from internal functions.
Calls made during class autoloading are never woven (see [limitations.md](limitations.md#class-loading-is-not-woven)), but a pattern such as `execution(* *(..))` still advises every function your code calls.
Restrict such patterns to your own namespaces.

## Freezing

`freeze()` makes the advice configuration read-only.
After that, `addAspect()`, `removeAspect()`, `addAdvisor()`, `removeAdvisor()`, `registerService()`, `registerServiceClass()`, `include()`, `exclude()` and `addConfig()` throw `AOP\Exception\WeaverFrozenException`.
`enable()` and `disable()` still work.

Freeze the Weaver at the end of the bootstrap, once the advice is final.

## Compiler

`compiler()` returns the `PointcutExpressionCompiler`.
It turns an expression into a pointcut object, for example in a test.
See [pointcuts.md](pointcuts.md#compiling-expressions-directly).

## Targets and configurable objects

| Method | Behaviour |
|---|---|
| `targetSource(string $class, TargetSourceInterface $source): object` | Returns a stand-in for `$class` whose calls go to the source's target. See [target-sources.md](target-sources.md). |
| `configurable(callable $configurer)` | Configures objects of `#[Configurable]` classes. See [configuration.md](configuration.md#configurable). |

## Introductions

`introductionRules()` returns the `#[DeclareParents]` rules of the registered aspects.
Introductions are experimental.
This build does not apply them (see [limitations.md](limitations.md#experimental-features)).

## Errors

| Exception | When |
|---|---|
| `AspectDefinitionException` | A class is not an aspect, an aspect is registered twice, an advisor id is taken, or a declaration is invalid. |
| `WeaverFrozenException` | A change is made after `freeze()`. |
| `PointcutSyntaxException`, `BindingException`, `UnsupportedPointcutDesignatorException` | An expression is invalid. See [pointcuts.md](pointcuts.md#errors). |
| `ConfigurationException` | The configuration array is invalid. See [configuration.md](configuration.md). |
