--TEST--
reset() forgets decisions, unregister() stops interception, re-registering replaces the pair
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
; opcache would inline these trivial functions (optimizer pass 16), see 025-opcache-inlining.phpt
opcache.optimization_level=0x7FFE3FFF
--FILE--
<?php
function f() { return 'original'; }

$asked = 0;
$advise = true;
$matcher = function (array $jp) use (&$asked, &$advise) {
    if ($jp['name'] !== 'f') {
        return 0;
    }
    ++$asked;
    return $advise ? 1 : 0;
};
\AOP\Engine::register($matcher, fn () => 'advised');
echo f(), ' ', f(), ' asked=', $asked, "\n";
$advise = false;
echo f(), " (cached)\n";
\AOP\Engine::reset();
echo f(), ' asked=', $asked, "\n";
$advise = true;
\AOP\Engine::register($matcher, fn () => 'advised again');
echo f(), ' asked=', $asked, "\n";
\AOP\Engine::unregister();
echo f(), ' asked=', $asked, "\n";

echo "-- unregister inside the dispatcher\n";
\AOP\Engine::register($matcher, function ($jp, $self, $args, $original) {
    \AOP\Engine::unregister();
    return 'last advised + '.$original($args).' + '.f();
});
echo f(), "\n";
echo f(), "\n";
var_dump(\AOP\Engine::isRegistered());

echo "-- register/reset inside the matcher\n";
\AOP\Engine::register(function (array $jp) use ($matcher) {
    \AOP\Engine::reset();
    \AOP\Engine::register($matcher, fn () => 'from new pair');
    return 1;
}, fn () => 'from first pair');
echo f(), "\n";
echo f(), "\n";
\AOP\Engine::unregister();
?>
--EXPECT--
advised advised asked=1
advised (cached)
original asked=2
advised again asked=3
original asked=3
-- unregister inside the dispatcher
last advised + original + original
original
bool(false)
-- register/reset inside the matcher
from new pair
from new pair
