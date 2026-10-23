--TEST--
Calls made while a class is being autoloaded are not intercepted unless aop.weave_autoload=1
--EXTENSIONS--
aop
--FILE--
<?php
require __DIR__ . '/000-helper.inc';

spl_autoload_register(static function (string $class): void {
    echo 'autoload ', $class, ' ', str_repeat('x', 2), "\n";
    eval('final class ' . $class . ' { public static function hello(): string { return str_repeat("y", 3); } }');
});

\AOP\Engine::register(
    static fn (array $jp): int => 'str_repeat' === $jp['name'] ? \AOP\Engine::ADVISE : 0,
    logging_dispatcher(),
);

echo Lazy1::hello(), "\n";

ini_set('aop.weave_autoload', '1');
echo Lazy2::hello(), "\n";
?>
--EXPECT--
autoload Lazy1 xx
> str_repeat('y', 3)
< str_repeat = 'yyy'
yyy
autoload Lazy2 > str_repeat('x', 2)
< str_repeat = 'xx'
xx
> str_repeat('y', 3)
< str_repeat = 'yyy'
yyy
