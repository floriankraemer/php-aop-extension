--TEST--
Classes in AOP\ and calls made by AOP\ code are never intercepted
--EXTENSIONS--
aop
--FILE--
<?php
namespace AOP\Runtime {
    class Helper
    {
        public function work() { return 'helper'; }
        public function callUser(\UserSvc $s) { return $s->run(); }
        public function callUserIndirect(\UserSvc $s) { return call_user_func([$s, 'run']).'/'.implode(array_map([$s, 'run'], [1])); }
        public function callUserViaClosure(\UserSvc $s) { return (fn () => $s->run())(); }
    }
    function helper_function() { return 'aop function'; }
}
namespace {
    class UserSvc
    {
        public function run() { return 'run'; }
        public function viaHelper() { return (new \AOP\Runtime\Helper())->work(); }
    }

    \AOP\Engine::register(
        function (array $jp) {
            if (!str_ends_with($jp['kind'], '-execution')) {
                return 0; // field probes and initialization (stream G) are not what this test is about
            }
            echo 'asked ', $jp['class'] ?? '', '::', $jp['name'], "\n";
            return 1;
        },
        fn ($jp, $self, $args, $original) => '<'.$original($args).'>'
    );
    $h = new \AOP\Runtime\Helper();
    $s = new UserSvc();
    $r = [$h->work(), \AOP\Runtime\helper_function(), $h->callUser($s), $h->callUserIndirect($s),
        $h->callUserViaClosure($s), $s->run(), $s->viaHelper()];
    \AOP\Engine::unregister();
    var_dump($r);
}
?>
--EXPECT--
asked UserSvc::run
asked UserSvc::viaHelper
array(7) {
  [0]=>
  string(6) "helper"
  [1]=>
  string(12) "aop function"
  [2]=>
  string(3) "run"
  [3]=>
  string(7) "run/run"
  [4]=>
  string(3) "run"
  [5]=>
  string(5) "<run>"
  [6]=>
  string(8) "<helper>"
}
