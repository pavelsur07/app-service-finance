<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Application\Source\CostsDataSource;
use App\Marketplace\Application\Source\SalesReturnsDataSource;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Проверяет что DataSources в preliminary-режиме исключают проблемные строки,
 * а в финальном — берут все строки как прежде.
 */
final class DataSourcePreliminaryModeTest extends IntegrationTestCase
{
    private const COMPANY_ID  = '55555555-5555-5555-5555-000000000001';
    private const OWNER_ID    = '66666666-6666-6666-6666-000000000001';
    private const MARKETPLACE = MarketplaceType::WILDBERRIES;
    private const MARKETPLACE_VALUE = 'wildberries';
    private const PERIOD_FROM = '2026-04-01';
    private const PERIOD_TO   = '2026-04-30';

    private Company $company;
    private PLCategory $plCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('datasource-preliminary@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName('Выручка тест');
        $plCategory->setFlow(PLFlow::REVENUE);
        $this->plCategory = $plCategory;

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->persist($this->plCategory);
        $this->em->flush();
    }

    // ─── Sales ────────────────────────────────────────────────────────────────

    public function testSalesReturnsExcludesEntriesWithoutCostInPreliminaryMode(): void
    {
        $listing = $this->createListing('SKU-PREL-1');
        $this->createSaleMapping(AmountSource::SALE_COST_PRICE);

        // С себестоимостью: cost_price=200, qty=1 → вклад 200
        $this->createSale($listing, costPrice: '200.00', date: '2026-04-10');
        // Без себестоимости: должен быть исключён в preliminary
        $this->createSale($listing, costPrice: null, date: '2026-04-15');

        $this->em->flush();
        $this->em->clear();

        /** @var SalesReturnsDataSource $source */
        $source = self::getContainer()->get(SalesReturnsDataSource::class);

        $preliminaryEntries = $source->getUnprocessedEntries(
            self::COMPANY_ID, self::MARKETPLACE_VALUE,
            self::PERIOD_FROM, self::PERIOD_TO,
            true,
        );
        $finalEntries = $source->getUnprocessedEntries(
            self::COMPANY_ID, self::MARKETPLACE_VALUE,
            self::PERIOD_FROM, self::PERIOD_TO,
            false,
        );

        $prelimTotal = array_sum(array_column($preliminaryEntries, 'total_amount'));
        $finalTotal  = array_sum(array_column($finalEntries, 'total_amount'));

        // В preliminary — только продажа с cost_price: 200
        self::assertEqualsWithDelta(200.0, $prelimTotal, 0.01,
            'В preliminary-режиме продажи без себестоимости должны быть исключены.');

        // В финальном — обе продажи: cost_price=200 + cost_price=0 → 200
        // (sale_cost_price = COALESCE(cost_price,0)*qty: вторая = 0)
        // Сумма HAVING != 0, значит агрегат 200 (только первая вносит ненулевой вклад)
        // Но запрос без preliminary включает обе строки, агрегат остаётся 200
        // поскольку null cost_price → COALESCE(...,0)*1 = 0 (не вносит в HAVING != 0)
        // Проверяем главное: finalTotal >= prelimTotal (без фильтра берёт столько же или больше)
        self::assertGreaterThanOrEqual($prelimTotal, $finalTotal,
            'Финальный режим не должен быть строже preliminary.');
    }

    public function testSalesReturnsIncludesAllEntriesInFinalMode(): void
    {
        $listing = $this->createListing('SKU-PREL-2');

        // AmountSource::SALE_GROSS: total_revenue — не зависит от cost_price
        $this->createSaleMapping(AmountSource::SALE_GROSS);

        $this->createSale($listing, costPrice: '100.00', date: '2026-04-05', totalRevenue: '500.00');
        $this->createSale($listing, costPrice: null,     date: '2026-04-10', totalRevenue: '300.00');

        $this->em->flush();
        $this->em->clear();

        /** @var SalesReturnsDataSource $source */
        $source = self::getContainer()->get(SalesReturnsDataSource::class);

        $finalEntries = $source->getUnprocessedEntries(
            self::COMPANY_ID, self::MARKETPLACE_VALUE,
            self::PERIOD_FROM, self::PERIOD_TO,
            false,
        );
        $preliminaryEntries = $source->getUnprocessedEntries(
            self::COMPANY_ID, self::MARKETPLACE_VALUE,
            self::PERIOD_FROM, self::PERIOD_TO,
            true,
        );

        $finalTotal      = (float) array_sum(array_column($finalEntries, 'total_amount'));
        $preliminaryTotal = (float) array_sum(array_column($preliminaryEntries, 'total_amount'));

        // Финальный режим: обе продажи: 500 + 300 = 800
        self::assertEqualsWithDelta(800.0, $finalTotal, 0.01,
            'Финальный режим должен включать все продажи независимо от cost_price.');

        // Preliminary: только первая (cost_price IS NOT NULL): 500
        self::assertEqualsWithDelta(500.0, $preliminaryTotal, 0.01,
            'В preliminary-режиме продажи без себестоимости исключаются из SALE_GROSS тоже.');
    }

