# Configuration

Aspects and advisors can be described in an array, without attributes.
The shape follows Spring's XML schema: `<aop:config>`, `<aop:pointcut>`, `<aop:aspect>` and `<aop:advisor>`.
Use it when the aspect classes should stay free of attributes, or when the wiring should live in one place.

The library reads only the array.
If you keep the configuration in XML or YAML, parse it into an array first.

## Example

[`examples/21-configuration.php`](../examples/21-configuration.php) is a complete script.
The aspect class has no attributes:

```php
// A plain class: no attributes, the configuration says what it advises.
final class AuditLog
{
    public function before(JoinPointInterface $jp): void
    {
        echo "audit: {$jp->getSignature()->toShortString()}\n";
    }

    public function around(\AOP\JoinPoint\ProceedingJoinPointInterface $pjp, string $item, int $quantity): mixed
    {
        echo "audit: $quantity x $item\n";

        return $pjp->proceed();
    }
}
```

The configuration array names the pointcut, the aspect and its advice:

```php
$config = [
    'pointcuts' => [
        'order_operations' => 'execution(* Demo\Config\Orders::*(..))',
    ],
    'aspects' => [
        [
            'id' => 'audit',
            'class' => AuditLog::class,
            'order' => 10,
            'advice' => [
                ['type' => 'before', 'pointcut-ref' => 'order_operations', 'method' => 'before'],
                ['type' => 'around', 'pointcut' => 'execution(* Demo\Config\Orders::place(..)) && args(item, quantity)', 'method' => 'around', 'arg-names' => ['item', 'quantity']],
            ],
        ],
    ],
];
```

The factory is optional.
Here it creates the aspect objects.

Its output shows the around advice wrapping the before advice, and the aspect being removed again:

```text
audit: 2 x book
audit: Orders::place(..)
placed 2 x book
audit: Orders::cancel(..)
cancelled book
--- removeAspect
placed 5 x pen
```

## Keys

### Top level

| Key | Meaning |
|---|---|
| `pointcuts` | Named expressions, shared by all aspects and advisors. |
| `aspects` | A list of aspect entries. |
| `advisors` | A list of advisor entries. |

Unknown top-level keys are rejected.

### Pointcuts

| Key | Meaning |
|---|---|
| `pointcuts` | Named expressions. Names are letters, digits and underscores. |
| `aspects[].pointcuts` | Names local to one aspect. They may override a shared name. |
| `pointcut-ref` | A name from `pointcuts`, or `Some\Aspect::name` for a named pointcut of another aspect. |
| `pointcut` | An expression written in place. |

### Aspects

| Key | Meaning |
|---|---|
| `class` | The aspect class. Created by the factory, or with `new` when there is no factory. |
| `ref` | An identifier that the factory turns into an object. Requires a factory. |
| `instance` | An object that is used as it is. |
| `id` | Optional name, used in messages. |
| `order` | The order between aspects. Default `PHP_INT_MAX`, lower runs first. |
| `scope` | `singleton`, `pertarget` or `perthis`. |
| `advice` | The advice list. |
| `declare-parents` | Introductions (experimental, see below). |

Exactly one of `class`, `ref` and `instance` must be given.
Each aspect class can be configured once.
A class with `#[Aspect]` is rejected here; give it to `Weaver::fromAspects()` instead.

### Advice

| Key | Meaning |
|---|---|
| `type` | `before`, `after`, `after-returning`, `after-throwing` or `around`. |
| `method` | The method of the aspect. Required. |
| `returning` | The name of the return value parameter. Only for `after-returning`. |
| `throwing` | The name of the exception parameter. Only for `after-throwing`. |
| `arg-names` | Optional. Each name must be a parameter of the method. |

`arg-names` only checks the parameters.
The values come from the pointcut's `args()`.

### Advisors

| Key | Meaning |
|---|---|
| `advice` | Any object that an advisor adapter supports, such as a Spring-style interceptor. |
| `advice-ref` | The identifier of the advice object, resolved with the factory. |
| `pointcut` or `pointcut-ref` | The pointcut. |
| `id`, `order` | As for aspects. |

### Errors

A wrong entry throws `AOP\Config\ConfigurationException`.
The message starts with the path of the entry, for example `aop.aspects[0].advice[0]`.
Some messages seen in this build:

- `"method" (the advice method of App\AuditAspect) is required.`
- `unknown key(s) "bogus"; allowed: type, pointcut, pointcut-ref, method, returning, throwing, arg-names.`
- `"returning" is only valid for after-returning advice, this is before.`
- `give exactly one of "class", "ref" and "instance" (found ref, instance).`
- `"ref" => "svc" needs an $aspectFactory to be resolved.`

## Factories

The factory is a `callable(string): object`.
It receives the class name, or the `ref` identifier, and returns the object.
Without a factory, `class` is created with `new`, so its constructor must take no required arguments.

`ConfigLoader::load()` returns a `ConfigResult` without weaving.
It is the same step that `Weaver::fromConfig()` performs first.
Pass a `ConfigResult` to `Weaver::addConfig()` when you want to inspect it before registering.

## Removing aspects

`removeAspect(class)` removes an aspect that came from a configuration, and its advisors (see [weaver.md](weaver.md)).

## Introductions

`declare-parents` is the array form of `#[DeclareParents]`.
Introductions are experimental, and this build does not apply them (see [limitations.md](limitations.md#experimental-features)).

## `#[Configurable]`

Some objects are created with `new`, but a container should configure them.
`#[Configurable]` marks such a class.
A configurer runs for each new object of the class.

```php
// Objects of this class are created with `new`, but a container configures them.
#[Configurable(service: 'mailer')]
final class Mailer
{
    public string $transport = 'not configured';

    public function __construct()
    {
        // The configurer runs after this constructor (the outermost one), so it can see the injected values.
    }

    public function send(string $to): string
    {
        return "sent to $to via {$this->transport}";
    }
}

$weaver = Weaver::fromAspects([]);
$weaver->configurable(static function (object $object, Configurable $attribute): void {
    // A real application looks the service up in its container here.
    $object->transport = "SMTP service '{$attribute->service}'";
});
$weaver->enable();

$mailer = new Mailer();
echo $mailer->send('ops@example.test')."\n";
```

[`examples/22-configurable.php`](../examples/22-configurable.php) runs this code.
Its output shows the configured value:

```text
sent to ops@example.test via SMTP service 'mailer'
```

The configurer receives the new object and the attribute.
The attribute's options are:

| Option | Default | Effect |
|---|---|---|
| `service` | the class name | Passed to the configurer, which decides how to find the service. |
| `autowire` | `false` | Passed to the configurer, which decides what it means. |
| `preConstruction` | `false` | Runs the configurer before the constructor, so the constructor can use the injected values. |

Without `preConstruction`, the configurer runs after the outermost constructor.
A `parent::__construct()` call is not outermost.

A class without a constructor is configured through the `initialization` join point, which is experimental.
This build does not emit that join point, so such a class is not configured yet (see [limitations.md](limitations.md#experimental-features)).
