--TEST--
Introductions: rule validation, type patterns, only classes compiled afterwards, eval, namespaces, conditional classes, interception of introduced methods
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
interface Tagged { public function tag(): string; }
class TagImpl implements Tagged { public function tag(): string { return 'tag'; } }
class CompiledBefore {}

foreach ([
    [['typePattern' => 'X', 'interface' => 'Tagged']],
    [['typePattern' => 'X', 'interface' => 'TagImpl', 'defaultImpl' => 'TagImpl']],
    [['typePattern' => 'X', 'interface' => 'NoSuchInterface', 'defaultImpl' => 'TagImpl']],
    [['typePattern' => '', 'interface' => 'Tagged', 'defaultImpl' => 'TagImpl']],
    ['not an array'],
] as $rules) {
    try {
        \AOP\Engine::setIntroductions($rules);
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    }
}

\AOP\Engine::setIntroductions([['typePattern' => 'Shop\*\Entity*', 'interface' => 'Tagged', 'defaultImpl' => 'TagImpl']]);
eval(<<<'PHP'
    namespace Shop\Catalog {
        class EntityProduct {}
        class Product {}
        if (true) {
            class EntityConditional {}
        }
        function declareLater() { class EntityInFunction {} }
    }
    namespace Shop\Catalog\Sub {
        class EntityTooDeep {}
    }
    namespace Shop {
        class EntityTooShallow {}
    }
    PHP);
\Shop\Catalog\declareLater();
foreach (['CompiledBefore', 'Shop\Catalog\EntityProduct', 'Shop\Catalog\Product', 'Shop\Catalog\EntityConditional',
        'Shop\Catalog\EntityInFunction', 'Shop\Catalog\Sub\EntityTooDeep', 'Shop\EntityTooShallow'] as $class) {
    echo $class, ': ', var_export(is_subclass_of($class, 'Tagged'), true), "\n";
}

echo "a woven class is declared at run time (no early binding):\n";
eval('namespace Shop\Hoist; var_dump(class_exists("Shop\Hoist\EntityEarly", false)); class EntityEarly {}');
eval('namespace NotWoven; var_dump(class_exists("NotWoven\Early", false)); class Early {}');

echo "rules are replaced; an empty list stops weaving:\n";
\AOP\Engine::setIntroductions([]);
eval('namespace Shop\Late; class EntityAfterReset {}');
var_dump(is_subclass_of('Shop\Late\EntityAfterReset', 'Tagged'));

echo "introduced methods are ordinary methods (method-execution join points):\n";
\AOP\Engine::setIntroductions([['typePattern' => '**', 'interface' => 'Tagged', 'defaultImpl' => 'TagImpl']]);
eval('class Anything {}');
\AOP\Engine::register(
    fn (array $jp) => 'method-execution' === $jp['kind'] && 'tag' === $jp['name'] ? 1 : 0,
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) {
        return $jp['class'].'::'.$jp['name'].' advised: '.$original($args);
    }
);
echo (new Anything())->tag(), "\n";
?>
--EXPECT--
AOP\Engine::setIntroductions(): Argument #1 ($rules) must be a list of arrays with the keys "typePattern", "interface" and "defaultImpl" (rule 0 has no "defaultImpl")
AOP\Engine::setIntroductions(): Argument #1 ($rules) rule 0: "TagImpl" is not an interface
AOP\Engine::setIntroductions(): Argument #1 ($rules) rule 0: "NoSuchInterface" is not an interface
AOP\Engine::setIntroductions(): Argument #1 ($rules) rule 0: "typePattern" must be a non-empty string
AOP\Engine::setIntroductions(): Argument #1 ($rules) must be a list of arrays with the keys "typePattern", "interface" and "defaultImpl" (rule 0 has no "typePattern")
CompiledBefore: false
Shop\Catalog\EntityProduct: true
Shop\Catalog\Product: false
Shop\Catalog\EntityConditional: true
Shop\Catalog\EntityInFunction: true
Shop\Catalog\Sub\EntityTooDeep: false
Shop\EntityTooShallow: false
a woven class is declared at run time (no early binding):
bool(false)
bool(true)
rules are replaced; an empty list stops weaving:
bool(false)
introduced methods are ordinary methods (method-execution join points):
Anything::tag advised: TagImpl::tag advised: tag
