<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class WbFinanceRateLimiterTest extends TestCase
{
    public function testAcquireRejectsSecondRequestForSameConnectionWithinMinute(): void
    {
        $limiter = $this->createLimiter();
        $connectionId = '6c3a0e9f-1c09-4a2f-a63f-27f3eddd67df';

        self::assertTrue($limiter->acquire($connectionId));
        self::assertFalse($limiter->acquire($connectionId));
    }

    public function testAcquireUsesSeparateBucketsForDifferentConnections(): void
    {
        $limiter = $this->createLimiter();

        self::assertTrue($limiter->acquire('6c3a0e9f-1c09-4a2f-a63f-27f3eddd67df'));
        self::assertTrue($limiter->acquire('4d91db78-6c9b-4fb9-8478-24ab4c68253f'));
    }

    private function createLimiter(): WbFinanceRateLimiter
    {
        $factory = new RateLimiterFactory(
            [
                'id' => 'wb_finance',
                'policy' => 'fixed_window',
                'limit' => 1,
                'interval' => '1 minute',
            ],
            new InMemoryStorage(),
        );

        return new WbFinanceRateLimiter($factory);
    }
}
