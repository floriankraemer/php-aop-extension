--TEST--
Introductions: implements, delegating methods with the interface's signature, per-object default implementation, declared and inherited methods win
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
require __DIR__.'/120-intro-lib.inc';
\AOP\Engine::setIntroductions([
    ['typePattern' => 'App\Model\**', 'interface' => 'Lib\Greeter', 'defaultImpl' => 'Lib\DefaultGreeter'],
    ['typePattern' => '\App\Model\P*', 'interface' => 'Lib\Counts', 'defaultImpl' => '\Lib\DefaultCounts'],
]);
require __DIR__.'/120-intro-classes.inc';

use App\Model\{User, Post, Already, Child};
use App\Model\Deep\Er\Leaf;
use Lib\{Greeter, Counts, DefaultGreeter, Tone};

foreach ([User::class, Post::class, Already::class, Child::class, Leaf::class, Other\Outside::class] as $class) {
    printf("%-22s %s\n", $class, implode(', ', class_implements($class)));
}

$p = new Post();
var_dump($p instanceof Greeter, $p instanceof Counts, $p instanceof Countable, count($p));
echo $p->greet('Ann'), "\n";
echo $p->greet('Bob', '?', Tone::Cold, 'hey'), "\n";
echo $p->greet(name: 'Cy', word: 'yo'), "\n";
$list = ['a'];
$p->collect($list, 1, 'b');
var_dump($list);
var_dump($p->self() instanceof DefaultGreeter, $p->self($p) === $p);
echo Post::describe(), "\n";
try {
    $p->greet(['not a string']);
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $p->reset(); // returns the delegate, not static (Post): a known limitation of `static` return types
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "one delegate per object, created lazily:\n";
$q = new Post();
var_dump(DefaultGreeter::$created);
echo $q->greet('Q'), "\n", $q->greet('Q'), "\n";
var_dump(DefaultGreeter::$created);
echo "declared and inherited methods win:\n";
echo (new User())->greet('U'), "\n";
echo (new Child())->greet('C'), "\n";
var_dump((new Child())->self() instanceof DefaultGreeter);
echo (new Already())->greet('A'), "\n";
echo "the synthesized signature:\n";
echo new ReflectionMethod(Post::class, 'greet');
echo new ReflectionMethod(Post::class, 'collect');
unset($p, $q);
echo "end\n";
?>
--EXPECTF--
App\Model\User         Lib\Greeter
App\Model\Post         Lib\Greeter, Lib\Counts, Countable
App\Model\Already      Lib\Greeter
App\Model\Child        Lib\Greeter
App\Model\Deep\Er\Leaf Lib\Greeter
Other\Outside          
bool(true)
bool(true)
bool(true)
int(42)
hello Ann! (warm, call 1)
hey Bob? (cold, call 2)
yo Cy! (warm, call 3)
array(3) {
  [0]=>
  string(1) "a"
  [1]=>
  int(1)
  [2]=>
  string(1) "b"
}
bool(true)
bool(true)
static default
TypeError: App\Model\Post::greet(): Argument #1 ($name) must be of type string, array given, called in %s on line %d
TypeError: App\Model\Post::reset(): Return value must be of type App\Model\Post, Lib\DefaultCounts returned
one delegate per object, created lazily:
int(1)
hello Q! (warm, call 1)
hello Q! (warm, call 2)
int(2)
declared and inherited methods win:
own greet U
inherited greet C
bool(true)
already
the synthesized signature:
Method [ <user, prototype Lib\Greeter> public method greet ] {
  @@ %s120-intro-classes.inc 13 - 13

  - Parameters [4] {
    Parameter #0 [ <required> string $name ]
    Parameter #1 [ <optional> string $suffix = \Lib\Greeter::SUFFIX ]
    Parameter #2 [ <optional> Lib\Tone $tone = \Lib\Tone::Warm ]
    Parameter #3 [ <optional> string $word = Lib\GREETING ]
  }
  - Return [ string ]
}
Method [ <user, prototype Lib\Greeter> public method collect ] {
  @@ %s120-intro-classes.inc 13 - 13

  - Parameters [2] {
    Parameter #0 [ <required> array &$into ]
    Parameter #1 [ <optional> string|int ...$items ]
  }
  - Return [ void ]
}
end
