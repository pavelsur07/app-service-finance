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

        // Fail-close: без factory защитный контроль недоступен, поэтому блокируем операцию
        $this->logger->error('Password change rate limiter factory is missing, request blocked', [
            'identifier' => $identifier,
        ]);

        return false;
    }

    public function reset(string $identifier): void
    {
        if (null !== $this->factory && is_a($this->factory, RateLimiterFactoryInterface::class, false)) {
            $this->factory->create($identifier)->reset();
        }
    }
}
