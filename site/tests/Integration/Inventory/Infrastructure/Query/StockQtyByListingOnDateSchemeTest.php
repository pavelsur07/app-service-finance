<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Infrastructure\Query;

use App\Inventory\Enum\FulfillmentScheme;
use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Query\StockQtyByListingOnDateQuery;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Фильтр схемы FBO / FBS в остатках на дату: отчёту «Оборачиваемость» нужны
 * деньги только на складе маркетплейса или только на складе продавца.
 * Фильтр применяется к строкам выбранной сессии, а не к выбору сессии.
 */
final class StockQtyByListingOnDateSchemeTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000711';
    private const LISTING_A = '55555555-5555-5555-5555-000000000711';
    private const LISTING_B = '55555555-5555-5555-5555-000000000712';
    private const SESSION_ID = '22222222-2222-2222-2222-000000000711';

    private StockQtyByListingOnDateQuery $query;
    private \DateTimeImmutable $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = self::getContainer()->get(StockQtyByListingOnDateQuery::class);
        $this->date = new \DateTimeImmutable('today');
    }

    public function testSchemeFilterSeparatesFboAndFbs(): void
    {
        $this->persistSnapshot(self::LISTING_A, 'fbo', '10.000', '01');
        $this->persistSnapshot(self::LISTING_A, 'fbs', '4.000', '02');
        $this->persistSnapshot(self::LISTING_B, 'rfbs', '3.000', '03');
        $this->em->flush();

        self::assertSame(
            [self::LISTING_A => 14.0, self::LISTING_B => 3.0],
            $this->query->execute(self::COMPANY_ID, $this->date)->qtyByListingId,
        );
        self::assertSame(
            [self::LISTING_A => 10.0],
            $this->query->execute(self::COMPANY_ID, $this->date, null, FulfillmentScheme::Fbo)->qtyByListingId,
        );
        self::assertSame(
            [self::LISTING_A => 4.0, self::LISTING_B => 3.0],
            $this->query->execute(self::COMPANY_ID, $this->date, null, FulfillmentScheme::Fbs)->qtyByListingId,
        );
    }

    public function testSchemeWithoutRowsGivesEmptyQtyButKeepsSnapshotDate(): void
    {
        $this->persistSnapshot(self::LISTING_A, 'fbo', '10.000', '04');
        $this->em->flush();

        $result = $this->query->execute(self::COMPANY_ID, $this->date, null, FulfillmentScheme::Fbs);

        self::assertSame([], $result->qtyByListingId);
        self::assertSame(['ozon' => $this->date->format('Y-m-d')], $result->snapshotDateBySource);
        self::assertSame([], $result->staleSources);
    }

    private function persistSnapshot(string $listingId, string $fulfillmentType, string $quantity, string $tail): void
    {
        $this->em->persist(
            StockSnapshotBuilder::aStockSnapshot()
                ->withCompanyId(self::COMPANY_ID)
                ->withSnapshotSessionId(self::SESSION_ID)
                ->withSnapshotDate($this->date)
                ->withSnapshotAt($this->date->setTime(4, 5))
                ->withSource(MarketplaceType::OZON)
                ->withListingId($listingId)
                ->withProductId(null)
                ->withStatus(StockStatus::Available)
                ->withFulfillmentType($fulfillmentType)
                ->withQuantity($quantity)
                ->build(),
        );
    }
}
