<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Query;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Entity\AdDocumentLine;
use App\MarketplaceAds\Infrastructure\Query\AdEfficiencyQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class AdEfficiencyQueryTest extends IntegrationTestCase
{
    private const COMPANY_A_ID = '11111111-1111-1111-1111-000000000001';
    private const COMPANY_B_ID = '11111111-1111-1111-1111-000000000002';
    private const OWNER_A_ID   = '22222222-2222-2222-2222-000000000001';
    private const OWNER_B_ID   = '22222222-2222-2222-2222-000000000002';

    private const LISTING_1_ID = '55555555-5555-5555-5555-000000000001';
    private const LISTING_2_ID = '55555555-5555-5555-5555-000000000002';
    private const LISTING_3_ID = '55555555-5555-5555-5555-000000000003';
    private const LISTING_4_ID = '55555555-5555-5555-5555-000000000004';
    private const LISTING_B_ID = '55555555-5555-5555-5555-00000000000B';

    private const PERIOD_FROM = '2026-04-01';
    private const PERIOD_TO   = '2026-04-30';

    private AdEfficiencyQuery $query;
    private Company $companyA;
    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = self::getContainer()->get(AdEfficiencyQuery::class);

        $this->seedFixtures();
    }

    public function testHappyPathReturnsListingsFromBothSalesAndAds(): void
    {
        $dto = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 25,
        );

        self::assertSame(3, $dto->total);
        self::assertCount(3, $dto->items);

        $ids = array_map(static fn ($i) => $i->listingId, $dto->items);
        self::assertContains(self::LISTING_1_ID, $ids);
        self::assertContains(self::LISTING_2_ID, $ids);
        self::assertContains(self::LISTING_3_ID, $ids);
        self::assertNotContains(self::LISTING_4_ID, $ids, 'listing4 — вне периода, должен быть исключён');
        self::assertNotContains(self::LISTING_B_ID, $ids, 'IDOR: листинг Company B не должен попасть');

        // totals — по всему набору (1000 + 500 + 0) = 1500 revenue; (100 + 0 + 50) = 150 ad_spend
        self::assertEqualsWithDelta(1500.0, (float) $dto->totalRevenue, 0.01);
        self::assertEqualsWithDelta(150.0, (float) $dto->totalAdSpend, 0.01);
        self::assertNotNull($dto->totalDrrPercent);
        // DRR = 150 / 1500 * 100 = 10
        self::assertEqualsWithDelta(10.0, (float) $dto->totalDrrPercent, 0.01);
    }

    public function testListing1HasBothRevenueAndAdSpendAndDrrComputed(): void
    {
        $dto = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 25,
        );

        $listing1 = $this->findItem($dto->items, self::LISTING_1_ID);
        self::assertNotNull($listing1);
        self::assertEqualsWithDelta(1000.0, (float) $listing1->revenue, 0.01);
        self::assertEqualsWithDelta(100.0, (float) $listing1->adSpend, 0.01);
        self::assertNotNull($listing1->drrPercent);
        // DRR = 100 / 1000 * 100 = 10
        self::assertEqualsWithDelta(10.0, (float) $listing1->drrPercent, 0.01);
    }

    public function testListing2HasZeroAdSpendAndDrrIsZero(): void
    {
        $dto = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 25,
        );

        $listing2 = $this->findItem($dto->items, self::LISTING_2_ID);
        self::assertNotNull($listing2);
        self::assertEqualsWithDelta(500.0, (float) $listing2->revenue, 0.01);
        self::assertEqualsWithDelta(0.0, (float) $listing2->adSpend, 0.01);
        // revenue > 0, adSpend = 0 → drrPercent = 0 (не null)
        self::assertNotNull($listing2->drrPercent);
        self::assertEqualsWithDelta(0.0, (float) $listing2->drrPercent, 0.01);
    }

    public function testListing3HasZeroRevenueAndDrrIsNull(): void
    {
        $dto = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 25,
        );

        $listing3 = $this->findItem($dto->items, self::LISTING_3_ID);
        self::assertNotNull($listing3);
        self::assertEqualsWithDelta(0.0, (float) $listing3->revenue, 0.01);
        self::assertEqualsWithDelta(50.0, (float) $listing3->adSpend, 0.01);
        self::assertNull($listing3->drrPercent, 'revenue = 0 → drrPercent обязан быть null');
    }

    public function testMarketplaceFilterIncludesOnlyMatchingListings(): void
    {
        $this->seedWildberriesListingWithSales();

        $dtoAll = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 25,
        );
        self::assertSame(4, $dtoAll->total, 'Без фильтра должны попасть Ozon + WB листинги');

        $dtoOzon = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            MarketplaceType::OZON->value,
            page: 1,
            pageSize: 25,
        );
        self::assertSame(3, $dtoOzon->total);
        foreach ($dtoOzon->items as $item) {
            self::assertSame('ozon', $item->marketplace);
        }

        $dtoWb = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            MarketplaceType::WILDBERRIES->value,
            page: 1,
            pageSize: 25,
        );
        self::assertSame(1, $dtoWb->total);
        self::assertSame('wildberries', $dtoWb->items[0]->marketplace);
    }

    public function testPagination(): void
    {
        // Страница 1 — 2 записи
        $page1 = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 2,
        );
        self::assertSame(3, $page1->total);
        self::assertCount(2, $page1->items);

        // Страница 2 — 1 запись
        $page2 = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 2,
            pageSize: 2,
        );
        self::assertSame(3, $page2->total);
        self::assertCount(1, $page2->items);

        // Страница 3 — пусто
        $page3 = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 3,
            pageSize: 2,
        );
        self::assertSame(3, $page3->total);
        self::assertCount(0, $page3->items);

        // Records across pages — нет дубликатов
        $idsPage1 = array_map(static fn ($i) => $i->listingId, $page1->items);
        $idsPage2 = array_map(static fn ($i) => $i->listingId, $page2->items);
        self::assertEmpty(array_intersect($idsPage1, $idsPage2));
    }

    public function testSortByRevenueDescendingOrdersListings(): void
    {
        $dto = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 25,
            sortBy: 'revenue',
            sortDir: 'desc',
        );

        $ids = array_map(static fn ($i) => $i->listingId, $dto->items);
        self::assertSame(
            [self::LISTING_1_ID, self::LISTING_2_ID, self::LISTING_3_ID],
            $ids,
            'revenue DESC: 1000 > 500 > 0',
        );
    }

    public function testSortByAdSpendAscendingOrdersListings(): void
    {
        $dto = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 25,
            sortBy: 'adSpend',
            sortDir: 'asc',
        );

        $ids = array_map(static fn ($i) => $i->listingId, $dto->items);
        self::assertSame(
            [self::LISTING_2_ID, self::LISTING_3_ID, self::LISTING_1_ID],
            $ids,
            'adSpend ASC: 0 < 50 < 100',
        );
    }

    public function testIdorCompanyBDataIsNotReturnedForCompanyA(): void
    {
        $dto = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 25,
        );

        $ids = array_map(static fn ($i) => $i->listingId, $dto->items);
        self::assertNotContains(self::LISTING_B_ID, $ids);

        // А обратно — Company B видит свои
        $dtoB = $this->query->getPage(
            self::COMPANY_B_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 25,
        );
        self::assertSame(1, $dtoB->total);
        self::assertSame(self::LISTING_B_ID, $dtoB->items[0]->listingId);
    }

    public function testOrphanedAdLineListingIdIsExcludedFromCountAndTotals(): void
    {
        // marketplace_ad_document_lines.listing_id не имеет FK на marketplace_listings:
        // возможна ситуация, когда ad-строка ссылается на несуществующий листинг.
        // Такие «висячие» id не должны попадать ни в total, ни в totalAdSpend,
        // иначе API будет отдавать числа, несовместимые с отрисованной таблицей.
        $orphanListingId = '99999999-9999-9999-9999-000000000001';
        $this->persistAdDocumentWithLine(
            companyId:   self::COMPANY_A_ID,
            marketplace: MarketplaceType::OZON,
            reportDate:  '2026-04-20',
            campaignId:  'CAMP-ORPHAN',
            parentSku:   'SKU-ORPHAN',
            totalCost:   '42.00',
            listingId:   $orphanListingId,
            lineCost:    '42.00',
        );
        $this->em->flush();
        $this->em->clear();

        $dto = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 25,
        );

        self::assertSame(3, $dto->total, 'Висячий listing_id не должен увеличивать total');
        $ids = array_map(static fn ($i) => $i->listingId, $dto->items);
        self::assertNotContains($orphanListingId, $ids);

        // totalAdSpend = 100 + 50 = 150 (висячие 42.00 не учтены)
        self::assertEqualsWithDelta(150.0, (float) $dto->totalAdSpend, 0.01);
        self::assertEqualsWithDelta(1500.0, (float) $dto->totalRevenue, 0.01);
    }

    public function testTotalsAreComputedOverFullSetNotJustThePage(): void
    {
        $dto = $this->query->getPage(
            self::COMPANY_A_ID,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
            null,
            page: 1,
            pageSize: 1, // на страницу — только 1 запись
        );

        self::assertCount(1, $dto->items);
        self::assertSame(3, $dto->total);
        // totals не должны зависеть от pageSize
        self::assertEqualsWithDelta(1500.0, (float) $dto->totalRevenue, 0.01);
        self::assertEqualsWithDelta(150.0, (float) $dto->totalAdSpend, 0.01);
    }

    /**
     * @param list<\App\MarketplaceAds\Application\DTO\AdEfficiencyItemDTO> $items
     */
    private function findItem(array $items, string $listingId): ?\App\MarketplaceAds\Application\DTO\AdEfficiencyItemDTO
    {
        foreach ($items as $item) {
            if ($item->listingId === $listingId) {
                return $item;
            }
        }

        return null;
    }

    private function seedFixtures(): void
    {
        // --- Companies & owners ---
        $ownerA = UserBuilder::aUser()
            ->withId(self::OWNER_A_ID)
            ->withEmail('ad-eff-a@example.test')
            ->build();
        $ownerB = UserBuilder::aUser()
            ->withId(self::OWNER_B_ID)
            ->withEmail('ad-eff-b@example.test')
            ->build();

        $this->companyA = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_A_ID)
            ->withOwner($ownerA)
            ->build();
        $this->companyB = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_B_ID)
            ->withOwner($ownerB)
            ->build();

        $this->em->persist($ownerA);
        $this->em->persist($ownerB);
        $this->em->persist($this->companyA);
        $this->em->persist($this->companyB);

        // --- Listings (Company A, OZON) ---
        $listing1 = $this->newListing(
            self::LISTING_1_ID,
            $this->companyA,
            MarketplaceType::OZON,
            'SKU-001',
            'Товар 1',
        );
        $listing2 = $this->newListing(
            self::LISTING_2_ID,
            $this->companyA,
            MarketplaceType::OZON,
            'SKU-002',
            'Товар 2',
        );
        $listing3 = $this->newListing(
            self::LISTING_3_ID,
            $this->companyA,
            MarketplaceType::OZON,
            'SKU-003',
            'Товар 3',
        );
        $listing4 = $this->newListing(
            self::LISTING_4_ID,
            $this->companyA,
            MarketplaceType::OZON,
            'SKU-004',
            'Товар 4 (out of period)',
        );

        // --- Listing Company B (IDOR) ---
        $listingB = $this->newListing(
            self::LISTING_B_ID,
            $this->companyB,
            MarketplaceType::OZON,
            'SKU-B',
            'Товар компании B',
        );

        foreach ([$listing1, $listing2, $listing3, $listing4, $listingB] as $l) {
            $this->em->persist($l);
        }

        // --- Sales in period (Company A) ---
        // listing1: revenue = 1000, listing2: revenue = 500.
        $this->persistSale($this->companyA, $listing1, MarketplaceType::OZON, '2026-04-10', '1000.00', 1, 'ORD-A-1');
        $this->persistSale($this->companyA, $listing2, MarketplaceType::OZON, '2026-04-12', '500.00',  1, 'ORD-A-2');

        // listing4: продажи ВНЕ периода → должен быть исключён
        $this->persistSale($this->companyA, $listing4, MarketplaceType::OZON, '2026-03-15', '999.00', 1, 'ORD-A-4-before');

        // Sales Company B (IDOR)
        $this->persistSale($this->companyB, $listingB, MarketplaceType::OZON, '2026-04-15', '200.00', 1, 'ORD-B-1');

        $this->em->flush();

        // --- Ad documents in period ---
        // listing1: adSpend = 100, listing3: adSpend = 50
        $this->persistAdDocumentWithLine(
            companyId:     self::COMPANY_A_ID,
            marketplace:   MarketplaceType::OZON,
            reportDate:    '2026-04-10',
            campaignId:    'CAMP-L1',
            parentSku:     'SKU-001',
            totalCost:     '100.00',
            listingId:     self::LISTING_1_ID,
            lineCost:      '100.00',
        );
        $this->persistAdDocumentWithLine(
            companyId:     self::COMPANY_A_ID,
            marketplace:   MarketplaceType::OZON,
            reportDate:    '2026-04-12',
            campaignId:    'CAMP-L3',
            parentSku:     'SKU-003',
            totalCost:     '50.00',
            listingId:     self::LISTING_3_ID,
            lineCost:      '50.00',
        );

        // listing4: реклама ВНЕ периода → должен быть исключён
        $this->persistAdDocumentWithLine(
            companyId:     self::COMPANY_A_ID,
            marketplace:   MarketplaceType::OZON,
            reportDate:    '2026-03-20',
            campaignId:    'CAMP-L4-before',
            parentSku:     'SKU-004',
            totalCost:     '77.00',
            listingId:     self::LISTING_4_ID,
            lineCost:      '77.00',
        );

        // listingB (IDOR)
        $this->persistAdDocumentWithLine(
            companyId:     self::COMPANY_B_ID,
            marketplace:   MarketplaceType::OZON,
            reportDate:    '2026-04-15',
            campaignId:    'CAMP-B',
            parentSku:     'SKU-B',
            totalCost:     '33.00',
            listingId:     self::LISTING_B_ID,
            lineCost:      '33.00',
        );

        $this->em->flush();
        $this->em->clear();
    }

    private function seedWildberriesListingWithSales(): void
    {
        $companyA = $this->em->getRepository(Company::class)->find(self::COMPANY_A_ID);

        $wbListingId = '55555555-5555-5555-5555-000000000005';
        $wbListing = $this->newListing(
            $wbListingId,
            $companyA,
            MarketplaceType::WILDBERRIES,
            'WB-001',
            'WB товар',
        );
        $this->em->persist($wbListing);

        $this->persistSale(
            $companyA,
            $wbListing,
            MarketplaceType::WILDBERRIES,
            '2026-04-14',
            '700.00',
            1,
            'ORD-WB-1',
        );

        $this->persistAdDocumentWithLine(
            companyId:     self::COMPANY_A_ID,
            marketplace:   MarketplaceType::WILDBERRIES,
            reportDate:    '2026-04-14',
            campaignId:    'CAMP-WB',
            parentSku:     'WB-001',
            totalCost:     '70.00',
            listingId:     $wbListingId,
            lineCost:      '70.00',
        );

        $this->em->flush();
        $this->em->clear();
    }

    private function newListing(
        string $id,
        Company $company,
        MarketplaceType $marketplace,
        string $sku,
        string $name,
    ): MarketplaceListing {
        $listing = new MarketplaceListing($id, $company, null, $marketplace);
        $listing->setMarketplaceSku($sku);
        $listing->setSize('UNKNOWN');
        $listing->setPrice('0.00');
        $listing->setName($name);

        return $listing;
    }

    private function persistSale(
        Company $company,
        MarketplaceListing $listing,
        MarketplaceType $marketplace,
        string $dateYmd,
        string $totalRevenue,
        int $quantity,
        string $externalOrderId,
    ): void {
        $sale = new MarketplaceSale(
            Uuid::uuid4()->toString(),
            $company,
            $listing,
            $marketplace,
        );
        $sale->setExternalOrderId($externalOrderId);
        $sale->setSaleDate(new \DateTimeImmutable($dateYmd));
        $sale->setQuantity($quantity);
        $sale->setPricePerUnit($totalRevenue);
        $sale->setTotalRevenue($totalRevenue);

        $this->em->persist($sale);
    }

    private function persistAdDocumentWithLine(
        string $companyId,
        MarketplaceType $marketplace,
        string $reportDate,
        string $campaignId,
        string $parentSku,
        string $totalCost,
        string $listingId,
        string $lineCost,
    ): void {
        // AdDocument.adRawDocumentId — колонка типа guid (Assert::uuid в конструкторе), но без FK.
        // Для read-only агрегата раскопка raw-доков не нужна — достаточно валидного uuid.
        $adRawDocumentId = Uuid::uuid4()->toString();

        $adDocument = new AdDocument(
            companyId: $companyId,
            marketplace: $marketplace,
            reportDate: new \DateTimeImmutable($reportDate),
            campaignId: $campaignId,
            campaignName: $campaignId,
            parentSku: $parentSku,
            totalCost: $totalCost,
            totalImpressions: 0,
            totalClicks: 0,
            adRawDocumentId: $adRawDocumentId,
        );
        $this->em->persist($adDocument);

        $line = new AdDocumentLine(
            adDocument: $adDocument,
            listingId: $listingId,
            sharePercent: '100.00',
            cost: $lineCost,
            impressions: 0,
            clicks: 0,
        );
        $this->em->persist($line);
    }
}
