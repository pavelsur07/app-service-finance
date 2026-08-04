<?php

declare(strict_types=1);

namespace App\Shared\Service\RateLimiter;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class PasswordChangeRateLimiter
{
    public function __construct(
        private readonly ?object $factory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function consume(string $identifier, int $tokens = 1): bool
    {
        if (null !== $this->factory && is_a($this->factory, RateLimiterFactoryInterface::class, false)) {
            return $this->factory->create($identifier)->consume($tokens)->isAccepted();
        }

        // Fail-open, но с сигналом: без factory защитный контроль фактически отключён
        $this->logger->warning('Password change rate limiter factory is missing, request allowed', [
            'identifier' => $identifier,
        ]);

        return true;
    }

    public function reset(string $identifier): void
    {
        if (null !== $this->factory && is_a($this->factory, RateLimiterFactoryInterface::class, false)) {
            $this->factory->create($identifier)->reset();
        }
    }
}
