<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Application\DTO\FinancialSummaryCategoryView;
use App\Ingestion\Application\DTO\FinancialSummaryMarketplaceCategoryView;
use App\Ingestion\Application\DTO\FinancialSummaryMonthView;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
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

        [$from, $toExclusive] = $this->monthRangeBounds($yearFrom, $monthFrom, $yearTo, $monthTo);
        $conditions = [
            'ft.company_id = :companyId',
            'ft.occurred_at >= :from',
            'ft.occurred_at < :toExclusive',
        ];
        $params = [
            'companyId' => $companyId,
            'from' => $from,
            'toExclusive' => $toExclusive,
            'inDirection' => TransactionDirection::IN->value,
            'outDirection' => TransactionDirection::OUT->value,
        ];
        $types = [
            'from' => Types::DATETIME_IMMUTABLE,
            'toExclusive' => Types::DATETIME_IMMUTABLE,
        ];
        $this->addShopFilter($conditions, $params, $shopRef);

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT TO_CHAR(DATE_TRUNC('month', ft.occurred_at), 'YYYY-MM') AS period,
                        COALESCE(SUM(CASE WHEN ft.direction = :inDirection THEN ft.amount_minor::bigint ELSE 0 END), 0) AS income_minor,
                        COALESCE(SUM(CASE WHEN ft.direction = :outDirection THEN ABS(ft.amount_minor::bigint) ELSE 0 END), 0) AS expense_minor,
                        COALESCE(MIN(ft.currency), 'RUB') AS currency
                 FROM ingest_financial_transactions ft
                 WHERE %s
                 GROUP BY DATE_TRUNC('month', ft.occurred_at)
                 ORDER BY DATE_TRUNC('month', ft.occurred_at) ASC",
                implode(' AND ', $conditions),
            ),
            $params,
            $types,
        );

        return array_map(
            static function (array $row): FinancialSummaryMonthView {
                [$year, $month] = array_map('intval', explode('-', (string) $row['period']));
                $incomeMinor = (int) $row['income_minor'];
                $expenseMinor = (int) $row['expense_minor'];

                return new FinancialSummaryMonthView(
                    year: $year,
                    month: $month,
                    incomeMinor: $incomeMinor,
                    expenseMinor: $expenseMinor,
                    netMinor: $incomeMinor - $expenseMinor,
                    currency: (string) $row['currency'],
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
        [$from, $toExclusive] = $this->monthBounds($year, $month);
        $conditions = [
            'ft.company_id = :companyId',
            'ft.occurred_at >= :from',
            'ft.occurred_at < :toExclusive',
        ];
        $params = [
            'companyId' => $companyId,
            'from' => $from,
            'toExclusive' => $toExclusive,
        ];
        $types = [
            'from' => Types::DATETIME_IMMUTABLE,
            'toExclusive' => Types::DATETIME_IMMUTABLE,
        ];
        $this->addShopFilter($conditions, $params, $shopRef);

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT ft.type,
                        ft.direction,
                        COALESCE(SUM(ABS(ft.amount_minor::bigint)), 0) AS amount_minor
                 FROM ingest_financial_transactions ft
                 WHERE %s
                 GROUP BY ft.type, ft.direction
                 ORDER BY ft.direction ASC, ft.type ASC',
                implode(' AND ', $conditions),
            ),
            $params,
            $types,
        );

        return array_map(
            static function (array $row): FinancialSummaryCategoryView {
                $type = (string) $row['type'];
                $direction = (string) $row['direction'];
                $transactionType = TransactionType::tryFrom($type);

                return new FinancialSummaryCategoryView(
                    categoryId: sprintf('%s:%s', $type, $direction),
                    categoryName: $transactionType?->label() ?? $type,
                    flow: TransactionDirection::IN->value === $direction ? 'income' : 'expense',
                    amountMinor: (int) $row['amount_minor'],
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
                        COALESCE(NULLIF(ft.source_data->>'_ozon_category_group', ''), 'Требует классификации') AS category_group,
                        COALESCE(NULLIF(ft.source_data->>'_ozon_category_label', ''), NULLIF(ft.description, ''), ft.type, 'Неклассифицированная категория Ozon') AS category_name,
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

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function monthBounds(int $year, int $month): array
    {
        Assert::range($month, 1, 12);
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));

        return [$from, $from->modify('first day of next month')];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function monthRangeBounds(int $yearFrom, int $monthFrom, int $yearTo, int $monthTo): array
    {
        Assert::range($monthFrom, 1, 12);
        Assert::range($monthTo, 1, 12);

        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $yearFrom, $monthFrom));
        $to = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $yearTo, $monthTo));
        Assert::lessThanEq($from, $to);

        return [$from, $to->modify('first day of next month')];
    }

    /**
     * @param list<string> $conditions
     * @param array<string, mixed> $params
     */
    private function addShopFilter(array &$conditions, array &$params, ?string $shopRef): void
    {
        if (null === $shopRef || '' === trim($shopRef)) {
            return;
        }

        $conditions[] = 'ft.shop_ref = :shopRef';
        $params['shopRef'] = trim($shopRef);
    }
}
