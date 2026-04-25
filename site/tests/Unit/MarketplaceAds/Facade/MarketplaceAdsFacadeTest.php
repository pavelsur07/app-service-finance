<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Facade;

use App\MarketplaceAds\Facade\MarketplaceAdsFacade;
use App\MarketplaceAds\Infrastructure\Query\AdDocumentQuery;
use App\MarketplaceAds\Infrastructure\Query\AdSpendByListingQuery;
use PHPUnit\Framework\TestCase;

final class MarketplaceAdsFacadeTest extends TestCase
{
    public function testGetAdSpendByListingForPeriodDelegatesToQuery(): void
    {
        $companyId = '11111111-1111-1111-1111-000000000001';
        $from = new \DateTimeImmutable('2026-04-01');
        $to = new \DateTimeImmutable('2026-04-30');
        $marketplace = 'ozon';

        $expected = [
            '55555555-5555-5555-5555-000000000001' => '150.00',
            '55555555-5555-5555-5555-000000000002' => '70.50',
        ];

        $adSpendByListingQuery = $this->createMock(AdSpendByListingQuery::class);
        $adSpendByListingQuery
            ->expects(self::once())
            ->method('getByListingForPeriod')
            ->with($companyId, $from, $to, $marketplace)
            ->willReturn($expected);

        $adDocumentQuery = $this->createMock(AdDocumentQuery::class);
        $adDocumentQuery->expects(self::never())->method(self::anything());

        $facade = new MarketplaceAdsFacade($adDocumentQuery, $adSpendByListingQuery);

        $result = $facade->getAdSpendByListingForPeriod($companyId, $from, $to, $marketplace);

        self::assertSame($expected, $result);
    }

    public function testGetAdSpendByListingForPeriodPassesNullMarketplaceByDefault(): void
    {
        $companyId = '11111111-1111-1111-1111-000000000001';
        $from = new \DateTimeImmutable('2026-04-01');
        $to = new \DateTimeImmutable('2026-04-30');

        $adSpendByListingQuery = $this->createMock(AdSpendByListingQuery::class);
        $adSpendByListingQuery
            ->expects(self::once())
            ->method('getByListingForPeriod')
            ->with($companyId, $from, $to, null)
            ->willReturn([]);

        $adDocumentQuery = $this->createMock(AdDocumentQuery::class);

        $facade = new MarketplaceAdsFacade($adDocumentQuery, $adSpendByListingQuery);

        self::assertSame([], $facade->getAdSpendByListingForPeriod($companyId, $from, $to));
    }
}
