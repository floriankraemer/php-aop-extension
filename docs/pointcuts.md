# Pointcut expressions

A pointcut selects join points.
You write it as a string in `#[Before(...)]`, `#[Around(...)]` and the other advice attributes, or in `#[Pointcut(...)]`.
The same language is used by the configuration array and by `Weaver::fromAspects()`.

Every example on this page compiles with the extension loaded.
The runnable scripts are listed in [examples/README.md](../examples/README.md).

## Grammar

```ebnf
pointcut        = orExpr ;
orExpr          = andExpr , { "||" , andExpr } ;
andExpr         = unary , { "&&" , unary } ;
unary           = [ "!" ] , primary ;
primary         = "(" , pointcut , ")" | designator | namedPointcut ;
namedPointcut   = [ className "::" ] identifier "(" ")" ;

designator      = "execution" "(" methodPattern ")"
                | "call" "(" methodPattern ")"
                | "within" "(" typePattern ")"
                | "withincode" "(" methodPattern ")"
                | "this" "(" ( typePattern | binding ) ")"
                | "target" "(" ( typePattern | binding ) ")"
                | "args" "(" argList ")"
                | "@annotation" "(" ( attributeName | binding ) ")"
                | "@within" "(" ( attributeName | binding ) ")"
                | "@target" "(" ( attributeName | binding ) ")"
                | "@args" "(" ( attributeName | binding ) ")"
                | "@this" "(" ( attributeName | binding ) ")"
                | "@withincode" "(" ( attributeName | binding ) ")"
                | "cflow" "(" pointcut ")"
                | "cflowbelow" "(" pointcut ")"
                | "if" "(" [ className "::" ] identifier "(" ")" ")"
                | "initialization" "(" ( ctorPattern | typePattern [ "+" ] ) ")"
                | "adviceexecution" "(" ")"
                | "service" "(" serviceName ")"
                | "handler" "(" typePattern [ "+" ] ")"                 (* experimental *)
                | "get" "(" fieldPattern ")"                            (* experimental *)
                | "set" "(" fieldPattern ")"                            (* experimental *)
                | "preinitialization" "(" ... ")"                       (* raises an error *)
                | "staticinitialization" "(" ... ")" ;                  (* raises an error *)

methodPattern   = { methodModifier } [ returnType ] [ typePattern [ "+" ] "::" ] namePattern "(" paramList ")"
                | { methodModifier } typePattern [ "+" ] "::__construct" "(" paramList ")" ;
ctorPattern     = { methodModifier } typePattern [ "+" ] "::__construct" "(" paramList ")" ;
methodModifier  = [ "!" ] ( "public" | "protected" | "private" | "static" | "final" | "abstract" ) ;
fieldPattern    = { fieldModifier } [ [ "?" ] typePattern [ "+" ] ] typePattern [ "+" ] "::" "$" namePattern ;
fieldModifier   = [ "!" ] ( "public" | "protected" | "private" | "static" | "readonly" ) ;
```

Points that the grammar does not show:

- Operators: `&&` binds tighter than `||`, and `!` applies to one primary.
  Use parentheses to group.
- Every branch of `||` must bind the same names, because whichever branch matches, each bound advice parameter needs a value.
  `args(a) || execution(* App\X::m(..))` with `a` as an advice parameter throws a `BindingException` that names the expression and the differing names.
- Keywords are recognised only when followed by `(`.
  A class or function named `args` or `this` can still appear as a name.
- `call`, `withincode` and the `@`-designators are documented in their own sections below.

## Type patterns

A type pattern names a class or interface, with wildcards.

| Pattern | Matches |
|---|---|
| `App\Service\UserService` | Exactly this class or interface. |
| `App\Service\*` | Any class directly in `App\Service`, not in sub-namespaces. |
| `App\Service\**` | Any class in `App\Service` or in any sub-namespace. |
| `App\Service\User*` | Classes whose short name starts with `User`. |
| `App\Service\Base+` | `Base` and every subtype, including interface implementations. |

