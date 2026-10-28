--TEST--
Functions in interfaces, traits, enums and abstract classes: bodies are woven, bodiless declarations are not
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
namespace App;

interface Shape
{
    public const SIDES = 0;
    public function area(): float;
    public static function make(): static;
}

trait Greets
{
    public string $greeting = 'hi';
    public function greet(string $who): string { return "{$this->greeting} {$who}"; }
    public static function shout(string $s): string { return strtoupper($s); }
    abstract public function name(): string;
    public function viaAbstract(): string { return $this->name(); }
}

abstract class Base implements Shape
{
    use Greets;

    abstract protected function sides(): int;
    public function describe(): string { return static::class.' with '.$this->sides().' sides'; }
    public static function make(): static { return new static(); }
}

final class Square extends Base
{
    public function area(): float { return 4.0; }
    protected function sides(): int { return 4; }
    public function name(): string { return 'square'; }
}

enum Suit: string implements \JsonSerializable
{
    case Hearts = 'H';
    case Spades = 'S';

    public function label(): string { return ucfirst(strtolower($this->name)); }
    public static function fromChar(string $c): self { return self::from($c); }
    public function jsonSerialize(): mixed { return $this->label(); }
    public static function count(): int { return \count(self::cases()); }
}

$seen = [];
\AOP\Engine::register(
    static fn (array $jp): int => (!$jp['internal'] && str_ends_with($jp['kind'], '-execution')) ? \AOP\Engine::ADVISE : 0,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$seen): mixed {
        $seen[] = ($jp['class'] ?? '').'::'.$jp['name'].' '.json_encode($args);
        return $original($args);
    }
);

$s = Square::make();
var_dump($s->area(), $s->describe(), $s->greet('bob'), Square::shout('x'), $s->viaAbstract());
var_dump(Suit::fromChar('S')->label(), Suit::count(), json_encode(Suit::Hearts));
echo implode("\n", $seen), "\n";
?>
--EXPECT--
float(4)
string(23) "App\Square with 4 sides"
string(6) "hi bob"
string(1) "X"
string(6) "square"
string(6) "Spades"
int(2)
string(8) ""Hearts""
App\Square::make []
App\Square::area []
App\Square::describe []
App\Square::sides []
App\Square::greet ["bob"]
App\Square::shout ["x"]
App\Square::viaAbstract []
App\Square::name []
App\Suit::fromChar ["S"]
App\Suit::label []
App\Suit::count []
App\Suit::jsonSerialize []
App\Suit::label []
