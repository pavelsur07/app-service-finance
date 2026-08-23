<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Application\RecalculateListingSnapshotsAction;
use App\MarketplaceAnalytics\Domain\Service\SnapshotCalculationPolicy;
use App\MarketplaceAnalytics\Entity\ListingDailySnapshot;
use DG\BypassFinals;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

BypassFinals::allowPaths([
    '*/src/MarketplaceAnalytics/Domain/Service/SnapshotCalculationPolicy.php',
]);

final class RecalculateListingSnapshotsActionTest extends TestCase
{
    public function testRecalculatesUniqueListingsForEveryDayAndFlushesTheBatch(): void
    {
        $calls = [];
        $snapshotPolicy = $this->createMock(SnapshotCalculationPolicy::class);
        $snapshotPolicy->expects(self::exactly(4))
            ->method('calculateForListingDay')
            ->willReturnCallback(function (
                string $companyId,
                string $listingId,
                \DateTimeImmutable $date,
                string $marketplace,
            ) use (&$calls): ListingDailySnapshot {
                $calls[] = [$companyId, $listingId, $date->format('Y-m-d'), $marketplace];

                return $this->createMock(ListingDailySnapshot::class);
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::once())->method('clear');

        $action = new RecalculateListingSnapshotsAction($snapshotPolicy, $entityManager);
        $action(
            '11111111-1111-1111-1111-111111111111',
            ['listing-a', 'listing-a', 'listing-b'],
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-02'),
            'ozon',
        );

        self::assertSame([
            ['11111111-1111-1111-1111-111111111111', 'listing-a', '2026-08-01', 'ozon'],
            ['11111111-1111-1111-1111-111111111111', 'listing-a', '2026-08-02', 'ozon'],
            ['11111111-1111-1111-1111-111111111111', 'listing-b', '2026-08-01', 'ozon'],
            ['11111111-1111-1111-1111-111111111111', 'listing-b', '2026-08-02', 'ozon'],
        ], $calls);
    }
}
