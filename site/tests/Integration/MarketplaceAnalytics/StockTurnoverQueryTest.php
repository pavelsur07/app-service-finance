<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAnalytics;

use App\Company\Entity\Company;
use App\Inventory\Enum\FulfillmentScheme;
use App\Inventory\Enum\StockStatus;
use App\Marketplace\Entity\Inventory\MarketplaceInventoryCostPrice;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\DTO\StockTurnoverFilter;
use App\MarketplaceAnalytics\DTO\StockTurnoverRow;
use App\MarketplaceAnalytics\Enum\TurnoverBucket;
use App\MarketplaceAnalytics\Infrastructure\Query\StockTurnoverQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class StockTurnoverQueryTest extends IntegrationTestCase
{
    private StockTurnoverQuery $query;
    private Company $company;
    private \DateTimeImmutable $date;
    /** @var array<string, MarketplaceListing> */
    private array $listings = [];
    private int $saleIndex = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = self::getContainer()->get(StockTurnoverQuery::class);
        $this->date = new \DateTimeImmutable('yesterday');

        $this->company = CompanyBuilder::aCompany()->withIndex(861)->build();
        $owner = $this->company->getUser();
        self::assertNotNull($owner);
        $this->em->persist($owner);
        $this->em->persist($this->company);
    }

    public function testRowsBucketsAndSummary(): void
    {
        $this->seedStandardSet();

        $report = $this->query->execute($this->filter());

        self::assertSame(['A', 'B', 'C', 'D'], $this->keys($report->rows));

        $a = $report->rows[0];
        self::assertSame(412, $a->stockQty);
        self::assertSame(39, $a->salesQty);
        self::assertSame(1.3, $a->salesPerDay);
        self::assertSame(316, $a->daysOfCover);
        self::assertSame('1800.00', $a->costPrice);
        self::assertSame('741600.00', $a->stockValue);
        self::assertSame(TurnoverBucket::Frozen, $a->bucket);
        self::assertSame('Худи серое', $a->title);
        self::assertSame('SKU-A', $a->sku);
        self::assertSame('ozon', $a->marketplace);

        self::assertSame(TurnoverBucket::Fast, $report->rows[1]->bucket);
        self::assertSame(7, $report->rows[1]->daysOfCover);
        self::assertSame(TurnoverBucket::NoSales, $report->rows[2]->bucket);
        self::assertNull($report->rows[2]->daysOfCover);

        $summary = $report->summary;
        self::assertSame('772400.00', $summary->totalValue);
        self::assertSame('743600.00', $summary->frozenValue);
        self::assertNotNull($summary->frozenShare);
        self::assertEqualsWithDelta(96.27, $summary->frozenShare, 0.01);
        self::assertSame('28800.00', $summary->valueByBucket['fast']);
        self::assertSame('0.00', $summary->valueByBucket['medium']);
        self::assertSame('0.00', $summary->valueByBucket['slow']);
        self::assertSame('741600.00', $summary->valueByBucket['frozen']);
        self::assertSame('2000.00', $summary->valueByBucket['no_sales']);
        self::assertSame(1, $summary->listingsWithoutCostPrice);
        self::assertSame(15, $summary->qtyWithoutCostPrice);
        self::assertSame(4, $summary->listingsTotal);
        self::assertSame(['ozon' => $this->date->format('Y-m-d')], $summary->snapshotDateBySource);
        self::assertSame([], $summary->staleSources);
    }

    public function testBucketFilterNarrowsRowsButNotSummary(): void
    {
        $this->seedStandardSet();

        $report = $this->query->execute($this->filter(bucket: TurnoverBucket::Frozen));

        // D тоже «свыше 90» (15 шт при 3 продажах — 150 дней), хоть и без себестоимости.
        self::assertSame(['A', 'D'], $this->keys($report->rows));
        self::assertSame('772400.00', $report->summary->totalValue);
        self::assertSame(4, $report->summary->listingsTotal);
    }

    public function testListingWithoutStockIsExcluded(): void
    {
        $this->seedStandardSet();

        $report = $this->query->execute($this->filter());

        self::assertNotContains('E', $this->keys($report->rows));
        self::assertSame(4, $report->summary->listingsTotal);
    }

    public function testListingWithoutCostPriceIsCountedSeparately(): void
    {
        $this->seedStandardSet();

        $report = $this->query->execute($this->filter());
        $d = $report->rows[3];

        self::assertSame('D', $this->listingKey($d));
        self::assertNull($d->costPrice);
        self::assertNull($d->stockValue);
        self::assertSame(15, $d->stockQty);
        self::assertSame(TurnoverBucket::Frozen, $d->bucket); // 15 шт при 3 продажах за 30 дней — 150 дней
        self::assertSame(1, $report->summary->listingsWithoutCostPrice);
        self::assertSame(15, $report->summary->qtyWithoutCostPrice);
        // Без цены — не в деньгах: итог только A + B + C.
        self::assertSame('772400.00', $report->summary->totalValue);
    }

    public function testTagFilterKeepsOnlyTaggedListings(): void
    {
        $this->seedStandardSet();
        $tag = MarketplaceListingTagBuilder::aTag()->forCompanyId((string) $this->company->getId())->withName('хит')->build();
        $this->em->persist($tag);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at) VALUES (?, ?, ?, NOW())',
            [$this->listings['B']->getId(), $tag->getId(), (string) $this->company->getId()],
        );

        $report = $this->query->execute($this->filter(tagIds: [$tag->getId()]));

        self::assertSame(['B'], $this->keys($report->rows));
        self::assertSame('28800.00', $report->summary->totalValue);
        self::assertSame(1, $report->summary->listingsTotal);
    }

    public function testStaleSourceIsExcludedAndReported(): void
    {
        $staleDate = $this->date->modify('-5 days');
        $a = $this->listing('A', 'Худи серое');
        $this->snapshot($a, '412.000', 'fbo', $staleDate);
        $this->sale($a, 39);
        $this->em->flush();

        $report = $this->query->execute($this->filter());

        self::assertSame([], $report->rows);
        self::assertSame(0, $report->summary->listingsTotal);
        self::assertSame('0.00', $report->summary->totalValue);
        self::assertNull($report->summary->frozenShare);
        self::assertSame(['ozon' => $staleDate->format('Y-m-d')], $report->summary->staleSources);
        self::assertSame([], $report->summary->snapshotDateBySource);
    }

    public function testSchemeFbsUsesOnlySellerWarehouseRows(): void
    {
        $a = $this->listing('A', 'Худи серое');
        $this->snapshot($a, '400.000', 'fbo');
        $this->snapshot($a, '12.000', 'fbs');
        $this->costPrice($a, '1800.00');
        $this->sale($a, 39);
        $this->em->flush();

        $fbs = $this->query->execute($this->filter(scheme: FulfillmentScheme::Fbs));
        $all = $this->query->execute($this->filter());

        self::assertSame(12, $fbs->rows[0]->stockQty);
        self::assertSame('21600.00', $fbs->summary->totalValue);
        self::assertSame(412, $all->rows[0]->stockQty);
    }

    /**
     * A: 412 шт, 39 продаж, 1800 → свыше 90; B: 48 шт, 186 продаж, 600 → до 30;
     * C: 20 шт, 0 продаж, 100 → нет продаж; D: 15 шт, 3 продажи, без цены; E: только продажи.
     */
    private function seedStandardSet(): void
    {
        $a = $this->listing('A', 'Худи серое');
        $b = $this->listing('B', 'Футболка чёрная');
        $c = $this->listing('C', 'Носки');
        $d = $this->listing('D', 'Кепка');
        $e = $this->listing('E', 'Шарф');

        $this->snapshot($a, '412.000', 'fbo');
        $this->snapshot($b, '48.000', 'fbo');
        $this->snapshot($c, '20.000', 'fbo');
        $this->snapshot($d, '15.000', 'fbo');

        $this->costPrice($a, '1800.00');
        $this->costPrice($b, '600.00');
        $this->costPrice($c, '100.00');

        $this->sale($a, 39);
        $this->sale($b, 186);
        $this->sale($d, 3);
        $this->sale($e, 50);
        // Продажа за пределами окна в расчёт не входит.
        $this->sale($c, 7, $this->date->modify('-40 days'));

        $this->em->flush();
    }

    private function listing(string $key, string $name): MarketplaceListing
    {
        $index = 8610 + ord($key);
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($this->company)
            ->withIndex($index)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('SKU-'.$key)
            ->withName($name)
            ->build();
        $this->em->persist($listing);
        $this->listings[$key] = $listing;

        return $listing;
    }

    private function snapshot(MarketplaceListing $listing, string $qty, string $fulfillmentType, ?\DateTimeImmutable $date = null): void
    {
        $date ??= $this->date;
        $this->em->persist(
            StockSnapshotBuilder::aStockSnapshot()
                ->withCompanyId((string) $this->company->getId())
                ->withSnapshotSessionId('22222222-2222-2222-2222-000000000861')
                ->withSnapshotDate($date)
                ->withSnapshotAt($date->setTime(4, 5))
                ->withSource(MarketplaceType::OZON)
                ->withListingId($listing->getId())
                ->withProductId(null)
                ->withStatus(StockStatus::Available)
                ->withFulfillmentType($fulfillmentType)
                ->withSourceSku($listing->getMarketplaceSku().'-'.$fulfillmentType)
                ->withQuantity($qty)
                ->build(),
        );
    }

    private function costPrice(MarketplaceListing $listing, string $amount): void
    {
        $this->em->persist(new MarketplaceInventoryCostPrice(
            Uuid::uuid4()->toString(),
            (string) $this->company->getId(),
            $listing,
            $this->date->modify('-100 days'),
            $amount,
        ));
    }

    private function sale(MarketplaceListing $listing, int $quantity, ?\DateTimeImmutable $saleDate = null): void
    {
        $this->em->persist(
            MarketplaceSaleBuilder::aSale()
                ->forCompany($this->company)
                ->forListing($listing)
                ->withIndex(861000 + ++$this->saleIndex)
                ->withSaleDate($saleDate ?? $this->date->modify('-3 days'))
                ->withQuantity($quantity)
                ->build(),
        );
    }

    /** @param list<string> $tagIds */
    private function filter(?FulfillmentScheme $scheme = null, ?TurnoverBucket $bucket = null, array $tagIds = []): StockTurnoverFilter
    {
        return new StockTurnoverFilter((string) $this->company->getId(), $this->date, 30, null, $scheme, $bucket, $tagIds);
    }

    /**
     * @param list<StockTurnoverRow> $rows
     *
     * @return list<string>
     */
    private function keys(array $rows): array
    {
        return array_map(fn (StockTurnoverRow $row): string => $this->listingKey($row), $rows);
    }

    private function listingKey(StockTurnoverRow $row): string
    {
        foreach ($this->listings as $key => $listing) {
            if ($listing->getId() === $row->listingId) {
                return $key;
            }
        }

        return $row->listingId;
    }
}
