<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Entity\MarketplaceConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class WbFinanceRateLimiter
{
    private const HASH_PREFIX_LENGTH = 8;
    private const COOLDOWN_KEY_PREFIX = 'wb_finance:sales_reports:cooldown';
    private const GLOBAL_BUCKET = 'global';
    private const REMOTE_429_COOLDOWN_BUFFER_SECONDS = 15;

    public function __construct(
        private readonly RateLimiterFactory $factory,
        private readonly ClockInterface $clock,
        ?LoggerInterface $logger = null,
        private readonly ?WbFinanceCooldownStorageInterface $cooldownStorage = null,
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

    public function resolveSalesReportsBucketId(MarketplaceConnection $connection): string
    {
        return $this->resolveSalesReportsBucket($connection)['bucket_id'];
    }

    public function resolveSalesReportsBucketSource(MarketplaceConnection $connection): string
    {
        return $this->resolveSalesReportsBucket($connection)['bucket_source'];
    }

    /** @return array{bucket_id: string, bucket_source: string} */
    public function resolveSalesReportsBucket(MarketplaceConnection $connection): array
    {
        $settings = $connection->getSettings() ?? [];
        $settingSources = [
            'sellerId' => 'seller_id',
            'seller_id' => 'seller_id',
            'accountId' => 'account_id',
            'account_id' => 'account_id',
            'supplierId' => 'supplier_id',
            'supplier_id' => 'supplier_id',
            'wbSellerId' => 'wb_seller_id',
            'wb_seller_id' => 'wb_seller_id',
        ];

        foreach ($settingSources as $key => $source) {
            $value = $settings[$key] ?? null;
            if (is_scalar($value)) {
                $normalized = $this->normalizeBucketPart((string) $value);
                if ('' !== $normalized) {
                    return ['bucket_id' => $normalized, 'bucket_source' => $source];
                }
            }
        }

        return [
            'bucket_id' => 'connection:'.$this->normalizeBucketPart($connection->getId()),
            'bucket_source' => 'connection',
        ];
    }

    public function resolveSalesReportsSellerBucketId(MarketplaceConnection $connection): string
    {
        return $this->resolveSalesReportsBucketId($connection);
    }

    public function buildSalesReportsRateLimitKeyForSellerBucket(string $sellerBucketId): string
    {
        $normalized = $this->normalizeBucketPart($sellerBucketId);
        if ('' === $normalized) {
            $normalized = self::GLOBAL_BUCKET;
        }

        return 'wb_finance_sales_reports:'.$normalized;
    }

    public function secondsUntil(\DateTimeImmutable $retryAfter): int
    {
        return max(1, $retryAfter->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    public function cooldownUntilAfterRemote429(?int $retryAfterSeconds, int $defaultSeconds = 70): \DateTimeImmutable
    {
        $seconds = null !== $retryAfterSeconds
            ? max(1, $retryAfterSeconds)
            : max(1, $defaultSeconds) + self::REMOTE_429_COOLDOWN_BUFFER_SECONDS;

        return $this->clock->now()->modify(sprintf('+%d seconds', $seconds));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function getActiveSalesReportsCooldownUntil(string $sellerBucketId): ?\DateTimeImmutable
    {
        if (null === $this->cooldownStorage) {
            return null;
        }

        $timestamp = $this->cooldownStorage->getUntilTimestamp($this->buildSalesReportsCooldownKey($sellerBucketId));
        if (null === $timestamp) {
            return null;
        }

        $now = $this->clock->now()->getTimestamp();
        if ($timestamp <= $now) {
            return null;
        }

        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone($this->clock->now()->getTimezone());
    }

    public function setSalesReportsCooldownUntil(string $sellerBucketId, \DateTimeImmutable $cooldownUntil): void
    {
        if (null === $this->cooldownStorage) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $timestamp = $cooldownUntil->getTimestamp();
        if ($timestamp <= $now) {
            return;
        }

        $key = $this->buildSalesReportsCooldownKey($sellerBucketId);
        $currentTimestamp = $this->cooldownStorage->getUntilTimestamp($key);
        $effectiveTimestamp = max($timestamp, $currentTimestamp ?? 0);
        $ttlSeconds = max(1, $effectiveTimestamp - $now);
        $this->cooldownStorage->setUntilTimestamp($key, $effectiveTimestamp, $ttlSeconds);
    }

    public function buildSalesReportsCooldownKey(string $sellerBucketId): string
    {
        $normalized = $this->normalizeBucketPart($sellerBucketId);
        if ('' === $normalized || self::GLOBAL_BUCKET === $normalized) {
            return self::COOLDOWN_KEY_PREFIX.':'.self::GLOBAL_BUCKET;
        }

        return self::COOLDOWN_KEY_PREFIX.':'.$normalized;
    }

    private function normalizeBucketPart(string $value): string
    {
        $normalized = trim($value);
        if ('' === $normalized) {
            return '';
        }

        return preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', $normalized) ?? '';
    }

    private function extractHashPrefix(string $sellerRateLimitKey): string
    {
        $separatorPosition = strrpos($sellerRateLimitKey, ':');
        $hash = false === $separatorPosition ? $sellerRateLimitKey : substr($sellerRateLimitKey, $separatorPosition + 1);

        return substr($hash, 0, self::HASH_PREFIX_LENGTH);
    }
}
