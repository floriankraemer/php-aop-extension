<?php

declare(strict_types=1);

namespace Demo\Declare;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\DeclareError;
use AOP\Attribute\DeclareWarning;
use AOP\Exception\DeclareErrorException;
use AOP\Weaver;

final class LegacyApi
{
    public function oldCall(): string
    {
        return 'legacy';
    }
}

final class Gateway
{
    public function unsafeQuery(string $sql): string
    {
        return "ran $sql";
    }

    public function safeQuery(string $sql): string
    {
        return "ran $sql";
    }
}

#[Aspect]
#[DeclareWarning('execution(* Demo\Declare\LegacyApi::*(..))', 'LegacyApi is deprecated, use the new API')]
#[DeclareError('execution(* Demo\Declare\Gateway::unsafe*(..))', 'unsafe methods must not be called')]
final class PolicyAspect
{
}

// Warnings are E_USER_WARNING; this handler prints them instead of stopping the script.
set_error_handler(static function (int $severity, string $message): bool {
    echo "warning ($severity): $message\n";

    return true;
});

$weaver = Weaver::fromAspects([new PolicyAspect()]);
$weaver->enable();

$legacy = new LegacyApi();
echo $legacy->oldCall()."\n";
echo $legacy->oldCall()."\n";

$gateway = new Gateway();
echo $gateway->safeQuery('select 1')."\n";

try {
    $gateway->unsafeQuery('drop table users');
} catch (DeclareErrorException $e) {
    echo 'error: '.$e->getMessage()."\n";
}

/* Expected output:
warning (512): LegacyApi is deprecated, use the new API [method-execution(LegacyApi::oldCall(..)), declared by Demo\Declare\PolicyAspect]
legacy
legacy
ran select 1
error: unsafe methods must not be called [method-execution(Gateway::unsafeQuery(..)), declared by Demo\Declare\PolicyAspect]
*/
