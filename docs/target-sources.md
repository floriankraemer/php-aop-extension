# Target sources

A target source decides which object a call really goes to.
Spring uses it for hot swapping, pooling and per-thread instances.
This library has the same four sources, and a fifth one for Fibers.

## How it works

PHP cannot swap the object behind a variable without a proxy.
So the caller holds a *stand-in*: an instance of the class that was created without its constructor.
`Weaver::targetSource()` attaches an advisor to the stand-in.
That advisor sends every instance method call on the stand-in to `getTarget()` of the source.
The stand-in's own method bodies never run.

```php
$swappable = new HotSwappableTargetSource(new Greeting('hello'));
$standIn = $weaver->targetSource(Greeting::class, $swappable);
echo $standIn->say()."\n";
$swappable->swap(new Greeting('bonjour'));
echo $standIn->say()."\n";
```

Because the stand-in is created without its constructor, this also works for `final` classes.
Only the caller that holds the stand-in sees the source.
Other objects of the class are not affected.

## The sources

| Class | Target | Use it for |
|---|---|---|
| `SingletonTargetSource` | The same object every time. | The default behaviour, made explicit. |
| `HotSwappableTargetSource` | The object set last, with `swap()`. | Replacing an implementation at run time. |
| `PrototypeTargetSource` | A new object for every call, from a factory. `getCreatedCount()` counts them. | Stateful objects that must not be shared. |
| `PoolingTargetSource` | An object borrowed for one call and returned. `stats()` reports the pool. | Expensive objects, such as connections. |
| `FiberLocalTargetSource` | One object per Fiber, from a factory. | What Spring calls ThreadLocal, in PHP's concurrency model. |

[`examples/20-target-sources.php`](../examples/20-target-sources.php) shows each source.
Its output for the pool and the fiber source:

```text
--- pooling: targets are borrowed for one call and returned
pooled (#5)
pooled (#5)
created 1, borrowed 2, released 2, idle 1
--- fiber-local: one target per fiber
in a fiber: fiber (#6)
in the main fiber: fiber (#7)
created: 2
```

The pool reused one object for two calls.
The fiber source made one object for the fiber and one for the main fiber.

### Pooling

`PoolingTargetSource(string $targetClass, \Closure $factory, int $maxSize = 25)`.
A call takes an idle object, or creates one while the pool is below `maxSize`.
When the call ends, the object goes back to the pool.
If every object is in use, the call throws `AOP\TargetSource\PoolExhaustedException`.

`stats()` returns a `PoolStats` with `maxSize`, `active`, `idle`, `created`, `borrowed`, `released` and `exhausted`.

### Fiber-local

`FiberLocalTargetSource(string $targetClass, \Closure $factory)`.
Each Fiber gets its own object, created on its first call.
`getCreatedCount()` tells how many were made.

## Writing your own source

Implement `TargetSourceInterface`.

| Method | Purpose |
|---|---|
| `getTargetClass(): string` | The class of the targets. |
| `isStatic(): bool` | `true` when the target never changes. |
| `getTarget(): object` | The object for the next call. |
| `releaseTarget(object $target): void` | Called after the call. |

## Limits

- Only instance methods go through the source.
  Static methods and properties belong to the class, not to a target.
- The stand-in is an instance of the class.
  Code that compares its class or checks its type sees the target class.
- The source is consulted on every call, so keep `getTarget()` cheap.

## Errors

| Exception | When |
|---|---|
| `PoolExhaustedException` | Every pooled object is in use. |
| `TargetSourceException` | A pooled object is released that was not borrowed from the pool. |
