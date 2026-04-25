<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAnalytics;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Entity\AdDocumentLine;
use App\MarketplaceAds\Infrastructure\Query\AdEfficiencyQuery;
use App\MarketplaceAnalytics\Infrastructure\Query\UnitExtendedQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Регрессия на главное бизнес-требование заказчика:
 * суммы за одинаковый период в /marketplace-ads/efficiency и
 * /marketplace-analytics/unit-extended обязаны совпадать (totals.adSpend).
 *
 * Расхождение между двумя отчётами за тот же период подрывает доверие
 * к системе целиком — пользователь не понимает, какому числу верить.
 */
final class UnitExtendedAdSpendConsistencyTest extends IntegrationTestCase
{
    private const TEST_COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OWNER_ID        = '22222222-2222-2222-2222-000000000001';

    private const LISTING_OZON_1_ID = '55555555-5555-5555-5555-000000000001';
    private const LISTING_OZON_2_ID = '55555555-5555-5555-5555-000000000002';
    private const LISTING_OZON_3_ID = '55555555-5555-5555-5555-000000000003';
    private const LISTING_WB_ID     = '55555555-5555-5555-5555-000000000004';
    private const ORPHAN_LISTING_ID = '99999999-9999-9999-9999-000000000077';

    private const PERIOD_FROM = '2025-01-01';
    private const PERIOD_TO   = '2025-01-31';

    private const ORPHAN_AD_SPEND = '777.00';

    private AdEfficiencyQuery $adEfficiencyQuery;
    private UnitExtendedQuery $unitExtendedQuery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adEfficiencyQuery = self::getContainer()->get(AdEfficiencyQuery::class);
        $this->unitExtendedQuery = self::getContainer()->get(UnitExtendedQuery::class);

