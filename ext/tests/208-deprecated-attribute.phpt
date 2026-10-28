--TEST--
PHP 8.4+: #[\Deprecated] user functions: the call site reports the deprecation, every OriginalCall reports it again
--EXTENSIONS--
aop
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip PHP 8.4+ (#[\Deprecated])'); ?>
--INI--
opcache.jit=disable
--FILE--
<?php
#[\Deprecated('use new_one()')]
function old_one($x) { return "old $x"; }
\AOP\Engine::register(fn (array $jp) => $jp['name'] === 'old_one' ? 1 : 0,
    fn (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) => 'advised ' . $original($args));
var_dump(old_one(1));
?>
--EXPECTF--
Deprecated: Function old_one() is deprecated, use new_one() in %s on line %d

Deprecated: Function old_one() is deprecated, use new_one() in %s on line %d
string(13) "advised old 1"
