<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Writer;

use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;

final readonly class DefaultSaleMappingWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function createMapping(
        string $companyId,
        MarketplaceType $marketplace,
        AmountSource $amountSource,
        string $plCategoryId,
        string $plCode,
        bool $isNegative,
        ?string $descriptionTemplate,
    ): int {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->connection->executeStatement(
            // Инвариант «один активный источник суммы» закреплён частичным
            // уникальным индексом uniq_active_sale_mapping_source; NOT EXISTS ниже
            // превращает нарушение в тихий skip вместо исключения на штатном пути.
            // ON CONFLICT без указания ключа — чтобы срабатывали оба уникальных
            // индекса таблицы, а не только uniq_sale_mapping.
            // Категория ОПиУ перечитывается из pl_categories внутри самой вставки:
            // между preview и INSERT она может быть удалена, сменить код или
            // перестать быть LEAF_INPUT. Совпадение по id мало — сверяем и код,
            // потому что правило конфига адресует категорию именно кодом.
            <<<'SQL'
            INSERT INTO marketplace_sale_mappings
                (id, company_id, marketplace, operation_type, amount_source, pl_category_id,
                 is_negative, description_template, sort_order, is_active, created_at, updated_at)
            SELECT
                :id, :company_id, :marketplace, :operation_type, :amount_source, pl.id,
                :is_negative, :description_template, :sort_order, true, :created_at, :updated_at
            FROM pl_categories pl
            WHERE pl.id = :pl_category_id
              AND pl.company_id = :company_id
              AND pl.code = :pl_code
              AND pl.type = 'LEAF_INPUT'
              AND NOT EXISTS (
                  SELECT 1
                  FROM marketplace_sale_mappings existing
                  WHERE existing.company_id = :company_id
                    AND existing.marketplace = :marketplace
                    AND existing.operation_type = :operation_type
                    AND existing.amount_source = :amount_source
                    AND existing.is_active = true
              )
            ON CONFLICT DO NOTHING
            SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'company_id' => $companyId,
                'marketplace' => $marketplace->value,
                'operation_type' => $amountSource->getOperationType(),
                'amount_source' => $amountSource->value,
                'pl_category_id' => $plCategoryId,
                'pl_code' => $plCode,
                'is_negative' => $isNegative,
                'description_template' => $descriptionTemplate,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'is_negative' => ParameterType::BOOLEAN,
                'description_template' => ParameterType::STRING,
                'sort_order' => ParameterType::INTEGER,
            ],
        );
    }
}
