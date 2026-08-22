<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Query;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class BalanceDynamicsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{date:string,account_count:int,balance:string}>
     */
    public function fetchBalanceSeries(
        string $companyId,
        string $currency,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $sql = <<<'SQL'
            SELECT
                TO_CHAR(days.day, 'YYYY-MM-DD') AS date,
                COUNT(account.id)::int AS account_count,
                COALESCE(SUM(COALESCE(latest.closing_balance, account.opening_balance)), 0)::text AS balance
            FROM (
                SELECT GENERATE_SERIES(
                    CAST(:date_from AS date),
                    CAST(:date_to AS date),
                    INTERVAL '1 day'
                )::date AS day
            ) days
            LEFT JOIN money_account account
                ON account.company_id = :company_id
               AND account.currency = :currency
               AND account.is_active = TRUE
               AND account.opening_balance_date <= days.day
            LEFT JOIN LATERAL (
                SELECT snapshot.closing_balance
                FROM money_account_daily_balance snapshot
                WHERE snapshot.company_id = :company_id
                  AND snapshot.money_account_id = account.id
                  AND snapshot.currency = :currency
                  AND snapshot.date BETWEEN account.opening_balance_date AND days.day
                ORDER BY snapshot.date DESC
                LIMIT 1
            ) latest ON TRUE
            GROUP BY days.day
            ORDER BY days.day
            SQL;

        /** @var list<array{date:string,account_count:int|string,balance:string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'company_id' => $companyId,
                'currency' => $currency,
                'date_from' => $from,
                'date_to' => $to,
            ],
            [
                'date_from' => Types::DATE_IMMUTABLE,
                'date_to' => Types::DATE_IMMUTABLE,
            ],
        );

        return array_map(static fn (array $row): array => [
            'date' => $row['date'],
            'account_count' => (int) $row['account_count'],
            'balance' => $row['balance'],
        ], $rows);
    }

    /**
     * @return list<array{date:string,flow_kind:string,value:string}>
     */
    public function fetchFlowSeries(
        string $companyId,
        string $currency,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $sql = <<<'SQL'
            SELECT
                TO_CHAR(tx.occurred_at, 'YYYY-MM-DD') AS date,
                category.flow_kind,
                SUM(
                    CASE tx.direction
                        WHEN :inflow THEN split.amount
                        WHEN :outflow THEN -split.amount
                        ELSE 0
                    END
                )::text AS value
            FROM cash_transaction tx
            INNER JOIN cash_transaction_split split
                ON split.cash_transaction_id = tx.id
               AND split.company_id = :company_id
            INNER JOIN cashflow_categories category
                ON category.id = split.cashflow_category_id
               AND category.company_id = :company_id
            WHERE tx.company_id = :company_id
              AND tx.occurred_at BETWEEN :date_from AND :date_to
              AND tx.currency = :currency
              AND tx.is_transfer = FALSE
              AND tx.deleted_at IS NULL
              AND category.flow_kind IN (:flow_kinds)
              AND (category.system_code IS NULL OR category.system_code NOT IN (:unallocated_codes))
            GROUP BY tx.occurred_at, category.flow_kind
            SQL;

        /** @var list<array{date:string,flow_kind:string,value:string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'company_id' => $companyId,
                'currency' => $currency,
                'date_from' => $from,
                'date_to' => $to,
                'inflow' => CashDirection::INFLOW->value,
                'outflow' => CashDirection::OUTFLOW->value,
                'flow_kinds' => [
                    CashflowFlowKind::OPERATING->value,
                    CashflowFlowKind::FINANCING->value,
                    CashflowFlowKind::INVESTING->value,
                ],
                'unallocated_codes' => [
                    CashflowCategory::CODE_UNALLOCATED,
                    CashflowCategory::SYSTEM_UNALLOCATED,
                ],
            ],
            [
                'date_from' => Types::DATE_IMMUTABLE,
                'date_to' => Types::DATE_IMMUTABLE,
                'flow_kinds' => ArrayParameterType::STRING,
                'unallocated_codes' => ArrayParameterType::STRING,
            ],
        );

        return $rows;
    }
}
