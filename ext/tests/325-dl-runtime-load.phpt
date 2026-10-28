--TEST--
dl(): an extension loaded at run time installs its backend at once, for aop.interception=woven, hook and auto
--SKIPIF--
<?php
if (!function_exists('dl') || !function_exists('shell_exec') || !ini_get('enable_dl') || !defined('PHP_BINARY') || PHP_BINARY === '') {
    die('skip dl() or shell_exec() is not available');
}
$cmd = escapeshellarg(PHP_BINARY).' -n -d extension_dir='.escapeshellarg(ini_get('extension_dir'))
    .' -r '.escapeshellarg('echo dl("aop.".PHP_SHLIB_SUFFIX) ? "loaded" : "failed";').' 2>&1';
if (trim((string) shell_exec($cmd)) !== 'loaded') {
    die('skip dl() cannot load aop here (not CLI, or dl is disabled)');
}
?>
--INI--
opcache.jit=disable
--FILE--
<?php
$script = <<<'PHP'
dl("aop." . PHP_SHLIB_SUFFIX);
try {
    AOP\Engine::register(
        fn (array $jp) => !$jp["internal"] && $jp["kind"] === "function-execution" ? AOP\Engine::ADVISE : 0,
        fn (array $jp, ?object $self, array $args, AOP\OriginalCall $original) => $original($args) * 10
    );
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
    return;
}
// Only code compiled after dl() is intercepted completely: the woven prologues and the hook backend's
// direct calls (opcodes the compiler picks while zend_execute_ex is not overridden) need it.
eval('function f(int $x): int { return $x + 1; } class C { public function __construct(public readonly int $v) {} }
    echo f(1), " ", (new C(3))->v, " field interception: ", AOP\Engine::fieldInterceptionAvailable() ? "yes" : "no", "\n";');
PHP;

foreach (['woven', 'hook', 'auto'] as $mode) {
    echo "aop.interception=$mode: ";
    echo shell_exec(escapeshellarg(PHP_BINARY).' -n -d extension_dir='.escapeshellarg(ini_get('extension_dir'))
        .' -d aop.interception='.$mode.' -r '.escapeshellarg($script).' 2>&1');
}
echo "aop.enable=0: ";
echo shell_exec(escapeshellarg(PHP_BINARY).' -n -d extension_dir='.escapeshellarg(ini_get('extension_dir'))
    .' -d aop.enable=0 -r '.escapeshellarg($script).' 2>&1');
?>
--EXPECT--
aop.interception=woven: 20 3 field interception: no
aop.interception=hook: 20 3 field interception: yes
aop.interception=auto: 20 3 field interception: yes
aop.enable=0: AOP\Engine::register(): interception is disabled (aop.enable=0)
