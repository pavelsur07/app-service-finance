<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Infrastructure\Query\Reconciliation;

use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\CostBucket;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\LedgerFlowTotals;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;

/**
 * Что система записала в учёт из сырых начислений by-day.
 *
 * В сверку входят только строки, чей `raw_document_id` указывает на документ by-day: затраты и продажи
 * снятого формата v3 (до 08.09.2026) сверять не с чем. Затраты вне by-day считаются отдельно.
 * Нетто-расход = затраты − сторно (`operation_type`), как в `CostReconciliationQuery`.
 */
final readonly class OzonLedgerTotalsQuery
{
    private const NO_CATEGORY_CODE = 'ozon_no_category';

    public function __construct(private Connection $connection)
    {
    }

    public function flows(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency = 'RUB'): LedgerFlowTotals
    {
        $params = $this->params($companyId, $from, $to);

        $sales = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COALESCE(SUM(s.total_revenue), 0) AS amount, COUNT(s.id) AS cnt
                FROM marketplace_sales s
                INNER JOIN marketplace_raw_documents d ON d.id = s.raw_document_id
                WHERE s.company_id = :companyId
                  AND s.marketplace = :marketplace
                  AND s.sale_date >= :from
                  AND s.sale_date <= :to
                  AND d.api_endpoint = :endpoint
                SQL,
            $params,
        );

        $returns = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COALESCE(SUM(r.refund_amount), 0) AS amount, COUNT(r.id) AS cnt
                FROM marketplace_returns r
                INNER JOIN marketplace_raw_documents d ON d.id = r.raw_document_id
                WHERE r.company_id = :companyId
                  AND r.marketplace = :marketplace
                  AND r.return_date >= :from
                  AND r.return_date <= :to
                  AND d.api_endpoint = :endpoint
                SQL,
            $params,
        );

        return new LedgerFlowTotals(
            Money::fromString((string) ($sales['amount'] ?? '0'), $currency),
            (int) ($sales['cnt'] ?? 0),
            Money::fromString((string) ($returns['amount'] ?? '0'), $currency),
            (int) ($returns['cnt'] ?? 0),
        );
    }

    /**
     * @return array<string, CostBucket> код категории → нетто-расход по документам by-day
     */
    public function costsByCategory(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency = 'RUB'): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    COALESCE(cc.code, :noCategory) AS category_code,
                    SUM(CASE WHEN c.operation_type = 'storno' THEN -c.amount ELSE c.amount END) AS net,
                    COUNT(c.id) AS cnt
                FROM marketplace_costs c
                INNER JOIN marketplace_raw_documents d ON d.id = c.raw_document_id
                LEFT JOIN marketplace_cost_categories cc ON cc.id = c.category_id
                WHERE c.company_id = :companyId
                  AND c.marketplace = :marketplace
                  AND c.cost_date >= :from
                  AND c.cost_date <= :to
                  AND d.api_endpoint = :endpoint
                GROUP BY 1
                SQL,
            $this->params($companyId, $from, $to) + ['noCategory' => self::NO_CATEGORY_CODE],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['category_code']] = new CostBucket(
                Money::fromString((string) $row['net'], $currency),
                (int) $row['cnt'],
            );
        }

        return $result;
    }

    /**
     * Затраты периода, чей документ — не by-day (или документа нет). В сверку не входят.
     */
    public function costsOutsideRaw(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency = 'RUB'): CostBucket
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(CASE WHEN c.operation_type = 'storno' THEN -c.amount ELSE c.amount END), 0) AS net,
                    COUNT(c.id) AS cnt
                FROM marketplace_costs c
                LEFT JOIN marketplace_raw_documents d ON d.id = c.raw_document_id
                WHERE c.company_id = :companyId
                  AND c.marketplace = :marketplace
                  AND c.cost_date >= :from
                  AND c.cost_date <= :to
                  AND (d.id IS NULL OR d.api_endpoint IS DISTINCT FROM :endpoint)
                SQL,
            $this->params($companyId, $from, $to),
        );

        return new CostBucket(
            Money::fromString((string) ($row['net'] ?? '0'), $currency),
            (int) ($row['cnt'] ?? 0),
        );
    }

    /**
     * @return array<string, string>
     */
    private function params(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [
            'companyId' => $companyId,
            'marketplace' => MarketplaceType::OZON->value,
            'endpoint' => MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];
    }
}
