<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UnitEconomyCostMapping migrate: truncate old data, apply NOT NULL, add unique constraint, remove old columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('TRUNCATE TABLE unit_economy_cost_mappings');

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                ALTER COLUMN cost_category_id   SET NOT NULL,
                ALTER COLUMN cost_category_name SET NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                DROP CONSTRAINT IF EXISTS uq_cost_mapping_company_marketplace_code
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                ADD CONSTRAINT uq_cost_mapping_company_marketplace_category
                    UNIQUE (company_id, marketplace, cost_category_id)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                DROP CONSTRAINT IF EXISTS chk_cost_mapping_marketplace,
                ADD CONSTRAINT chk_cost_mapping_marketplace
                    CHECK (marketplace IN (
                        'wildberries','ozon','yandex_market','sber_megamarket'))
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                DROP CONSTRAINT IF EXISTS chk_cost_mapping_unit_economy_cost_type,
                ADD CONSTRAINT chk_cost_mapping_unit_economy_cost_type
                    CHECK (unit_economy_cost_type IN (
                        'logistics_to','logistics_back','storage',
                        'advertising_cpc','advertising_other','advertising_external',
                        'commission','other'))
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                DROP COLUMN IF EXISTS is_system,
                DROP COLUMN IF EXISTS cost_category_code
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                ADD COLUMN IF NOT EXISTS is_system           BOOLEAN     NOT NULL DEFAULT FALSE,
                ADD COLUMN IF NOT EXISTS cost_category_code  VARCHAR(50) DEFAULT NULL
        SQL);
    }
}
