<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;

/**
 * Правила продаж/возвратов компании, сгруппированные по источнику суммы.
 *
 * Возвращает и неактивные тоже: уникальный ключ marketplace_sale_mappings
 * включает pl_category_id, поэтому отключённое правило с той же целью не даст
 * вставить новое — preview обязан это видеть, иначе разойдётся с apply.
 */
final readonly class SaleMappingsByAmountSourceQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<string, list<array{id: string, pl_category_id: string, pl_category_name: ?string, is_active: bool, is_negative: bool}>>
     */
    public function fetchIndexed(string $companyId, MarketplaceType $marketplace): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT m.id, m.amount_source, m.pl_category_id, m.is_active, m.is_negative, c.name AS pl_category_name
            FROM marketplace_sale_mappings m
            LEFT JOIN pl_categories c ON c.id = m.pl_category_id AND c.company_id = m.company_id
            WHERE m.company_id = :companyId
              AND m.marketplace = :marketplace
            SQL,
            ['companyId' => $companyId, 'marketplace' => $marketplace->value],
        );

        $result = [];
        foreach ($rows as $row) {
            $amountSource = (string) $row['amount_source'];
            $result[$amountSource] ??= [];
            $result[$amountSource][] = [
                'id' => (string) $row['id'],
                'pl_category_id' => (string) $row['pl_category_id'],
                'pl_category_name' => null === $row['pl_category_name'] ? null : (string) $row['pl_category_name'],
                'is_active' => (bool) $row['is_active'],
                'is_negative' => (bool) $row['is_negative'],
            ];
        }

        return $result;
    }
}
