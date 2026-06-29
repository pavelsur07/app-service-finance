<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Infrastructure\Query;

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

    public function testCoverageSuppressesFailedBackfillIssueWhenSuccessfulRawCoverageExists(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $failedParent = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-20'),
            windowTo: new \DateTimeImmutable('2026-06-26'),
            shopRef: $connectionRef,
        );
        $failedChild = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-20'),
            windowTo: new \DateTimeImmutable('2026-06-26'),
            shopRef: $connectionRef,
            parentJobId: $failedParent->getId(),
        );
        $failedChild->markRunning();
        $failedChild->markFailed('RuntimeException: stale fixed connector error.');

        $successfulParent = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-27'),
            shopRef: $connectionRef,
        );
        $successfulChild = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-20'),
            windowTo: new \DateTimeImmutable('2026-06-26'),
            shopRef: $connectionRef,
            parentJobId: $successfulParent->getId(),
        );
        $successfulChild->markRunning();
        $successfulChild->markCompleted();
        $raw = $this->rawRecord(
            companyId: $companyId,
            shopRef: $connectionRef,
            resourceType: OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS,
            fetchedAt: new \DateTimeImmutable('2026-06-28 17:42:00+00:00'),
            externalId: 'search-promo-products:2026-06-20:2026-06-26',
            source: IngestSource::OZON,
            connectionRef: $connectionRef,
            syncJobId: $successfulChild->getId(),
        );
        $raw->markNormalizationSkipped();

        $this->em->persist($failedParent);
        $this->em->persist($failedChild);
        $this->em->persist($successfulParent);
        $this->em->persist($successfulChild);
        $this->em->persist($raw);
        $this->em->flush();

        /** @var CoverageQuery $query */
        $query = self::getContainer()->get(CoverageQuery::class);
        $cells = $query->heatmap(
            $companyId,
            $connectionRef,
            new \DateTimeImmutable('2026-06-20'),
            new \DateTimeImmutable('2026-06-20'),
        );

        self::assertCount(1, $cells);
        self::assertSame('2026-06-20', $cells[0]->date);
        self::assertSame($connectionRef, $cells[0]->shopRef);
        self::assertSame(OzonResourceType::PERFORMANCE_SEARCH_PROMO_PRODUCTS, $cells[0]->resourceType);
        self::assertSame(1, $cells[0]->rawCount);
        self::assertSame(0, $cells[0]->txCount);
        self::assertSame(0, $cells[0]->issueCount);
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
        $this->em->persist($this->transaction($companyId, $rawRecordId, 'wb-sale-noise', 7777, TransactionType::SALE, source: IngestSource::WILDBERRIES));

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
        $companySummary = $query->summary($companyId, null, 2026, 6);
        $byType = $query->breakdownByType($companyId, 'shop-1', 2026, 6);

        self::assertSame(500, $summary->canonTotalMinor);
        self::assertSame(750, $summary->ozonControlTotalMinor);
        self::assertSame(-250, $summary->canonVsOzonDeltaMinor);
        self::assertSame(1499, $companySummary->canonTotalMinor);
        self::assertSame(749, $companySummary->canonVsOzonDeltaMinor);
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

    public function testFinancialSummaryUsesNormalizedTransactionsAndOptionalShopScope(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $raw = $this->rawRecord(
            companyId: $companyId,
            shopRef: 'shop-1',
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            fetchedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            externalId: 'financial-summary-raw-1',
        );
        $otherShopRaw = $this->rawRecord(
            companyId: $companyId,
            shopRef: 'shop-2',
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            fetchedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            externalId: 'financial-summary-raw-2',
        );

        $this->em->persist($raw);
        $this->em->persist($otherShopRaw);
        $this->em->persist($this->transaction($companyId, $raw->getId(), 'summary-sale-1', 1000, TransactionType::SALE));
        $this->em->persist($this->transaction($companyId, $raw->getId(), 'summary-commission-1', -200, TransactionType::COMMISSION));
        $this->em->persist($this->transaction($companyId, $otherShopRaw->getId(), 'summary-sale-2', 500, TransactionType::SALE, 'shop-2'));
        $this->em->persist($this->transaction($companyId, $otherShopRaw->getId(), 'summary-refund-2', -100, TransactionType::REFUND, 'shop-2'));
        $this->em->persist($this->transaction(
            $companyId,
            $raw->getId(),
            'summary-july-ignored',
            99999,
            TransactionType::SALE,
            occurredAt: new \DateTimeImmutable('2026-07-01 00:00:00+00:00'),
        ));
        $this->em->flush();

        /** @var FinancialSummaryQuery $query */
        $query = self::getContainer()->get(FinancialSummaryQuery::class);

        $allShopsMonth = $query->byMonth($companyId, null, 2026, 6, 2026, 6);
        $shopOneMonth = $query->byMonth($companyId, 'shop-1', 2026, 6, 2026, 6);
        $byCategory = $query->byCategory($companyId, null, 2026, 6);

        self::assertCount(1, $allShopsMonth);
        self::assertSame(1500, $allShopsMonth[0]->incomeMinor);
        self::assertSame(300, $allShopsMonth[0]->expenseMinor);
        self::assertSame(1200, $allShopsMonth[0]->netMinor);
        self::assertSame(1000, $shopOneMonth[0]->incomeMinor);
        self::assertSame(200, $shopOneMonth[0]->expenseMinor);
        $categoryAmounts = array_column($byCategory, 'amountMinor', 'categoryId');
        ksort($categoryAmounts);

        self::assertSame(
            [
                'commission:out' => 200,
                'refund:out' => 100,
                'sale:in' => 1500,
            ],
            $categoryAmounts,
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

    public function testFinancialSummaryMarketplaceCategoriesKeepLegacyDescriptionFallback(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $raw = $this->rawRecord(
            companyId: $companyId,
            shopRef: 'shop-1',
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            fetchedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            externalId: 'marketplace-category-legacy-raw',
        );

        $this->em->persist($raw);
        $this->em->persist($this->legacyMarketplaceCategoryTransaction(
            companyId: $companyId,
            rawRecordId: $raw->getId(),
            externalId: 'ozon:accrual-by-day:legacy:posting:32',
            amountMinor: 1000,
            description: 'Ozon accrual posting 32',
        ));
        $this->em->persist($this->legacyMarketplaceCategoryTransaction(
            companyId: $companyId,
            rawRecordId: $raw->getId(),
            externalId: 'ozon:accrual-by-day:legacy:item:1',
            amountMinor: 250,
            description: 'Ozon accrual item 1',
        ));
        $this->em->flush();

        /** @var FinancialSummaryQuery $query */
        $query = self::getContainer()->get(FinancialSummaryQuery::class);
        $categories = $query->marketplaceCategories($companyId, 'shop-1', 2026, 6);
        $amountsByName = array_column($categories, 'amountMinor', 'categoryName');

        self::assertSame(-250, $amountsByName['Ozon accrual item 1'] ?? null);
        self::assertSame(-1000, $amountsByName['Ozon accrual posting 32'] ?? null);
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

    private function legacyMarketplaceCategoryTransaction(
        string $companyId,
        string $rawRecordId,
        string $externalId,
        int $amountMinor,
        string $description,
    ): FinancialTransaction {
        return new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor($amountMinor, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            rawRecordId: $rawRecordId,
            description: $description,
            sourceData: [
                '_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY,
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
        ?\DateTimeImmutable $occurredAt = null,
        ?IngestSource $source = null,
        ?array $sourceData = null,
    ): FinancialTransaction {
        $source ??= IngestSource::OZON;
        $sourceData ??= IngestSource::OZON === $source ? ['_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY] : [];

        return new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: $shopRef,
            source: $source,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: $type,
            direction: $amountMinor >= 0 ? TransactionDirection::IN : TransactionDirection::OUT,
            money: Money::fromMinor($amountMinor, 'RUB'),
            occurredAt: $occurredAt ?? new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            rawRecordId: $rawRecordId,
            sourceData: $sourceData,
        );
    }
}
