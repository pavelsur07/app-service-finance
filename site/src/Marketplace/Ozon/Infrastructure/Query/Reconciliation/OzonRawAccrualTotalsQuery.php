<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Infrastructure\Query\Reconciliation;

use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\CostBucket;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\RawFlowTotals;
use App\Marketplace\Ozon\Application\Service\OzonAccrualServiceCategoryResolver;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;

/**
 * Итоги сырых начислений Ozon (`/v1/finance/accrual/by-day`) за период — то, что Ozon отдал, до нашей обработки.
 *
 * Документ by-day: `raw_data = {accruals: [...], service_types: {type_id: name}}`, один на день; если документов дня несколько
 * (пересоздание), берётся самый свежий завершённо обработанный, чтобы день не считался дважды.
 * Разбор повторяет форму, по которой процессоры строят продажи, возвраты и затраты, но считает суммы заново
 * и независимо от них — иначе сверка проверяла бы процессор самим процессором.
 * Числа читаются только если похожи на число; не массивы на месте массивов пропускаются, а не роняют запрос.
 */
final readonly class OzonRawAccrualTotalsQuery
{
    /** Код комиссии за продажу, как у `OzonAccrualCostsRawProcessor`. */
    public const COMMISSION_CODE = 'ozon_sale_commission';

    private const NUMBER = '^-?[0-9]+(\.[0-9]+)?$';

    /**
     * Общая часть: документы периода → начисления периода.
     * Дата начисления — календарная строка `Y-m-d`, сравнивается как строка.
     */
    private const ACCRUALS_CTE = <<<'SQL'
        WITH docs AS (
            SELECT DISTINCT ON (d.period_from) d.raw_data::jsonb AS payload
            FROM marketplace_raw_documents d
            WHERE d.company_id = :companyId
              AND d.marketplace = :marketplace
              AND d.api_endpoint = :endpoint
              AND d.processing_status = :completed
              AND d.period_from >= :from
              AND d.period_from <= :to
            ORDER BY d.period_from, d.synced_at DESC, d.id
        ),
        accrual_rows AS (
            SELECT a.item AS item, docs.payload AS payload
            FROM docs
            CROSS JOIN LATERAL jsonb_array_elements(
                CASE WHEN jsonb_typeof(docs.payload -> 'accruals') = 'array' THEN docs.payload -> 'accruals' ELSE '[]'::jsonb END
            ) AS a(item)
            WHERE jsonb_typeof(a.item) = 'object'
              AND (a.item ->> 'date') >= :from
              AND (a.item ->> 'date') <= :to
        ),
        product_rows AS (
            SELECT p.item AS item, ar.payload AS payload
            FROM accrual_rows ar
            CROSS JOIN LATERAL jsonb_array_elements(
                CASE WHEN jsonb_typeof(ar.item -> 'posting' -> 'products') = 'array' THEN ar.item -> 'posting' -> 'products' ELSE '[]'::jsonb END
            ) AS p(item)
            WHERE jsonb_typeof(p.item) = 'object'
        )
        SQL;

    public function __construct(
        private Connection $connection,
        private OzonAccrualServiceCategoryResolver $categoryResolver,
    ) {
    }

    /**
     * Дни периода, по которым загружен документ by-day (пустой день тоже документ).
     *
     * @return list<string> `Y-m-d`
     */
    public function presentDays(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<string> $days */
        $days = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT to_char(d.period_from, 'YYYY-MM-DD') AS day
                FROM marketplace_raw_documents d
                WHERE d.company_id = :companyId
                  AND d.marketplace = :marketplace
                  AND d.api_endpoint = :endpoint
                  AND d.processing_status = :completed
                  AND d.period_from >= :from
                  AND d.period_from <= :to
                ORDER BY day
                SQL,
            $this->params($companyId, $from, $to),
        );

        return array_map('strval', $days);
    }

    public function flows(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency = 'RUB'): RawFlowTotals
    {
        $row = $this->connection->fetchAssociative(
            self::ACCRUALS_CTE.<<<'SQL'

                SELECT
                    COALESCE(SUM(t.sale_price)  FILTER (WHERE t.sale_amount > 0), 0)  AS sales_buyer,
                    COALESCE(SUM(t.sale_amount) FILTER (WHERE t.sale_amount > 0), 0)  AS sales_seller,
                    COUNT(*)                    FILTER (WHERE t.sale_amount > 0)      AS sales_count,
                    COALESCE(-SUM(t.sale_price)  FILTER (WHERE t.sale_amount < 0), 0) AS returns_buyer,
                    COALESCE(-SUM(t.sale_amount) FILTER (WHERE t.sale_amount < 0), 0) AS returns_seller,
                    COUNT(*)                    FILTER (WHERE t.sale_amount < 0)      AS returns_count
                FROM (
                    SELECT
                        CASE WHEN (pr.item -> 'commission' -> 'sale_amount' ->> 'amount') ~ :number
                             THEN ROUND((pr.item -> 'commission' -> 'sale_amount' ->> 'amount')::numeric, 2) END AS sale_amount,
                        CASE WHEN (pr.item -> 'commission' -> 'sale_price' ->> 'amount') ~ :number
                             THEN ROUND((pr.item -> 'commission' -> 'sale_price' ->> 'amount')::numeric, 2) END AS sale_price
                    FROM product_rows pr
                ) t
                WHERE t.sale_amount IS NOT NULL
                SQL,
            $this->params($companyId, $from, $to) + ['number' => self::NUMBER],
        );

        return new RawFlowTotals(
            Money::fromString((string) ($row['sales_buyer'] ?? '0'), $currency),
            Money::fromString((string) ($row['sales_seller'] ?? '0'), $currency),
            (int) ($row['sales_count'] ?? 0),
            Money::fromString((string) ($row['returns_buyer'] ?? '0'), $currency),
            Money::fromString((string) ($row['returns_seller'] ?? '0'), $currency),
            (int) ($row['returns_count'] ?? 0),
        );
    }

    /**
     * Нетто-расход по категориям: −Σ(сумма Ozon со знаком). Отрицательное начисление Ozon — расход продавца,
     * положительное — возврат удержанного; то же правило, что `operation_type` в учёте (charge − storno).
     *
     * @return array<string, CostBucket> код категории → нетто-расход
     */
    public function costsByCategory(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency = 'RUB'): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::ACCRUALS_CTE.<<<'SQL'
                ,
                entries AS (
                    SELECT 'commission' AS kind, NULL::text AS type_id, NULL::text AS type_name,
                           CASE WHEN (pr.item -> 'commission' -> 'commission' ->> 'amount') ~ :number
                                THEN ROUND((pr.item -> 'commission' -> 'commission' ->> 'amount')::numeric, 2) END AS amount
                    FROM product_rows pr
                    UNION ALL
                    SELECT 'service', s.item ->> 'type_id', pr.payload -> 'service_types' ->> (s.item ->> 'type_id'),
                           CASE WHEN (s.item -> 'accrued' ->> 'amount') ~ :number
                                THEN ROUND((s.item -> 'accrued' ->> 'amount')::numeric, 2) END
                    FROM product_rows pr
                    CROSS JOIN LATERAL jsonb_array_elements(
                        CASE WHEN jsonb_typeof(pr.item -> 'delivery' -> 'services') = 'array' THEN pr.item -> 'delivery' -> 'services' ELSE '[]'::jsonb END
                    ) AS s(item)
                    WHERE jsonb_typeof(s.item) = 'object'
                    UNION ALL
                    SELECT 'item_fee', f.item ->> 'type_id', ar.payload -> 'service_types' ->> (f.item ->> 'type_id'),
                           CASE WHEN (f.item -> 'accrued' ->> 'amount') ~ :number
                                THEN ROUND((f.item -> 'accrued' ->> 'amount')::numeric, 2) END
                    FROM accrual_rows ar
                    CROSS JOIN LATERAL jsonb_array_elements(
                        CASE WHEN jsonb_typeof(ar.item -> 'item_fees' -> 'fees') = 'array' THEN ar.item -> 'item_fees' -> 'fees' ELSE '[]'::jsonb END
                    ) AS g(item)
                    CROSS JOIN LATERAL jsonb_array_elements(
                        CASE WHEN jsonb_typeof(g.item -> 'fees') = 'array' THEN g.item -> 'fees' ELSE '[]'::jsonb END
                    ) AS f(item)
                    WHERE jsonb_typeof(g.item) = 'object' AND jsonb_typeof(f.item) = 'object'
                    UNION ALL
                    SELECT 'non_item', ar.item -> 'non_item_fee' ->> 'type_id', ar.payload -> 'service_types' ->> (ar.item -> 'non_item_fee' ->> 'type_id'),
                           CASE WHEN (ar.item -> 'non_item_fee' -> 'accrued' ->> 'amount') ~ :number
                                THEN ROUND((ar.item -> 'non_item_fee' -> 'accrued' ->> 'amount')::numeric, 2) END
                    FROM accrual_rows ar
                    WHERE jsonb_typeof(ar.item -> 'non_item_fee') = 'object'
                )
                SELECT e.kind, e.type_id, e.type_name, SIGN(e.amount) AS amount_sign, SUM(e.amount) AS total, COUNT(*) AS cnt
                FROM entries e
                WHERE e.amount IS NOT NULL AND e.amount <> 0
                GROUP BY e.kind, e.type_id, e.type_name, SIGN(e.amount)
                SQL,
            $this->params($companyId, $from, $to) + ['number' => self::NUMBER],
        );

        /** @var array<string, CostBucket> $result */
        $result = [];
        foreach ($rows as $row) {
            $total = Money::fromString((string) $row['total'], $currency);
            $code = 'commission' === $row['kind']
                ? self::COMMISSION_CODE
                : $this->categoryResolver->resolve(
                    null === $row['type_id'] ? null : (string) $row['type_id'],
                    null === $row['type_name'] ? null : (string) $row['type_name'],
                    (float) $row['amount_sign'],
                )['code'];

            $bucket = new CostBucket($total->negate(), (int) $row['cnt']);
            $result[$code] = isset($result[$code]) ? $result[$code]->plus($bucket) : $bucket;
        }

        return $result;
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
            'completed' => PipelineStatus::COMPLETED->value,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];
    }
}
