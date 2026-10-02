<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Infrastructure\Query\Reconciliation;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Ozon\Application\Reconciliation\DTO\RealizationTotals;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;

/**
 * Итоги отчёта «Реализация» за месяц, посчитанные из сырого документа (`document_type = realization`).
 *
 * Читается сырьё, а не обработанная таблица `marketplace_ozon_realizations`: так сверка видит отчёт сразу после загрузки
 * и не зависит от ручного или автоматического шага «применить выручку», а источник остаётся независимым от учёта.
 * Правила разбора строки те же, что у `ProcessOzonRealizationAction`: строка без sku пропускается; продажа —
 * `delivery_commission` с количеством и ценой больше нуля (цена округляется до копеек), возврат — `return_commission`
 * с количеством и ценой больше нуля. Документ без строк — отчёт не загружен (`null`), а не нулевые продажи; отчёт, где у всех строк нет sku, загруженным считается (нули).
 */
final readonly class OzonRealizationTotalsQuery
{
    private const NUMBER = '^[0-9]+(\.[0-9]+)?$';

    public function __construct(private Connection $connection)
    {
    }

    public function fetch(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency = 'RUB'): ?RealizationTotals
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                WITH docs AS (
                    SELECT DISTINCT ON (d.period_from) d.raw_data::jsonb AS payload
                    FROM marketplace_raw_documents d
                    WHERE d.company_id = :companyId
                      AND d.marketplace = :marketplace
                      AND d.document_type = 'realization'
                      AND d.period_from >= :from
                      AND d.period_to <= :to
                    ORDER BY d.period_from, d.synced_at DESC, d.id
                ),
                report_rows AS (
                    SELECT r.item AS item
                    FROM docs
                    CROSS JOIN LATERAL jsonb_array_elements(
                        CASE WHEN jsonb_typeof(docs.payload -> 'result' -> 'rows') = 'array' THEN docs.payload -> 'result' -> 'rows' ELSE '[]'::jsonb END
                    ) AS r(item)
                    WHERE jsonb_typeof(r.item) = 'object'
                ),
                parsed AS (
                    SELECT
                        COALESCE(trim(item -> 'item' ->> 'sku'), '') AS sku,
                        CASE WHEN (item -> 'delivery_commission' ->> 'price_per_instance') ~ :number
                             THEN ROUND((item -> 'delivery_commission' ->> 'price_per_instance')::numeric, 2) ELSE 0 END AS sale_price,
                        CASE WHEN (item -> 'delivery_commission' ->> 'quantity') ~ :number
                             THEN TRUNC((item -> 'delivery_commission' ->> 'quantity')::numeric) ELSE 0 END AS sale_qty,
                        CASE WHEN (item -> 'return_commission' ->> 'price_per_instance') ~ :number
                             THEN ROUND((item -> 'return_commission' ->> 'price_per_instance')::numeric, 2) ELSE 0 END AS return_price,
                        CASE WHEN (item -> 'return_commission' ->> 'quantity') ~ :number
                             THEN TRUNC((item -> 'return_commission' ->> 'quantity')::numeric) ELSE 0 END AS return_qty
                    FROM report_rows
                )
                SELECT
                    COUNT(*) AS rows_count,
                    COALESCE(SUM(sale_price * sale_qty) FILTER (WHERE sku <> '' AND sale_price > 0 AND sale_qty > 0), 0) AS sales_amount,
                    COALESCE(SUM(sale_qty) FILTER (WHERE sku <> '' AND sale_price > 0 AND sale_qty > 0), 0) AS sales_quantity,
                    COALESCE(SUM(return_price * return_qty) FILTER (WHERE sku <> '' AND return_price > 0 AND return_qty > 0), 0) AS returns_amount,
                    COALESCE(SUM(return_qty) FILTER (WHERE sku <> '' AND return_price > 0 AND return_qty > 0), 0) AS returns_quantity
                FROM parsed
                SQL,
            [
                'companyId' => $companyId,
                'marketplace' => MarketplaceType::OZON->value,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'number' => self::NUMBER,
            ],
        );

        if (false === $row || 0 === (int) $row['rows_count']) {
            return null;
        }

        return new RealizationTotals(
            Money::fromString((string) $row['sales_amount'], $currency),
            (int) $row['sales_quantity'],
            Money::fromString((string) $row['returns_amount'], $currency),
            (int) $row['returns_quantity'],
        );
    }
}
