<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ИП Лазарева: упаковка Ozon (ozon_package_labor, ozon_package_materials) — из «Логистики возвратов»
 * в «Хранение», как в базовом маппинге после PR #2568.
 *
 * Автонастройка существующие маппинги не перезаписывает, поэтому у компании правка делается данными.
 * Затрагиваются только строки, которые сейчас указывают на COGS_RETURNS_DELIVERY: ручной выбор
 * другой категории не перетирается. Уже проведённые в ОПиУ документы не пересчитываются —
 * правка действует на затраты, ещё не попавшие в документ.
 * Без нужной категории у компании UPDATE ничего не меняет (NULL не подставляется).
 */
final class Version20261005100000 extends AbstractMigration
{
    private const COMPANY_ID = '3f4d87cc-a967-43a7-9b5e-8a9113c0a910';

    public function getDescription(): string
    {
        return 'Remap Ozon package cost categories from COGS_RETURNS_DELIVERY to OPEX_WH_STORAGE for one company';
    }

    public function up(Schema $schema): void
    {
        $this->remap('COGS_RETURNS_DELIVERY', 'OPEX_WH_STORAGE');
    }

    public function down(Schema $schema): void
    {
        $this->remap('OPEX_WH_STORAGE', 'COGS_RETURNS_DELIVERY');
    }

    private function remap(string $fromCode, string $toCode): void
    {
        $this->addSql(<<<'SQL'
            UPDATE marketplace_cost_pl_mappings m
            SET pl_category_id = target.id,
                updated_at = (now() AT TIME ZONE 'Europe/Moscow')
            FROM marketplace_cost_categories cc,
                 pl_categories source,
                 pl_categories target
            WHERE cc.id = m.cost_category_id
              AND cc.marketplace = 'ozon'
              AND cc.code IN ('ozon_package_labor', 'ozon_package_materials')
              AND m.company_id = :company_id
              AND source.id = m.pl_category_id
              AND source.company_id = m.company_id
              AND source.code = :from_code
              AND target.company_id = m.company_id
              AND target.code = :to_code
        SQL, [
            'company_id' => self::COMPANY_ID,
            'from_code' => $fromCode,
            'to_code' => $toCode,
        ]);
    }
}
