<?php

declare(strict_types=1);

namespace App\Api\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class ApiRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.external_api_key')]
        private RateLimiterFactory $keys,
        #[Autowire(service: 'limiter.external_api_failures')]
        private RateLimiterFactory $failures,
    ) {
    }

    public function checkFailures(string $ip): void
    {
        $limit = $this->failures->create($ip)->consume(0);
        if (!$limit->isAccepted() || $limit->getRemainingTokens() < 1) {
            throw new TooManyRequestsHttpException(60, 'Too many unsuccessful authentication attempts.');
        }
    }

    public function failed(string $ip): void
    {
        if (!$this->failures->create($ip)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(60, 'Too many unsuccessful authentication attempts.');
        }
    }

    public function consumeKey(string $keyId): void
    {
        $limit = $this->keys->create($keyId)->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'API key rate limit exceeded.');
        }
    }
}
