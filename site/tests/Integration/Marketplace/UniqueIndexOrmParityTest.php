<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Stage 1.5 (R-12): уникальные индексы sales/returns/costs/raw, которые реально защищают данные
 * на проде и создаются миграциями, обязаны быть объявлены в ORM с теми же колонками и предикатом.
 * Иначе `doctrine:schema:update` считает их лишними и предлагает DROP — снять защиту от дублей.
 * Тестовая БД построена миграциями с нуля, поэтому тест заодно подтверждает empty-db паритет.
 */
final class UniqueIndexOrmParityTest extends IntegrationTestCase
{
    /** @return iterable<string, array{class-string, string, list<string>, string|null}> */
    public static function guardedUniqueIndexes(): iterable
    {
        $notBlank = static fn (string $column): string => sprintf("((%1\$s IS NOT NULL) AND (TRIM(BOTH FROM %1\$s) <> ''::text))", $column);
        $active = "((processing_status IS NULL) OR ((processing_status)::text <> 'failed'::text))";

        yield 'sales: legacy marketplace+srid' => [MarketplaceSale::class, 'uniq_marketplace_srid', ['marketplace', 'external_order_id'], null];
        yield 'sales: company key' => [MarketplaceSale::class, 'uniq_marketplace_sales_company_marketplace_external_order', ['company_id', 'marketplace', 'external_order_id'], $notBlank('external_order_id')];
        yield 'returns: company key' => [MarketplaceReturn::class, 'uniq_marketplace_returns_company_marketplace_external_return', ['company_id', 'marketplace', 'external_return_id'], $notBlank('external_return_id')];
        yield 'costs: company key' => [MarketplaceCost::class, 'uniq_marketplace_costs_company_marketplace_external', ['company_id', 'marketplace', 'external_id'], $notBlank('external_id')];
        yield 'raw: ozon sales_report period' => [MarketplaceRawDocument::class, 'uniq_marketplace_raw_documents_active_period', ['company_id', 'marketplace', 'document_type', 'period_from', 'period_to'], "(((marketplace)::text = 'ozon'::text) AND ((document_type)::text = 'sales_report'::text) AND $active)"];
        yield 'raw: endpoint period' => [MarketplaceRawDocument::class, 'uniq_mrd_active_company_marketplace_type_endpoint_period', ['company_id', 'marketplace', 'document_type', 'api_endpoint', 'period_from', 'period_to'], $active];
        yield 'raw: sales_report exact day' => [MarketplaceRawDocument::class, 'uniq_mrd_active_sales_report_exact_day', ['company_id', 'marketplace', 'document_type', 'period_from', 'period_to'], "(((document_type)::text = 'sales_report'::text) AND (period_from = period_to) AND $active)"];
    }

    /**
     * @param class-string $entity
     * @param list<string> $columns
     */
    #[DataProvider('guardedUniqueIndexes')]
    public function testOrmDeclaresTheIndexExactlyAsDatabaseHasIt(string $entity, string $name, array $columns, ?string $where): void
    {
        $constraint = $this->em->getClassMetadata($entity)->table['uniqueConstraints'][$name] ?? null;

        self::assertNotNull($constraint, sprintf('ORM не объявляет unique %s: schema:update предложит его DROP.', $name));
        self::assertSame($columns, $constraint['columns'], 'Порядок и состав колонок ORM = индекс в БД.');
        self::assertSame($where, $constraint['options']['where'] ?? null, 'Предикат ORM = pg_get_expr(indpred).');

        $row = $this->connection->fetchAssociative(
            'SELECT i.indisunique, pg_get_expr(i.indpred, i.indrelid) AS predicate,
                    array_to_string(ARRAY(SELECT a.attname FROM unnest(i.indkey::int[]) WITH ORDINALITY k(attnum, ord)
                                          JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum
                                          ORDER BY k.ord), \',\') AS cols
             FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid
             WHERE c.relname = :name',
            ['name' => $name],
        );

        self::assertIsArray($row, sprintf('Миграции не создали индекс %s (empty-db расходится с ORM).', $name));
        self::assertTrue((bool) $row['indisunique']);
        self::assertSame(implode(',', $columns), $row['cols']);
        self::assertSame($where, $row['predicate']);
    }
}
