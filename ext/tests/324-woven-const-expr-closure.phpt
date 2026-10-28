--TEST--
Woven catch blocks inside closures in constant expressions (8.5+)
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80500) die('skip closures in constant expressions need PHP 8.5');
?>
--INI--
opcache.jit=disable
--FILE--
<?php
const GUARD = static function (callable $f): string {
    try {
        $f();
        return 'fine';
    } catch (RuntimeException | LogicException $e) {
        return 'guarded '.get_class($e);
    }
};

class Runner
{
    public const HANDLER = static function (callable $f): string {
        try {
            $f();
            return 'fine';
        } catch (Exception) {
            return 'class const caught';
        }
    };

    public function run(callable $f, Closure $guard = static function (callable $f): string {
        try {
            $f();
            return 'fine';
        } catch (Throwable) {
            return 'default caught';
        }
    }): string {
        return $guard($f);
    }
}

$seen = [];
\AOP\Engine::register(
    static fn (array $jp): int => (!$jp['internal'] && (str_ends_with($jp['kind'], '-execution') || 'exception-handler' === $jp['kind'])) ? \AOP\Engine::ADVISE : 0,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$seen): mixed {
        $seen[] = $jp['kind'].' '.($jp['class'] ?? '').'::'.$jp['name'];
        return 'exception-handler' === $jp['kind'] ? null : $original($args);
    }
);

$boom = static fn () => throw new LogicException('boom');
echo (GUARD)($boom), "\n", (Runner::HANDLER)($boom), "\n", (new Runner())->run($boom), "\n";
echo implode("\n", $seen), "\n";
?>
--EXPECT--
guarded LogicException
class const caught
default caught
exception-handler ::LogicException
exception-handler Runner::Exception
method-execution Runner::run
exception-handler Runner::Throwable
