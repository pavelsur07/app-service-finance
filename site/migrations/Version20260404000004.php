<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UnitEconomyCostMapping: rename unique constraint to uq_cost_mapping_company_marketplace_category';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                DROP CONSTRAINT IF EXISTS uniq_cost_mapping
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                ADD CONSTRAINT uq_cost_mapping_company_marketplace_category
                    UNIQUE (company_id, marketplace, cost_category_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                DROP CONSTRAINT IF EXISTS uq_cost_mapping_company_marketplace_category
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                ADD CONSTRAINT uniq_cost_mapping
                    UNIQUE (company_id, marketplace, cost_category_id)
        SQL);
    }
}
