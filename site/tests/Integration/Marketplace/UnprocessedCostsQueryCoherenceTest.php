<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\UnprocessedCostsQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Гарантирует что getControlSum() и execute() считаются на одном подмножестве
 * строк при одинаковом флаге $preliminary.
 *
 * Если в execute() добавляется новый фильтр без добавления в getControlSum()
 * (или наоборот), тест поймает расхождение — CloseMonthStageAction бросит
 * RuntimeException в production на проверке контрольной суммы.
 */
final class UnprocessedCostsQueryCoherenceTest extends IntegrationTestCase
{
    private const COMPANY_ID        = '77777777-7777-7777-7777-000000000001';
    private const OWNER_ID          = '88888888-8888-8888-8888-000000000001';
    private const MARKETPLACE       = MarketplaceType::OZON;
    private const MARKETPLACE_VALUE = 'ozon';
    private const PERIOD_FROM       = '2026-05-01';
    private const PERIOD_TO         = '2026-05-31';

    private Company $company;
    private UnprocessedCostsQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('coherence-owner@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName('Логистика тест');
        $plCategory->setFlow(PLFlow::EXPENSE);

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->persist($plCategory);
        $this->em->flush();

        $this->query = self::getContainer()->get(UnprocessedCostsQuery::class);

        // 5 regular costs (100.00 each) + 3 ozon_other_service costs (50.00 each)
        $regular = $this->createMappedCategory('coherence_logistic', 'Логистика', $plCategory);
        $other   = $this->createMappedCategory('ozon_other_service', 'Прочие услуги Ozon', $plCategory);

        for ($i = 0; $i < 5; $i++) {
            $this->createCost($regular, '100.00', '2026-05-0' . ($i + 1));
        }
        for ($i = 0; $i < 3; $i++) {
            $this->createCost($other, '50.00', '2026-05-1' . $i);
        }

        $this->em->flush();
        $this->em->clear();
    }

    public function testControlSumMatchesExecuteSumInPreliminaryMode(): void
    {
        // preliminary=true excludes ozon_other_service → only 5×100 = 500
        $controlSum = (float) $this->query->getControlSum(
            self::COMPANY_ID,
            self::MARKETPLACE_VALUE,
            self::PERIOD_FROM,
            self::PERIOD_TO,
            true,
        );

        $executeSum = $this->sumFromExecute(preliminary: true);

        self::assertEqualsWithDelta(500.0, $controlSum, 0.01, 'getControlSum(preliminary) должен быть 500');
        self::assertEqualsWithDelta(500.0, $executeSum,  0.01, 'execute(preliminary) сумма должна быть 500');
        self::assertEqualsWithDelta(
            0.0,
            abs($controlSum - $executeSum),
            0.01,
            sprintf('getControlSum=%s и execute-сумма=%s должны совпадать в preliminary-режиме', $controlSum, $executeSum),
        );
    }

    public function testControlSumMatchesExecuteSumInFinalMode(): void
    {
        // preliminary=false includes all → 5×100 + 3×50 = 650
        $controlSum = (float) $this->query->getControlSum(
            self::COMPANY_ID,
            self::MARKETPLACE_VALUE,
            self::PERIOD_FROM,
            self::PERIOD_TO,
            false,
        );

        $executeSum = $this->sumFromExecute(preliminary: false);

        self::assertEqualsWithDelta(650.0, $controlSum, 0.01, 'getControlSum(final) должен быть 650');
        self::assertEqualsWithDelta(650.0, $executeSum,  0.01, 'execute(final) сумма должна быть 650');
        self::assertEqualsWithDelta(
            0.0,
            abs($controlSum - $executeSum),
            0.01,
            sprintf('getControlSum=%s и execute-сумма=%s должны совпадать в final-режиме', $controlSum, $executeSum),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createMappedCategory(string $code, string $name, PLCategory $plCategory): MarketplaceCostCategory
    {
        $category = new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
        );
        $category->setCode($code);
        $category->setName($name);
        $this->em->persist($category);

        $mapping = new MarketplaceCostPLMapping(
            Uuid::uuid4()->toString(),
            $this->company->getId(),
            $category,
            $plCategory->getId(),
            true,
        );
        $this->em->persist($mapping);

        return $category;
    }

    private function createCost(MarketplaceCostCategory $category, string $amount, string $costDate): void
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
        $cost->setExternalId('ext-' . Uuid::uuid4()->toString());
        $this->em->persist($cost);
    }

    /** Mirrors the aggregation in CloseMonthStageAction (~line 195-205). */
    private function sumFromExecute(bool $preliminary): float
    {
        $rows = $this->query->execute(
            self::COMPANY_ID,
            self::MARKETPLACE_VALUE,
            self::PERIOD_FROM,
            self::PERIOD_TO,
            $preliminary,
        );

        $sum = '0';
        foreach ($rows as $row) {
            $amount = (string) $row['total_amount'];
            if ((bool) $row['is_negative']) {
                $sum = bcadd($sum, $amount, 2);
            } else {
                $sum = bcsub($sum, $amount, 2);
            }
        }

        return (float) $sum;
    }
}
