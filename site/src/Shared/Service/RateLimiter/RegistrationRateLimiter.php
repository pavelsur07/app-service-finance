<?php

declare(strict_types=1);

namespace App\Shared\Service\RateLimiter;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class RegistrationRateLimiter
{
    public function __construct(
        private readonly ?object $factory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function consume(string $identifier, int $tokens = 1): bool
    {
        if (null !== $this->factory && is_a($this->factory, RateLimiterFactory::class, false)) {
            return $this->factory->create($identifier)->consume($tokens)->isAccepted();
        }

        $this->logger->error('Registration rate limiter factory is missing, request blocked', [
            'identifier' => $identifier,
        ]);

        return false;
    }
}
