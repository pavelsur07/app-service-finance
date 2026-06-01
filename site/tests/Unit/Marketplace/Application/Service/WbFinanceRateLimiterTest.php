<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class WbFinanceRateLimiterTest extends TestCase
{
    public function testTryConsumeReturnsNullWhenTokenIsAcceptedAndRetryAfterWhenBucketIsBusy(): void
    {
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $limiter = $this->createLimiter($clock);
        $sellerRateLimitKey = 'wb_finance_sales_reports:'.hash('sha256', 'token-a');

        self::assertNull($limiter->tryConsume($sellerRateLimitKey));
        $retryAfter = $limiter->tryConsume($sellerRateLimitKey);

        self::assertNotNull($retryAfter);
        self::assertGreaterThan($clock->now()->getTimestamp(), $retryAfter->getTimestamp());
        self::assertSame('2026-01-01T00:00:00+00:00', $clock->now()->format(DATE_ATOM));
    }

    public function testTryConsumeUsesSeparateBucketsForDifferentSellerRateLimitKeys(): void
    {
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $limiter = $this->createLimiter($clock);

        self::assertNull($limiter->tryConsume('wb_finance_sales_reports:'.hash('sha256', 'token-a')));
        self::assertNull($limiter->tryConsume('wb_finance_sales_reports:'.hash('sha256', 'token-b')));

        self::assertSame('2026-01-01T00:00:00+00:00', $clock->now()->format(DATE_ATOM));
    }

    public function testResolveSalesReportsBucketIdFallsBackToConnectionWhenSettingsHaveNoSellerIdentifiers(): void
    {
        $connectionId = '6eada2b7-b453-4c33-a92a-e7dce52e291c';
        $connection = new MarketplaceConnection(
            $connectionId,
            CompanyBuilder::aCompany()->build(),
            MarketplaceType::WILDBERRIES,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setSettings(['name' => 'WB account without seller id']);

        $limiter = $this->createLimiter();

        self::assertSame('connection:'.$connectionId, $limiter->resolveSalesReportsBucketId($connection));
        self::assertSame('connection', $limiter->resolveSalesReportsBucketSource($connection));
        self::assertNotSame('global', $limiter->resolveSalesReportsBucketId($connection));
        self::assertSame('wb_finance_sales_reports:connection:'.$connectionId, $limiter->buildSalesReportsRateLimitKeyForSellerBucket($limiter->resolveSalesReportsBucketId($connection)));
        self::assertSame('wb_finance:sales_reports:cooldown:connection:'.$connectionId, $limiter->buildSalesReportsCooldownKey($limiter->resolveSalesReportsBucketId($connection)));
    }

    public function testResolveSalesReportsBucketIdUsesConnectionEvenWhenSellerSettingsExist(): void
    {
        $connection = new MarketplaceConnection(
            '6eada2b7-b453-4c33-a92a-e7dce52e291c',
            CompanyBuilder::aCompany()->build(),
            MarketplaceType::WILDBERRIES,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setSettings(['account_id' => 'account-42']);

        $limiter = $this->createLimiter();

        self::assertSame('connection:'.$connection->getId(), $limiter->resolveSalesReportsBucketId($connection));
        self::assertSame('connection', $limiter->resolveSalesReportsBucketSource($connection));
    }

    private function createLimiter(?MockClock $clock = null): WbFinanceRateLimiter
    {
        $factory = new RateLimiterFactory([
            'id' => 'wb_finance',
            'policy' => 'token_bucket',
            'limit' => 1,
            'rate' => ['interval' => '1 second', 'amount' => 1],
        ], new InMemoryStorage());

        return new WbFinanceRateLimiter($factory, $clock ?? new MockClock());
    }
}