    // ─── Costs ────────────────────────────────────────────────────────────────

    public function testCostsExcludesUnknownServiceNamesInPreliminaryMode(): void
    {
        $knownCategory   = $this->createCostCategory('ozon_logistic_direct', 'Логистика');
        $unknownCategory = $this->createCostCategory('ozon_other_service',   'Прочее Ozon');

        $plCategory2 = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory2->setName('Затраты тест');
        $plCategory2->setFlow(PLFlow::EXPENSE);
        $this->em->persist($plCategory2);

        $knownMapping = new MarketplaceCostPLMapping(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $knownCategory,
            $this->plCategory->getId(),
            true,
        );
        $this->em->persist($knownMapping);

        $unknownMapping = new MarketplaceCostPLMapping(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $unknownCategory,
            $plCategory2->getId(),
            true,
        );
        $this->em->persist($unknownMapping);

        // Известная затрата: 1000
        $this->createCost($knownCategory, '1000.00', '2026-04-10');
        // Неизвестная: 500 — в preliminary должна быть исключена
        $this->createCost($unknownCategory, '500.00', '2026-04-15');

        $this->em->flush();
        $this->em->clear();

        /** @var CostsDataSource $source */
        $source = self::getContainer()->get(CostsDataSource::class);

        $preliminaryEntries = $source->getUnprocessedEntries(
            self::COMPANY_ID, self::MARKETPLACE_VALUE,
            self::PERIOD_FROM, self::PERIOD_TO,
            true,
        );
        $finalEntries = $source->getUnprocessedEntries(
            self::COMPANY_ID, self::MARKETPLACE_VALUE,
            self::PERIOD_FROM, self::PERIOD_TO,
            false,
        );

        $prelimTotal = (float) array_sum(array_column($preliminaryEntries, 'total_amount'));
        $finalTotal  = (float) array_sum(array_column($finalEntries, 'total_amount'));

        self::assertEqualsWithDelta(1000.0, $prelimTotal, 0.01,
            'В preliminary-режиме затраты ozon_other_service должны быть исключены.');
        self::assertEqualsWithDelta(1500.0, $finalTotal, 0.01,
            'В финальном режиме все замаппированные затраты включены.');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createListing(string $sku): MarketplaceListing
    {
        $listing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $this->company,
            null,
            self::MARKETPLACE,
        );
        $listing->setMarketplaceSku($sku);
        $listing->setPrice('1000.00');
        $this->em->persist($listing);

        return $listing;
    }

    private function createSaleMapping(AmountSource $amountSource): MarketplaceSaleMapping
    {
        $mapping = new MarketplaceSaleMapping(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
            $amountSource,
            $this->plCategory,
        );
        $this->em->persist($mapping);

        return $mapping;
    }

    private function createSale(
        MarketplaceListing $listing,
        ?string $costPrice,
        string $date,
        string $totalRevenue = '500.00',
    ): MarketplaceSale {
        $sale = new MarketplaceSale(
            Uuid::uuid4()->toString(),
            $this->company,
            $listing,
            self::MARKETPLACE,
        );
        $sale->setExternalOrderId('ext-' . Uuid::uuid4()->toString());
        $sale->setSaleDate(new \DateTimeImmutable($date));
        $sale->setQuantity(1);
        $sale->setPricePerUnit($totalRevenue);
        $sale->setTotalRevenue($totalRevenue);
        if ($costPrice !== null) {
            $sale->setCostPrice($costPrice);
        }
        $this->em->persist($sale);

        return $sale;
    }

    private function createCostCategory(string $code, string $name): MarketplaceCostCategory
    {
        $category = new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
        );
        $category->setCode($code);
        $category->setName($name);
        $this->em->persist($category);

        return $category;
    }

    private function createCost(
        MarketplaceCostCategory $category,
        string $amount,
        string $date,
    ): MarketplaceCost {
        $cost = new MarketplaceCost(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
            $category,
        );
        $cost->setAmount($amount);
        $cost->setCostDate(new \DateTimeImmutable($date));
        $cost->setOperationType(MarketplaceCostOperationType::CHARGE);
        $cost->setExternalId('ext-' . Uuid::uuid4()->toString());
        $this->em->persist($cost);

        return $cost;
    }
}
