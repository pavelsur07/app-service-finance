<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Application\Command\PreflightMonthCloseCommand;
use App\Marketplace\Application\MonthClosePreflightAction;
use App\Marketplace\Application\Service\MarketplacePeriodIntegrityChecker;
use App\Marketplace\Application\Source\CostsDataSource;
use App\Marketplace\Application\Source\MarketplaceDataSourceInterface;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Infrastructure\Query\MonthCloseAdvisoryLockQuery;
use App\Marketplace\Infrastructure\Query\UnprocessedCostsQuery;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Marketplace\Wildberries\Application\FinancialReport\WbFinancialReportPeriodResolver;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceFinancialReportSyncStatusBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

/**
 * Stage 1.2 (R-04 / M-01): финальное закрытие месяца Marketplace не проходит, пока данные
 * закрываемого периода неполны. «Сегодня» зафиксировано на 2026-10-04, поэтому сентябрь 2026 —
 * полностью завершённый месяц из 30 ожидаемых дней, а результат не зависит от реальной даты.
 */
final class MonthCloseIntegrityGateTest extends IntegrationTestCase
{
    private const COMPANY_ID = '41111111-1111-1111-1111-000000000201';
    private const OTHER_COMPANY_ID = '41111111-1111-1111-1111-000000000202';
    private const OWNER_ID = '42222222-2222-2222-2222-000000000201';
    private const OTHER_OWNER_ID = '42222222-2222-2222-2222-000000000202';
    private const MARKETPLACE = MarketplaceType::WILDBERRIES;
    private const YEAR = 2026;
    private const MONTH = 9;

    private Company $company;
    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->set(
            WbFinancialReportPeriodResolver::class,
            new WbFinancialReportPeriodResolver(new MockClock('2026-10-04 12:00:00 Europe/Moscow')),
        );

