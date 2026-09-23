<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Facade;

use App\Marketplace\Facade\WbFinanceThrottleFacade;
use App\Marketplace\Wildberries\Application\Service\WbFinanceCooldownStorageInterface;
use App\Marketplace\Wildberries\Application\Service\WbFinanceRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class WbFinanceThrottleFacadeTest extends TestCase
{
    private const BUCKET = 'connection:connection-1';
    private const COOLDOWN_KEY = 'wb_finance:sales_reports:cooldown:connection:connection-1';

    private MockClock $clock;
    private WbFinanceCooldownStorageInterface $storage;
    private WbFinanceThrottleFacade $facade;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-06-22T00:00:00+00:00');
        $this->storage = new class implements WbFinanceCooldownStorageInterface {
            /** @var array<string, int> */
            private array $values = [];

            public function getUntilTimestamp(string $key): ?int
            {
                return $this->values[$key] ?? null;
            }

            public function setUntilTimestamp(string $key, int $untilTimestamp, int $ttlSeconds): void
            {
                $this->values[$key] = $untilTimestamp;
            }
        };
        $this->facade = new WbFinanceThrottleFacade(new WbFinanceRateLimiter(
            new RateLimiterFactory(
                ['id' => 'wb_finance', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '70 seconds'],
                new InMemoryStorage(),
            ),
            $this->clock,
            null,
            $this->storage,
        ));
    }

    public function testFreeSlotIsReserved(): void
    {
        self::assertNull($this->facade->reserveSalesReportsSlot(self::BUCKET));
    }

    public function testExhaustedLocalBucketIsDeniedWithoutSharedCooldown(): void
    {
        $this->facade->reserveSalesReportsSlot(self::BUCKET);

        $denied = $this->facade->reserveSalesReportsSlot(self::BUCKET);

        self::assertNotNull($denied);
        self::assertFalse($denied->sharedCooldown);
        self::assertGreaterThanOrEqual(1, $denied->waitSeconds);
    }

    public function testActiveSharedCooldownDeniesBeforeTouchingLocalBucket(): void
    {
        $this->storage->setUntilTimestamp(self::COOLDOWN_KEY, $this->clock->now()->getTimestamp() + 30, 30);

        $denied = $this->facade->reserveSalesReportsSlot(self::BUCKET);

        self::assertNotNull($denied);
        self::assertTrue($denied->sharedCooldown);
        self::assertSame(30, $denied->waitSeconds);

        // Локальный токен не потрачен: после cooldown слот свободен.
        $this->clock->sleep(31);
        self::assertNull($this->facade->reserveSalesReportsSlot(self::BUCKET));
    }

    public function testRemote429WritesSharedCooldownAndReturnsWait(): void
    {
        $waitSeconds = $this->facade->registerSalesReportsRemote429(self::BUCKET, 12, 70);

        self::assertSame(12, $waitSeconds);
        self::assertSame($this->clock->now()->getTimestamp() + 12, $this->storage->getUntilTimestamp(self::COOLDOWN_KEY));
        self::assertTrue($this->facade->reserveSalesReportsSlot(self::BUCKET)?->sharedCooldown);
    }

    public function testRemote429WithoutRetryHeaderUsesDefaultPlusBuffer(): void
    {
        self::assertSame(85, $this->facade->registerSalesReportsRemote429(self::BUCKET, null, 70));
    }
}
