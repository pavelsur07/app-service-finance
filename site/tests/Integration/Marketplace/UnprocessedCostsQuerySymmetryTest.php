<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\Document;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\PreflightCostsQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedCostsQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Гарантирует симметрию "формулы A" (getControlSum / preflight net_amount_for_pl)
 * и "формулы B" (execute → PLEntryDTO-like reduce, повторяет логику
 * CloseMonthStageAction::__invoke строки ~195–205).
 *
 * Баг до фикса: getControlSum использовал SUM(c.amount) без учёта operation_type,
 * тогда как execute вычитает storno через is_storno-флаг. Поскольку после
 * миграции Version20260413120000 все storno-строки хранятся как amount ≥ 0
 * с operation_type='storno', расхождение на паре charge+storno равнялось
 * 2 · Σ storno.amount.
 */
final class UnprocessedCostsQuerySymmetryTest extends IntegrationTestCase
{
    private const MARKETPLACE = MarketplaceType::OZON;
    private const MARKETPLACE_VALUE = 'ozon';
    private const PERIOD_FROM = '2026-01-01';
    private const PERIOD_TO = '2026-01-31';

    private Company $company;

    private UnprocessedCostsQuery $unprocessedCostsQuery;
    private PreflightCostsQuery $preflightCostsQuery;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000077')
            ->withEmail('symmetry-owner@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000077')
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();