        $owner = UserBuilder::aUser()->withId(self::OWNER_ID)->withEmail('gate-owner@example.test')->build();
        $this->company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $otherOwner = UserBuilder::aUser()->withId(self::OTHER_OWNER_ID)->withEmail('gate-other@example.test')->build();
        $this->otherCompany = CompanyBuilder::aCompany()->withId(self::OTHER_COMPANY_ID)->withOwner($otherOwner)->build();

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->persist($otherOwner);
        $this->em->persist($this->otherCompany);
        $this->em->flush();
    }

    /** R-04: на старом коде закрытие неполного месяца проходило; теперь — отказ без единого побочного эффекта. */
    public function testFinalCloseIsBlockedWhenExpectedDaysAreIncompleteAndNothingIsChanged(): void
    {
        $cost = $this->seedClosableCost();
        $this->seedStatuses(self::COMPANY_ID, '2026-09-01', '2026-09-30');
        $this->setStatus(self::COMPANY_ID, '2026-09-12', FinancialReportSyncStatus::PROCESSING);
        $this->setStatus(self::COMPANY_ID, '2026-09-13', FinancialReportSyncStatus::FAILED);
        $this->deleteStatus(self::COMPANY_ID, '2026-09-14');

        $message = $this->closeExpectingBlock(preliminary: false);

        self::assertStringContainsString('3 из 30 ожидаемых дней', $message);
        self::assertStringContainsString('2026-09-12 processing', $message);
        self::assertStringContainsString('2026-09-13 failed', $message);
        self::assertStringContainsString('2026-09-14 missing', $message);
        $this->assertNothingClosed($cost->getId());
    }

    public function testPreflightExposesStructuredViolationsAsBlockingError(): void
    {
        $this->seedClosableCost();
        $this->seedStatuses(self::COMPANY_ID, '2026-09-01', '2026-09-30');
        $this->setStatus(self::COMPANY_ID, '2026-09-20', FinancialReportSyncStatus::CONFLICT);

        $result = $this->preflight(preliminary: false);

        self::assertFalse($result->canClose());
        $check = $this->check($result->checks, 'report_days_ready');
        self::assertTrue($check->blocking);
        self::assertSame(1, $check->value);
        self::assertSame([['business_date' => '2026-09-20', 'state' => 'failed', 'status' => 'conflict']], $check->details);
    }

    /** Happy path: все дни готовы — закрытие проходит как раньше: документ создан, строка привязана, этап закрыт. */
    public function testFinalCloseSucceedsWhenAllDaysAreReadyIncludingEmptyDay(): void
    {
        $cost = $this->seedClosableCost();
        $this->seedStatuses(self::COMPANY_ID, '2026-09-01', '2026-09-30');
        $this->setStatus(self::COMPANY_ID, '2026-09-05', FinancialReportSyncStatus::EMPTY);

        $result = $this->close(preliminary: false);

        self::assertCount(1, $result['plDocumentIds']);
        self::assertSame(1, $this->documentsCount(self::COMPANY_ID));
        self::assertSame($result['plDocumentIds'][0], $this->costDocumentId($cost->getId()));
        $monthClose = $this->monthClose();
        self::assertNotNull($monthClose);
        self::assertTrue($monthClose->isStageClosed(CloseStage::COSTS));
        self::assertFalse($monthClose->isStageLastCloseWasPreliminary(CloseStage::COSTS));
    }

    public function testProblemsOutsideThePeriodAndInOtherCompaniesDoNotBlock(): void
    {
        $cost = $this->seedClosableCost();
        $this->seedStatuses(self::COMPANY_ID, '2026-09-01', '2026-09-30');
        // Другой месяц той же компании: август с ошибками и в процессе.
        $this->seedStatuses(self::COMPANY_ID, '2026-08-10', '2026-08-12', FinancialReportSyncStatus::FAILED_FINAL);
        $this->seedStatuses(self::COMPANY_ID, '2026-08-13', '2026-08-14', FinancialReportSyncStatus::PROCESSING);
        // Другая компания: в сентябре все дни сломаны.
        $this->seedStatuses(self::OTHER_COMPANY_ID, '2026-09-01', '2026-09-30', FinancialReportSyncStatus::FAILED);
        // Сегодняшний и будущие дни не требуются.
        $this->seedStatuses(self::COMPANY_ID, '2026-10-04', '2026-10-06', FinancialReportSyncStatus::PROCESSING);

        $result = $this->close(preliminary: false);

        self::assertCount(1, $result['plDocumentIds']);
        self::assertNotNull($this->costDocumentId($cost->getId()));
    }

    public function testOperationalCloseIsNotBlockedByIncompleteDaysButReportsAWarning(): void
    {
        $cost = $this->seedClosableCost();
        $this->seedStatuses(self::COMPANY_ID, '2026-09-01', '2026-09-30');
        $this->setStatus(self::COMPANY_ID, '2026-09-29', FinancialReportSyncStatus::PROCESSING);

        $preliminary = $this->preflight(preliminary: true);
        $check = $this->check($preliminary->checks, 'report_days_ready');
        self::assertFalse($check->blocking);
        self::assertFalse($check->passed);
        self::assertTrue($preliminary->canClose());

        $this->close(preliminary: true);

        self::assertNotNull($this->costDocumentId($cost->getId()));
        $monthClose = $this->monthClose();
        self::assertNotNull($monthClose);
        self::assertTrue($monthClose->isStageLastCloseWasPreliminary(CloseStage::COSTS));
    }

    public function testFailedDaysCoveredByCompletedLegacyDocumentAreReady(): void
    {
        $this->seedStatuses(self::COMPANY_ID, '2026-04-01', '2026-04-30');
        $this->seedStatuses(self::COMPANY_ID, '2026-04-10', '2026-04-14', FinancialReportSyncStatus::FAILED, replace: true);
        $this->seedRawDocument('2026-04-08', '2026-04-14', MarketplaceRawFormat::WB_REPORT_DETAIL_BY_PERIOD, PipelineStatus::COMPLETED);

        $coverage = self::getContainer()->get(MarketplacePeriodIntegrityChecker::class)
            ->checkReportDays(self::COMPANY_ID, self::MARKETPLACE, '2026-04-01', '2026-04-30');

        self::assertNotNull($coverage);
        self::assertSame(30, $coverage->expectedDays);
        self::assertTrue($coverage->isReady(), 'Данные апреля пришли легаси-документом; failed (429) подневного загрузчика не делает месяц неполным.');
    }

    public function testLegacyCoverageIsBoundedByEraStatusFormatAndCompletion(): void
    {
        $this->seedStatuses(self::COMPANY_ID, '2026-04-01', '2026-04-30');
        foreach (['2026-04-05', '2026-04-06', '2026-04-08', '2026-04-21', '2026-04-22'] as $day) {
            $this->setStatus(self::COMPANY_ID, $day, FinancialReportSyncStatus::FAILED);
        }
        $this->setStatus(self::COMPANY_ID, '2026-04-07', FinancialReportSyncStatus::CONFLICT);

        // 04-05: легаси-документ не завершён; 04-06: завершённый документ другого (не легаси) формата;
        // 04-07: валидный легаси-документ, но статус conflict не снимается; 04-08: валидный легаси — покрыт;
        // 04-21/22: легаси-документ шире эпохи (заканчивается после 14.05.2026) день не покрывает.
        $this->seedRawDocument('2026-04-05', '2026-04-05', MarketplaceRawFormat::WB_REPORT_DETAIL_BY_PERIOD, PipelineStatus::PENDING);
        $this->seedRawDocument('2026-04-06', '2026-04-06', MarketplaceRawFormat::WB_FINANCE_SALES_REPORTS_DETAILED, PipelineStatus::COMPLETED);
        $this->seedRawDocument('2026-04-07', '2026-04-07', MarketplaceRawFormat::WB_REPORT_DETAIL_BY_PERIOD, PipelineStatus::COMPLETED);
        $this->seedRawDocument('2026-04-08', '2026-04-08', MarketplaceRawFormat::WB_REPORT_DETAIL_BY_PERIOD, PipelineStatus::COMPLETED);
        $this->seedRawDocument('2026-04-20', '2026-05-20', MarketplaceRawFormat::WB_REPORT_DETAIL_BY_PERIOD, PipelineStatus::COMPLETED);

        $coverage = self::getContainer()->get(MarketplacePeriodIntegrityChecker::class)
            ->checkReportDays(self::COMPANY_ID, self::MARKETPLACE, '2026-04-01', '2026-04-30');

        self::assertNotNull($coverage);
        self::assertSame(
            ['2026-04-05 failed', '2026-04-06 failed', '2026-04-07 conflict', '2026-04-21 failed', '2026-04-22 failed'],
            array_map(static fn ($violation): string => $violation->label(), $coverage->violations),
        );
    }

    /**
     * M-01: документ построен, но строка, обязанная в него войти, осталась без document_id.
     * Источник, не выполняющий привязку, имитирует такое расхождение; закрытие обязано откатиться целиком.
     */
    public function testRowsLeftUnlinkedAfterDocumentBuildRollBackTheWholeClose(): void
    {
        $cost = $this->seedClosableCost();
        $this->seedStatuses(self::COMPANY_ID, '2026-09-01', '2026-09-30');
        $realSource = self::getContainer()->get(CostsDataSource::class);
        $noLinkSource = new class($realSource) implements MarketplaceDataSourceInterface {
            public function __construct(private readonly CostsDataSource $inner)
            {
            }

            public function supports(MarketplaceType $marketplace): bool
            {
                return $this->inner->supports($marketplace);
            }

            public function getStage(): CloseStage
            {
                return $this->inner->getStage();
            }

            public function getSourceId(): string
            {
                return $this->inner->getSourceId();
            }

            public function getLabel(): string
            {
                return $this->inner->getLabel();
            }

            public function getUnprocessedEntries(string $companyId, string $marketplace, string $periodFrom, string $periodTo, bool $preliminary = false): array
            {
                return $this->inner->getUnprocessedEntries($companyId, $marketplace, $periodFrom, $periodTo, $preliminary);
            }

            public function markProcessed(string $companyId, string $marketplace, string $documentId, string $periodFrom, string $periodTo, bool $preliminary = false): int
            {
                return 0; // привязки нет — строка остаётся подходящей и непривязанной
            }
        };

        $action = new CloseMonthStageAction(
            self::getContainer()->get(MonthClosePreflightAction::class),
            self::getContainer()->get(MarketplaceMonthCloseRepository::class),
            self::getContainer()->get(MarketplaceConnectionRepository::class),
            self::getContainer()->get(FinanceFacade::class),
            self::getContainer()->get(UnprocessedCostsQuery::class),
            self::getContainer()->get(EntityManagerInterface::class),
            new NullLogger(),
            [$noLinkSource],
            self::getContainer()->get(MonthCloseAdvisoryLockQuery::class),
            self::getContainer()->get(MarketplacePeriodIntegrityChecker::class),
        );

        try {
            ($action)($this->command(false));
            self::fail('Закрытие с непривязанными строками не должно завершаться успешно.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('остались необработанные строки периода', $e->getMessage());
            self::assertStringContainsString('costs (1)', $e->getMessage());
        }

        $this->assertNothingClosed($cost->getId());
    }

    /**
     * Паритет выборки и привязки для продаж: строка с нулевой суммой по маппингу законно остаётся без документа,
     * а строки другого месяца не затрагиваются; закрытие при этом не откатывается инвариантом M-01.
     */
    public function testSalesReturnsCloseWithZeroAmountRowKeepsItUnlinkedAndDoesNotRollBack(): void
    {
        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName('Выручка WB');
        $plCategory->setFlow(PLFlow::INCOME);
        $this->em->persist($plCategory);
        $this->em->persist(new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $this->company, self::MARKETPLACE, AmountSource::SALE_REVENUE, $plCategory));
        $listing = new MarketplaceListing(Uuid::uuid4()->toString(), $this->company, null, self::MARKETPLACE);
        $listing->setMarketplaceSku('SKU-GATE-1');
        $listing->setPrice('1000.00');
        $this->em->persist($listing);
        $positive = $this->createSale($listing, '500.00', '2026-09-10');
        $zero = $this->createSale($listing, '0.00', '2026-09-11');
        $otherMonth = $this->createSale($listing, '300.00', '2026-08-30');
        $this->em->flush();
        $this->seedStatuses(self::COMPANY_ID, '2026-09-01', '2026-09-30');

        $action = self::getContainer()->get(CloseMonthStageAction::class);
        $result = ($action)(new CloseMonthStageCommand(
            companyId: self::COMPANY_ID,
            marketplace: self::MARKETPLACE->value,
            year: self::YEAR,
            month: self::MONTH,
            stage: CloseStage::SALES_RETURNS->value,
            actorUserId: self::OWNER_ID,
        ));
        $this->em->clear();

        self::assertCount(1, $result['plDocumentIds']);
        self::assertSame($result['plDocumentIds'][0], $this->tableDocumentId('marketplace_sales', $positive->getId()));
        self::assertNull($this->tableDocumentId('marketplace_sales', $zero->getId()), 'Нулевая строка законно остаётся без документа.');
        self::assertNull($this->tableDocumentId('marketplace_sales', $otherMonth->getId()), 'Строки другого месяца не затрагиваются.');
    }

    /** Законно непривязанные строки (include_in_pl = false) инвариант M-01 не ломают. */
    public function testRowsLegitimatelyExcludedFromPlDoNotTripTheLinkedInvariant(): void
    {
        $included = $this->seedClosableCost();
        $excludedCategory = $this->createCostCategory('wb_excluded', 'Исключено из ОПиУ');
        $this->createCostMapping($excludedCategory, Uuid::uuid4()->toString(), includeInPl: false);
        $excluded = $this->createCost($excludedCategory, '70.00', '2026-09-11');
        $this->em->flush();
        $this->seedStatuses(self::COMPANY_ID, '2026-09-01', '2026-09-30');

        $this->close(preliminary: false);

        self::assertNotNull($this->costDocumentId($included->getId()));
        self::assertNull($this->costDocumentId($excluded->getId()), 'Строка с include_in_pl = false остаётся без документа по замыслу.');
    }

    // ------------------------------------------------------------------ helpers

    private function seedClosableCost(): MarketplaceCost
    {
        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $plCategory->setName('Затраты WB');
        $plCategory->setFlow(PLFlow::EXPENSE);
        $this->em->persist($plCategory);

        $category = $this->createCostCategory('wb_gate_commission', 'Комиссия');
        $this->createCostMapping($category, $plCategory->getId(), includeInPl: true);
        $cost = $this->createCost($category, '150.00', '2026-09-10');
        $this->em->flush();

        return $cost;
    }

    private function createCostCategory(string $code, string $name): MarketplaceCostCategory
    {
        $category = new MarketplaceCostCategory(Uuid::uuid4()->toString(), $this->company, self::MARKETPLACE);
        $category->setCode($code);
        $category->setName($name);
        $this->em->persist($category);

        return $category;
    }

    private function createCostMapping(MarketplaceCostCategory $category, ?string $plCategoryId, bool $includeInPl): void
    {
        $this->em->persist(new MarketplaceCostPLMapping(Uuid::uuid4()->toString(), self::COMPANY_ID, $category, $plCategoryId, $includeInPl));
    }

    private function createCost(MarketplaceCostCategory $category, string $amount, string $costDate): MarketplaceCost
    {
        $cost = new MarketplaceCost(Uuid::uuid4()->toString(), $this->company, self::MARKETPLACE, $category);
        $cost->setAmount($amount);
        $cost->setCostDate(new \DateTimeImmutable($costDate));
        $cost->setOperationType(MarketplaceCostOperationType::CHARGE);
        $cost->setExternalId('cost-'.Uuid::uuid4()->toString());
        $this->em->persist($cost);

        return $cost;
    }

    private function createSale(MarketplaceListing $listing, string $revenue, string $saleDate): MarketplaceSale
    {
        $sale = new MarketplaceSale(Uuid::uuid4()->toString(), $this->company, $listing, self::MARKETPLACE);
        $sale->setExternalOrderId('sale-'.Uuid::uuid4()->toString());
        $sale->setSaleDate(new \DateTimeImmutable($saleDate));
        $sale->setQuantity(1);
        $sale->setPricePerUnit($revenue);
        $sale->setTotalRevenue($revenue);
        $sale->setCostPrice('100.00');
        $this->em->persist($sale);

        return $sale;
    }

    private function tableDocumentId(string $table, string $id): ?string
    {
        $value = $this->em->getConnection()->fetchOne(sprintf('SELECT document_id FROM %s WHERE id = :id', $table), ['id' => $id]);

        return false === $value ? null : $value;
    }

    private function seedStatuses(string $companyId, string $from, string $to, FinancialReportSyncStatus $status = FinancialReportSyncStatus::SUCCESS, bool $replace = false): void
    {
        for ($day = new \DateTimeImmutable($from); $day <= new \DateTimeImmutable($to); $day = $day->modify('+1 day')) {
            if ($replace) {
                $this->deleteStatus($companyId, $day->format('Y-m-d'));
            }

            $this->em->persist(MarketplaceFinancialReportSyncStatusBuilder::aStatus()
                ->withCompanyId($companyId)
                ->withBusinessDate($day->format('Y-m-d'))
                ->withStatus($status)
                ->build());
            $this->em->flush();
        }
    }

    private function setStatus(string $companyId, string $day, FinancialReportSyncStatus $status): void
    {
        $this->deleteStatus($companyId, $day);
        $this->seedStatuses($companyId, $day, $day, $status);
    }

    private function deleteStatus(string $companyId, string $day): void
    {
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            "DELETE FROM marketplace_financial_report_sync_statuses WHERE company_id = :c AND marketplace = 'wildberries' AND report_type = 'sales_report' AND business_date = :d",
            ['c' => $companyId, 'd' => $day],
        );
    }

    private function seedRawDocument(string $from, string $to, MarketplaceRawFormat $format, PipelineStatus $status): void
    {
        $company = $this->em->find(Company::class, self::COMPANY_ID);
        self::assertInstanceOf(Company::class, $company);
        $this->em->persist(MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(self::MARKETPLACE)
            ->withPeriod(new \DateTimeImmutable($from), new \DateTimeImmutable($to))
            ->withApiEndpoint($format->value)
            ->withProcessingStatus($status)
            ->build());
        $this->em->flush();
    }

    private function command(bool $preliminary): CloseMonthStageCommand
    {
        return new CloseMonthStageCommand(
            companyId: self::COMPANY_ID,
            marketplace: self::MARKETPLACE->value,
            year: self::YEAR,
            month: self::MONTH,
            stage: CloseStage::COSTS->value,
            actorUserId: self::OWNER_ID,
            preliminary: $preliminary,
        );
    }

    /** @return array{monthCloseId: string, plDocumentIds: string[], preflightResult: \App\Marketplace\Application\DTO\PreflightResult} */
    private function close(bool $preliminary): array
    {
        $action = self::getContainer()->get(CloseMonthStageAction::class);
        $result = ($action)($this->command($preliminary));
        $this->em->clear();

        return $result;
    }

    private function closeExpectingBlock(bool $preliminary): string
    {
        try {
            $this->close($preliminary);
        } catch (\DomainException $e) {
            return $e->getMessage();
        }

        self::fail('Ожидался отказ в закрытии: данные периода неполны.');
    }

    private function preflight(bool $preliminary): \App\Marketplace\Application\DTO\PreflightResult
    {
        $action = self::getContainer()->get(MonthClosePreflightAction::class);

        return ($action)(new PreflightMonthCloseCommand(
            companyId: self::COMPANY_ID,
            marketplace: self::MARKETPLACE->value,
            year: self::YEAR,
            month: self::MONTH,
            stage: CloseStage::COSTS,
            preliminary: $preliminary,
        ));
    }

    /** @param array<\App\Marketplace\Application\DTO\PreflightCheck> $checks */
    private function check(array $checks, string $key): \App\Marketplace\Application\DTO\PreflightCheck
    {
        foreach ($checks as $check) {
            if ($check->key === $key) {
                return $check;
            }
        }

        self::fail(sprintf('Проверка %s отсутствует', $key));
    }

    private function monthClose(): ?\App\Marketplace\Entity\MarketplaceMonthClose
    {
        return self::getContainer()->get(MarketplaceMonthCloseRepository::class)->findByPeriod(self::COMPANY_ID, self::MARKETPLACE, self::YEAR, self::MONTH);
    }

    private function assertNothingClosed(string $costId): void
    {
        self::assertSame(0, $this->documentsCount(self::COMPANY_ID), 'Документ ОПиУ не должен быть создан.');
        self::assertNull($this->costDocumentId($costId), 'Строка не должна быть привязана к документу.');
        $monthClose = $this->monthClose();
        if (null !== $monthClose) {
            self::assertFalse($monthClose->isStageClosed(CloseStage::COSTS));
        }
    }

    private function documentsCount(string $companyId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM documents WHERE company_id = :c', ['c' => $companyId]);
    }

    private function costDocumentId(string $costId): ?string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT document_id FROM marketplace_costs WHERE id = :id', ['id' => $costId]);

        return false === $value ? null : $value;
    }
}
