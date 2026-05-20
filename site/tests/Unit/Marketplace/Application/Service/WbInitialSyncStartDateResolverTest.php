<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Service\Integration\WildberriesAdapter;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class WbInitialSyncStartDateResolverTest extends TestCase
{
    public function testFallbackWhenNoSettingsAndNoRawDocumentsReturnsNowMinusSixtyDays(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings([]);

        $rawRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawRepository->expects(self::once())
            ->method('findMinPeriodFromForSuccessfulDocuments')
            ->willReturn(null);

        $resolver = new WbInitialSyncStartDateResolver($rawRepository, new MockClock('2026-05-10 12:34:56'));

        self::assertSame('2026-03-11', $resolver->resolve($company, $connection)->format('Y-m-d'));
    }

    public function testReturnsMinPeriodFromWhenCompletedRawDocumentsExist(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);

        $rawRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawRepository->expects(self::once())
            ->method('findMinPeriodFromForSuccessfulDocuments')
            ->with(
                $company,
                MarketplaceType::WILDBERRIES,
                'sales_report',
                WildberriesAdapter::FINANCE_API_ENDPOINT,
                self::callback(static function (\DateTimeImmutable $date): bool {
                    return '2026-01-01 00:00:00' === $date->format('Y-m-d H:i:s');
                }),
                self::callback(static function (\DateTimeImmutable $date): bool {
                    return '2026-05-09 00:00:00' === $date->format('Y-m-d H:i:s');
                }),
            )
            ->willReturn(new \DateTimeImmutable('2026-04-20 17:10:00'));

        $resolver = new WbInitialSyncStartDateResolver($rawRepository, new MockClock('2026-05-10 00:00:00'));

        self::assertSame('2026-04-20', $resolver->resolve($company, $connection)->format('Y-m-d'));
    }

    public function testSettingsOverrideReturnsConfiguredDate(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings(['wb_initial_sync_start_date' => '2026-05-09']);

        $rawRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawRepository->expects(self::never())->method('findMinPeriodFromForSuccessfulDocuments');

        $resolver = new WbInitialSyncStartDateResolver($rawRepository, new MockClock('2026-05-10 00:00:00 Europe/Moscow'));

        self::assertSame('2026-05-09', $resolver->resolve($company, $connection)->format('Y-m-d'));
    }

    public function testInvalidSettingsDateFallsBackToDefaultWindow(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings(['wb_initial_sync_start_date' => 'invalid-date']);

        $rawRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawRepository->expects(self::once())
            ->method('findMinPeriodFromForSuccessfulDocuments')
            ->willReturn(null);

        $resolver = new WbInitialSyncStartDateResolver($rawRepository, new MockClock('2026-05-10 00:00:00'));

        self::assertSame('2026-03-11', $resolver->resolve($company, $connection)->format('Y-m-d'));
    }

    public function testYesterdayOverrideIsAccepted(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings(['wb_initial_sync_start_date' => '2026-05-09']);

        $rawRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawRepository->expects(self::never())->method('findMinPeriodFromForSuccessfulDocuments');

        $resolver = new WbInitialSyncStartDateResolver($rawRepository, new MockClock('2026-05-10 00:00:00'));

        self::assertSame('2026-05-09', $resolver->resolve($company, $connection)->format('Y-m-d'));
    }

    public function testTodayOverrideIsIgnoredAndLocalRawDateIsUsed(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings(['wb_initial_sync_start_date' => '2026-05-10']);

        $rawRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawRepository->expects(self::once())
            ->method('findMinPeriodFromForSuccessfulDocuments')
            ->willReturn(new \DateTimeImmutable('2026-04-20 10:00:00'));

        $resolver = new WbInitialSyncStartDateResolver($rawRepository, new MockClock('2026-05-10 00:00:00'));

        self::assertSame('2026-04-20', $resolver->resolve($company, $connection)->format('Y-m-d'));
    }

    public function testFutureOverrideIsIgnoredAndFallbackIsUsedWhenNoLocalRawDocuments(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings(['wb_initial_sync_start_date' => '2026-05-11']);

        $rawRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawRepository->expects(self::once())
            ->method('findMinPeriodFromForSuccessfulDocuments')
            ->willReturn(null);

        $resolver = new WbInitialSyncStartDateResolver($rawRepository, new MockClock('2026-05-10 00:00:00'));

        self::assertSame('2026-03-11', $resolver->resolve($company, $connection)->format('Y-m-d'));
    }
}
