<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Application\Command\ReopenMonthStageCommand;
use App\Marketplace\Application\ReopenMonthStageAction;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Проверяет поведение флага CloseMonthStageCommand::preliminary:
 *  - префикс «[Оперативное закрытие …]» в comment у каждой DocumentOperation
 *  - per-stage флаг settings.last_close_was_preliminary[stage]
 *    и settings.preliminary_calculated_at[stage] в MarketplaceMonthClose
 *  - сброс флага при финальном закрытии (только для соответствующего этапа)
 *  - default-поведение (preliminary=false) идентично прежнему флоу
 */
final class CloseMonthStageActionPreliminaryTest extends IntegrationTestCase
{
    private const COMPANY_ID  = '11111111-1111-1111-1111-000000000099';
    private const OWNER_ID    = '22222222-2222-2222-2222-000000000099';
    private const MARKETPLACE = MarketplaceType::OZON;
    private const MARKETPLACE_VALUE = 'ozon';
    private const YEAR  = 2026;
    private const MONTH = 2;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('preliminary-owner@example.test')
            ->build();

        $this->company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testPreliminaryFlagAddsMarkerToEachOperationComment(): void
    {
        $this->seedCostsForCosts();

        $this->closeCosts(preliminary: true);

        $conn = $this->em->getConnection();

        $documentRows = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(1, $documentRows, 'PLDocument должен быть создан при предзакрытии.');

        $operationComments = $conn->fetchFirstColumn(
            'SELECT do.comment
               FROM document_operations do
               JOIN documents d ON d.id = do.document_id
              WHERE d.company_id = :c',
            ['c' => self::COMPANY_ID],
        );

        self::assertNotEmpty($operationComments, 'У документа должны быть операции.');
        foreach ($operationComments as $comment) {
            self::assertIsString($comment);
            self::assertStringStartsWith(
                '[Оперативное закрытие ',
                (string) $comment,
                'Каждая операция должна иметь префикс предзакрытия.',
            );
        }
    }

