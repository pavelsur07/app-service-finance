<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Infrastructure\Query;

use App\Marketplace\Application\DTO\OzonRealizationIntegrity;
use Doctrine\DBAL\Connection;

/** Exact multiplicities matter: identical legitimate source rows must survive. */
final readonly class OzonRealizationIntegrityQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function fetch(string $companyId, string $rawDocumentId): OzonRealizationIntegrity
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            WITH document AS MATERIALIZED (
                SELECT raw_data::jsonb payload, md5(raw_data::text) raw_hash, period_from, period_to
                FROM marketplace_raw_documents
                WHERE company_id = :company AND id = :document AND marketplace = 'ozon' AND document_type = 'realization'
            ), source AS MATERIALIZED (
                SELECT item, period_from, period_to
                FROM document CROSS JOIN LATERAL jsonb_array_elements(
                    CASE WHEN jsonb_typeof(payload->'result'->'rows') = 'array' THEN payload->'result'->'rows' ELSE '[]'::jsonb END
                ) r(item)
            ), parsed AS (
                SELECT item->'item'->>'sku' sku, NULLIF(NULLIF(item->'item'->>'offer_id', ''), '0') offer_id,
                    NULLIF(NULLIF(item->'item'->>'name', ''), '0') name, period_from, period_to,
                    CASE WHEN (item->'delivery_commission'->>'price_per_instance') ~ :number THEN ROUND((item->'delivery_commission'->>'price_per_instance')::numeric, 2) ELSE 0 END price,
                    CASE WHEN (item->'delivery_commission'->>'quantity') ~ :number THEN TRUNC((item->'delivery_commission'->>'quantity')::numeric) ELSE 0 END qty,
                    CASE WHEN (item->'return_commission'->>'price_per_instance') ~ :number THEN ROUND((item->'return_commission'->>'price_per_instance')::numeric, 2) ELSE 0 END return_price,
                    CASE WHEN (item->'return_commission'->>'quantity') ~ :number THEN TRUNC((item->'return_commission'->>'quantity')::numeric) ELSE 0 END return_qty
                FROM source
            ), projected AS MATERIALIZED (
                SELECT jsonb_build_array(sku, offer_id, name,
                    CASE WHEN price > 0 AND qty > 0 THEN price ELSE 0 END,
                    CASE WHEN price > 0 AND qty > 0 THEN qty ELSE return_qty END,
                    CASE WHEN price > 0 AND qty > 0 THEN price * qty ELSE 0 END,
                    CASE WHEN return_price > 0 AND return_qty > 0 THEN return_price ELSE 0 END,
                    CASE WHEN return_price > 0 AND return_qty > 0 THEN return_qty ELSE 0 END,
                    CASE WHEN return_price > 0 AND return_qty > 0 THEN return_price * return_qty ELSE 0 END,
                    period_from::text, period_to::text) signature,
                    CASE WHEN price > 0 AND qty > 0 THEN price * qty ELSE 0 END sales,
                    CASE WHEN return_price > 0 AND return_qty > 0 THEN return_price * return_qty ELSE 0 END returns
                FROM parsed WHERE COALESCE(sku, '') <> '' AND ((price > 0 AND qty > 0) OR (return_price > 0 AND return_qty > 0))
            ), expected AS (
                SELECT signature, COUNT(*) copies FROM projected GROUP BY signature
            ), actual_rows AS MATERIALIZED (
                SELECT jsonb_build_array(sku, offer_id, name, seller_price_per_instance, quantity, total_amount,
                    COALESCE(return_price_per_instance, 0), COALESCE(return_quantity, 0), COALESCE(return_amount, 0),
                    period_from::text, period_to::text) signature
                FROM marketplace_ozon_realizations WHERE company_id = :company AND raw_document_id = :document
            ), actual AS (
                SELECT signature, COUNT(*) copies FROM actual_rows GROUP BY signature
            ), comparison AS MATERIALIZED (
                SELECT e.copies expected, a.copies actual FROM expected e FULL JOIN actual a USING (signature)
            )
            SELECT (SELECT raw_hash FROM document) raw_hash,
                (SELECT COUNT(*) FROM source) source_rows, (SELECT COUNT(*) FROM projected) expected_rows,
                (SELECT COUNT(*) FROM actual_rows) actual_rows,
                (SELECT COALESCE(SUM(sales), 0)::numeric(20,2)::text FROM projected) sales,
                (SELECT COALESCE(SUM(returns), 0)::numeric(20,2)::text FROM projected) returns,
                (NOT EXISTS (SELECT 1 FROM comparison WHERE actual IS DISTINCT FROM expected))::int matches,
                (EXISTS (SELECT 1 FROM expected) AND NOT EXISTS (SELECT 1 FROM comparison WHERE actual IS DISTINCT FROM 2 * expected))::int doubled
            SQL, ['company' => $companyId, 'document' => $rawDocumentId, 'number' => '^[0-9]+(\.[0-9]+)?$']);
        if (false === $row || !is_string($row['raw_hash'])) {
            throw new \DomainException('Целевой raw-документ реализации не найден у компании.');
        }

        return new OzonRealizationIntegrity($row['raw_hash'], (int) $row['source_rows'], (int) $row['expected_rows'], (int) $row['actual_rows'],
            (string) $row['sales'], (string) $row['returns'], 1 === (int) $row['matches'], 1 === (int) $row['doubled']);
    }
}
