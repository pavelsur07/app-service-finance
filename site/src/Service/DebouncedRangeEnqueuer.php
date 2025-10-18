<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DebouncedRangeEnqueuer
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function shouldEnqueueCompanyDay(string $companyId, \DateTimeImmutable $day): bool
    {
        $key = 'auto_rules:enqueue:'.$companyId.':'.$day->format('Y-m-d');

        return $this->cache->get($key, function (ItemInterface $item) {
            $item->expiresAfter(120);

            return true;
        });
    }
}
