<?php

declare(strict_types=1);

namespace Demo\Visibility;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

class Vault
{
    private string $secret = 'token';

    public function reveal(): string
    {
        return $this->decode();
    }

    private function decode(): string
    {
        return strtoupper($this->secret);
    }

    public function viaProtected(): string
    {
        return $this->guard();
    }

    protected function guard(): string
    {
        return 'guarded';
    }

    public static function version(): string
    {
        return '1.0';
    }

    final public function seal(): string
    {
        return 'sealed';
    }
}

#[Aspect]
final class TraceAspect
{
    #[Around('execution(* Demo\Visibility\Vault::*(..))')]
    public function trace(ProceedingJoinPointInterface $pjp): mixed
    {
        $name = $pjp->getSignature()->toShortString();
        echo "enter $name\n";
        $result = $pjp->proceed();
        echo "leave $name\n";

        return $result;
    }
}

$weaver = Weaver::fromAspects([new TraceAspect()]);
$weaver->enable();

$vault = new Vault();
echo $vault->reveal()."\n";
echo $vault->viaProtected()."\n";
echo Vault::version()."\n";
echo $vault->seal()."\n";

/* Expected output:
enter Vault::reveal(..)
enter Vault::decode(..)
leave Vault::decode(..)
leave Vault::reveal(..)
TOKEN
enter Vault::viaProtected(..)
enter Vault::guard(..)
leave Vault::guard(..)
leave Vault::viaProtected(..)
guarded
enter Vault::version(..)
leave Vault::version(..)
1.0
enter Vault::seal(..)
leave Vault::seal(..)
sealed
*/
