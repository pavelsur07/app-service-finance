<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Finance\Enum\PLFlow;
use App\Ingestion\Application\DTO\FinancialSummaryCategoryView;
use App\Ingestion\Application\DTO\FinancialSummaryMarketplaceCategoryView;
use App\Ingestion\Application\DTO\FinancialSummaryMonthView;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Webmozart\Assert\Assert;

final class FinancialSummaryQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<FinancialSummaryMonthView>
     */
    public function byMonth(
        string $companyId,
        ?string $shopRef,
        int $yearFrom,
        int $monthFrom,
        int $yearTo,
        int $monthTo,
    ): array {
        Assert::uuid($companyId);
        unset($shopRef);

        $periodFrom = sprintf('%04d-%02d', $yearFrom, $monthFrom);
        $periodTo = sprintf('%04d-%02d', $yearTo, $monthTo);

        $rows = $this->connection->createQueryBuilder()
            ->select(
                's.period',
                'COALESCE(SUM(s.amount_income), 0) AS income_amount',
                'COALESCE(SUM(s.amount_expense), 0) AS expense_amount',
            )
            ->from('pl_monthly_snapshots', 's')
            ->where('s.company_id = :companyId')
            ->andWhere('s.period >= :periodFrom')
            ->andWhere('s.period <= :periodTo')
            ->andWhere('s.rebuilt_at IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->setParameter('periodFrom', $periodFrom)
            ->setParameter('periodTo', $periodTo)
            ->groupBy('s.period')
            ->orderBy('s.period', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static function (array $row): FinancialSummaryMonthView {
                [$year, $month] = array_map('intval', explode('-', (string) $row['period']));
                $incomeMinor = self::decimalToMinor((string) $row['income_amount']);
                $expenseMinor = self::decimalToMinor((string) $row['expense_amount']);

                return new FinancialSummaryMonthView(
                    year: $year,
                    month: $month,
                    incomeMinor: $incomeMinor,
                    expenseMinor: $expenseMinor,
                    netMinor: $incomeMinor - $expenseMinor,
                    currency: 'RUB',
                );
            },
            $rows,
        );
    }

    /**
     * @return list<FinancialSummaryCategoryView>
     */
    public function byCategory(string $companyId, ?string $shopRef, int $year, int $month): array
    {
        Assert::uuid($companyId);
        unset($shopRef);

        $period = sprintf('%04d-%02d', $year, $month);

        $rows = $this->connection->createQueryBuilder()
            ->select(
                'c.id AS category_id',
                'c.name AS category_name',
                'c.flow',
                'COALESCE(SUM(s.amount_income), 0) AS income_amount',
                'COALESCE(SUM(s.amount_expense), 0) AS expense_amount',
            )
            ->from('pl_monthly_snapshots', 's')
            ->innerJoin('s', 'pl_categories', 'c', 'c.id = s.pl_category_id')
            ->where('s.company_id = :companyId')
            ->andWhere('s.period = :period')
            ->andWhere('s.rebuilt_at IS NOT NULL')
            ->andWhere('s.pl_category_id IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->setParameter('period', $period)
            ->groupBy('c.id', 'c.name', 'c.flow')
            ->orderBy('c.name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static function (array $row): FinancialSummaryCategoryView {
                $flow = (string) $row['flow'];
                $incomeMinor = self::decimalToMinor((string) $row['income_amount']);
                $expenseMinor = self::decimalToMinor((string) $row['expense_amount']);
                $amountMinor = match ($flow) {
                    PLFlow::INCOME->value => $incomeMinor,
                    PLFlow::EXPENSE->value => $expenseMinor,
                    default => $incomeMinor - $expenseMinor,
                };

                return new FinancialSummaryCategoryView(
                    categoryId: (string) $row['category_id'],
                    categoryName: (string) $row['category_name'],
                    flow: strtolower($flow),
                    amountMinor: $amountMinor,
                );
            },
            $rows,
        );
    }

    /**
     * @return list<FinancialSummaryMarketplaceCategoryView>
     */
    public function marketplaceCategories(string $companyId, ?string $shopRef, int $year, int $month): array
    {
        Assert::uuid($companyId);
        [$from, $toExclusive] = $this->monthBounds($year, $month);

        $conditions = [
            'ft.company_id = :companyId',
            'ft.source = :source',
            "ft.source_data->>'_ingestion_resource' = :resourceType",
            'ft.occurred_at >= :from',
            'ft.occurred_at < :toExclusive',
        ];
        $params = [
            'companyId' => $companyId,
            'source' => IngestSource::OZON->value,
            'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            'from' => $from,
            'toExclusive' => $toExclusive,
            'outDirection' => TransactionDirection::OUT->value,
        ];
        $types = [
            'from' => Types::DATETIME_IMMUTABLE,
            'toExclusive' => Types::DATETIME_IMMUTABLE,
        ];

        if (null !== $shopRef && '' !== trim($shopRef)) {
            $conditions[] = 'ft.shop_ref = :shopRef';
            $params['shopRef'] = trim($shopRef);
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "WITH base AS (
                    SELECT
                        ft.source,
                        COALESCE(NULLIF(ft.source_data->>'_ozon_category_group', ''), 'Без группы Ozon') AS category_group,
                        COALESCE(NULLIF(ft.source_data->>'_ozon_category_label', ''), ft.description, ft.type) AS category_name,
                        ft.type,
                        ft.direction,
                        ABS(ft.amount_minor::bigint) AS amount_minor,
                        CASE
                            WHEN ft.source_data->>'_ozon_category_sort_order' ~ '^[0-9]+$'
                                THEN (ft.source_data->>'_ozon_category_sort_order')::int
                            ELSE 9999
                        END AS sort_order
                    FROM ingest_financial_transactions ft
                    WHERE %s
                )
                SELECT
                    source,
                    category_group,
                    category_name,
                    type,
                    direction,
                    COALESCE(SUM(CASE WHEN direction = :outDirection THEN -amount_minor ELSE amount_minor END), 0) AS amount_minor,
                    COUNT(*) AS tx_count,
                    MIN(sort_order) AS sort_order
                FROM base
                GROUP BY source, category_group, category_name, type, direction
                ORDER BY sort_order ASC, category_group ASC, category_name ASC, type ASC, direction ASC",
                implode(' AND ', $conditions),
            ),
            $params,
            $types,
        );

        return array_map(
            static fn (array $row): FinancialSummaryMarketplaceCategoryView => new FinancialSummaryMarketplaceCategoryView(
                source: (string) $row['source'],
                categoryGroup: (string) $row['category_group'],
                categoryName: (string) $row['category_name'],
                type: (string) $row['type'],
                direction: (string) $row['direction'],
                amountMinor: (int) $row['amount_minor'],
                txCount: (int) $row['tx_count'],
                sortOrder: (int) $row['sort_order'],
            ),
            $rows,
        );
    }

    private static function decimalToMinor(string $amount): int
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        $normalized = $negative ? substr($amount, 1) : $amount;
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');
        $minor = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$minor : $minor;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function monthBounds(int $year, int $month): array
    {
        Assert::range($month, 1, 12);
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));

        return [$from, $from->modify('first day of next month')];
    }
}
