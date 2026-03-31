<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UnitEconomyCostMapping expand: add cost_category_id and cost_category_name columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                ADD COLUMN IF NOT EXISTS cost_category_id   VARCHAR(36) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS cost_category_name VARCHAR(255) DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_cost_mapping_category_id
                ON unit_economy_cost_mappings (company_id, marketplace, cost_category_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_cost_mapping_category_id');

        $this->addSql(<<<'SQL'
            ALTER TABLE unit_economy_cost_mappings
                DROP COLUMN IF EXISTS cost_category_id,
                DROP COLUMN IF EXISTS cost_category_name
        SQL);
    }
}
