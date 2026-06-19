<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Finance\Enum\PLFlow;
use App\Ingestion\Application\DTO\FinancialSummaryCategoryView;
use App\Ingestion\Application\DTO\FinancialSummaryMonthView;
use Doctrine\DBAL\Connection;
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

    private static function decimalToMinor(string $amount): int
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        $normalized = $negative ? substr($amount, 1) : $amount;
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');
        $minor = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$minor : $minor;
    }
}
