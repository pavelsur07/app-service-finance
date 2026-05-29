<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class WbFinanceRateLimiter
{
    private const HASH_PREFIX_LENGTH = 8;

    public function __construct(
        private readonly RateLimiterFactory $factory,
        private readonly ClockInterface $clock,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    private LoggerInterface $logger;

    public function tryConsume(string $sellerRateLimitKey, int $tokens = 1): ?\DateTimeImmutable
    {
        $limit = $this->factory->create($sellerRateLimitKey)->consume($tokens);
        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfter = $limit->getRetryAfter();
        $waitSeconds = max(1, $retryAfter->getTimestamp() - $this->clock->now()->getTimestamp());
        $this->logger->info('WB finance throttle bucket busy.', [
            'seller_token_hash_prefix' => $this->extractHashPrefix($sellerRateLimitKey),
            'retry_after' => $retryAfter->format(\DateTimeInterface::ATOM),
            'wait_seconds' => $waitSeconds,
        ]);

        return $retryAfter;
    }

    private function extractHashPrefix(string $sellerRateLimitKey): string
    {
        $separatorPosition = strrpos($sellerRateLimitKey, ':');
        $hash = false === $separatorPosition ? $sellerRateLimitKey : substr($sellerRateLimitKey, $separatorPosition + 1);

        return substr($hash, 0, self::HASH_PREFIX_LENGTH);
    }
}
