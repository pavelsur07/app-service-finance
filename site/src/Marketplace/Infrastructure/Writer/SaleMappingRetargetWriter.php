<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Writer;

use Doctrine\DBAL\Connection;

/**
 * Перенос активного правила продаж/возвратов на другую строку ввода ОПиУ. Переносит, только если
 * правило всё ещё смотрит на ожидаемую строку, а новая строка — LEAF_INPUT той же компании:
 * правку, сделанную после сверки, не затираем.
 */
final readonly class SaleMappingRetargetWriter
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return int число перенесённых правил: 0 или 1
     */
    public function retarget(string $companyId, string $mappingId, string $fromPlCategoryId, string $toPlCategoryId): int
    {
        return (int) $this->connection->executeStatement(
            "UPDATE marketplace_sale_mappings m
             SET pl_category_id = pl.id, updated_at = :now
             FROM pl_categories pl
             WHERE m.id = :mapping_id
               AND m.company_id = :company_id
               AND m.is_active = true
               AND m.pl_category_id = :from_id
               AND pl.id = :to_id
               AND pl.company_id = :company_id
               AND pl.type = 'LEAF_INPUT'",
            [
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'mapping_id' => $mappingId,
                'company_id' => $companyId,
                'from_id' => $fromPlCategoryId,
                'to_id' => $toPlCategoryId,
            ],
        );
    }

    /**
     * Строка ОПиУ активного правила компании; null — правила нет или оно отключено.
     */
    public function currentTarget(string $companyId, string $mappingId): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT pl_category_id FROM marketplace_sale_mappings WHERE id = :mapping_id AND company_id = :company_id AND is_active = true',
            ['mapping_id' => $mappingId, 'company_id' => $companyId],
        );

        return false === $value || null === $value ? null : (string) $value;
    }
}
