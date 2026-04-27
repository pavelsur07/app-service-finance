<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Application\Command\PreflightMonthCloseCommand;
use App\Marketplace\Application\MonthClosePreflightAction;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Проверяет что preflight-проверки возвращают details:
 *   - SKU продаж без себестоимости
 *   - SKU возвратов без себестоимости
 *   - список уникальных service names нераспознанных операций
 */
final class PreflightActionDetailsTest extends IntegrationTestCase
{
    private const COMPANY_ID  = '33333333-3333-3333-3333-000000000001';
    private const OWNER_ID    = '44444444-4444-4444-4444-000000000001';
    private const MARKETPLACE = MarketplaceType::OZON;
    private const MARKETPLACE_VALUE = 'ozon';
    private const YEAR  = 2026;
    private const MONTH = 3;
    private const PERIOD_FROM = '2026-03-01';
    private const PERIOD_TO   = '2026-03-31';

    private Company $company;
    private MonthClosePreflightAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('preflight-details@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();

        $this->action = self::getContainer()->get(MonthClosePreflightAction::class);
    }

    public function testSalesWithoutCostReturnsSkusInDetails(): void
    {
        $listing1 = $this->createListing('SKU-ALPHA');
        $listing2 = $this->createListing('SKU-BETA');

        // Два заказа без себестоимости по SKU-ALPHA, один — по SKU-BETA
        $this->createSale($listing1, costPrice: null, date: '2026-03-10');
        $this->createSale($listing1, costPrice: null, date: '2026-03-12');
        $this->createSale($listing2, costPrice: null, date: '2026-03-15');
        $this->em->flush();
        $this->em->clear();

        $result = ($this->action)($this->makeSalesCommand());

        $check = $this->findCheck($result->checks, 'sales_without_cost');
        self::assertNotNull($check, 'Проверка sales_without_cost должна присутствовать');
        self::assertFalse($check->passed);
        self::assertCount(2, $check->details, 'details должен содержать 2 уникальных SKU');

        // Первый элемент — с наибольшим count (SKU-ALPHA: 2 шт.)
        self::assertSame('SKU-ALPHA', $check->details[0]['marketplace_sku']);
        self::assertSame(2, (int) $check->details[0]['count']);

        self::assertSame('SKU-BETA', $check->details[1]['marketplace_sku']);
        self::assertSame(1, (int) $check->details[1]['count']);
    }

    public function testReturnsWithoutCostReturnsSkusInDetails(): void
    {
        $listing1 = $this->createListing('SKU-GAMMA');
        $listing2 = $this->createListing('SKU-DELTA');

        // Один возврат без себестоимости по SKU-GAMMA, два — по SKU-DELTA
        $this->createReturn($listing1, costPrice: null, date: '2026-03-05');
        $this->createReturn($listing2, costPrice: null, date: '2026-03-08');
        $this->createReturn($listing2, costPrice: null, date: '2026-03-09');
        $this->em->flush();
        $this->em->clear();

        $result = ($this->action)($this->makeSalesCommand());

        $check = $this->findCheck($result->checks, 'returns_without_cost');
        self::assertNotNull($check, 'Проверка returns_without_cost должна присутствовать');
        self::assertFalse($check->passed);
        self::assertCount(2, $check->details, 'details должен содержать 2 уникальных SKU');

        // Первый — SKU-DELTA (2 шт.), отсортирован по count DESC
        self::assertSame('SKU-DELTA', $check->details[0]['marketplace_sku']);
        self::assertSame(2, (int) $check->details[0]['count']);

        self::assertSame('SKU-GAMMA', $check->details[1]['marketplace_sku']);
        self::assertSame(1, (int) $check->details[1]['count']);
    }

