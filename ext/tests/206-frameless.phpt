--TEST--
PHP 8.4+: internal functions with frameless variants are not compiled frameless while aop is active, so they can be advised
--EXTENSIONS--
aop
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip PHP 8.4+ (frameless calls)'); ?>
--INI--
opcache.jit=disable
--FILE--
<?php
namespace App {
    function run() {
        // All of these have frameless handlers (ZEND_FRAMELESS_ICALL_1..3) in 8.4+.
        return [
            str_contains('haystack', 'st'), \strpos('abc', 'c'), substr('abcdef', 1, 2), dirname('/a/b/c'),
            implode(',', [1, 2]), in_array(2, [1, 2]), min(3, 1), max(1, 3), trim(' x '), class_exists('App\Nope'),
            preg_match('/b/', 'abc'), str_replace('a', 'b', 'aaa'),
        ];
    }
}
namespace {
    $names = [];
    \AOP\Engine::register(
        fn (array $jp) => $jp['internal'] && $jp['class'] === null && ($jp['caller']['function'] ?? null) === 'App\run'
            ? \AOP\Engine::ADVISE | \AOP\Engine::CALLER_SENSITIVE : ($jp['internal'] ? \AOP\Engine::CALLER_SENSITIVE : 0),
        function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$names) {
            $names[] = $jp['name'];
            return $original($args);
        }
    );
    echo json_encode(App\run()), "\n";
    echo implode(',', $names), "\n";
}
?>
--EXPECT--
[true,2,"bc","\/a\/b","1,2",true,1,3,"x",false,1,"bbb"]
str_contains,strpos,substr,dirname,implode,in_array,min,max,trim,class_exists,preg_match,str_replace
