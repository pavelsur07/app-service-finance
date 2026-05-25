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
    public function testWaitSleepsBeforeSecondRequestForSameConnectionWithinMinute(): void
    {
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $limiter = $this->createLimiter($clock);
        $connectionId = '6c3a0e9f-1c09-4a2f-a63f-27f3eddd67df';

        $limiter->wait($connectionId);
        $limiter->wait($connectionId);

        self::assertSame('2026-01-01T00:01:01+00:00', $clock->now()->format(DATE_ATOM));
    }

    public function testWaitUsesSeparateBucketsForDifferentConnections(): void
    {
        $clock = new MockClock('2026-01-01T00:00:00Z');
        $limiter = $this->createLimiter($clock);

        $limiter->wait('6c3a0e9f-1c09-4a2f-a63f-27f3eddd67df');
        $limiter->wait('4d91db78-6c9b-4fb9-8478-24ab4c68253f');

        self::assertSame('2026-01-01T00:00:00+00:00', $clock->now()->format(DATE_ATOM));
    }

    private function createLimiter(?MockClock $clock = null): WbFinanceRateLimiter
    {
        $factory = new RateLimiterFactory([
            'id' => 'wb_finance',
            'policy' => 'token_bucket',
            'limit' => 1,
            'rate' => ['interval' => '61 seconds', 'amount' => 1],
        ], new InMemoryStorage());

        return new WbFinanceRateLimiter($factory, $clock ?? new MockClock());
    }
}
