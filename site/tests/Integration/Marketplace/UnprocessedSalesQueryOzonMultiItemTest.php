<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Marketplace\Infrastructure\Query\UnprocessedSalesQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Регрессия бага SALE_GROSS для Ozon:
 * price_per_unit у Ozon хранит accrual за posting целиком (не цену за штуку),
 * а quantity — число item'ов в posting'е. Старая формула
 * price_per_unit × quantity умножала accrual на количество товаров и
 * завышала выручку на multi-item posting'ах
 * (см. OzonSalesRawProcessor::processBatch:216-229).
 *
 * Фикс: AmountSource::SALE_GROSS для Ozon разворачивается в s.total_revenue.
 */
final class UnprocessedSalesQueryOzonMultiItemTest extends IntegrationTestCase
{
    private const PERIOD_FROM = '2026-01-01';
    private const PERIOD_TO = '2026-01-31';

    private Company $company;
    private PLCategory $plCategory;
    private MarketplaceListing $ozonListing;
    private MarketplaceListing $wbListing;

    private UnprocessedSalesQuery $unprocessedSalesQuery;
    private MarketplaceFacade $marketplaceFacade;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000099')
            ->withEmail('ozon-sale-gross@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000099')
            ->withOwner($owner)
            ->build();

        $this->plCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($this->company)
            ->withName('MP_GROSS_REVENUE')
            ->build();

