--TEST--
aop.interception=auto: the hook backend when the opcache JIT is not configured
--EXTENSIONS--
aop
--INI--
aop.interception=auto
opcache.jit=disable
display_startup_errors=1
--FILE--
<?php
var_dump(\AOP\Engine::fieldInterceptionAvailable());
function f(int $x): int { return $x; }
\AOP\Engine::register(
    static fn (array $jp): int => 'f' === $jp['name'] ? \AOP\Engine::ADVISE : 0,
    static fn (array $jp, ?object $self, array $args, \AOP\OriginalCall $original): mixed => $original($args) + 100,
);
var_dump(f(1));
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();
var_dump(str_contains($info, 'Interception backend => hook (auto: opcache JIT not configured)'));
var_dump(str_contains($info, 'Field interception => available'));
?>
--EXPECT--
bool(true)
int(101)
bool(true)
bool(true)
