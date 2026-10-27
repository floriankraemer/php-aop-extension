<?php

declare(strict_types=1);

namespace Demo\Audit;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\AfterReturning;
use AOP\Attribute\AfterThrowing;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Pointcut;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\MethodSignature;
use AOP\Weaver;

// Marks a parameter that must never reach a log.
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Sensitive
{
}

// Marks a service class as a boundary of the application.
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Service
{
}

final class InMemoryLog
{
    /** @var list<string> */
    public array $lines = [];

    public function write(string $line): void
    {
        $this->lines[] = $line;
        echo $line."\n";
    }
}

final class UserRecord
{
    public function __construct(public readonly int $id, public readonly string $email)
    {
    }
}

#[Service]
final class UserService
{
    /** @var array<string, UserRecord> */
    private array $byEmail = [];

    public function register(string $email, #[Sensitive] string $password): UserRecord
    {
        if (isset($this->byEmail[$email])) {
            throw new \DomainException("$email is already registered");
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password is too short');
        }

        return $this->byEmail[$email] = new UserRecord(count($this->byEmail) + 1, $email);
    }

    public function changePassword(int $userId, #[Sensitive] string $old, #[Sensitive] string $new): void
    {
        // Nothing to store in this example.
    }
}

#[Service]
final class BillingService
{
    public function invoice(int $userId, int $cents): string
    {
        return sprintf('invoice %d: %d.%02d EUR', $userId, intdiv($cents, 100), $cents % 100);
    }
}

#[Aspect]
final class ServiceBoundaries
{
    // A named pointcut: every method of a class that carries #[Service].
    #[Pointcut('execution(public * *(..)) && @within(Demo\Audit\Service)')]
    public function services(): void
    {
    }
}

#[Aspect]
final class AuditAspect
{
    public function __construct(private readonly InMemoryLog $log)
    {
    }

    #[Before('Demo\Audit\ServiceBoundaries::services()')]
    public function entering(JoinPointInterface $jp): void
    {
        $this->log->write('-> '.$jp->getSignature()->toShortString().' '.$this->describeArguments($jp));
    }

    #[AfterReturning(pointcut: 'Demo\Audit\ServiceBoundaries::services()', returning: 'result')]
    public function returned(JoinPointInterface $jp, mixed $result): void
    {
        $this->log->write('<- '.$jp->getSignature()->toShortString().' returned '.$this->describeResult($result));
    }

    #[AfterThrowing(pointcut: 'Demo\Audit\ServiceBoundaries::services()', throwing: 'e')]
    public function failed(JoinPointInterface $jp, \Throwable $e): void
    {
        $this->log->write('!! '.$jp->getSignature()->toShortString().' threw '.$e::class.': '.$e->getMessage());
    }

    private function describeArguments(JoinPointInterface $jp): string
    {
        $signature = $jp->getSignature();
        \assert($signature instanceof MethodSignature);

        $parts = [];
        foreach ($signature->method->getParameters() as $position => $parameter) {
            $sensitive = [] !== $parameter->getAttributes(Sensitive::class);
            $parts[] = $parameter->getName().'='.($sensitive ? '***' : json_encode($jp->getArgs()[$position]));
        }

        return '('.implode(', ', $parts).')';
    }

    private function describeResult(mixed $result): string
    {
        return match (true) {
            null === $result => 'nothing',
            $result instanceof UserRecord => "user #{$result->id}",
            default => json_encode($result),
        };
    }
}

$log = new InMemoryLog();
$weaver = Weaver::fromAspects([new ServiceBoundaries(), new AuditAspect($log)]);
$weaver->enable();

$users = new UserService();
$users->register('ada@example.test', 'correct horse');
$users->changePassword(1, 'correct horse', 'battery staple');

try {
    $users->register('ada@example.test', 'another secret');
} catch (\DomainException) {
    // The aspect has logged the failure already.
}

try {
    $users->register('bob@example.test', 'short');
} catch (\InvalidArgumentException) {
}

(new BillingService())->invoice(1, 1999);

echo count($log->lines)." audit lines, no password among them\n";

/* Expected output:
-> UserService::register(..) (email="ada@example.test", password=***)
<- UserService::register(..) returned user #1
-> UserService::changePassword(..) (userId=1, old=***, new=***)
<- UserService::changePassword(..) returned nothing
-> UserService::register(..) (email="ada@example.test", password=***)
!! UserService::register(..) threw DomainException: ada@example.test is already registered
-> UserService::register(..) (email="bob@example.test", password=***)
!! UserService::register(..) threw InvalidArgumentException: Password is too short
-> BillingService::invoice(..) (userId=1, cents=1999)
<- BillingService::invoice(..) returned "invoice 1: 19.99 EUR"
10 audit lines, no password among them
*/
