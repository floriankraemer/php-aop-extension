<?php
namespace App\Model;

use Lib\Greeter as G;

class User
{
    public function greet(string $name, string $suffix = '?', \Lib\Tone $tone = \Lib\Tone::Cold, string $word = 'hi'): string
    {
        return "own greet $name";
    }
}
class Post {}
class Already implements G
{
    public function greet(string $name, string $suffix = '', \Lib\Tone $tone = \Lib\Tone::Warm, string $word = ''): string { return 'already'; }
    public function collect(array &$into, int|string ...$items): void {}
    public function self(?G $other = null): ?G { return null; }
    public static function describe(): string { return 'already'; }
}
abstract class Base
{
    public function greet(string $name, string $suffix = '.', \Lib\Tone $tone = \Lib\Tone::Warm, string $word = 'base'): string
    {
        return "inherited greet $name";
    }
    abstract public function self(?G $other = null): ?G;
}
class Child extends Base {}

namespace App\Model\Deep\Er;

class Leaf {}

namespace Other;

class Outside {}
