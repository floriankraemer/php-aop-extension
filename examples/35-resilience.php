<?php

declare(strict_types=1);

namespace Demo\Resilience;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Order;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Retry
{
    public function __construct(public readonly int $attempts = 3, public readonly int $backoffMs = 100)
    {
    }
}

final class GatewayDown extends \RuntimeException
{
}

final class CircuitOpen extends \RuntimeException
{
}

// Time only moves when the code says so: a "sleep" advances the clock instantly.
final class FakeClock
{
    public int $nowMs = 0;

    public function sleep(int $ms): void
    {
        $this->nowMs += $ms;
    }
}

// Deterministic: the numbered calls listed in $failingCalls fail. The class is final, no proxy is involved.
final class PaymentGateway
{
    private int $calls = 0;

    /** @param list<int> $failingCalls */
    public function __construct(private readonly array $failingCalls)
    {
    }

    #[Retry(attempts: 3, backoffMs: 100)]
    public function charge(string $orderId, int $cents): string
    {
        $call = ++$this->calls;
        if (\in_array($call, $this->failingCalls, true)) {
            echo "    gateway call #$call: down\n";
            throw new GatewayDown("gateway unavailable for $orderId");
        }
        echo "    gateway call #$call: ok\n";

        return "$orderId charged $cents";
    }
}

// Order(1): retry is the outer layer, the breaker sits between it and the gateway.
// The breaker then sees every single attempt, so consecutive failures are counted per attempt
// and an open circuit short-circuits the attempt that follows. Retry gives up at once on
// CircuitOpen, because waiting and trying again against an open circuit is pointless.
// With the order reversed, the breaker would count one failure per exhausted call and could
// not stop the retries in the middle of a call.
#[Aspect]
#[Order(1)]
final class RetryAspect
{
    public function __construct(private readonly FakeClock $clock)
    {
    }

    #[Around('execution(* Demo\Resilience\PaymentGateway::*(..)) && @annotation(retry)')]
    public function retry(ProceedingJoinPointInterface $pjp, Retry $retry): mixed
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $pjp->proceed();
            } catch (CircuitOpen $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($attempt >= $retry->attempts) {
                    echo "  retry: giving up after $attempt attempts\n";
                    throw $e;
                }
                $backoff = $retry->backoffMs * 2 ** ($attempt - 1);
                echo "  retry: attempt $attempt failed, backing off {$backoff}ms (t={$this->clock->nowMs}ms)\n";
                $this->clock->sleep($backoff);
            }
        }
    }
}

#[Aspect]
#[Order(2)]
final class CircuitBreakerAspect
{
    private const CLOSED = 'closed';
    private const OPEN = 'open';
    private const HALF_OPEN = 'half-open';

    private string $state = self::CLOSED;
    private int $failures = 0;
    private int $openedAtMs = 0;

    public function __construct(
        private readonly FakeClock $clock,
        private readonly int $threshold = 3,
        private readonly int $coolDownMs = 30_000,
    ) {
    }

    #[Around('execution(* Demo\Resilience\PaymentGateway::*(..))')]
    public function guard(ProceedingJoinPointInterface $pjp): mixed
    {
        if (self::OPEN === $this->state) {
            if ($this->clock->nowMs - $this->openedAtMs < $this->coolDownMs) {
                echo "  breaker: open, rejecting call\n";
                throw new CircuitOpen('payment circuit is open');
            }
            $this->state = self::HALF_OPEN;
            echo "  breaker: cool-down passed, half-open, one trial call\n";
        }

        try {
            $result = $pjp->proceed();
        } catch (\Throwable $e) {
            if (self::HALF_OPEN === $this->state || ++$this->failures >= $this->threshold) {
                $this->state = self::OPEN;
                $this->openedAtMs = $this->clock->nowMs;
                echo "  breaker: opened at t={$this->clock->nowMs}ms\n";
            }
            throw $e;
        }

        if (self::CLOSED !== $this->state) {
            echo "  breaker: trial succeeded, closed\n";
        }
        $this->state = self::CLOSED;
        $this->failures = 0;

        return $result;
    }
}

$clock = new FakeClock();
Weaver::fromAspects([new CircuitBreakerAspect($clock), new RetryAspect($clock)])->enable();

// Calls 1-2 fail, 3 works, 4-6 fail, everything after works.
$gateway = new PaymentGateway([1, 2, 4, 5, 6]);

$pay = static function (string $title, string $orderId) use ($gateway, $clock): void {
    echo "$title (t={$clock->nowMs}ms)\n";
    try {
        echo "  => {$gateway->charge($orderId, 2500)}\n";
    } catch (\RuntimeException $e) {
        echo '  => failed: '.$e::class.': '.$e->getMessage()."\n";
    }
};

$pay('1. success after retries', 'order-1');
$pay('2. retries exhausted, circuit opens', 'order-2');
$pay('3. open circuit rejects fast', 'order-3');
$clock->sleep(30_000);
$pay('4. recovery after the cool-down', 'order-4');

/* Expected output:
1. success after retries (t=0ms)
    gateway call #1: down
  retry: attempt 1 failed, backing off 100ms (t=0ms)
    gateway call #2: down
  retry: attempt 2 failed, backing off 200ms (t=100ms)
    gateway call #3: ok
  => order-1 charged 2500
2. retries exhausted, circuit opens (t=300ms)
    gateway call #4: down
  retry: attempt 1 failed, backing off 100ms (t=300ms)
    gateway call #5: down
  retry: attempt 2 failed, backing off 200ms (t=400ms)
    gateway call #6: down
  breaker: opened at t=600ms
  retry: giving up after 3 attempts
  => failed: Demo\Resilience\GatewayDown: gateway unavailable for order-2
3. open circuit rejects fast (t=600ms)
  breaker: open, rejecting call
  => failed: Demo\Resilience\CircuitOpen: payment circuit is open
4. recovery after the cool-down (t=30600ms)
  breaker: cool-down passed, half-open, one trial call
    gateway call #7: ok
  breaker: trial succeeded, closed
  => order-4 charged 2500
*/