        $this->unprocessedCostsQuery = self::getContainer()->get(UnprocessedCostsQuery::class);
        $this->preflightCostsQuery = self::getContainer()->get(PreflightCostsQuery::class);
    }

    public function testOnlyChargeHasDeltaZero(): void
    {
        $category = $this->createMappedCategory('only_charge', 'Only charge');
        $this->createCost($category, '1000.00', MarketplaceCostOperationType::CHARGE, '2026-01-10');
        $this->em->flush();

        self::assertEqualsWithDelta(1000.0, (float) $this->getControlSum(), 0.01);
        self::assertEqualsWithDelta(1000.0, $this->handlerPlDocumentSum(), 0.01);
        self::assertEqualsWithDelta(1000.0, (float) $this->getPreflightNetAmount(), 0.01);
        $this->assertSymmetry();
    }

    public function testChargeMinusStornoInSameCategoryHasDeltaZero(): void
    {
        $category = $this->createMappedCategory('charge_storno', 'Charge minus storno');
        $this->createCost($category, '1000.00', MarketplaceCostOperationType::CHARGE, '2026-01-10');
        $this->createCost($category, '300.00', MarketplaceCostOperationType::STORNO, '2026-01-12');
        $this->em->flush();

        // controlSum = 1000 − 300 = 700; handler sum = 1000 − 300 = 700.
        self::assertEqualsWithDelta(700.0, (float) $this->getControlSum(), 0.01);
        self::assertEqualsWithDelta(700.0, $this->handlerPlDocumentSum(), 0.01);
        self::assertEqualsWithDelta(700.0, (float) $this->getPreflightNetAmount(), 0.01);
        $this->assertSymmetry();
    }

    public function testStandaloneStornoCategoryContributesNegatively(): void
    {
        $categoryCharge = $this->createMappedCategory('only_charge_cat', 'Only-charge cat');
        $categoryStorno = $this->createMappedCategory('only_storno_cat', 'Only-storno cat');

        $this->createCost($categoryCharge, '2000.00', MarketplaceCostOperationType::CHARGE, '2026-01-10');
        $this->createCost($categoryStorno, '500.00', MarketplaceCostOperationType::STORNO, '2026-01-12');
        $this->em->flush();

        // A = 2000 − 500 = 1500; B сводит standalone-storno группу в отрицательный вклад.
        self::assertEqualsWithDelta(1500.0, (float) $this->getControlSum(), 0.01);
        self::assertEqualsWithDelta(1500.0, $this->handlerPlDocumentSum(), 0.01);
        $this->assertSymmetry();

        // Одиночная storno-группа даёт отрицательный вклад.
        $rows = $this->unprocessedCostsQuery->execute(
            $this->company->getId(),
            self::MARKETPLACE_VALUE,
            self::PERIOD_FROM,
            self::PERIOD_TO,
        );
        $stornoOnlyRows = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['cost_category_code'] === 'only_storno_cat' && $r['is_storno'] === true,
        ));
        self::assertCount(1, $stornoOnlyRows);
        self::assertFalse($stornoOnlyRows[0]['is_negative'], 'Standalone storno row must have is_negative=false to subtract from PL sum.');
    }

    public function testNetNegativeCategoryStaysNegativeNotAbs(): void
    {
        $category = $this->createMappedCategory('net_negative', 'Net negative');
        $this->createCost($category, '500.00', MarketplaceCostOperationType::CHARGE, '2026-01-10');
        $this->createCost($category, '800.00', MarketplaceCostOperationType::STORNO, '2026-01-12');
        $this->em->flush();

        // 500 − 800 = −300; важно что результат отрицательный, не ABS.
        self::assertEqualsWithDelta(-300.0, (float) $this->getControlSum(), 0.01);
        self::assertEqualsWithDelta(-300.0, $this->handlerPlDocumentSum(), 0.01);
        self::assertEqualsWithDelta(-300.0, (float) $this->getPreflightNetAmount(), 0.01);
        $this->assertSymmetry();
    }

    public function testIncludeInPlFalseIsIgnoredByBothFormulas(): void
    {
        $included = $this->createMappedCategory('included', 'Included', includeInPl: true);
        $excluded = $this->createMappedCategory('excluded', 'Excluded', includeInPl: false);

        $this->createCost($included, '700.00', MarketplaceCostOperationType::CHARGE, '2026-01-10');
        $this->createCost($excluded, '999.00', MarketplaceCostOperationType::CHARGE, '2026-01-11');
        $this->createCost($excluded, '111.00', MarketplaceCostOperationType::STORNO, '2026-01-12');
        $this->em->flush();

        self::assertEqualsWithDelta(700.0, (float) $this->getControlSum(), 0.01);
        self::assertEqualsWithDelta(700.0, $this->handlerPlDocumentSum(), 0.01);
        self::assertEqualsWithDelta(700.0, (float) $this->getPreflightNetAmount(), 0.01);
        $this->assertSymmetry();
    }

    public function testAlreadyProcessedRowIsIgnoredByBothFormulas(): void
    {
        $category = $this->createMappedCategory('processed_cat', 'Processed cat');

        $processed = $this->createCost($category, '5000.00', MarketplaceCostOperationType::CHARGE, '2026-01-10');
        $this->createCost($category, '400.00', MarketplaceCostOperationType::CHARGE, '2026-01-12');
        $this->em->flush();

        $document = new Document(Uuid::uuid4()->toString(), $this->company);
        $this->em->persist($document);
        $this->em->flush();

        $processed->setDocument($document);
        $this->em->flush();

        // Preflight не фильтрует document_id (это отдельная метрика already_processed),
        // поэтому здесь сравниваем только getControlSum с handler'ом.
        self::assertEqualsWithDelta(400.0, (float) $this->getControlSum(), 0.01);
        self::assertEqualsWithDelta(400.0, $this->handlerPlDocumentSum(), 0.01);
        self::assertEqualsWithDelta(
            0.0,
            abs((float) $this->getControlSum() - $this->handlerPlDocumentSum()),
            0.01,
        );
    }

    public function testCostDateOutOfPeriodIsIgnoredByBothFormulas(): void
    {
        $category = $this->createMappedCategory('period_cat', 'Period cat');
        $this->createCost($category, '1200.00', MarketplaceCostOperationType::CHARGE, '2026-01-10');
        $this->createCost($category, '9999.00', MarketplaceCostOperationType::CHARGE, '2025-12-31'); // до периода
        $this->createCost($category, '7777.00', MarketplaceCostOperationType::STORNO, '2026-02-01'); // после периода
        $this->em->flush();

        self::assertEqualsWithDelta(1200.0, (float) $this->getControlSum(), 0.01);
        self::assertEqualsWithDelta(1200.0, $this->handlerPlDocumentSum(), 0.01);
        self::assertEqualsWithDelta(1200.0, (float) $this->getPreflightNetAmount(), 0.01);
        $this->assertSymmetry();
    }

    /**
     * Воспроизводит production-инцидент: company b57d7682-...
     * январь 2026, 1 000 000 начислений + 15 596.06 сторно.
     *
     * До фикса: A = 1 015 596.06, B = 984 403.94, delta ≈ 31 192.12 (= 2·Σ storno).
     * После фикса: delta = 0.
     */
    public function testIncidentReproductionHasDeltaZero(): void
    {
        $category = $this->createMappedCategory('incident_cat', 'Incident cat');
        $this->createCost($category, '1000000.00', MarketplaceCostOperationType::CHARGE, '2026-01-10');
        $this->createCost($category, '15596.06', MarketplaceCostOperationType::STORNO, '2026-01-15');
        $this->em->flush();

        $controlSum = (float) $this->getControlSum();
        $handlerSum = $this->handlerPlDocumentSum();

        self::assertEqualsWithDelta(984403.94, $controlSum, 0.01, 'After fix getControlSum == handler plDocumentSum == charge − storno.');
        self::assertEqualsWithDelta(984403.94, $handlerSum, 0.01);
        self::assertEqualsWithDelta(0.0, abs($controlSum - $handlerSum), 0.01, 'Control sum and handler sum must match.');
    }

    // ----------------- helpers -----------------

    private function createMappedCategory(
        string $code,
        string $name,
        bool $includeInPl = true,
    ): MarketplaceCostCategory {
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
            // plCategoryId: использование FK на реальную PLCategory не требуется —
            // поле хранит guid и фильтруется через IS NOT NULL.
            Uuid::uuid4()->toString(),
            $includeInPl,
        );
        $this->em->persist($mapping);

        return $category;
    }

    private function createCost(
        MarketplaceCostCategory $category,
        string $amount,
        MarketplaceCostOperationType $operationType,
        string $costDate,
    ): MarketplaceCost {
        $cost = new MarketplaceCost(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
            $category,
        );
        $cost->setAmount($amount);
        $cost->setCostDate(new \DateTimeImmutable($costDate));
        $cost->setOperationType($operationType);
        $cost->setExternalId('ext-' . Uuid::uuid4()->toString());
        $this->em->persist($cost);

        return $cost;
    }

    private function getControlSum(): string
    {
        return $this->unprocessedCostsQuery->getControlSum(
            $this->company->getId(),
            self::MARKETPLACE_VALUE,
            self::PERIOD_FROM,
            self::PERIOD_TO,
        );
    }

    private function getPreflightNetAmount(): string
    {
        $stats = $this->preflightCostsQuery->getCostsStats(
            $this->company->getId(),
            self::MARKETPLACE_VALUE,
            self::PERIOD_FROM,
            self::PERIOD_TO,
        );

        return (string) $stats['net_amount_for_pl'];
    }

    /**
     * Воспроизводит CloseMonthStageAction::__invoke строки ~195–205:
     *   для каждой entry: isNegative=true → +amount, isNegative=false → -amount.
     */
    private function handlerPlDocumentSum(): float
    {
        $rows = $this->unprocessedCostsQuery->execute(
            $this->company->getId(),
            self::MARKETPLACE_VALUE,
            self::PERIOD_FROM,
            self::PERIOD_TO,
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

    private function assertSymmetry(): void
    {
        $controlSum = (float) $this->getControlSum();
        $handlerSum = $this->handlerPlDocumentSum();
        $preflight = (float) $this->getPreflightNetAmount();

        self::assertEqualsWithDelta(
            0.0,
            abs($controlSum - $handlerSum),
            0.01,
            sprintf(
                'Formula A (getControlSum=%s) must equal Formula B (handler plDocumentSum=%s).',
                $controlSum,
                $handlerSum,
            ),
        );
        self::assertEqualsWithDelta(
            0.0,
            abs($controlSum - $preflight),
            0.01,
            sprintf(
                'Preflight net_amount_for_pl (%s) must equal getControlSum (%s).',
                $preflight,
                $controlSum,
            ),
        );
    }
}
