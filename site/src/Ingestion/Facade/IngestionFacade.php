<?php

declare(strict_types=1);

namespace App\Ingestion\Facade;

use App\Ingestion\Application\DTO\CoverageCellView;
use App\Ingestion\Application\DTO\FinancialSummaryCategoryView;
use App\Ingestion\Application\DTO\FinancialSummaryMarketplaceCategoryView;
use App\Ingestion\Application\DTO\FinancialSummaryMonthView;
use App\Ingestion\Application\DTO\FinancialTransactionView;
use App\Ingestion\Application\DTO\IssueListItemView;
use App\Ingestion\Application\DTO\PaginationMeta;
use App\Ingestion\Application\DTO\ReconciliationByTypeView;
use App\Ingestion\Application\DTO\ReconciliationSummaryView;
use App\Ingestion\Application\DTO\ShopOptionView;
use App\Ingestion\Application\Service\IssueDescriptionFormatter;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Infrastructure\Query\CoverageQuery;
use App\Ingestion\Infrastructure\Query\FinancialSummaryQuery;
use App\Ingestion\Infrastructure\Query\IssuesQuery;
use App\Ingestion\Infrastructure\Query\ReconciliationQuery;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\NormalizationIssueRepository;
use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;
use Pagerfanta\Pagerfanta;

final readonly class IngestionFacade
{
    public function __construct(
        private FinancialTransactionRepository $financialTransactionRepository,
        private NormalizationIssueRepository $normalizationIssueRepository,
        private CoverageQuery $coverageQuery,
        private ReconciliationQuery $reconciliationQuery,
        private IssuesQuery $issuesQuery,
        private FinancialSummaryQuery $financialSummaryQuery,
        private IssueDescriptionFormatter $issueDescriptionFormatter,
    ) {
    }

    /**
     * @return iterable<FinancialTransactionView>
     */
    public function getTransactions(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $shopRef = null,
    ): iterable {
        foreach ($this->financialTransactionRepository->iterateByPeriod($companyId, $from, $to, $shopRef) as $transaction) {
            yield $this->projectTransactionToView($transaction);
        }
    }

    private function projectTransactionToView(FinancialTransaction $transaction): FinancialTransactionView
    {
        return new FinancialTransactionView(
            id: $transaction->getId(),
            companyId: $transaction->getCompanyId(),
            shopRef: $transaction->getShopRef(),
            source: $transaction->getSource()->value,
            externalId: $transaction->getExternalId(),
            operationGroupId: $transaction->getOperationGroupId(),
            type: $transaction->getType()->value,
            direction: $transaction->getDirection()->value,
            amountMinor: $transaction->getAmountMinor(),
            currency: $transaction->getCurrency(),
            occurredAt: $transaction->getOccurredAt(),
            sourceTz: $transaction->getSourceTz(),
            orderRef: $transaction->getOrderRef(),
            payoutRef: $transaction->getPayoutRef(),
            counterpartyId: $transaction->getCounterpartyId(),
            listingId: $transaction->getListingId(),
            listingSku: $transaction->getListingSku(),
            description: $transaction->getDescription(),
            rawRecordId: $transaction->getRawRecordId(),
        );
    }

    public function countOpenIssues(string $companyId): int
    {
        return $this->normalizationIssueRepository->countOpenForCompany($companyId);
    }

    /**
     * @return array{cells: list<CoverageCellView>, shops: list<ShopOptionView>}
     */
    public function getCoverage(
        string $companyId,
        ?string $shopRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return [
            'cells' => $this->coverageQuery->heatmap($companyId, $shopRef, $from, $to),
            'shops' => $this->coverageQuery->shops($companyId),
        ];
    }

    /**
     * @return array{summary: ReconciliationSummaryView, byType: list<ReconciliationByTypeView>}
     */
    public function getReconciliation(string $companyId, ?string $shopRef, int $year, int $month): array
    {
        return [
            'summary' => $this->reconciliationQuery->summary($companyId, $shopRef, $year, $month),
            'byType' => $this->reconciliationQuery->breakdownByType($companyId, $shopRef, $year, $month),
        ];
    }

    /**
     * @return array{items: list<IssueListItemView>, meta: PaginationMeta}
     */
    public function listIssues(
        string $companyId,
        ?string $shopRef,
        ?int $year,
        ?int $month,
        int $page,
        int $limit,
    ): array {
        $page = max(1, $page);
        $limit = min(200, max(1, $limit));
        $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(
            new QueryAdapter(
                $this->issuesQuery->build($companyId, $shopRef, $year, $month),
                static function (QueryBuilder $countQb): void {
                    $countQb
                        ->select('COUNT(i.id) AS total_results')
                        ->resetOrderBy();
                },
            ),
            $page,
            $limit,
        );

        $items = array_map(
            fn (array $row): IssueListItemView => $this->mapIssueRow($row),
            iterator_to_array($pager->getCurrentPageResults()),
        );

        return [
            'items' => $items,
            'meta' => new PaginationMeta(
                page: $pager->getCurrentPage(),
                limit: $pager->getMaxPerPage(),
                total: $pager->getNbResults(),
                totalPages: $pager->getNbPages(),
            ),
        ];
    }

    /**
     * @return array{byMonth: list<FinancialSummaryMonthView>, byCategory: list<FinancialSummaryCategoryView>, marketplaceCategories: list<FinancialSummaryMarketplaceCategoryView>}
     */
    public function getFinancialSummary(
        string $companyId,
        ?string $shopRef,
        int $yearFrom,
        int $monthFrom,
        int $yearTo,
        int $monthTo,
    ): array {
        return [
            'byMonth' => $this->financialSummaryQuery->byMonth(
                $companyId,
                $shopRef,
                $yearFrom,
                $monthFrom,
                $yearTo,
                $monthTo,
            ),
            'byCategory' => $this->financialSummaryQuery->byCategory($companyId, $shopRef, $yearTo, $monthTo),
            'marketplaceCategories' => $this->financialSummaryQuery->marketplaceCategories($companyId, $shopRef, $yearTo, $monthTo),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapIssueRow(array $row): IssueListItemView
    {
        $kind = NormalizationIssueKind::from((string) $row['kind']);
        $details = $this->decodeDetails($row['details'] ?? []);

        return new IssueListItemView(
            id: (string) $row['id'],
            kind: $kind->value,
            humanDescription: $this->issueDescriptionFormatter->humanize($kind, $details),
            createdAt: (new \DateTimeImmutable((string) $row['created_at']))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDetails(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }

        if (!is_string($details) || '' === $details) {
            return [];
        }

        $decoded = json_decode($details, true);

        return is_array($decoded) ? $decoded : [];
    }
}
