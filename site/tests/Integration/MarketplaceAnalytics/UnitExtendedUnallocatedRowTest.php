<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAnalytics;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Entity\AdDocumentLine;
use App\MarketplaceAnalytics\Infrastructure\Query\UnitExtendedQuery;
use App\MarketplaceAnalytics\Infrastructure\Query\WidgetSummaryQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Карточки (WidgetSummaryQuery) = «Итого» таблицы (UnitExtendedQuery) — без тегов и под
 * тегом. Две разные «Прибыли» на одной странице — исходная жалоба (issue #2940); с этапа 3
 * реклама везде по статистике, учётные рекламные категории — только справка adAccrued.
 */
final class UnitExtendedUnallocatedRowTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000002940';
    private const OWNER_ID = '22222222-2222-2222-2222-000000002940';
    private const LISTING_ID = '55555555-5555-5555-5555-000000002940';
    private const AD_ONLY_LISTING_ID = '55555555-5555-5555-5555-000000002941';
    private const TAG_ID = '66666666-6666-6666-6666-000000002940';
    private const FROM = '2026-11-01';
    private const TO = '2026-11-30';

    private Company $company;
    /** @var array<string, MarketplaceCostCategory> */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()->withId(self::OWNER_ID)->withEmail('unallocated-2940@example.test')->build();
        $this->company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);

        $listing = $this->listing(self::LISTING_ID, 'SKU-1');
        // Только реклама в периоде: ни продаж, ни затрат, ни остатков.
        $adOnly = $this->listing(self::AD_ONLY_LISTING_ID, 'SKU-2');

        $sale = new MarketplaceSale(Uuid::uuid4()->toString(), $this->company, $listing, MarketplaceType::OZON);
        $sale->setExternalOrderId('ORD-1');
        $sale->setSaleDate(new \DateTimeImmutable('2026-11-05'));
        $sale->setQuantity(1);
        $sale->setPricePerUnit('1000.00');
        $sale->setTotalRevenue('1000.00');
        $this->em->persist($sale);

        // К товару: комиссия 100 и учётные клики 40 (реклама уже в статистике — не затрата).
        $this->cost('ozon_sale_commission', '100.00', MarketplaceCostOperationType::CHARGE, $listing);
        $this->cost('ozon_cpc', '40.00', MarketplaceCostOperationType::CHARGE, $listing);
        // Без товара: хранение 50, акции 30 − сторно 10, клики 70, предоплата рекламы 500.
        $this->cost('ozon_storage', '50.00', MarketplaceCostOperationType::CHARGE);
        $this->cost('ozon_marketing_action', '30.00', MarketplaceCostOperationType::CHARGE);
        $this->cost('ozon_marketing_action', '10.00', MarketplaceCostOperationType::STORNO);
        $this->cost('ozon_cpc', '70.00', MarketplaceCostOperationType::CHARGE);
        $this->cost('ozon_marketing_services', '500.00', MarketplaceCostOperationType::CHARGE);
        // Вне периода — не считается.
        $this->cost('ozon_storage', '999.00', MarketplaceCostOperationType::CHARGE, null, '2026-10-31');

        $tag = MarketplaceListingTagBuilder::aTag()->withId(self::TAG_ID)->forCompanyId(self::COMPANY_ID)->build();
        $this->em->persist($tag);
        $this->em->flush();
        foreach ([self::LISTING_ID, self::AD_ONLY_LISTING_ID] as $listingId) {
            $this->connection->executeStatement(
                'INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at) VALUES (?, ?, ?, NOW())',
                [$listingId, self::TAG_ID, self::COMPANY_ID],
            );
        }

        // Статистика: 70 на товар с продажей, 30 на товар только с рекламой — всего 100.
        $this->adStatistics('SKU-1', self::LISTING_ID, '70.00');
        $this->adStatistics('SKU-2', self::AD_ONLY_LISTING_ID, '30.00');
        $this->em->flush();
        $this->em->clear();
    }

    public function testWidgetsEqualTotalsWithoutTags(): void
    {
        $result = self::getContainer()->get(UnitExtendedQuery::class)
            ->execute(self::COMPANY_ID, 'ozon', self::FROM, self::TO);

        // 50 + 30 − 10 = 70; клики и предоплата в строку не входят — реклама из статистики.
        self::assertNotNull($result['unallocated']);
        self::assertEqualsWithDelta(70.0, $result['unallocated']['totalCosts'], 0.001);
        // Строка товара: учётные клики 40 не входят в «Прочие» — иначе реклама дважды.
        $row = $this->row($result['items'], self::LISTING_ID);
        self::assertEqualsWithDelta(0.0, $row['otherCosts'], 0.001);
        // Товар только с рекламой — строка с убытком на её сумму.
        self::assertEqualsWithDelta(-30.0, $this->row($result['items'], self::AD_ONLY_LISTING_ID)['profit'], 0.001);
        // 1000 − 100 − 70 − 100 (статистика) = 730.
        self::assertEqualsWithDelta(730.0, $result['totals']['profit'], 0.001);

        $widgets = $this->widgets(null);
        $this->assertWidgetsMatchTotals($widgets, $result['totals']);
        // Справка: учёт рекламы 40 + 70 + 500.
        self::assertEqualsWithDelta(-610.0, $widgets['adAccrued'], 0.001);
    }

    public function testWidgetsEqualTotalsUnderTag(): void
    {
        $result = self::getContainer()->get(UnitExtendedQuery::class)
            ->execute(self::COMPANY_ID, 'ozon', self::FROM, self::TO, tagIds: [self::TAG_ID]);

        self::assertNull($result['unallocated']);
        // 1000 − 100 − 70 − 30 = 800: реклама товара только с рекламой — в «Итого».
        self::assertEqualsWithDelta(800.0, $result['totals']['profit'], 0.001);

        $widgets = $this->widgets([self::LISTING_ID, self::AD_ONLY_LISTING_ID]);
        $this->assertWidgetsMatchTotals($widgets, $result['totals']);
        self::assertNull($widgets['adAccrued']);
    }

    public function testOtherMarketplaceHasNoRow(): void
    {
        $result = self::getContainer()->get(UnitExtendedQuery::class)
            ->execute(self::COMPANY_ID, 'wildberries', self::FROM, self::TO);

        self::assertNull($result['unallocated']);
    }

    /**
     * Каждая карточка — одноимённая величина «Итого» (в P&L-знаке).
     *
     * @param array<string, mixed> $widgets
     * @param array<string, mixed> $totals
     */
    private function assertWidgetsMatchTotals(array $widgets, array $totals): void
    {
        $groups = array_column($widgets['widgetGroups'], 'netAmount', 'serviceGroup');

        self::assertEqualsWithDelta($totals['revenue'], $widgets['revenue'], 0.001);
        self::assertEqualsWithDelta(-$totals['returnsTotal'], $widgets['returnsTotal'], 0.001);
        self::assertEqualsWithDelta(-$totals['costPriceTotal'], $widgets['costPriceTotal'], 0.001);
        self::assertEqualsWithDelta(-$totals['commission'], $groups['Комиссия'], 0.001);
        self::assertEqualsWithDelta(-$totals['logistics'], $groups['Логистика'], 0.001);
        self::assertEqualsWithDelta(-$totals['adSpend'], $groups['Реклама'], 0.001);
        self::assertEqualsWithDelta(-$totals['otherCosts'], $groups['Прочие'], 0.001);
        self::assertEqualsWithDelta(-$totals['totalCosts'], $widgets['totalCosts'], 0.001);
        self::assertEqualsWithDelta($totals['profit'], $widgets['profit'], 0.001);
    }

    /**
     * @param list<string>|null $listingIds
     *
     * @return array<string, mixed>
     */
    private function widgets(?array $listingIds): array
    {
        return self::getContainer()->get(WidgetSummaryQuery::class)->getSummary(
            self::COMPANY_ID,
            'ozon',
            new \DateTimeImmutable(self::FROM),
            new \DateTimeImmutable(self::TO),
            $listingIds,
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function row(array $items, string $listingId): array
    {
        foreach ($items as $item) {
            if ($item['listingId'] === $listingId) {
                return $item;
            }
        }
        self::fail('Нет строки листинга '.$listingId);
    }

    private function listing(string $id, string $sku): MarketplaceListing
    {
        $listing = new MarketplaceListing($id, $this->company, null, MarketplaceType::OZON);
        $listing->setMarketplaceSku($sku);
        $listing->setSize('UNKNOWN');
        $listing->setPrice('0.00');
        $listing->setName('Товар '.$sku);
        $this->em->persist($listing);

        return $listing;
    }

    private function adStatistics(string $sku, string $listingId, string $cost): void
    {
        $doc = new AdDocument(
            companyId: self::COMPANY_ID,
            marketplace: MarketplaceType::OZON,
            reportDate: new \DateTimeImmutable('2026-11-05'),
            campaignId: 'C-'.$sku,
            campaignName: 'C-'.$sku,
            parentSku: $sku,
            totalCost: $cost,
            totalImpressions: 0,
            totalClicks: 0,
            adRawDocumentId: Uuid::uuid4()->toString(),
        );
        $this->em->persist($doc);
        $this->em->persist(new AdDocumentLine($doc, $listingId, '100.00', $cost, 0, 0));
    }

    private function cost(
        string $code,
        string $amount,
        MarketplaceCostOperationType $type,
        ?MarketplaceListing $listing = null,
        string $date = '2026-11-05',
    ): void {
        if (!isset($this->categories[$code])) {
            $category = new MarketplaceCostCategory(Uuid::uuid4()->toString(), $this->company, MarketplaceType::OZON);
            $category->setCode($code);
            $category->setName($code);
            $this->em->persist($category);
            $this->categories[$code] = $category;
        }
        $category = $this->categories[$code];

        $cost = new MarketplaceCost(Uuid::uuid4()->toString(), $this->company, MarketplaceType::OZON, $category);
        $cost->setListing($listing);
        $cost->setAmount($amount);
        $cost->setCostDate(new \DateTimeImmutable($date));
        $cost->setOperationType($type);
        $cost->setExternalId('ext-'.Uuid::uuid4()->toString());
        $this->em->persist($cost);
    }
}
