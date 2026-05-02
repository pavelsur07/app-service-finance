<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class WbInitialSyncStartDateResolverTest extends TestCase
{
    public function testAcceptsYesterdayOverride(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings(['wb_initial_sync_start_date' => '2026-05-09']);

        $rawRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawRepository->expects(self::never())->method('findMinPeriodFromForSuccessfulDocuments');

        $resolver = new WbInitialSyncStartDateResolver($rawRepository, new MockClock('2026-05-10 00:00:00'));

        self::assertSame('2026-05-09', $resolver->resolve($company, $connection)->format('Y-m-d'));
    }

    public function testIgnoresTodayOverrideAndUsesLocalRawDocuments(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);
        $connection->setSettings(['wb_initial_sync_start_date' => '2026-05-10']);

        $rawRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawRepository->expects(self::once())
            ->method('findMinPeriodFromForSuccessfulDocuments')
            ->willReturn(new \DateTimeImmutable('2026-04-20 00:00:00'));

        $resolver = new WbInitialSyncStartDateResolver($rawRepository, new MockClock('2026-05-10 00:00:00'));

        self::assertSame('2026-04-20', $resolver->resolve($company, $connection)->format('Y-m-d'));
    }

    public function testIgnoresFutureOverrideAndUsesFallbackWhenNoLocalRawDocuments(): void
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
