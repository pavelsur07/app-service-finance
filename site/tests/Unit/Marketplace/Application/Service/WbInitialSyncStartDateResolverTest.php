<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Service\Integration\WildberriesAdapter;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class WbInitialSyncStartDateResolverTest extends TestCase
{
    public function testUsesValidCachedStartDateWithoutDiscovery(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings(['wb_initial_sync_start_date' => '2026-04-01', 'keep' => 'me']);

        $adapter = $this->createMock(WildberriesAdapter::class);
        $adapter->expects(self::never())->method('hasRawReportData');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $resolver = new WbInitialSyncStartDateResolver($adapter, $em, new NullLogger(), new MockClock('2026-05-10 00:00:00'));
        $date = $resolver->resolve($company, $connection);

        self::assertSame('2026-04-01', $date?->format('Y-m-d'));
    }

    public function testDiscoversFromAprilWhenJanToMarchEmpty(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings(['keep' => 'me']);

        $adapter = $this->createMock(WildberriesAdapter::class);
        $adapter->method('hasRawReportData')->willReturnCallback(static fn ($c, $from) => (int) $from->format('n') >= 4);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $resolver = new WbInitialSyncStartDateResolver($adapter, $em, new NullLogger(), new MockClock('2026-05-10 00:00:00'));
        $date = $resolver->resolve($company, $connection);

        self::assertSame('2026-04-01', $date?->format('Y-m-d'));
        self::assertSame('me', $connection->getSettings()['keep']);
        self::assertSame('2026-04-01', $connection->getSettings()['wb_initial_sync_start_date']);
    }

    public function testPropagatesRateLimitException(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);

        $adapter = $this->createMock(WildberriesAdapter::class);
        $adapter->method('hasRawReportData')->willThrowException(new MarketplaceRateLimitException(429, 'rl', '2026-01-01', '2026-01-31', 12));

        $resolver = new WbInitialSyncStartDateResolver($adapter, $this->createMock(EntityManagerInterface::class), new NullLogger(), new MockClock('2026-05-10 00:00:00'));

        $this->expectException(MarketplaceRateLimitException::class);
        $resolver->resolve($company, $connection);
    }

    public function testInvalidCachedStartDateTriggersDiscovery(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings(['wb_initial_sync_start_date' => 'invalid-date']);

        $adapter = $this->createMock(WildberriesAdapter::class);
        $adapter->expects(self::once())->method('hasRawReportData')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $resolver = new WbInitialSyncStartDateResolver($adapter, $em, new NullLogger(), new MockClock('2026-05-10 00:00:00'));
        $date = $resolver->resolve($company, $connection);

        self::assertSame('2026-01-01', $date?->format('Y-m-d'));
    }

    public function testReturnsNullAndStoresNoDataMetadataWhenAllMonthsEmpty(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings(['keep' => 'me']);

        $adapter = $this->createMock(WildberriesAdapter::class);
        $adapter->method('hasRawReportData')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $resolver = new WbInitialSyncStartDateResolver($adapter, $em, new NullLogger(), new MockClock('2026-05-10 00:00:00'));
        $date = $resolver->resolve($company, $connection);

        self::assertNull($date);
        self::assertSame('me', $connection->getSettings()['keep']);
        self::assertArrayHasKey('wb_initial_sync_no_data_found_at', $connection->getSettings());
    }
}