`*` never crosses a `\`.
Use `**` for that.
Verified: `Demo\Sem\Svc\*` matches a class in `Demo\Sem\Svc`, and does not match a class in `Demo\Sem\Svc\Inner`.
`Demo\Sem\Svc\**` matches both.

## Method patterns

The pattern for `execution()` and `call()` has the form:

```text
execution( [modifiers] [returnType] [declaringType[+]::]name(params) )
```

| Part | Accepted | Notes |
|---|---|---|
| Modifiers | any of `public`, `protected`, `private`, `static`, `final`, `abstract`, each optionally negated with `!`; none = any | All listed modifiers must hold. An unknown word raises a `PointcutSyntaxException` that names it. |
| Return type | `*`, `void`, `int`, `?string`, … | `*` matches any return type, including none. |
| Declaring type | a type pattern, optionally `+` | Omitted for functions. |
| Name | a name pattern with `*` | `__construct` names a constructor. |
| Parameters | see below | |

```text
execution(public * App\Service\*::*(..))           // public methods of App\Service classes
execution(protected ?string App\Base::format(..))  // protected methods returning ?string
execution(* App\Service\**::save*(..))             // save, save_all, ... in any App\Service sub-namespace
```

```text
execution(private static * App\Repo\**::*(..))     // private static methods
execution(!static * *(..))                         // every non-static method and every function
execution(public !final * App\Service\*::*(..))    // public methods that are not final
```

`execution(* App\Repo::*(..))` without modifiers advises every method of the class, whatever its visibility.
A modifier word is only a modifier when the rest of the pattern follows it; a method named `final` is still written `execution(* final(..))`.
The modifiers also work in `call()`, `withincode()` and `initialization()`, and `!` works in field patterns (`get(!static * App\Cfg::$*)`).

A plain function counts as public, non-static, non-final and non-abstract: `execution(public * App\Util\fmt(..))` and `execution(!static * *(..))` match it, `execution(static * *(..))` does not.

Method patterns and the declaring type:

- A type in a method pattern matches the runtime class of the object, and the classes in its hierarchy that declare the method.
  For example, `execution(* App\Base::run(..))` also matches an override `run()` declared in `App\Child`, because the signature is declared by `App\Base`.
  That is AspectJ's rule. Write `App\Child::run` to select the override only.
- Constructors are the exception: they are not overridden, see below.

### Parameter lists

| Pattern | Matches |
|---|---|
| `()` | No parameters. |
| `(..)` | Any parameters, including none. |
| `(int, string)` | Exactly an `int` followed by a `string`. |
| `(int, ..)` | An `int`, then any other parameters. |
| `(*)` | Exactly one parameter of any type. |
| `(*, string)` | Two parameters, the second a `string`. |

### Function patterns

A function pattern has no declaring type.
It is the qualified name of the function.
Write the qualified name.
A leading backslash is allowed.

```text
execution(* App\Util\format*(..))     // functions in App\Util whose name starts with format
execution(* str_repeat(..))           // the internal function str_repeat
execution(* App\Util\hash(string))    // exactly one string parameter
```

A method pattern never matches a function, and a function pattern never matches a method.

### Constructors

Constructors use the name `__construct`.
The return type is omitted.

```text
execution(* App\Connection::__construct(..))       // every constructor execution of App\Connection
initialization(App\Connection+)                    // only the outermost constructor of a Connection
```

A constructor pattern matches the constructor declared by the named type, like in AspectJ, and not the constructors of its subclasses:

- `execution(* App\Base::__construct(..))` matches the execution of the constructor declared by `App\Base`.
  A subclass with its own constructor is not matched.
  A subclass without one runs `Base::__construct`, so it is matched; so is the `parent::__construct()` call of a subclass constructor, once per call.
- `execution(* App\Base+::__construct(..))` also matches the constructors the subclasses declare.
- `call()`, `withincode()` and `initialization()` follow the same rule.

Use `initialization()` when you want one advice call per `new`.
[`examples/04-constructors.php`](../examples/04-constructors.php) shows the difference.

## Designators

| Designator | Matches | Binds | Example |
|---|---|---|---|
| `execution(P)` | `method-execution`, `constructor-execution`, `function-execution` | - | `execution(* App\Repo\*::find*(..))` |
| `call(P)` | `method-call`, `constructor-call`, `function-call` | - | `call(* App\Mailer::send(..))` |
| `within(T)` | Execution join points inside a type. Call join points whose caller is in the type. | - | `within(App\Service\*)` |
| `withincode(P)` | Join points whose enclosing code matches the method pattern. | - | `withincode(* App\Checkout::place(..))` |
| `this(T)` | The executing object (for call kinds, the caller) is an instance of T. | `this(name)` | `this(App\Cart)` |
| `target(T)` | The object the member belongs to is an instance of T. | `target(name)` | `target(App\Repository\*)` |
| `args(a, b, ..)` | The arguments match the list. A name binds the argument. | `args(name)` | `args(id, ..)` |
| `@annotation(A)` | The method or function carries attribute A. | `@annotation(name)` | `@annotation(App\Audited)` |
| `@within(A)` | The declaring class carries attribute A. | `@within(name)` | `@within(App\Audited)` |
| `@target(A)` | The class of the target object carries attribute A. | `@target(name)` | `@target(App\Audited)` |
| `@args(A)` | An argument's class carries attribute A. | `@args(name)` | `@args(App\Audited)` |
| `@this(A)` | The class of the executing object carries attribute A. | `@this(name)` | `@this(App\Audited)` |
| `@withincode(A)` | The enclosing method carries attribute A. | `@withincode(name)` | `@withincode(App\Audited)` |
| `cflow(p)` | Some join point matching `p` is active on the stack of the current Fiber, the join point itself included. | the bindings of `p` | `cflow(execution(* App\Import::run(..)))` |
| `cflowbelow(p)` | As `cflow(p)`, but the join point itself does not count. | the bindings of `p` | `cflowbelow(execution(* App\Import::run(..)))` |
| `if(C::m())` | A public static method returns `true`. Runs for every join point. | - | `if(App\Flags::enabled())` |
| `initialization(P)` | The outermost constructor execution of a matching type. | - | `initialization(App\Connection+)` |
| `adviceexecution()` | The execution of an advice method. | - | `adviceexecution() && within(App\Audit)` |
| `service(name)` | The object or class registered under a service name. | - | `service(orders.repository)` |
| `handler(T)` | A `catch` of T. Experimental. | `args(e)` | see [limitations](limitations.md#experimental-features) |
| `get(F)`, `set(F)` | Reads and writes of a property. Experimental. | `args(v)` for `set` | see [limitations](limitations.md#experimental-features) |
| `preinitialization(...)`, `staticinitialization(...)` | Nothing. They raise `UnsupportedPointcutDesignatorException`. | - | - |

The designators apply only to the kinds they name.
A designator that does not apply to a kind returns false.
It never throws.

### Binding

A designator with a name binds a value.
The advice declares a parameter with the same name, and the library passes the bound value.

```php
#[Before('execution(* Demo\Pointcuts\Account::*(..)) && @annotation(audit)')]
public function methodAnnotation(Audited $audit, JoinPointInterface $jp): void
{
    echo "@annotation on {$jp->getSignature()->getName()}: level {$audit->level}\n";
}
```

- The parameter type must fit the value: an attribute class for `@annotation`, an object type for `this` and `target`, the declared type for `args`.
- Parameters that are not bound are filled from the join point, the static part, `returning` or `throwing`.
- `!` cannot bind a value.

[advice.md](advice.md#parameters) lists the parameter rules.

### `args()`

`args()` matches the arguments by position.
A name binds that argument.
Patterns follow the parameter list rules above.

```text
execution(* App\Account::deposit(..)) && args(amount, ..)   // binds the first argument as amount
execution(* App\Account::deposit(int, string))             // exactly an int and a string
```

For `field-set` join points `args()` takes the value.
For handlers it takes the exception.

### `call()`, `withincode()` and `within()`

`call()` describes the callee.
It fires at the call site, so `withincode()` can tell which method makes the call.

```text
call(* App\Mailer::send(..)) && withincode(* App\Checkout::place(..))
```

`within()` and `@within()` use the executing code for execution join points and the caller for call join points.
If the caller is unknown, for example when a call comes from internal code, they do not match.

### `cflow()` and `cflowbelow()`

`cflow(p)` is true while a join point matching `p` is on the call stack of the current Fiber.
The stack includes the current join point.
`cflowbelow(p)` leaves out the current join point.

```text
// save() runs somewhere inside import()
execution(* App\Repository::save(..)) && cflow(execution(* App\Importer::import(..)))
```

[`examples/07-cflow.php`](../examples/07-cflow.php) shows `cflow` and `cflowbelow` side by side.
The engine tracks the join points that the inner pointcut of `cflow()` matches.
So the entry point needs no advice of its own.
The Spring API's `ControlFlowPointcut` does not register its entry points, so it only works when other advice advises them (see [spring-api.md](spring-api.md#control-flow)).

### `if()`

`if(C::m())` calls a public static method of an aspect or any class.
The method returns `bool`.
It may take one parameter, a `JoinPointInterface`.

```text
execution(* App\Feature::run(..)) && if(App\Flags::featureOn())
```

The method runs for every join point that matches the rest of the expression.
A bare `if(m())` refers to a method of the aspect that declares the pointcut.
[`examples/08-if-designator.php`](../examples/08-if-designator.php) shows the runtime check.

### `initialization()`

`initialization(P)` matches the outermost constructor of an object.
`P` is a constructor pattern, or a type with `+` for the type and its subtypes.

```text
initialization(App\Connection+)
```

A `parent::__construct()` call is not outermost, so it does not match.
For a class without a constructor, the designator relies on the experimental `initialization` join point, which this build does not emit yet.

### `adviceexecution()`

`adviceexecution()` matches the execution of an advice method.
It is useful to trace advice, and to restrict it with `within()`.
An advice method is never advised by its own pointcut, so the advice of an aspect cannot loop.
[`examples/25-advice-execution.php`](../examples/25-advice-execution.php) shows it.

### `service()`

`service(name)` matches objects registered with `Weaver::registerService()` and classes registered with `registerServiceClass()`.
The name can contain dots and dashes.
A `*` matches any part of the name, as in `mail*`.

```text
service(orders.repository) && execution(* App\OrderRepository::save(..))
service(mail*)              && execution(* App\Mailer::send(..))
```

[`examples/17-services.php`](../examples/17-services.php) shows both forms.

### Attribute designators

The `@` designators take the attribute class, or a binding.
Attribute classes are matched by name, on the class or on the method.

```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Audited
{
    public function __construct(public readonly string $level = 'info')
    {
    }
}
```

[`examples/09-pointcut-designators.php`](../examples/09-pointcut-designators.php) binds `@annotation` and `@within`, and shows `args()` and `target()`.

## Named pointcuts

A method with `#[Pointcut]` names a pointcut.
Other advice in the same aspect, or in another aspect, can use the name.