        $this->seedFixtures();
    }

    public function test_totals_adSpend_matches_efficiency_for_same_period(): void
    {
        $companyId = self::TEST_COMPANY_ID;
        $from = self::PERIOD_FROM;
        $to   = self::PERIOD_TO;
        $marketplace = null;

        $efficiencyResult = $this->adEfficiencyQuery->getPage(
            $companyId,
            new \DateTimeImmutable($from),
            new \DateTimeImmutable($to),
            $marketplace,
            page: 1,
            pageSize: 1000,
            sortBy: 'revenue',
            sortDir: 'desc',
        );

        $unitExtendedResult = $this->unitExtendedQuery->execute(
            $companyId,
            $marketplace,
            $from,
            $to,
            \PHP_INT_MAX,
        );

        self::assertEqualsWithDelta(
            (float) $efficiencyResult->totalAdSpend,
            (float) $unitExtendedResult['totals']['adSpend'],
            0.01,
            'totals.adSpend на unit-extended должен совпадать с efficiency за тот же период '
            .'(включая non-attributed РР). Если этот тест упал — нарушено главное требование '
            .'согласованности отчётов. Чинить задачу 2 (или агрегатор efficiency totals), '
            .'а не тест.',
        );

        // Сумма по строкам unit-extended.items МЕНЬШЕ totals.adSpend ровно на
        // величину non-attributed РР (777.00) — ожидаемое поведение: items
        // рисуют только attributed расходы, totals — полные, для паритета с efficiency.
        $itemsSum = array_sum(array_column($unitExtendedResult['items'], 'adSpend'));
        self::assertEqualsWithDelta(
            (float) $efficiencyResult->totalAdSpend - (float) self::ORPHAN_AD_SPEND,
            $itemsSum,
            0.01,
            'Сумма adSpend по строкам unit-extended должна быть меньше totals.adSpend '
            .'ровно на сумму non-attributed РР (висячих listing_id).',
        );
    }

    public function test_totals_adSpend_matches_efficiency_with_marketplace_filter(): void
    {
        $companyId = self::TEST_COMPANY_ID;
        $from = self::PERIOD_FROM;
        $to   = self::PERIOD_TO;

        // OZON: содержит non-attributed РР (orphan размещён в Ozon-доке)
        $efficiencyOzon = $this->adEfficiencyQuery->getPage(
            $companyId,
            new \DateTimeImmutable($from),
            new \DateTimeImmutable($to),
            MarketplaceType::OZON->value,
            page: 1,
            pageSize: 1000,
            sortBy: 'revenue',
            sortDir: 'desc',
        );

        $unitExtendedOzon = $this->unitExtendedQuery->execute(
            $companyId,
            MarketplaceType::OZON->value,
            $from,
            $to,
            \PHP_INT_MAX,
        );

        self::assertEqualsWithDelta(
            (float) $efficiencyOzon->totalAdSpend,
            (float) $unitExtendedOzon['totals']['adSpend'],
            0.01,
            'OZON: totals.adSpend на unit-extended должен совпадать с efficiency '
            .'за тот же период. При расхождении — чинить задачу 2.',
        );

        $itemsSumOzon = array_sum(array_column($unitExtendedOzon['items'], 'adSpend'));
        self::assertEqualsWithDelta(
            (float) $efficiencyOzon->totalAdSpend - (float) self::ORPHAN_AD_SPEND,
            $itemsSumOzon,
            0.01,
            'OZON: сумма по строкам должна отставать от totals.adSpend на величину non-attributed РР.',
        );

        // WILDBERRIES: orphan не размещён в WB-доке — non-attributed нет,
        // totals и items должны сходиться без поправки.
        $efficiencyWb = $this->adEfficiencyQuery->getPage(
            $companyId,
            new \DateTimeImmutable($from),
            new \DateTimeImmutable($to),
            MarketplaceType::WILDBERRIES->value,
            page: 1,
            pageSize: 1000,
            sortBy: 'revenue',
            sortDir: 'desc',
        );

        $unitExtendedWb = $this->unitExtendedQuery->execute(
            $companyId,
            MarketplaceType::WILDBERRIES->value,
            $from,
            $to,
            \PHP_INT_MAX,
        );

        self::assertEqualsWithDelta(
            (float) $efficiencyWb->totalAdSpend,
            (float) $unitExtendedWb['totals']['adSpend'],
            0.01,
            'WB: totals.adSpend на unit-extended должен совпадать с efficiency.',
        );

        $itemsSumWb = array_sum(array_column($unitExtendedWb['items'], 'adSpend'));
        self::assertEqualsWithDelta(
            (float) $efficiencyWb->totalAdSpend,
            $itemsSumWb,
            0.01,
            'WB: без non-attributed строк сумма по items должна равняться totals.adSpend.',
        );
    }

    private function seedFixtures(): void
    {
        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('unit-extended-consistency@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::TEST_COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        $listingOzon1 = $this->newListing(self::LISTING_OZON_1_ID, $company, MarketplaceType::OZON, 'SKU-O-1', 'Ozon товар 1');
        $listingOzon2 = $this->newListing(self::LISTING_OZON_2_ID, $company, MarketplaceType::OZON, 'SKU-O-2', 'Ozon товар 2');
        $listingOzon3 = $this->newListing(self::LISTING_OZON_3_ID, $company, MarketplaceType::OZON, 'SKU-O-3', 'Ozon товар 3');
        $listingWb    = $this->newListing(self::LISTING_WB_ID,    $company, MarketplaceType::WILDBERRIES, 'WB-1', 'WB товар');

        foreach ([$listingOzon1, $listingOzon2, $listingOzon3, $listingWb] as $l) {
            $this->em->persist($l);
        }

        // --- Sales (для построения строк unit-extended) ---
        $this->persistSale($company, $listingOzon1, MarketplaceType::OZON,        '2025-01-10', '1000.00', 1, 'ORD-O-1');
        $this->persistSale($company, $listingOzon2, MarketplaceType::OZON,        '2025-01-12', '500.00',  1, 'ORD-O-2');
        $this->persistSale($company, $listingOzon3, MarketplaceType::OZON,        '2025-01-15', '300.00',  1, 'ORD-O-3');
        $this->persistSale($company, $listingWb,    MarketplaceType::WILDBERRIES, '2025-01-20', '600.00',  1, 'ORD-WB-1');

        $this->em->flush();

        // --- 3 ad_documents, 5 ad_document_lines (4 attributed + 1 orphan) ---
        // doc1 (Ozon, total=100): line1 → listingOzon1 (100).
        $doc1 = $this->persistAdDocument(
            self::TEST_COMPANY_ID,
            MarketplaceType::OZON,
            '2025-01-10',
            'CAMP-OZON-1',
            'SKU-O-1',
            '100.00',
        );
        $this->persistAdDocumentLine($doc1, self::LISTING_OZON_1_ID, '100.00');

        // doc2 (Ozon, total=825): line2 → listingOzon2 (40), line3 → listingOzon3 (8),
        // line4 → ORPHAN (777). Висячий listing_id размещён здесь сознательно —
        // чтобы тест с фильтром по 'ozon' тоже видел non-attributed.
        $doc2 = $this->persistAdDocument(
            self::TEST_COMPANY_ID,
            MarketplaceType::OZON,
            '2025-01-15',
            'CAMP-OZON-2',
            'SKU-O-MIX',
            '825.00',
        );
        $this->persistAdDocumentLine($doc2, self::LISTING_OZON_2_ID, '40.00');
        $this->persistAdDocumentLine($doc2, self::LISTING_OZON_3_ID, '8.00');
        $this->persistAdDocumentLine($doc2, self::ORPHAN_LISTING_ID, self::ORPHAN_AD_SPEND);

        // doc3 (WB, total=120): line5 → listingWb (120). non-attributed нет.
        $doc3 = $this->persistAdDocument(
            self::TEST_COMPANY_ID,
            MarketplaceType::WILDBERRIES,
            '2025-01-20',
            'CAMP-WB-1',
            'WB-1',
            '120.00',
        );
        $this->persistAdDocumentLine($doc3, self::LISTING_WB_ID, '120.00');

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

    private function persistAdDocument(
        string $companyId,
        MarketplaceType $marketplace,
        string $reportDate,
        string $campaignId,
        string $parentSku,
        string $totalCost,
    ): AdDocument {
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
            adRawDocumentId: Uuid::uuid4()->toString(),
        );

        $this->em->persist($adDocument);

        return $adDocument;
    }

    private function persistAdDocumentLine(AdDocument $adDocument, string $listingId, string $cost): void
    {
        $line = new AdDocumentLine(
            adDocument: $adDocument,
            listingId: $listingId,
            sharePercent: '100.00',
            cost: $cost,
            impressions: 0,
            clicks: 0,
        );

        $this->em->persist($line);
    }
}
