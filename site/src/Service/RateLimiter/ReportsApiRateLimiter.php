<?php

namespace App\Service\RateLimiter;

use Symfony\Component\RateLimiter\RateLimiterFactory;

final class ReportsApiRateLimiter
{
    public function __construct(private readonly ?object $factory = null)
    {
    }

    public function consume(string $identifier, int $tokens = 1): bool
    {
        if ($this->factory !== null && is_a($this->factory, RateLimiterFactory::class, false)) {
            return $this->factory->create($identifier)->consume($tokens)->isAccepted();
        }

        return true;
    }
}
