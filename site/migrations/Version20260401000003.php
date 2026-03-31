<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UnitEconomyCostMapping contract: drop unused index after deploy verification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_cost_mapping_category_id');

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_cost_mapping_company
                ON unit_economy_cost_mappings (company_id)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_cost_mapping_company_marketplace
                ON unit_economy_cost_mappings (company_id, marketplace)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_cost_mapping_category_id
                ON unit_economy_cost_mappings (company_id, marketplace, cost_category_id)
        SQL);

        $this->addSql('DROP INDEX IF EXISTS idx_cost_mapping_company');
        $this->addSql('DROP INDEX IF EXISTS idx_cost_mapping_company_marketplace');
    }
}
