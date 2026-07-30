<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAnalytics;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Infrastructure\Query\UnitExtendedQuery;
use App\MarketplaceAnalytics\Infrastructure\Query\WidgetSummaryQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class UnitExtendedWbGrossAmountsTest extends IntegrationTestCase
{
    private const PERIOD_FROM = '2026-07-01';
    private const PERIOD_TO = '2026-07-31';

    private UnitExtendedQuery $query;
    private WidgetSummaryQuery $widgetSummaryQuery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = self::getContainer()->get(UnitExtendedQuery::class);
        $this->widgetSummaryQuery = self::getContainer()->get(WidgetSummaryQuery::class);
    }

    public function testUsesAmountsWithoutSppForWbSalesAndReturnsBySku(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(831)->build();
        $this->em->persist($company->getUser());
        $this->em->persist($company);

        $listingA = $this->listing($company, 8311, 'WB-GROSS-A');
        $listingB = $this->listing($company, 8312, 'WB-GROSS-B');
        $this->em->persist($listingA);
        $this->em->persist($listingB);

        $this->persistSale($company, $listingA, 'WB-GROSS-SALE-A', '1000.00', '1200.00', 2);
        $this->persistSale($company, $listingB, 'WB-GROSS-SALE-B', '500.00', '900.00', 3);
        $this->persistReturn($company, $listingA, 'WB-GROSS-RETURN-A', '1000.00', 2);
        $this->persistReturn($company, $listingB, 'WB-GROSS-RETURN-B', '500.00', 1);
        $this->em->flush();

        $result = $this->query->execute(
            (string) $company->getId(),
            MarketplaceType::WILDBERRIES->value,
            self::PERIOD_FROM,
            self::PERIOD_TO,
            limit: 100,
        );

        $itemsBySku = [];
        foreach ($result['items'] as $item) {
            $itemsBySku[$item['sku']] = $item;
        }

        self::assertSame(2000.0, $itemsBySku['WB-GROSS-A']['revenue']);
        self::assertSame(2000.0, $itemsBySku['WB-GROSS-A']['returnsTotal']);
        self::assertSame(2, $itemsBySku['WB-GROSS-A']['quantity']);
        self::assertSame(2, $itemsBySku['WB-GROSS-A']['returnsQuantity']);
        self::assertSame(1500.0, $itemsBySku['WB-GROSS-B']['revenue']);
        self::assertSame(500.0, $itemsBySku['WB-GROSS-B']['returnsTotal']);
        self::assertSame(3, $itemsBySku['WB-GROSS-B']['quantity']);
        self::assertSame(1, $itemsBySku['WB-GROSS-B']['returnsQuantity']);
        self::assertSame(3500.0, $result['totals']['revenue']);
        self::assertSame(2500.0, $result['totals']['returnsTotal']);
        self::assertSame(5, $result['totals']['quantity']);
        self::assertSame(3, $result['totals']['returnsQuantity']);
        self::assertSame(1000.0, $result['totals']['profit']);

        $widgetSummary = $this->widgetSummaryQuery->getSummary(
            (string) $company->getId(),
            MarketplaceType::WILDBERRIES->value,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
        );

        self::assertSame(3500.0, $widgetSummary['revenue']);
        self::assertSame(-2500.0, $widgetSummary['returnsTotal']);
        self::assertSame(1000.0, $widgetSummary['profit']);
    }

    public function testKeepsOzonRevenueAndReturnsUnchangedInMixedMarketplaceReport(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(832)->build();
        $this->em->persist($company->getUser());
        $this->em->persist($company);

        $listingWb = $this->listing($company, 8321, 'WB-MIXED');
        $listingOzon = $this->listing($company, 8322, 'OZON-MIXED', MarketplaceType::OZON);
        $this->em->persist($listingWb);
        $this->em->persist($listingOzon);

        $this->persistSale($company, $listingWb, 'WB-MIXED-SALE', '1000.00', '1200.00', 2);
        $this->persistSale(
            $company,
            $listingOzon,
            'OZON-MIXED-SALE',
            '15000.00',
            '15000.00',
            3,
            MarketplaceType::OZON,
        );
        $this->persistReturn(
            $company,
            $listingOzon,
            'OZON-MIXED-RETURN',
            '4000.00',
            3,
            MarketplaceType::OZON,
        );
        $this->em->flush();

        $result = $this->query->execute(
            (string) $company->getId(),
            null,
            self::PERIOD_FROM,
            self::PERIOD_TO,
            limit: 100,
        );

        $itemsBySku = [];
        foreach ($result['items'] as $item) {
            $itemsBySku[$item['sku']] = $item;
        }

        self::assertSame(2000.0, $itemsBySku['WB-MIXED']['revenue']);
        self::assertSame(15000.0, $itemsBySku['OZON-MIXED']['revenue']);
        self::assertSame(4000.0, $itemsBySku['OZON-MIXED']['returnsTotal']);
        self::assertSame(17000.0, $result['totals']['revenue']);
        self::assertSame(4000.0, $result['totals']['returnsTotal']);
    }

    /**
     * WB deduction rows may arrive without nm_id/barcode. They cannot be
     * allocated to a SKU row, but must still be income in the report summary.
     */
    public function testWbVoluntaryPaymentWithoutSkuIsIncomeInSummaryAndProfit(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(833)->build();
        $this->em->persist($company->getUser());
        $this->em->persist($company);

        $listing = $this->listing($company, 8331, 'WB-COMPENSATION');
        $this->em->persist($listing);
        $this->persistSale($company, $listing, 'WB-COMPENSATION-SALE', '1000.00', '1000.00', 1);

        $category = (new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::WILDBERRIES,
        ))
            ->setCode('wb_dobrovolnaya_vyplata_za_tovary')
            ->setName('Добровольная выплата за товары');
        $cost = (new MarketplaceCost(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::WILDBERRIES,
            $category,
        ))
            ->setAmount('49155.00')
            ->setCostDate(new \DateTimeImmutable('2026-07-15'))
            ->setOperationType(MarketplaceCostOperationType::STORNO);

        $this->em->persist($category);
        $this->em->persist($cost);
        $this->em->flush();

        $result = $this->query->execute(
            (string) $company->getId(),
            MarketplaceType::WILDBERRIES->value,
            self::PERIOD_FROM,
            self::PERIOD_TO,
            limit: 100,
        );

        self::assertSame(0.0, $result['items'][0]['otherCosts']);
        self::assertSame(1000.0, $result['items'][0]['profit']);
        self::assertSame(0.0, $result['totals']['totalCosts']);
        self::assertSame(1000.0, $result['totals']['profit']);

        $widgetSummary = $this->widgetSummaryQuery->getSummary(
            (string) $company->getId(),
            MarketplaceType::WILDBERRIES->value,
            new \DateTimeImmutable(self::PERIOD_FROM),
            new \DateTimeImmutable(self::PERIOD_TO),
        );

        $compensation = null;
        foreach ($widgetSummary['widgetGroups'] as $group) {
            foreach ($group['categories'] as $categoryRow) {
                if ('wb_dobrovolnaya_vyplata_za_tovary' === $categoryRow['code']) {
                    $compensation = $categoryRow;
                }
            }
        }

        self::assertNotNull($compensation);
        self::assertSame(0.0, $compensation['costsAmount']);
        self::assertSame(49155.0, $compensation['stornoAmount']);
        self::assertSame(49155.0, $compensation['netAmount']);
        self::assertSame(49155.0, $widgetSummary['totalCosts']);
        self::assertSame(50155.0, $widgetSummary['profit']);
    }

    public function testWbVoluntaryPaymentAttachedToSkuIncreasesSkuAndTotalProfit(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(836)->build();
        $this->em->persist($company->getUser());
        $this->em->persist($company);

        $listing = $this->listing($company, 8361, 'WB-COMPENSATION-SKU');
        $this->em->persist($listing);
        $this->persistSale($company, $listing, 'WB-COMPENSATION-SKU-SALE', '1000.00', '1000.00', 1);

        $category = (new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::WILDBERRIES,
        ))
            ->setCode('wb_dobrovolnaya_vyplata_za_tovary')
            ->setName('Добровольная выплата за товары');
        $cost = (new MarketplaceCost(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::WILDBERRIES,
            $category,
        ))
            ->setListing($listing)
            ->setAmount('49155.00')
            ->setCostDate(new \DateTimeImmutable('2026-07-15'))
            ->setOperationType(MarketplaceCostOperationType::STORNO);

        $this->em->persist($category);
        $this->em->persist($cost);
        $this->em->flush();

        $result = $this->query->execute(
            (string) $company->getId(),
            MarketplaceType::WILDBERRIES->value,
            self::PERIOD_FROM,
            self::PERIOD_TO,
            limit: 100,
        );

        self::assertSame(-49155.0, $result['items'][0]['otherCosts']);
        self::assertSame(50155.0, $result['items'][0]['profit']);
        self::assertSame(-49155.0, $result['totals']['otherCosts']);
        self::assertSame(50155.0, $result['totals']['profit']);
    }

    private function listing(
        Company $company,
        int $index,
        string $sku,
        MarketplaceType $marketplace = MarketplaceType::WILDBERRIES,
    ): MarketplaceListing {
        return MarketplaceListingBuilder::aListing()
            ->withIndex($index)
            ->forCompany($company)
            ->withMarketplace($marketplace)
            ->withMarketplaceSku($sku)
            ->build();
    }

    private function persistSale(
        Company $company,
        MarketplaceListing $listing,
        string $externalOrderId,
        string $pricePerUnit,
        string $totalRevenue,
        int $quantity,
        MarketplaceType $marketplace = MarketplaceType::WILDBERRIES,
    ): void {
        $this->em->persist(
            MarketplaceSaleBuilder::aSale()
                ->forCompany($company)
                ->forListing($listing)
                ->withMarketplace($marketplace)
                ->withExternalOrderId($externalOrderId)
                ->withSaleDate(new \DateTimeImmutable('2026-07-15'))
                ->withPricePerUnit($pricePerUnit)
                ->withTotalRevenue($totalRevenue)
                ->withQuantity($quantity)
                ->build(),
        );
    }

    private function persistReturn(
        Company $company,
        MarketplaceListing $listing,
        string $externalReturnId,
        string $refundAmount,
        int $quantity,
        MarketplaceType $marketplace = MarketplaceType::WILDBERRIES,
    ): void {
        $return = new MarketplaceReturn(
            Uuid::uuid4()->toString(),
            $company,
            $listing,
            $marketplace,
        );
        $return->setExternalReturnId($externalReturnId);
        $return->setReturnDate(new \DateTimeImmutable('2026-07-20'));
        $return->setRefundAmount($refundAmount);
        $return->setQuantity($quantity);

        $this->em->persist($return);
    }
}
