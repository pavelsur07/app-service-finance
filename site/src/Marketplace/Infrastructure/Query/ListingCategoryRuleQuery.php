<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\ListingCategorySource;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Правила категорий по ключу площадки (`marketplace_data->>'category_key'`).
 * Пишет без commit — транзакцией владеет вызывающий (Action, загрузка каталога).
 */
final readonly class ListingCategoryRuleQuery
{
    private const KEY = "(l.marketplace_data->>'category_key')";

    public function __construct(private Connection $connection)
    {
    }

    /** Создать или заменить правило; unique (company_id, source_key) держит «одно правило на ключ». */
    public function upsert(string $companyId, string $sourceKey, string $categoryId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO marketplace_listing_category_rules (id, company_id, source_key, category_id, created_at)
             VALUES (:id, :company, :key, :category, NOW())
             ON CONFLICT (company_id, source_key) DO UPDATE SET category_id = EXCLUDED.category_id',
            ['id' => Uuid::uuid7()->toString(), 'company' => $companyId, 'key' => $sourceKey, 'category' => $categoryId],
        );
    }

    /**
     * Ставит категорию по правилам листингам без категории или с категорией от правила.
     * Ручное назначение не трогает, совпадающие строки не пишет.
     *
     * @return int число изменённых листингов
     */
    public function apply(string $companyId): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE marketplace_listings l SET category_id = r.category_id, category_source = :rule
             FROM marketplace_listing_category_rules r
             WHERE l.company_id = :company AND r.company_id = :company
               AND r.source_key = '.self::KEY.'
               AND (l.category_source IS NULL OR l.category_source = :rule)
               AND l.category_id IS DISTINCT FROM r.category_id',
            ['company' => $companyId, 'rule' => ListingCategorySource::Rule->value],
        );
    }

    /**
     * Ключи площадок без правила, у которых есть листинги без категории.
     *
     * @return list<array{sourceKey: string, marketplace: string, subjectName: ?string, listingsCount: int, examples: list<string>}>
     */
    public function unmatched(string $companyId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::KEY.' AS source_key, MIN(l.marketplace) AS marketplace,
                    MAX(l.marketplace_data->>\'subject_name\') AS subject_name, COUNT(*) AS listings_count,
                    (ARRAY_AGG(l.name ORDER BY l.name) FILTER (WHERE l.name IS NOT NULL))[1:3] AS examples
             FROM marketplace_listings l
             WHERE l.company_id = :company AND l.category_id IS NULL AND '.self::KEY.' IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM marketplace_listing_category_rules r
                               WHERE r.company_id = :company AND r.source_key = '.self::KEY.')
             GROUP BY 1
             ORDER BY listings_count DESC, source_key',
            ['company' => $companyId],
        );

        return array_map(fn (array $r): array => [
            'sourceKey' => (string) $r['source_key'],
            'marketplace' => (string) $r['marketplace'],
            'subjectName' => null === $r['subject_name'] ? null : (string) $r['subject_name'],
            'listingsCount' => (int) $r['listings_count'],
            'examples' => $this->pgArray($r['examples']),
        ], $rows);
    }

    /**
     * @return list<array{sourceKey: string, marketplace: string, subjectName: ?string, categoryId: string, categoryName: string, listingsCount: int}>
     */
    public function rules(string $companyId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.source_key, c.id AS category_id, c.name AS category_name,
                    MIN(l.marketplace) AS marketplace, MAX(l.marketplace_data->>\'subject_name\') AS subject_name,
                    COUNT(l.id) FILTER (WHERE l.category_id = r.category_id) AS listings_count
             FROM marketplace_listing_category_rules r
             JOIN marketplace_listing_categories c ON c.id = r.category_id AND c.company_id = :company
             LEFT JOIN marketplace_listings l ON l.company_id = :company AND '.self::KEY.' = r.source_key
             WHERE r.company_id = :company
             GROUP BY r.source_key, c.id, c.name
             ORDER BY c.name, r.source_key',
            ['company' => $companyId],
        );

        return array_map(static fn (array $r): array => [
            'sourceKey' => (string) $r['source_key'],
            'marketplace' => str_starts_with((string) $r['source_key'], 'wb:') ? 'wildberries' : 'ozon',
            'subjectName' => null === $r['subject_name'] ? null : (string) $r['subject_name'],
            'categoryId' => (string) $r['category_id'],
            'categoryName' => (string) $r['category_name'],
            'listingsCount' => (int) $r['listings_count'],
        ], $rows);
    }

    /** @return list<string> */
    private function pgArray(mixed $value): array
    {
        if (!is_string($value) || '{}' === $value) {
            return [];
        }

        return array_map(
            static fn (?string $v): string => stripcslashes(trim((string) $v, '"')),
            str_getcsv(substr($value, 1, -1), ',', '"', '\\'),
        );
    }
}
