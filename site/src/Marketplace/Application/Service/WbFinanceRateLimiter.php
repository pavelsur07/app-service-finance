<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use Symfony\Component\RateLimiter\RateLimiterFactory;

final class WbFinanceRateLimiter
{
    public function __construct(private readonly ?RateLimiterFactory $factory = null)
    {
    }

    public function acquire(string $connectionId, int $tokens = 1): bool
    {
        if (null !== $this->factory) {
            return $this->factory->create($this->buildKey($connectionId))->consume($tokens)->isAccepted();
        }

        return true;
    }

    private function buildKey(string $connectionId): string
    {
        return sprintf('wb_finance:%s', $connectionId);
    }
}
