--TEST--
Woven property hooks (8.4+): hook bodies are not woven, a catch inside a hook body is, methods around them are
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) die('skip property hooks need PHP 8.4');
?>
--INI--
opcache.jit=disable
--FILE--
<?php
class Temp
{
    public float $celsius = 0.0;

    public float $fahrenheit {
        get => $this->celsius * 9 / 5 + 32;
        set {
            try {
                if (!is_finite($value)) {
                    throw new RangeException('not finite');
                }
                $this->celsius = ($value - 32) * 5 / 9;
            } catch (RangeException) {
                $this->celsius = 0.0;
            }
        }
    }

    public string $label {
        get {
            try {
                return $this->celsius > 30 ? throw new LogicException('hot') : 'ok';
            } catch (LogicException $e) {
                return $e->getMessage();
            }
        }
    }

    public function reading(): string { return $this->label.' '.$this->fahrenheit; }
}

$seen = [];
\AOP\Engine::register(
    static fn (array $jp): int => (!$jp['internal'] && (str_ends_with($jp['kind'], '-execution') || 'exception-handler' === $jp['kind'])) ? \AOP\Engine::ADVISE : 0,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$seen): mixed {
        $seen[] = $jp['kind'].' '.($jp['class'] ?? '').'::'.$jp['name'];
        return 'exception-handler' === $jp['kind'] ? null : $original($args);
    }
);

$t = new Temp();
$t->fahrenheit = 212.0;
echo $t->celsius, ' ', $t->fahrenheit, ' ', $t->label, "\n";
$t->fahrenheit = INF;
echo $t->celsius, ' ', $t->reading(), "\n";
echo implode("\n", $seen), "\n";
?>
--EXPECT--
100 212 hot
0 ok 32
exception-handler Temp::LogicException
exception-handler Temp::RangeException
method-execution Temp::reading
