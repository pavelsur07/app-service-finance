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
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Строка «Не распределено по товарам»: затраты без листинга входят в «Итого», и прибыль
 * «Итого» совпадает с карточками (WidgetSummaryQuery), когда реклама по учёту равна
 * статистике. Расхождение двух цифр на одной странице — исходная жалоба (issue #2940).
 */
final class UnitExtendedUnallocatedRowTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000002940';
    private const OWNER_ID = '22222222-2222-2222-2222-000000002940';
    private const LISTING_ID = '55555555-5555-5555-5555-000000002940';
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

        $listing = new MarketplaceListing(self::LISTING_ID, $this->company, null, MarketplaceType::OZON);
        $listing->setMarketplaceSku('SKU-1');
        $listing->setSize('UNKNOWN');
        $listing->setPrice('0.00');
        $listing->setName('Товар');
        $this->em->persist($listing);

        $sale = new MarketplaceSale(Uuid::uuid4()->toString(), $this->company, $listing, MarketplaceType::OZON);
        $sale->setExternalOrderId('ORD-1');
        $sale->setSaleDate(new \DateTimeImmutable('2026-11-05'));
        $sale->setQuantity(1);
        $sale->setPricePerUnit('1000.00');
        $sale->setTotalRevenue('1000.00');
        $this->em->persist($sale);

        // К товару: комиссия 100. Без товара: хранение 50, акции 30 − сторно 10, клики 70.
        $this->cost('ozon_sale_commission', '100.00', MarketplaceCostOperationType::CHARGE, $listing);
        $this->cost('ozon_storage', '50.00', MarketplaceCostOperationType::CHARGE);
        $this->cost('ozon_marketing_action', '30.00', MarketplaceCostOperationType::CHARGE);
        $this->cost('ozon_marketing_action', '10.00', MarketplaceCostOperationType::STORNO);
        $this->cost('ozon_cpc', '70.00', MarketplaceCostOperationType::CHARGE);
        // Вне периода — не считается.
        $this->cost('ozon_storage', '999.00', MarketplaceCostOperationType::CHARGE, null, '2026-10-31');
        $this->em->flush();

        // Статистика рекламы = учёту (70): тогда «Итого» и карточки обязаны совпасть.
        $doc = new AdDocument(
            companyId: self::COMPANY_ID,
            marketplace: MarketplaceType::OZON,
            reportDate: new \DateTimeImmutable('2026-11-05'),
            campaignId: 'C-1',
            campaignName: 'C-1',
            parentSku: 'SKU-1',
            totalCost: '70.00',
            totalImpressions: 0,
            totalClicks: 0,
            adRawDocumentId: Uuid::uuid4()->toString(),
        );
        $this->em->persist($doc);
        $this->em->persist(new AdDocumentLine($doc, self::LISTING_ID, '100.00', '70.00', 0, 0));
        $this->em->flush();
        $this->em->clear();
    }

    public function testUnallocatedRowExcludesAdCategoryAndTotalsMatchWidgets(): void
    {
        $result = self::getContainer()->get(UnitExtendedQuery::class)
            ->execute(self::COMPANY_ID, 'ozon', self::FROM, self::TO);

        $row = $result['unallocated'];
        self::assertNotNull($row);
        // 50 + 30 − 10 = 70; ozon_cpc в строку не входит — он в колонке «Реклама» из статистики.
        self::assertEqualsWithDelta(70.0, $row['totalCosts'], 0.001);
        self::assertEqualsWithDelta(170.0 + 70.0, $result['totals']['totalCosts'], 0.001);

        $widgets = self::getContainer()->get(WidgetSummaryQuery::class)->getSummary(
            self::COMPANY_ID,
            'ozon',
            new \DateTimeImmutable(self::FROM),
            new \DateTimeImmutable(self::TO),
        );
        // 1000 − 100 − 70 (без товара) − 70 (реклама) = 760.
        self::assertEqualsWithDelta(760.0, $widgets['profit'], 0.001);
        self::assertEqualsWithDelta($widgets['profit'], $result['totals']['profit'], 0.001);
    }

    public function testOtherMarketplaceHasNoRow(): void
    {
        $result = self::getContainer()->get(UnitExtendedQuery::class)
            ->execute(self::COMPANY_ID, 'wildberries', self::FROM, self::TO);

        self::assertNull($result['unallocated']);
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
