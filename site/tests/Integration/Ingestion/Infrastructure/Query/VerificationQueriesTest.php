<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Infrastructure\Query;

use App\Finance\Entity\PLMonthlySnapshot;
use App\Finance\Enum\PLFlow;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Entity\NormalizationIssue;
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Facade\IngestionFacade;
use App\Ingestion\Infrastructure\Query\CoverageQuery;
use App\Ingestion\Infrastructure\Query\FinancialSummaryQuery;
use App\Ingestion\Infrastructure\Query\IssuesQuery;
use App\Ingestion\Infrastructure\Query\ReconciliationQuery;
use App\Marketplace\Entity\OzonTransactionTotalsCheck;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class VerificationQueriesTest extends IntegrationTestCase
{
    public function testCoverageAggregatesRawTransactionsAndOpenIssuesByShopAndResource(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $raw = $this->rawRecord(
            companyId: $companyId,
            shopRef: 'shop-1',
            resourceType: 'ozon_finance_accrual_by_day',
            fetchedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            externalId: 'raw-1',
        );
        $otherShop = $this->rawRecord(
            companyId: $companyId,
            shopRef: 'shop-2',
            resourceType: 'ozon_finance_accrual_by_day',
            fetchedAt: new \DateTimeImmutable('2026-06-15 11:00:00+00:00'),
            externalId: 'raw-2',
        );

        $this->em->persist($raw);
        $this->em->persist($otherShop);
        $this->em->persist($this->transaction($companyId, $raw->getId(), 'tx-1', 1000, TransactionType::SALE));
        $this->em->persist($this->transaction($companyId, $raw->getId(), 'tx-2', -200, TransactionType::COMMISSION));
        $this->em->persist(new NormalizationIssue(
            $companyId,
            $raw->getId(),
            null,
            NormalizationIssueKind::SUM_MISMATCH,
            ['hidden' => 'details'],
        ));
        $this->em->persist(new NormalizationIssue(
            $companyId,
            $otherShop->getId(),
            null,
            NormalizationIssueKind::MAPPER_FAILURE,
            [],
        ));
        $this->em->flush();

        /** @var CoverageQuery $query */
        $query = self::getContainer()->get(CoverageQuery::class);
        $cells = $query->heatmap(
            $companyId,
            'shop-1',
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        self::assertCount(1, $cells);
        self::assertSame('2026-06-15', $cells[0]->date);
        self::assertSame('shop-1', $cells[0]->shopRef);
        self::assertSame(1, $cells[0]->rawCount);
        self::assertSame(2, $cells[0]->txCount);
        self::assertSame(1, $cells[0]->issueCount);
    }

    public function testCoverageIncludesRawOnlyIngestionRecordsByJobWindowDate(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::WILDBERRIES,
            resourceType: 'wildberries_finance_sales_report_detailed',
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-21'),
            windowTo: new \DateTimeImmutable('2026-06-21'),
            shopRef: $connectionRef,
        );
        $raw = $this->rawRecord(
            companyId: $companyId,
            shopRef: $connectionRef,
            resourceType: 'wildberries_finance_sales_report_detailed',
            fetchedAt: new \DateTimeImmutable('2026-06-22 09:17:43+00:00'),
            externalId: 'wb-sales-report-detailed:2026-06-21:rrd-0',
            source: IngestSource::WILDBERRIES,
            connectionRef: $connectionRef,
            syncJobId: $job->getId(),
        );
        $raw->markNormalizationSkipped();

        $this->em->persist($job);
        $this->em->persist($raw);
        $this->em->flush();

        /** @var CoverageQuery $query */
        $query = self::getContainer()->get(CoverageQuery::class);
        $cells = $query->heatmap(
            $companyId,
            $connectionRef,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        self::assertCount(1, $cells);
        self::assertSame('2026-06-21', $cells[0]->date);
        self::assertSame($connectionRef, $cells[0]->shopRef);
        self::assertSame('wildberries_finance_sales_report_detailed', $cells[0]->resourceType);
        self::assertSame(1, $cells[0]->rawCount);
        self::assertSame(0, $cells[0]->txCount);
        self::assertSame(0, $cells[0]->issueCount);
        self::assertNotNull($cells[0]->lastFetchedAt);
    }

    public function testCoverageExpandsRawOnlyMultiDayJobWindowAcrossRequestedOverlap(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: 'ozon_finance_accrual_by_day',
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-07'),
            shopRef: $connectionRef,
        );
        $raw = $this->rawRecord(
            companyId: $companyId,
            shopRef: $connectionRef,
            resourceType: 'ozon_finance_accrual_by_day',
            fetchedAt: new \DateTimeImmutable('2026-06-08 09:00:00+00:00'),
            externalId: 'ozon-accrual-by-day:2026-06-01:2026-06-07',
            source: IngestSource::OZON,
            connectionRef: $connectionRef,
            syncJobId: $job->getId(),
        );
        $raw->markNormalizationSkipped();

        $this->em->persist($job);
        $this->em->persist($raw);
        $this->em->flush();

        /** @var CoverageQuery $query */
        $query = self::getContainer()->get(CoverageQuery::class);
        $cells = $query->heatmap(
            $companyId,
            $connectionRef,
            new \DateTimeImmutable('2026-06-05'),
            new \DateTimeImmutable('2026-06-06'),
        );

        self::assertCount(2, $cells);
        self::assertSame(['2026-06-05', '2026-06-06'], array_map(static fn ($cell): string => $cell->date, $cells));
        foreach ($cells as $cell) {
            self::assertSame($connectionRef, $cell->shopRef);
            self::assertSame('ozon_finance_accrual_by_day', $cell->resourceType);
            self::assertSame(1, $cell->rawCount);
            self::assertSame(0, $cell->txCount);
            self::assertSame(0, $cell->issueCount);
        }
    }

    public function testCoverageFallsBackToFetchedDateForRawOnlyRecordsWithoutJobWindow(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::WILDBERRIES,
            resourceType: 'wildberries_finance_sales_report_detailed',
            kind: SyncJobKind::INCREMENTAL,
            shopRef: $connectionRef,
        );
        $raw = $this->rawRecord(
            companyId: $companyId,
            shopRef: $connectionRef,
            resourceType: 'wildberries_finance_sales_report_detailed',
            fetchedAt: new \DateTimeImmutable('2026-06-22 09:17:43+00:00'),
            externalId: 'wb-sales-report-detailed:incremental:rrd-0',
            source: IngestSource::WILDBERRIES,
            connectionRef: $connectionRef,
            syncJobId: $job->getId(),
        );
        $raw->markNormalizationSkipped();

        $this->em->persist($job);
        $this->em->persist($raw);
        $this->em->flush();

        /** @var CoverageQuery $query */
        $query = self::getContainer()->get(CoverageQuery::class);
        $cells = $query->heatmap(
            $companyId,
            $connectionRef,
            new \DateTimeImmutable('2026-06-22'),
            new \DateTimeImmutable('2026-06-22'),
        );

        self::assertCount(1, $cells);
        self::assertSame('2026-06-22', $cells[0]->date);
        self::assertSame($connectionRef, $cells[0]->shopRef);
        self::assertSame('wildberries_finance_sales_report_detailed', $cells[0]->resourceType);
        self::assertSame(1, $cells[0]->rawCount);
        self::assertSame(0, $cells[0]->txCount);
        self::assertSame(0, $cells[0]->issueCount);
    }

    public function testCoverageCountsFailedSyncJobsAsOpenIssues(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: 'ozon_finance_accrual_by_day',
            kind: SyncJobKind::INCREMENTAL,
            shopRef: $connectionRef,
        );
        $job->setCursorSnapshot('2026-06-22');
        $job->markRunning();
        $job->markFailed('Connector failed after retries.');

        $this->em->persist($job);
        $this->em->flush();

        /** @var CoverageQuery $query */
        $query = self::getContainer()->get(CoverageQuery::class);
        $cells = $query->heatmap(
            $companyId,
            $connectionRef,
            new \DateTimeImmutable('2026-06-22'),
            new \DateTimeImmutable('2026-06-22'),
        );

        self::assertCount(1, $cells);
        self::assertSame('2026-06-22', $cells[0]->date);
        self::assertSame($connectionRef, $cells[0]->shopRef);
        self::assertSame('ozon_finance_accrual_by_day', $cells[0]->resourceType);
        self::assertSame(0, $cells[0]->rawCount);
        self::assertSame(0, $cells[0]->txCount);
        self::assertSame(1, $cells[0]->issueCount);
        self::assertNull($cells[0]->lastFetchedAt);
    }

    public function testCoverageCountsFailedBackfillChunkOnceAcrossWindow(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $parent = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: 'ozon_finance_accrual_by_day',
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-30'),
            shopRef: $connectionRef,
        );
        $chunk = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: 'ozon_finance_accrual_by_day',
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-30'),
            shopRef: $connectionRef,
            parentJobId: $parent->getId(),
        );
        $chunk->markRunning();
        $chunk->markFailed('Connector failed after retries.');

        $this->em->persist($parent);
        $this->em->persist($chunk);
        $this->em->flush();

        /** @var CoverageQuery $query */
        $query = self::getContainer()->get(CoverageQuery::class);
        $cells = $query->heatmap(
            $companyId,
            $connectionRef,
            new \DateTimeImmutable('2026-06-10'),
            new \DateTimeImmutable('2026-06-20'),
        );

        self::assertCount(1, $cells);
        self::assertSame('2026-06-10', $cells[0]->date);
        self::assertSame($connectionRef, $cells[0]->shopRef);
        self::assertSame('ozon_finance_accrual_by_day', $cells[0]->resourceType);
        self::assertSame(0, $cells[0]->rawCount);
        self::assertSame(0, $cells[0]->txCount);
        self::assertSame(1, $cells[0]->issueCount);
    }

    public function testCoverageIgnoresFailedAggregateBackfillParentIssue(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $parent = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: 'ozon_finance_accrual_by_day',
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-30'),
            shopRef: $connectionRef,
        );
        $parent->markRunning();
        $parent->markFailed('partial failure: 1 failed, 0 cancelled, 4 completed');

        $child = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: 'ozon_finance_accrual_by_day',
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-15'),
            windowTo: new \DateTimeImmutable('2026-06-21'),
            shopRef: $connectionRef,
            parentJobId: $parent->getId(),
        );
        $child->markRunning();
        $child->markFailed('Connector failed after retries.');

        $this->em->persist($parent);
        $this->em->persist($child);
        $this->em->flush();

        /** @var CoverageQuery $query */
        $query = self::getContainer()->get(CoverageQuery::class);
        $cells = $query->heatmap(
            $companyId,
            $connectionRef,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        self::assertCount(1, $cells);
        self::assertSame('2026-06-15', $cells[0]->date);
        self::assertSame($connectionRef, $cells[0]->shopRef);
        self::assertSame('ozon_finance_accrual_by_day', $cells[0]->resourceType);
        self::assertSame(0, $cells[0]->rawCount);
        self::assertSame(0, $cells[0]->txCount);
        self::assertSame(1, $cells[0]->issueCount);
    }

    public function testReconciliationComparesShopCanonWithCompanyPeriodOzonControl(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $rawRecordId = Uuid::uuid7()->toString();

        $this->em->persist($this->transaction($companyId, $rawRecordId, 'sale-1', 1000, TransactionType::SALE));
        $this->em->persist($this->transaction($companyId, $rawRecordId, 'refund-1', -300, TransactionType::REFUND));
        $this->em->persist($this->transaction($companyId, $rawRecordId, 'commission-1', -200, TransactionType::COMMISSION));
        $this->em->persist($this->transaction($companyId, $rawRecordId, 'other-shop-sale', 999, TransactionType::SALE, 'shop-2'));

        $check = new OzonTransactionTotalsCheck(
            companyId: $companyId,
            rawDocumentId: Uuid::uuid7()->toString(),
            periodFrom: new \DateTimeImmutable('2026-06-01'),
            periodTo: new \DateTimeImmutable('2026-06-30'),
        );
        $check->markOk([], ['total_minor' => 750], []);
        $this->em->persist($check);
        $this->em->flush();

        /** @var ReconciliationQuery $query */
        $query = self::getContainer()->get(ReconciliationQuery::class);

        $summary = $query->summary($companyId, 'shop-1', 2026, 6);
        $byType = $query->breakdownByType($companyId, 'shop-1', 2026, 6);

        self::assertSame(500, $summary->canonTotalMinor);
        self::assertSame(750, $summary->ozonControlTotalMinor);
        self::assertSame(-250, $summary->canonVsOzonDeltaMinor);
        self::assertSame('RUB', $summary->currency);
        self::assertSame(['sale', 'refund', 'commission'], array_column($byType, 'type'));
        self::assertSame(
            ['sale' => 1000, 'refund' => -300, 'commission' => -200],
            array_column($byType, 'canonAmountMinor', 'type'),
        );
    }

    public function testIssuesFacadeReturnsOnlyOpenHumanizedItemsWithPagination(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $raw = $this->rawRecord($companyId, 'shop-1', 'ozon_finance_accrual_by_day', new \DateTimeImmutable(), 'issue-raw-1');
        $otherShop = $this->rawRecord($companyId, 'shop-2', 'ozon_finance_accrual_by_day', new \DateTimeImmutable(), 'issue-raw-2');
        $resolved = new NormalizationIssue(
            $companyId,
            $raw->getId(),
            Uuid::uuid7()->toString(),
            NormalizationIssueKind::UNKNOWN_FIELD,
            ['field' => 'x'],
        );
        $resolved->markResolved();

        $this->em->persist($raw);
        $this->em->persist($otherShop);
        $this->em->persist(new NormalizationIssue(
            $companyId,
            $raw->getId(),
            Uuid::uuid7()->toString(),
            NormalizationIssueKind::SUM_MISMATCH,
            ['raw' => 'must-not-leak'],
        ));
        $this->em->persist(new NormalizationIssue(
            $companyId,
            $otherShop->getId(),
            null,
            NormalizationIssueKind::MAPPER_FAILURE,
            [],
        ));
        $this->em->persist($resolved);
        $this->em->flush();

        /** @var IssuesQuery $issuesQuery */
        $issuesQuery = self::getContainer()->get(IssuesQuery::class);
        self::assertSame(1, $issuesQuery->count($companyId, 'shop-1', null, null));

        /** @var IngestionFacade $facade */
        $facade = self::getContainer()->get(IngestionFacade::class);
        $result = $facade->listIssues($companyId, 'shop-1', null, null, 1, 50);

        self::assertSame(1, $result['meta']->total);
        self::assertSame('sum_mismatch', $result['items'][0]->kind);
        self::assertSame(
            'Сумма операций не сходится с контрольной суммой источника',
            $result['items'][0]->humanDescription,
        );
    }

    public function testFinancialSummaryUsesOnlyRebuiltMonthlySnapshots(): void
    {
        $owner = UserBuilder::aUser()->withIndex(9001)->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-4111-8111-111111119001')
            ->withOwner($owner)
            ->build();
        $incomeCategory = PLCategoryBuilder::aPLCategory()
            ->withId('33333333-3333-4333-8333-333333339001')
            ->forCompany($company)
            ->withName('Продажи')
            ->withFlow(PLFlow::INCOME)
            ->build();
        $expenseCategory = PLCategoryBuilder::aPLCategory()
            ->withId('33333333-3333-4333-8333-333333339002')
            ->forCompany($company)
            ->withName('Комиссии')
            ->withFlow(PLFlow::EXPENSE)
            ->build();
        $legacyCategory = PLCategoryBuilder::aPLCategory()
            ->withId('33333333-3333-4333-8333-333333339003')
            ->forCompany($company)
            ->withName('Legacy')
            ->withFlow(PLFlow::INCOME)
            ->build();

        $incomeSnapshot = new PLMonthlySnapshot(Uuid::uuid7()->toString(), $company, '2026-06', $incomeCategory);
        $incomeSnapshot->setAmountIncome('123.45');
        $incomeSnapshot->setRebuiltAt(new \DateTimeImmutable('2026-06-20 10:00:00+00:00'));

        $expenseSnapshot = new PLMonthlySnapshot(Uuid::uuid7()->toString(), $company, '2026-06', $expenseCategory);
        $expenseSnapshot->setAmountExpense('23.45');
        $expenseSnapshot->setRebuiltAt(new \DateTimeImmutable('2026-06-20 10:00:00+00:00'));

        $legacySnapshot = new PLMonthlySnapshot(Uuid::uuid7()->toString(), $company, '2026-06', $legacyCategory);
        $legacySnapshot->setAmountIncome('999.99');

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($incomeCategory);
        $this->em->persist($expenseCategory);
        $this->em->persist($legacyCategory);
        $this->em->persist($incomeSnapshot);
        $this->em->persist($expenseSnapshot);
        $this->em->persist($legacySnapshot);
        $this->em->flush();

        /** @var FinancialSummaryQuery $query */
        $query = self::getContainer()->get(FinancialSummaryQuery::class);

        $byMonth = $query->byMonth($company->getId(), null, 2026, 6, 2026, 6);
        $byCategory = $query->byCategory($company->getId(), null, 2026, 6);

        self::assertCount(1, $byMonth);
        self::assertSame(12345, $byMonth[0]->incomeMinor);
        self::assertSame(2345, $byMonth[0]->expenseMinor);
        self::assertSame(10000, $byMonth[0]->netMinor);
        self::assertSame(
            ['Комиссии' => 2345, 'Продажи' => 12345],
            array_column($byCategory, 'amountMinor', 'categoryName'),
        );
    }

    public function testFinancialSummaryMarketplaceCategoriesUseOzonSourceDataAndShopFilter(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $raw = $this->rawRecord(
            companyId: $companyId,
            shopRef: 'shop-1',
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            fetchedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            externalId: 'marketplace-category-raw-1',
        );
        $otherShopRaw = $this->rawRecord(
            companyId: $companyId,
            shopRef: 'shop-2',
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            fetchedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            externalId: 'marketplace-category-raw-2',
        );

        $this->em->persist($raw);
        $this->em->persist($otherShopRaw);
        $this->em->persist($this->marketplaceCategoryTransaction(
            companyId: $companyId,
            rawRecordId: $raw->getId(),
            externalId: 'ozon:accrual-by-day:1:delivery:type-29',
            amountMinor: 1000,
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            group: 'Услуги доставки',
            label: 'Логистика',
            sortOrder: 400,
        ));
        $this->em->persist($this->marketplaceCategoryTransaction(
            companyId: $companyId,
            rawRecordId: $raw->getId(),
            externalId: 'ozon:accrual-by-day:2:delivery:type-29',
            amountMinor: 500,
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            group: 'Услуги доставки',
            label: 'Логистика',
            sortOrder: 400,
        ));
        $this->em->persist($this->marketplaceCategoryTransaction(
            companyId: $companyId,
            rawRecordId: $raw->getId(),
            externalId: 'ozon:accrual-by-day:3:acquiring:type-1',
            amountMinor: 120,
            type: TransactionType::FEE,
            direction: TransactionDirection::IN,
            group: 'Услуги партнёров',
            label: 'Эквайринг',
            sortOrder: 510,
        ));
        $this->em->persist($this->marketplaceCategoryTransaction(
            companyId: $companyId,
            rawRecordId: $otherShopRaw->getId(),
            externalId: 'ozon:accrual-by-day:4:delivery:type-29',
            amountMinor: 999,
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            group: 'Услуги доставки',
            label: 'Логистика',
            sortOrder: 400,
            shopRef: 'shop-2',
        ));
        $this->em->persist($this->marketplaceCategoryTransaction(
            companyId: $companyId,
            rawRecordId: $raw->getId(),
            externalId: 'ozon:legacy:ignored',
            amountMinor: 777,
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            group: 'Услуги доставки',
            label: 'Логистика',
            sortOrder: 400,
            resourceType: 'ozon_finance_legacy',
        ));
        $this->em->flush();

        /** @var FinancialSummaryQuery $query */
        $query = self::getContainer()->get(FinancialSummaryQuery::class);
        $categories = $query->marketplaceCategories($companyId, 'shop-1', 2026, 6);

        self::assertCount(2, $categories);
        self::assertSame('Логистика', $categories[0]->categoryName);
        self::assertSame('Услуги доставки', $categories[0]->categoryGroup);
        self::assertSame(TransactionType::FEE->value, $categories[0]->type);
        self::assertSame(TransactionDirection::OUT->value, $categories[0]->direction);
        self::assertSame(-1500, $categories[0]->amountMinor);
        self::assertSame(2, $categories[0]->txCount);
        self::assertSame('Эквайринг', $categories[1]->categoryName);
        self::assertSame(120, $categories[1]->amountMinor);
        self::assertSame(1, $categories[1]->txCount);
    }

    private function rawRecord(
        string $companyId,
        string $shopRef,
        string $resourceType,
        \DateTimeImmutable $fetchedAt,
        string $externalId,
        IngestSource $source = IngestSource::OZON,
        string $connectionRef = 'connection-1',
        ?string $syncJobId = null,
    ): IngestRawRecord {
        return new IngestRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $shopRef,
            source: $source,
            resourceType: $resourceType,
            externalId: $externalId,
            storagePath: sprintf('%s/%s.ndjson.gz', $companyId, $externalId),
            hash: hash('sha256', $companyId.$externalId),
            byteSize: 100,
            fetchedAt: $fetchedAt,
            syncJobId: $syncJobId ?? 'job-'.$externalId,
        );
    }

    private function marketplaceCategoryTransaction(
        string $companyId,
        string $rawRecordId,
        string $externalId,
        int $amountMinor,
        TransactionType $type,
        TransactionDirection $direction,
        string $group,
        string $label,
        int $sortOrder,
        string $shopRef = 'shop-1',
        string $resourceType = OzonResourceType::ACCRUAL_BY_DAY,
    ): FinancialTransaction {
        return new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: $shopRef,
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: $type,
            direction: $direction,
            money: Money::fromMinor($amountMinor, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            rawRecordId: $rawRecordId,
            description: sprintf('Ozon: %s', $label),
            sourceData: [
                '_ingestion_resource' => $resourceType,
                '_ozon_category_group' => $group,
                '_ozon_category_label' => $label,
                '_ozon_category_sort_order' => $sortOrder,
            ],
        );
    }

    private function transaction(
        string $companyId,
        string $rawRecordId,
        string $externalId,
        int $amountMinor,
        TransactionType $type,
        string $shopRef = 'shop-1',
    ): FinancialTransaction {
        return new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: $shopRef,
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: $type,
            direction: $amountMinor >= 0 ? TransactionDirection::IN : TransactionDirection::OUT,
            money: Money::fromMinor($amountMinor, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            rawRecordId: $rawRecordId,
        );
    }
}