        $this->ozonListing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $this->company,
            null,
            MarketplaceType::OZON,
        );
        $this->ozonListing->setMarketplaceSku('ozon-sku-1');
        $this->ozonListing->setPrice('1000.00');

        $this->wbListing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $this->company,
            null,
            MarketplaceType::WILDBERRIES,
        );
        $this->wbListing->setMarketplaceSku('wb-sku-1');
        $this->wbListing->setPrice('400.00');

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->persist($this->plCategory);
        $this->em->persist($this->ozonListing);
        $this->em->persist($this->wbListing);
        $this->em->flush();

        $this->unprocessedSalesQuery = self::getContainer()->get(UnprocessedSalesQuery::class);
        $this->marketplaceFacade = self::getContainer()->get(MarketplaceFacade::class);
    }

    /**
     * Тест 1: multi-item posting Ozon — воспроизводит баг.
     *
     * До фикса: 3 × 1000 = 3000 (накрутка ×3).
     * После фикса: total_revenue = 1000.
     */
    public function testOzonMultiItemPostingDoesNotInflateRevenue(): void
    {
        $this->createSaleMapping(MarketplaceType::OZON, AmountSource::SALE_GROSS);
        $this->createOzonSale(quantity: 3, pricePerUnit: '1000.00', totalRevenue: '1000.00');
        $this->em->flush();

        $total = $this->getSaleGrossTotal('ozon');

        self::assertEqualsWithDelta(
            1000.0,
            $total,
            0.01,
            'Для multi-item Ozon posting SALE_GROSS должен равняться total_revenue (1000), а не price_per_unit × quantity (3000).',
        );
    }

    /**
     * Тест 2: single-item posting Ozon — фикс не должен сломать норму.
     */
    public function testOzonSingleItemPostingUnchanged(): void
    {
        $this->createSaleMapping(MarketplaceType::OZON, AmountSource::SALE_GROSS);
        $this->createOzonSale(quantity: 1, pricePerUnit: '1000.00', totalRevenue: '1000.00');
        $this->em->flush();

        self::assertEqualsWithDelta(1000.0, $this->getSaleGrossTotal('ozon'), 0.01);
    }

    /**
     * Тест 3: Wildberries multi-item — другой маркетплейс не трогаем.
     *
     * WB: price_per_unit — реальная цена за штуку, формула ×quantity корректна.
     */
    public function testWildberriesMultiItemFormulaUnchanged(): void
    {
        $this->createSaleMapping(MarketplaceType::WILDBERRIES, AmountSource::SALE_GROSS);
        $this->createWbSale(quantity: 3, pricePerUnit: '400.00', totalRevenue: '1200.00');
        $this->em->flush();

        self::assertEqualsWithDelta(1200.0, $this->getSaleGrossTotal('wildberries'), 0.01);
    }

    /**
     * Тест 4: симметрия Unit-экономики и ОПиУ для одного и того же Ozon posting'а.
     */
    public function testUnitEconomyAndPnlAreSymmetricForOzonMultiItem(): void
    {
        $this->createSaleMapping(MarketplaceType::OZON, AmountSource::SALE_GROSS);
        $saleDate = new \DateTimeImmutable('2026-01-15');
        $this->createOzonSale(
            quantity: 3,
            pricePerUnit: '1000.00',
            totalRevenue: '1000.00',
            saleDate: $saleDate,
        );
        $this->em->flush();

        $pnl = $this->getSaleGrossTotal('ozon');

        $sales = $this->marketplaceFacade->getSalesForListingAndDate(
            $this->company->getId(),
            $this->ozonListing->getId(),
            $saleDate,
        );
        $unit = 0.0;
        foreach ($sales as $sale) {
            $unit += (float) $sale->totalRevenue;
        }

        self::assertEqualsWithDelta(1000.0, $pnl, 0.01);
        self::assertEqualsWithDelta(1000.0, $unit, 0.01);
        self::assertEqualsWithDelta(
            0.0,
            abs($pnl - $unit),
            0.01,
            'Unit-экономика и ОПиУ SALE_GROSS обязаны давать одинаковую выручку на одном posting\'е Ozon.',
        );
    }

    /**
     * Тест 5: инцидентная фикстура из 3 posting'ов.
     *
     * До фикса: SALE_GROSS = 100000 + 50000×2 + 30000×3 = 290 000.
     * После фикса: SALE_GROSS = 100000 + 50000 + 30000 = 180 000 = Σ total_revenue.
     */
    public function testOzonIncidentReproductionThreePostingsSumsToTotalRevenue(): void
    {
        $this->createSaleMapping(MarketplaceType::OZON, AmountSource::SALE_GROSS);
        $this->createOzonSale(quantity: 1, pricePerUnit: '100000.00', totalRevenue: '100000.00', externalOrderId: 'POST-A');
        $this->createOzonSale(quantity: 2, pricePerUnit: '50000.00',  totalRevenue: '50000.00',  externalOrderId: 'POST-B');
        $this->createOzonSale(quantity: 3, pricePerUnit: '30000.00',  totalRevenue: '30000.00',  externalOrderId: 'POST-C');
        $this->em->flush();

        $pnl = $this->getSaleGrossTotal('ozon');

        self::assertEqualsWithDelta(180000.0, $pnl, 0.01);

        $totalRevenueSum = (float) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(total_revenue), 0) FROM marketplace_sales
             WHERE company_id = :companyId AND marketplace = :marketplace
             AND sale_date BETWEEN :periodFrom AND :periodTo',
            [
                'companyId' => $this->company->getId(),
                'marketplace' => 'ozon',
                'periodFrom' => self::PERIOD_FROM,
                'periodTo' => self::PERIOD_TO,
            ],
        );

        self::assertEqualsWithDelta(180000.0, $totalRevenueSum, 0.01);
        self::assertEqualsWithDelta(
            0.0,
            abs($pnl - $totalRevenueSum),
            0.01,
            'После фикса SALE_GROSS (ОПиУ) должен совпадать с Σ total_revenue (Unit-экономика).',
        );
    }

    // ------------------------- helpers -------------------------

    private function getSaleGrossTotal(string $marketplace): float
    {
        $rows = $this->unprocessedSalesQuery->execute(
            $this->company->getId(),
            $marketplace,
            self::PERIOD_FROM,
            self::PERIOD_TO,
        );

        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) $row['total_amount'];
        }

        return $total;
    }

    private function createSaleMapping(MarketplaceType $marketplace, AmountSource $amountSource): MarketplaceSaleMapping
    {
        $mapping = new MarketplaceSaleMapping(
            Uuid::uuid4()->toString(),
            $this->company,
            $marketplace,
            $amountSource,
            $this->plCategory,
        );
        $this->em->persist($mapping);

        return $mapping;
    }

    private function createOzonSale(
        int $quantity,
        string $pricePerUnit,
        string $totalRevenue,
        ?\DateTimeImmutable $saleDate = null,
        ?string $externalOrderId = null,
    ): MarketplaceSale {
        return $this->createSale(
            MarketplaceType::OZON,
            $this->ozonListing,
            $quantity,
            $pricePerUnit,
            $totalRevenue,
            $saleDate,
            $externalOrderId,
        );
    }

    private function createWbSale(
        int $quantity,
        string $pricePerUnit,
        string $totalRevenue,
        ?\DateTimeImmutable $saleDate = null,
        ?string $externalOrderId = null,
    ): MarketplaceSale {
        return $this->createSale(
            MarketplaceType::WILDBERRIES,
            $this->wbListing,
            $quantity,
            $pricePerUnit,
            $totalRevenue,
            $saleDate,
            $externalOrderId,
        );
    }

    private function createSale(
        MarketplaceType $marketplace,
        MarketplaceListing $listing,
        int $quantity,
        string $pricePerUnit,
        string $totalRevenue,
        ?\DateTimeImmutable $saleDate,
        ?string $externalOrderId,
    ): MarketplaceSale {
        $sale = new MarketplaceSale(
            Uuid::uuid4()->toString(),
            $this->company,
            $listing,
            $marketplace,
        );
        $sale->setExternalOrderId($externalOrderId ?? ('ext-' . Uuid::uuid4()->toString()));
        $sale->setSaleDate($saleDate ?? new \DateTimeImmutable('2026-01-15'));
        $sale->setQuantity($quantity);
        $sale->setPricePerUnit($pricePerUnit);
        $sale->setTotalRevenue($totalRevenue);
        $this->em->persist($sale);

        return $sale;
    }
}
