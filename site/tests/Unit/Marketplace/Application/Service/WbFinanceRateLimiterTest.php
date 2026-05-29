<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class WbFinanceRateLimiterTest extends TestCase
{
    public function testWaitSleepsBeforeSecondRequestForSameSellerRateLimitKey(): void
    {
        $clock = new MockClock('@'.time());
        $startTimestamp = $clock->now()->getTimestamp();
        $limiter = $this->createLimiter($clock);
        $sellerRateLimitKey = 'wb_finance_sales_reports:'.hash('sha256', 'token-a');

        $limiter->wait($sellerRateLimitKey);
        $limiter->wait($sellerRateLimitKey);

        self::assertGreaterThan(0, $clock->now()->getTimestamp() - $startTimestamp);
    }

    public function testWaitUsesSeparateBucketsForDifferentSellerRateLimitKeys(): void
    {
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $limiter = $this->createLimiter($clock);

        $limiter->wait('wb_finance_sales_reports:'.hash('sha256', 'token-a'));
        $limiter->wait('wb_finance_sales_reports:'.hash('sha256', 'token-b'));

        self::assertSame('2026-01-01T00:00:00+00:00', $clock->now()->format(DATE_ATOM));
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