    public function testUnknownServiceNamesReturnsListInDetails(): void
    {
        $otherCategory = $this->createCostCategory('ozon_other_service', 'Прочие услуги Ozon');

        $this->createCostWithDescription($otherCategory, 'UnknownServiceA', '2026-03-10');
        $this->createCostWithDescription($otherCategory, 'UnknownServiceB', '2026-03-15');
        $this->createCostWithDescription($otherCategory, 'UnknownServiceA', '2026-03-20');
        $this->em->flush();
        $this->em->clear();

        $result = ($this->action)($this->makeCostsCommand());

        $check = $this->findCheck($result->checks, 'costs_unknown_service_names');
        self::assertNotNull($check, 'Проверка costs_unknown_service_names должна присутствовать');
        self::assertFalse($check->passed);
        self::assertCount(2, $check->details, 'details должен содержать 2 уникальных service name');

        $serviceNames = array_column($check->details, 'service_name');
        self::assertContains('UnknownServiceA', $serviceNames);
        self::assertContains('UnknownServiceB', $serviceNames);

        // UnknownServiceA встречается 2 раза — должен быть первым (ORDER BY COUNT DESC)
        self::assertSame('UnknownServiceA', $check->details[0]['service_name']);
        self::assertSame(2, (int) $check->details[0]['count']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createListing(string $marketplaceSku): MarketplaceListing
    {
        $listing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $this->company,
            null,
            self::MARKETPLACE,
        );
        $listing->setMarketplaceSku($marketplaceSku);
        $listing->setPrice('1000.00');
        $this->em->persist($listing);

        return $listing;
    }

    private function createSale(MarketplaceListing $listing, ?string $costPrice, string $date): MarketplaceSale
    {
        $sale = new MarketplaceSale(
            Uuid::uuid4()->toString(),
            $this->company,
            $listing,
            self::MARKETPLACE,
        );
        $sale->setExternalOrderId('ext-' . Uuid::uuid4()->toString());
        $sale->setSaleDate(new \DateTimeImmutable($date));
        $sale->setQuantity(1);
        $sale->setPricePerUnit('500.00');
        $sale->setTotalRevenue('500.00');
        if ($costPrice !== null) {
            $sale->setCostPrice($costPrice);
        }
        $this->em->persist($sale);

        return $sale;
    }

    private function createReturn(MarketplaceListing $listing, ?string $costPrice, string $date): MarketplaceReturn
    {
        $return = new MarketplaceReturn(
            Uuid::uuid4()->toString(),
            $this->company,
            $listing,
            self::MARKETPLACE,
        );
        $return->setReturnDate(new \DateTimeImmutable($date));
        $return->setQuantity(1);
        $return->setRefundAmount('500.00');
        if ($costPrice !== null) {
            $return->setCostPrice($costPrice);
        }
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

    private function createCostWithDescription(
        MarketplaceCostCategory $category,
        string $description,
        string $date,
    ): MarketplaceCost {
        $cost = new MarketplaceCost(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
            $category,
        );
        $cost->setAmount('100.00');
        $cost->setCostDate(new \DateTimeImmutable($date));
        $cost->setOperationType(MarketplaceCostOperationType::CHARGE);
        $cost->setExternalId('ext-' . Uuid::uuid4()->toString());
        $cost->setDescription($description);
        $this->em->persist($cost);

        return $cost;
    }

    private function makeSalesCommand(): PreflightMonthCloseCommand
    {
        return new PreflightMonthCloseCommand(
            companyId:   self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year:        self::YEAR,
            month:       self::MONTH,
            stage:       CloseStage::SALES_RETURNS,
        );
    }

    private function makeCostsCommand(): PreflightMonthCloseCommand
    {
        return new PreflightMonthCloseCommand(
            companyId:   self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year:        self::YEAR,
            month:       self::MONTH,
            stage:       CloseStage::COSTS,
        );
    }

    private function findCheck(array $checks, string $key): ?object
    {
        foreach ($checks as $check) {
            if ($check->key === $key) {
                return $check;
            }
        }

        return null;
    }
}
