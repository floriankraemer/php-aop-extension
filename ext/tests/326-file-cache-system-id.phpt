--TEST--
opcache.file_cache is keyed by the interception backend: bytecode compiled under hook is not loaded under woven or aop.enable=0
--SKIPIF--
<?php
if (!function_exists('shell_exec') || !defined('PHP_BINARY') || PHP_BINARY === '') die('skip shell_exec() is not available');
$opcache = is_file(PHP_EXTENSION_DIR.'/opcache.so') ? '-d zend_extension='.escapeshellarg(PHP_EXTENSION_DIR.'/opcache.so') : '';
$cmd = escapeshellarg(PHP_BINARY)." -n $opcache -r ".escapeshellarg('echo extension_loaded("Zend OPcache") ? "yes" : "no";').' 2>&1';
if (trim((string) shell_exec($cmd)) !== 'yes') die('skip opcache is not available');
?>
--FILE--
<?php
$root = sys_get_temp_dir().'/aop-fc-'.getmypid();
$cache = "$root/cache";
mkdir($cache, 0777, true);
file_put_contents("$root/lib.php", '<?php
class S { public function run(int $x): int { try { throw new LogicException("x"); } catch (LogicException) { return $x + 1; } } }
');
file_put_contents("$root/main.php", '<?php
require __DIR__."/lib.php";
try {
    AOP\Engine::register(
        fn (array $jp) => !$jp["internal"] && $jp["kind"] === "method-execution" ? AOP\Engine::ADVISE : 0,
        fn (array $jp, ?object $self, array $args, AOP\OriginalCall $original) => $original($args) * 10
    );
} catch (RuntimeException) {
    echo "no interception ";
}
echo (new S)->run(1), "\n";
');

$dir = ini_get('extension_dir');
$opcache = is_file(PHP_EXTENSION_DIR.'/opcache.so') ? '-d zend_extension='.escapeshellarg(PHP_EXTENSION_DIR.'/opcache.so') : '';
$base = escapeshellarg(PHP_BINARY).' -n -d extension_dir='.escapeshellarg($dir).' -d extension=aop '.$opcache
    .' -d opcache.enable_cli=1 -d opcache.jit=disable -d opcache.file_update_protection=0'
    .' -d opcache.file_cache='.escapeshellarg($cache).' -d opcache.file_cache_only=1 ';

foreach (['aop.interception=hook', 'aop.interception=woven', 'aop.enable=0', 'aop.interception=hook'] as $setting) {
    echo "$setting: ", shell_exec($base.'-d '.$setting.' '.escapeshellarg("$root/main.php").' 2>&1');
}
echo "cache variants: ", count(glob("$cache/*", GLOB_ONLYDIR)), "\n";

exec('rm -rf '.escapeshellarg($root));
?>
--EXPECT--
aop.interception=hook: 20
aop.interception=woven: 20
aop.enable=0: no interception 2
aop.interception=hook: 20
cache variants: 3