```php
#[Aspect]
final class Pointcuts
{
    // A named pointcut: a public method without parameters that returns void.
    #[Pointcut('execution(* Demo\Named\Shop::save(..))')]
    public function saves(): void
    {
    }

    // if() may call a public static method that takes the join point.
    public static function sampled(JoinPointInterface $jp): bool
    {
        return 'load' === $jp->getSignature()->getName();
    }
}
```

Rules:

- The method must be `public`, non-static, without parameters, and return `void`.
  Otherwise `AspectDefinitionException` is thrown.
- A name from another aspect is written as `App\Aspect::name()`.
- Named pointcuts combine with `&&`, `||`, `!` and parentheses like any designator.

`pointcut-ref` in the configuration array uses the same names (see [configuration.md](configuration.md)).

## Unsupported designators

`preinitialization()` and `staticinitialization()` are in AspectJ.
PHP has no moment that matches them: an argument list is evaluated before the constructor, and there is no separate phase before a parent constructor.
The compiler rejects them with `UnsupportedPointcutDesignatorException`, and the message suggests `execution()` or `initialization()` instead.

## Errors

| Error | Thrown when |
|---|---|
| `PointcutSyntaxException` | The expression does not parse, a name is unknown, or a regular expression is invalid. |
| `UnsupportedPointcutDesignatorException` | `preinitialization()` or `staticinitialization()` is used. |
| `BindingException` | A binding is used where it cannot bind, such as under `!`, or the branches of `\|\|` do not bind the same names. |

Errors are reported when the Weaver compiles the aspect, which happens when you add it.
An invalid pointcut never reaches a running request.

## Compiling expressions directly

`Weaver::compiler()` returns the compiler, which is useful to check an expression in a test.

```php
$expression = $weaver->compiler()->compile('execution(* Demo\Weaving\Catalog::find(..))', [], CatalogAspect::class);
```

The compiled object is a `PointcutInterface`, and it can be passed to a `DefaultPointcutAdvisor`.