    public function testPreliminaryFlagSetsSettingsFlag(): void
    {
        $this->seedCostsForCosts();

        $this->closeCosts(preliminary: true);

        $monthClose = $this->reloadMonthClose();
        self::assertNotNull($monthClose);

        $settings = $monthClose->getSettings();
        self::assertIsArray($settings);
        self::assertIsArray($settings['last_close_was_preliminary'] ?? null);
        self::assertTrue(
            $settings['last_close_was_preliminary'][CloseStage::COSTS->value] ?? false,
            'Per-stage флаг last_close_was_preliminary[costs] должен быть true.',
        );

        $calculatedAt = $settings['preliminary_calculated_at'][CloseStage::COSTS->value] ?? null;
        self::assertIsString($calculatedAt);
        self::assertNotFalse(
            \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $calculatedAt),
            'preliminary_calculated_at[costs] должен быть валидным ISO-таймстемпом.',
        );
    }

    /**
     * Регрессия: предварительное закрытие одного этапа НЕ должно
     * выставлять preliminary-флаг соседнего этапа того же месяца.
     * Иначе при следующем rebuild финально закрытый этап будет ошибочно
     * переоткрыт (см. P1-комментарий в PR #1673).
     */
    public function testPreliminaryFlagIsScopedToTheClosedStageOnly(): void
    {
        $this->seedCostsForCosts();

        $this->closeCosts(preliminary: true);

        $monthClose = $this->reloadMonthClose();
        self::assertNotNull($monthClose);

        self::assertTrue(
            $monthClose->isStageLastCloseWasPreliminary(CloseStage::COSTS),
            'Этап COSTS должен иметь per-stage preliminary-флаг = true.',
        );
        self::assertFalse(
            $monthClose->isStageLastCloseWasPreliminary(CloseStage::SALES_RETURNS),
            'Этап SALES_RETURNS не закрывался — его preliminary-флаг должен оставаться false.',
        );
    }

    public function testFinalCloseResetsPreliminaryFlag(): void
    {
        $this->seedCostsForCosts();

        // 1. Предварительное закрытие
        $this->closeCosts(preliminary: true);
        $afterPreliminary = $this->reloadMonthClose();
        self::assertNotNull($afterPreliminary);
        self::assertTrue($afterPreliminary->isStageLastCloseWasPreliminary(CloseStage::COSTS));

        // 2. Переоткрытие (та же стандартная процедура, что у финансиста)
        $this->reopenCosts();

        // 3. Финальное закрытие
        $this->closeCosts(preliminary: false);

        $finalMonthClose = $this->reloadMonthClose();
        self::assertNotNull($finalMonthClose);
        self::assertFalse(
            $finalMonthClose->isStageLastCloseWasPreliminary(CloseStage::COSTS),
            'После финального закрытия флаг этапа COSTS должен быть сброшен.',
        );

        $settings = $finalMonthClose->getSettings();
        self::assertIsArray($settings['preliminary_calculated_at'] ?? null);
        self::assertNull(
            $settings['preliminary_calculated_at'][CloseStage::COSTS->value] ?? 'unset',
            'preliminary_calculated_at[costs] должен быть null после финального закрытия.',
        );

        $conn = $this->em->getConnection();
        $operationComments = $conn->fetchFirstColumn(
            'SELECT do.comment
               FROM document_operations do
               JOIN documents d ON d.id = do.document_id
              WHERE d.company_id = :c',
            ['c' => self::COMPANY_ID],
        );

        self::assertNotEmpty($operationComments);
        foreach ($operationComments as $comment) {
            self::assertStringNotContainsString(
                '[Оперативное закрытие',
                (string) $comment,
                'У финальных операций не должно быть префикса.',
            );
        }
    }

    public function testDefaultIsFinalClose(): void
    {
        $this->seedCostsForCosts();

        $this->closeCosts(); // без флага → preliminary=false по дефолту

        $monthClose = $this->reloadMonthClose();
        self::assertNotNull($monthClose);
        self::assertFalse($monthClose->isStageLastCloseWasPreliminary(CloseStage::COSTS));

        $conn = $this->em->getConnection();
        $operationComments = $conn->fetchFirstColumn(
            'SELECT do.comment
               FROM document_operations do
               JOIN documents d ON d.id = do.document_id
              WHERE d.company_id = :c',
            ['c' => self::COMPANY_ID],
        );

        self::assertNotEmpty($operationComments);
        foreach ($operationComments as $comment) {
            self::assertStringNotContainsString(
                '[Оперативное закрытие',
                (string) $comment,
                'Default-флоу должен закрывать без префикса.',
            );
        }
    }

    // ── Stage 3 tests: soft-mode preflight bypass ────────────────────────────

    /**
     * Предзакрытие при продажах без себестоимости:
     * - блокирующий preflight игнорируется
     * - документ создаётся только с продажами у которых есть cost_price
     *
     * Используем SALE_REVENUE (total_revenue) чтобы продажа без cost_price
     * вносила ненулевой вклад в финальном режиме но исключалась в preliminary,
     * делая разницу в document_sum наблюдаемой.
     */
    public function testPreliminaryClose_BypassesSalesWithoutCostBlocker_AndExcludesThemFromDocument(): void
    {
        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName('Выручка продаж тест');
        $plCategory->setFlow(PLFlow::REVENUE);
        $this->em->persist($plCategory);

        $listing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $this->company,
            null,
            self::MARKETPLACE,
        );
        $listing->setMarketplaceSku('SKU-STAGE3-1');
        $listing->setPrice('1000.00');
        $this->em->persist($listing);

        // SALE_REVENUE = total_revenue, не зависит от cost_price
        $mapping = new MarketplaceSaleMapping(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
            AmountSource::SALE_REVENUE,
            $plCategory,
        );
        $this->em->persist($mapping);

        // Две продажи с себестоимостью: total_revenue = 1000 + 800 = 1800
        $this->createSaleEntityWithRevenue($listing, costPrice: '300.00', date: '2026-02-10', totalRevenue: '1000.00');
        $this->createSaleEntityWithRevenue($listing, costPrice: '500.00', date: '2026-02-15', totalRevenue: '800.00');
        // Одна без себестоимости: total_revenue = 600 — исключается в preliminary
        $this->createSaleEntityWithRevenue($listing, costPrice: null, date: '2026-02-20', totalRevenue: '600.00');

        $this->em->flush();
        $this->em->clear();

        $action = self::getContainer()->get(CloseMonthStageAction::class);
        $command = new CloseMonthStageCommand(
            companyId:   self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year:        self::YEAR,
            month:       self::MONTH,
            stage:       CloseStage::SALES_RETURNS->value,
            actorUserId: self::OWNER_ID,
            preliminary: true,
        );

        // Не должно бросить исключение, несмотря на продажу без себестоимости
        ($action)($command);

        $conn = $this->em->getConnection();
        $documentRows = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(1, $documentRows, 'PLDocument должен быть создан при предзакрытии с soft-bypass.');

        // Только 2 продажи с cost_price: total_revenue = 1000 + 800 = 1800
        $operationSum = (float) $conn->fetchOne(
            'SELECT COALESCE(SUM(ABS(do.amount)), 0)
               FROM document_operations do
               JOIN documents d ON d.id = do.document_id
              WHERE d.company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertEqualsWithDelta(1800.0, $operationSum, 0.01,
            'В документе должны быть только продажи с себестоимостью (1000+800=1800), продажа без cost_price (600) исключена.');
    }

    /**
     * Предзакрытие при нераспознанных service names:
     * - ozon_other_service исключается из документа
     */
    public function testPreliminaryClose_BypassesUnknownServiceNamesBlocker_AndExcludesThemFromDocument(): void
    {
        $knownPlCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $knownPlCategory->setName('Логистика тест');
        $knownPlCategory->setFlow(PLFlow::EXPENSE);
        $this->em->persist($knownPlCategory);

        $unknownPlCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $unknownPlCategory->setName('Прочее тест');
        $unknownPlCategory->setFlow(PLFlow::EXPENSE);
        $this->em->persist($unknownPlCategory);

        $knownCategory = new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
        );
        $knownCategory->setCode('ozon_logistic_direct');
        $knownCategory->setName('Логистика до покупателя');
        $this->em->persist($knownCategory);

        $unknownCategory = new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
        );
        $unknownCategory->setCode('ozon_other_service');
        $unknownCategory->setName('Прочие услуги Ozon');
        $this->em->persist($unknownCategory);

        $knownMapping = new MarketplaceCostPLMapping(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $knownCategory,
            $knownPlCategory->getId(),
            true,
        );
        $this->em->persist($knownMapping);

        $unknownMapping = new MarketplaceCostPLMapping(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $unknownCategory,
            $unknownPlCategory->getId(),
            true,
        );
        $this->em->persist($unknownMapping);

        // Известная затрата 1000
        $this->createCost($knownCategory, '1000.00', MarketplaceCostOperationType::CHARGE, '2026-02-10');
        // Неизвестная 400 — исключается в preliminary
        $this->createCost($unknownCategory, '400.00', MarketplaceCostOperationType::CHARGE, '2026-02-15');

        $this->em->flush();
        $this->em->clear();

        $action = self::getContainer()->get(CloseMonthStageAction::class);
        $command = new CloseMonthStageCommand(
            companyId:   self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year:        self::YEAR,
            month:       self::MONTH,
            stage:       CloseStage::COSTS->value,
            actorUserId: self::OWNER_ID,
            preliminary: true,
        );

        ($action)($command);

        $conn = $this->em->getConnection();
        $documentRows = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(1, $documentRows, 'PLDocument должен быть создан при предзакрытии без ozon_other_service.');

        $operationSum = (float) $conn->fetchOne(
            'SELECT COALESCE(SUM(ABS(do.amount)), 0)
               FROM document_operations do
               JOIN documents d ON d.id = do.document_id
              WHERE d.company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertEqualsWithDelta(1000.0, $operationSum, 0.01,
            'Только известная затрата (1000) должна быть в документе, ozon_other_service исключена.');
    }

    /**
     * costs_already_processed остаётся блокирующим даже в preliminary-режиме.
     */
    public function testPreliminaryClose_StillBlocksOnCostsAlreadyProcessed(): void
    {
        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName('Логистика блок');
        $plCategory->setFlow(PLFlow::EXPENSE);
        $this->em->persist($plCategory);

        $costCategory = new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
        );
        $costCategory->setCode('ozon_logistic_block');
        $costCategory->setName('Логистика блок');
        $this->em->persist($costCategory);

        $mapping = new MarketplaceCostPLMapping(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $costCategory,
            $plCategory->getId(),
            true,
        );
        $this->em->persist($mapping);

        $cost = $this->createCost($costCategory, '500.00', MarketplaceCostOperationType::CHARGE, '2026-02-10');
        $this->em->flush();

        // Вручную проставляем document_id — симулируем уже обработанную затрату
        $this->em->getConnection()->executeStatement(
            'UPDATE marketplace_costs SET document_id = :docId WHERE id = :id',
            ['docId' => Uuid::uuid4()->toString(), 'id' => $cost->getId()],
        );
        $this->em->clear();

        $action = self::getContainer()->get(CloseMonthStageAction::class);
        $command = new CloseMonthStageCommand(
            companyId:   self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year:        self::YEAR,
            month:       self::MONTH,
            stage:       CloseStage::COSTS->value,
            actorUserId: self::OWNER_ID,
            preliminary: true,
        );

        $this->expectException(\DomainException::class);
        ($action)($command);
    }

    /**
     * Финальное закрытие по-прежнему блокируется на sales_without_cost.
     */
    public function testFinalCloseUnchanged_StillBlocksOnSalesWithoutCost(): void
    {
        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName('Себестоимость регрессия');
        $plCategory->setFlow(PLFlow::EXPENSE);
        $this->em->persist($plCategory);

        $listing = new MarketplaceListing(
            Uuid::uuid4()->toString(),
            $this->company,
            null,
            self::MARKETPLACE,
        );
        $listing->setMarketplaceSku('SKU-REGRESSION-1');
        $listing->setPrice('1000.00');
        $this->em->persist($listing);

        $mapping = new MarketplaceSaleMapping(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
            AmountSource::SALE_COST_PRICE,
            $plCategory,
        );
        $this->em->persist($mapping);

        // Продажа без себестоимости — в финальном режиме блокирует
        $this->createSaleEntity($listing, null, '2026-02-10');

        $this->em->flush();
        $this->em->clear();

        $action = self::getContainer()->get(CloseMonthStageAction::class);
        $command = new CloseMonthStageCommand(
            companyId:   self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year:        self::YEAR,
            month:       self::MONTH,
            stage:       CloseStage::SALES_RETURNS->value,
            actorUserId: self::OWNER_ID,
            preliminary: false,
        );

        $this->expectException(\DomainException::class);
        ($action)($command);
    }

    // --- helpers ---

    private function createSaleEntity(
        MarketplaceListing $listing,
        ?string $costPrice,
        string $date,
    ): MarketplaceSale {
        return $this->createSaleEntityWithRevenue($listing, $costPrice, $date, '500.00');
    }

    private function createSaleEntityWithRevenue(
        MarketplaceListing $listing,
        ?string $costPrice,
        string $date,
        string $totalRevenue,
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

    private function seedCostsForCosts(): void
    {
        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName('Логистика Ozon');
        $plCategory->setFlow(PLFlow::EXPENSE);
        $this->em->persist($plCategory);

        $costCategory = new MarketplaceCostCategory(
            Uuid::uuid4()->toString(),
            $this->company,
            self::MARKETPLACE,
        );
        $costCategory->setCode('ozon_logistic_direct');
        $costCategory->setName('Логистика до покупателя');
        $this->em->persist($costCategory);

        $mapping = new MarketplaceCostPLMapping(
            Uuid::uuid4()->toString(),
            self::COMPANY_ID,
            $costCategory,
            $plCategory->getId(),
            true,
        );
        $this->em->persist($mapping);

        $this->createCost($costCategory, '1000.00', MarketplaceCostOperationType::CHARGE, '2026-02-10');
        $this->createCost($costCategory, '500.00',  MarketplaceCostOperationType::CHARGE, '2026-02-20');

        $this->em->flush();
        $this->em->clear();
    }

    private function closeCosts(bool $preliminary = false): void
    {
        /** @var CloseMonthStageAction $action */
        $action = self::getContainer()->get(CloseMonthStageAction::class);

        $command = new CloseMonthStageCommand(
            companyId:   self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year:        self::YEAR,
            month:       self::MONTH,
            stage:       CloseStage::COSTS->value,
            actorUserId: self::OWNER_ID,
            preliminary: $preliminary,
        );

        ($action)($command);

        $this->em->clear();
    }

    private function reopenCosts(): void
    {
        /** @var ReopenMonthStageAction $action */
        $action = self::getContainer()->get(ReopenMonthStageAction::class);

        ($action)(new ReopenMonthStageCommand(
            companyId:   self::COMPANY_ID,
            marketplace: self::MARKETPLACE_VALUE,
            year:        self::YEAR,
            month:       self::MONTH,
            stage:       CloseStage::COSTS,
        ));

        $this->em->clear();
    }

    private function reloadMonthClose(): ?MarketplaceMonthClose
    {
        /** @var MarketplaceMonthCloseRepository $repo */
        $repo = self::getContainer()->get(MarketplaceMonthCloseRepository::class);

        return $repo->findByPeriod(
            self::COMPANY_ID,
            self::MARKETPLACE,
            self::YEAR,
            self::MONTH,
        );
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
}
