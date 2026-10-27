<?php

declare(strict_types=1);

namespace Demo\Cache;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\AfterReturning;
use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

// The result of the method may be served from the cache, in the named region.
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Cacheable
{
    public function __construct(public readonly string $region)
    {
    }
}

// After the method returns, drop every cached entry of the region.
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CacheEvict
{
    public function __construct(public readonly string $region)
    {
    }
}

// A final class with a private method: a proxy-based AOP library can neither
// subclass the class nor override the private method. Weaving the method body
// itself has no such limit.
final class ExchangeRates
{
    public int $lookups = 0;

    public function convert(string $currency, int $cents): int
    {
        return intdiv($cents * $this->rate($currency), 100);
    }

    #[Cacheable('rates')]
    private function rate(string $currency): int
    {
        ++$this->lookups;
        echo "  (slow lookup of $currency)\n";

        return ['USD' => 110, 'GBP' => 85][$currency];
    }

    #[CacheEvict('rates')]
    public function refresh(): void
    {
        echo "  rates refreshed\n";
    }
}

final class ProductCatalog
{
    #[Cacheable('products')]
    public function title(int $id): string
    {
        echo "  (loading product $id)\n";

        return "Product #$id";
    }
}

#[Aspect]
final class CachingAspect
{
    /** @var array<string, array<string, mixed>> region => key => value */
    private array $entries = [];
    public int $hits = 0;
    public int $misses = 0;

    #[Around('execution(* *(..)) && @annotation(cacheable)')]
    public function cache(ProceedingJoinPointInterface $pjp, Cacheable $cacheable): mixed
    {
        $key = $pjp->getSignature()->toShortString().json_encode($pjp->getArgs());

        if (\array_key_exists($key, $this->entries[$cacheable->region] ?? [])) {
            ++$this->hits;
            echo "  HIT  $key\n";

            return $this->entries[$cacheable->region][$key];
        }

        ++$this->misses;
        echo "  MISS $key\n";

        return $this->entries[$cacheable->region][$key] = $pjp->proceed();
    }

    #[AfterReturning(pointcut: 'execution(* *(..)) && @annotation(evict)')]
    public function evict(JoinPointInterface $jp, CacheEvict $evict): void
    {
        unset($this->entries[$evict->region]);
        echo "  evicted region {$evict->region}\n";
    }
}

$cache = new CachingAspect();
Weaver::fromAspects([$cache])->enable();

$rates = new ExchangeRates();
$catalog = new ProductCatalog();

echo "private method on a final class:\n";
echo '  '.$rates->convert('USD', 1000)."\n";
echo '  '.$rates->convert('USD', 2000)."\n";
echo '  '.$rates->convert('GBP', 1000)."\n";

echo "different arguments, different entries:\n";
echo '  '.$catalog->title(1)."\n";
echo '  '.$catalog->title(2)."\n";
echo '  '.$catalog->title(1)."\n";

echo "eviction:\n";
$rates->refresh();
echo '  '.$rates->convert('USD', 1000)."\n";

echo "{$cache->hits} hits, {$cache->misses} misses, {$rates->lookups} slow rate lookups\n";

/* Expected output:
private method on a final class:
  MISS ExchangeRates::rate(..)["USD"]
  (slow lookup of USD)
  1100
  HIT  ExchangeRates::rate(..)["USD"]
  2200
  MISS ExchangeRates::rate(..)["GBP"]
  (slow lookup of GBP)
  850
different arguments, different entries:
  MISS ProductCatalog::title(..)[1]
  (loading product 1)
  Product #1
  MISS ProductCatalog::title(..)[2]
  (loading product 2)
  Product #2
  HIT  ProductCatalog::title(..)[1]
  Product #1
eviction:
  rates refreshed
  evicted region rates
  MISS ExchangeRates::rate(..)["USD"]
  (slow lookup of USD)
  1100
2 hits, 5 misses, 3 slow rate lookups
*/
