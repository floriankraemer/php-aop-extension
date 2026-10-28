--TEST--
PHP 8.4+: exit() is an internal function and can be advised; proceeding exits, shutdown functions run
--EXTENSIONS--
aop
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip PHP 8.4+ (exit() is a function)'); ?>
--INI--
opcache.jit=disable
--FILE--
<?php
register_shutdown_function(fn () => print("shutdown\n"));
\AOP\Engine::register(
    fn (array $jp) => $jp['name'] === 'exit' ? 1 : 0,
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) {
        echo "> exit(", json_encode($args), ")\n";
        try {
            return $original($args);
        } finally {
            echo "not reached\n";
        }
    }
);
function stop() { exit("bye\n"); }
stop();
echo "after\n";
?>
--EXPECT--
> exit(["bye\n"])
bye
shutdown
