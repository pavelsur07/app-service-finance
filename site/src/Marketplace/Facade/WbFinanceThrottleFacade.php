<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\Application\DTO\WbFinanceThrottleDTO;
use App\Marketplace\Wildberries\Application\Service\WbFinanceRateLimiter;

/**
 * Троттлинг WB finance sales-reports для других модулей (Ingestion
 * `WbFinanceReportClient`).
 *
 * Бакеты и cooldown общие с `SyncWbFinancialReportDayHandler`: за фасадом тот же
 * сервис `WbFinanceRateLimiter` (`limiter.wb_finance` + Redis cooldown), поэтому
 * 429 в одном потоке тормозит оба.
 */
final readonly class WbFinanceThrottleFacade
{
    public function __construct(
        private WbFinanceRateLimiter $rateLimiter,
    ) {
    }

    /**
     * Резервирует слот под один запрос. `null` — запрос можно отправлять.
     */
    public function reserveSalesReportsSlot(string $sellerBucketId): ?WbFinanceThrottleDTO
    {
        $cooldownUntil = $this->rateLimiter->getActiveSalesReportsCooldownUntil($sellerBucketId);
        if (null !== $cooldownUntil) {
            return new WbFinanceThrottleDTO($this->rateLimiter->secondsUntil($cooldownUntil), true);
        }

        $retryAfter = $this->rateLimiter->tryConsume(
            $this->rateLimiter->buildSalesReportsRateLimitKeyForSellerBucket($sellerBucketId),
        );
        if (null === $retryAfter) {
            return null;
        }

        return new WbFinanceThrottleDTO($this->rateLimiter->secondsUntil($retryAfter), false);
    }

    /**
     * Фиксирует 429 от WB общим cooldown и возвращает, сколько секунд ждать.
     */
    public function registerSalesReportsRemote429(string $sellerBucketId, ?int $retryAfterSeconds, int $defaultSeconds): int
    {
        $cooldownUntil = $this->rateLimiter->cooldownUntilAfterRemote429($retryAfterSeconds, $defaultSeconds);
        $this->rateLimiter->setSalesReportsCooldownUntil($sellerBucketId, $cooldownUntil);

        return $this->rateLimiter->secondsUntil($cooldownUntil);
    }
}
