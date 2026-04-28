<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CloseMonthStageActionMarkProcessedScopeTest extends IntegrationTestCase
{
    private const COMPANY_ID = '31111111-1111-1111-1111-000000000123';
    private const OWNER_ID = '32222222-2222-2222-2222-000000000123';
    private const MARKETPLACE = MarketplaceType::OZON;
    private const MARKETPLACE_VALUE = 'ozon';
    private const YEAR = 2026;
    private const MONTH = 2;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('mark-processed-scope@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testCostsCloseMarksOnlyRowsIncludedIntoPlDocument(): void
    {
        $plCategory = $this->createPlCategory('Затраты mapped');

        $includedCategory = $this->createCostCategory('cost_included', 'Included');
        $withoutMappingCategory = $this->createCostCategory('cost_without_mapping', 'Without mapping');
        $excludedCategory = $this->createCostCategory('cost_excluded', 'Excluded include_in_pl=false');

        $this->createCostMapping($includedCategory, $plCategory->getId(), true);
        $this->createCostMapping($excludedCategory, $plCategory->getId(), false);

        $includedCost = $this->createCost($includedCategory, '100.00', '2026-02-10');
        $withoutMappingCost = $this->createCost($withoutMappingCategory, '200.00', '2026-02-11');
        $excludedCost = $this->createCost($excludedCategory, '300.00', '2026-02-12');

        $this->em->flush();

        $this->closeStage(CloseStage::COSTS, preliminary: false);

        $this->assertMarked($includedCost->getId(), 'marketplace_costs', true);
        $this->assertMarked($withoutMappingCost->getId(), 'marketplace_costs', false);
        $this->assertMarked($excludedCost->getId(), 'marketplace_costs', false);
    }

    public function testCostsPreliminaryUsesSameFilterAsUnprocessedCostsQuery(): void
    {
        $plCategory = $this->createPlCategory('Затраты preliminary');

        $includedCategory = $this->createCostCategory('ozon_logistic_direct', 'Logistics');
        $preliminaryExcludedCategory = $this->createCostCategory('ozon_other_service', 'Other service');

        $this->createCostMapping($includedCategory, $plCategory->getId(), true);
        $this->createCostMapping($preliminaryExcludedCategory, $plCategory->getId(), true);

        $includedCost = $this->createCost($includedCategory, '150.00', '2026-02-15');
        $excludedCost = $this->createCost($preliminaryExcludedCategory, '250.00', '2026-02-16');

        $this->em->flush();

        $this->closeStage(CloseStage::COSTS, preliminary: true);

        $this->assertMarked($includedCost->getId(), 'marketplace_costs', true);
        $this->assertMarked($excludedCost->getId(), 'marketplace_costs', false);
    }

    public function testSalesPreliminaryDoesNotMarkRowsWithoutCostPrice(): void
    {
        $plCategory = $this->createPlCategory('Sale cost price');
        $this->createSaleMapping($plCategory, AmountSource::SALE_GROSS);

        $listing = $this->createListing('SKU-SALE-1');
        $saleWithCost = $this->createSale($listing, '120.00', '2026-02-10', '600.00');
        $saleWithoutCost = $this->createSale($listing, null, '2026-02-11', '700.00');

        $this->em->flush();

        $this->closeStage(CloseStage::SALES_RETURNS, preliminary: true);

        $this->assertMarked($saleWithCost->getId(), 'marketplace_sales', true);
        $this->assertMarked($saleWithoutCost->getId(), 'marketplace_sales', false);
    }

    public function testReturnsPreliminaryDoesNotMarkRowsWithoutCostPrice(): void
    {
        $plCategory = $this->createPlCategory('Return cost price');
        $this->createSaleMapping($plCategory, AmountSource::RETURN_COST_PRICE);

        $listing = $this->createListing('SKU-RET-1');
        $returnWithCost = $this->createReturn($listing, '30.00', '2026-02-20', '100.00');
        $returnWithoutCost = $this->createReturn($listing, null, '2026-02-21', '120.00');

        $this->em->flush();

        $this->closeStage(CloseStage::SALES_RETURNS, preliminary: true);

        $this->assertMarked($returnWithCost->getId(), 'marketplace_returns', true);
        $this->assertMarked($returnWithoutCost->getId(), 'marketplace_returns', false);
    }

    public function testFinalCloseWithValidDataStillMarksRowsAsProcessed(): void
    {
        $plCategory = $this->createPlCategory('Final close costs');
        $category = $this->createCostCategory('final_close_cost', 'Final close cost');
        $this->createCostMapping($category, $plCategory->getId(), true);

        $costA = $this->createCost($category, '400.00', '2026-02-10');
        $costB = $this->createCost($category, '600.00', '2026-02-12');

        $this->em->flush();

        $this->closeStage(CloseStage::COSTS, preliminary: false);

        $this->assertMarked($costA->getId(), 'marketplace_costs', true);
        $this->assertMarked($costB->getId(), 'marketplace_costs', true);
    }

    private function closeStage(CloseStage $stage, bool $preliminary): void
    {
        /** @var CloseMonthStageAction $action */
        $action = self::getContainer()->get(CloseMonthStageAction::class);

        ($action)(new CloseMonthStageCommand(
            companyId: self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year: self::YEAR,
            month: self::MONTH,
            stage: $stage->value,
            actorUserId: self::OWNER_ID,
            preliminary: $preliminary,
        ));

        $this->em->clear();
    }

    private function createPlCategory(string $name): PLCategory
    {
        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName($name);
        $plCategory->setFlow(PLFlow::EXPENSE);
        $this->em->persist($plCategory);

        return $plCategory;
    }

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

    private function createSaleMapping(PLCategory $plCategory, AmountSource $amountSource): void
    {
        $mapping = new MarketplaceSaleMapping(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
            $amountSource,
            $plCategory,
        );
        $this->em->persist($mapping);
    }

    private function createSale(
        MarketplaceListing $listing,
        ?string $costPrice,
        string $saleDate,
        string $totalRevenue,
    ): MarketplaceSale {
        $sale = new MarketplaceSale(
            Uuid::uuid4()->toString(),
            $this->company,
            $listing,
            self::MARKETPLACE,
        );
        $sale->setExternalOrderId('sale-' . Uuid::uuid4()->toString());
        $sale->setSaleDate(new \DateTimeImmutable($saleDate));
        $sale->setQuantity(1);
        $sale->setPricePerUnit($totalRevenue);
        $sale->setTotalRevenue($totalRevenue);
        $sale->setCostPrice($costPrice);
        $this->em->persist($sale);

        return $sale;
    }

    private function createReturn(
        MarketplaceListing $listing,
        ?string $costPrice,
        string $returnDate,
        string $refundAmount,
    ): MarketplaceReturn {
        $return = new MarketplaceReturn(
            Uuid::uuid4()->toString(),
            $this->company,
            $listing,
            self::MARKETPLACE,
        );
        $return->setExternalReturnId('return-' . Uuid::uuid4()->toString());
        $return->setReturnDate(new \DateTimeImmutable($returnDate));
        $return->setQuantity(1);
        $return->setRefundAmount($refundAmount);
        $return->setCostPrice($costPrice);
        $this->em->persist($return);

        return $return;
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

    private function createCostMapping(MarketplaceCostCategory $category, ?string $plCategoryId, bool $includeInPl): void
    {
        $mapping = new MarketplaceCostPLMapping(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $category,
            $plCategoryId,
            $includeInPl,
        );
        $this->em->persist($mapping);
    }

    private function createCost(MarketplaceCostCategory $category, string $amount, string $costDate): MarketplaceCost
    {
        $cost = new MarketplaceCost(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
            $category,
        );
        $cost->setAmount($amount);
        $cost->setCostDate(new \DateTimeImmutable($costDate));
        $cost->setOperationType(MarketplaceCostOperationType::CHARGE);
        $cost->setExternalId('cost-' . Uuid::uuid4()->toString());
        $this->em->persist($cost);

        return $cost;
    }

    private function assertMarked(string $id, string $table, bool $expectedMarked): void
    {
        $documentId = $this->em->getConnection()->fetchOne(
            sprintf('SELECT document_id FROM %s WHERE id = :id', $table),
            ['id' => $id],
        );

        if ($expectedMarked) {
            self::assertNotFalse($documentId);
            self::assertNotNull($documentId);

            return;
        }

        self::assertNull($documentId);
    }
}
