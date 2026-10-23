--TEST--
Unbounded recursion through an advised function raises the engine's stack error instead of crashing
--EXTENSIONS--
aop
--INI--
zend.max_allowed_stack_size=512K
--FILE--
<?php
require __DIR__ . '/000-helper.inc';

function down(int $n): int { return down($n + 1) + 1; }

\AOP\Engine::register(
    static fn (array $jp): int => 'down' === $jp['name'] ? \AOP\Engine::ADVISE : 0,
    static fn (array $jp, ?object $self, array $args, \AOP\OriginalCall $original): mixed => $original($args),
);

try {
    down(0);
} catch (\Error $e) {
    echo get_class($e), ': ', $e->getMessage() === '' ? '' : 'caught', "\n";
}
echo "alive\n";
?>
--EXPECTF--
Error: caught
alive
